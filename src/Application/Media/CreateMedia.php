<?php
/**
 * Create-media use case.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Application\Media;

use InvalidArgumentException;
use IsuDev\WPContentBridge\Application\Mutation\AuditEvent;
use IsuDev\WPContentBridge\Application\Mutation\AuditLog;
use IsuDev\WPContentBridge\Application\Mutation\IdempotencyStore;
use IsuDev\WPContentBridge\Domain\Media\MediaUploadRequest;
use IsuDev\WPContentBridge\Domain\Media\MediaUploadResult;
use Throwable;

/**
 * Imports one remote image, exactly once per idempotency key.
 */
final readonly class CreateMedia {

	public const ABILITY = 'wp-content-bridge/create-media';

	/**
	 * Creates the use case.
	 *
	 * @param MediaAccessManager $media       Media feature policy.
	 * @param MediaUploader      $uploader    Fetch/validate/store port.
	 * @param MediaRepository    $reader      Attachment read port for replays.
	 * @param IdempotencyStore   $idempotency Per-user retry key store.
	 * @param AuditLog           $audit       Append-only audit sink.
	 */
	public function __construct(
		private MediaAccessManager $media,
		private MediaUploader $uploader,
		private MediaRepository $reader,
		private IdempotencyStore $idempotency,
		private AuditLog $audit,
	) {}

	/**
	 * Executes the import and records exactly one redacted audit event.
	 *
	 * `MediaUploadRequest::from_input()` raises `InvalidArgumentException` for a
	 * malformed request, and `MediaAccessManager::require_read()` raises
	 * `MediaUnavailable` when the media feature is off. Both propagate after the
	 * audit row is recorded.
	 *
	 * @param array<string, mixed> $raw_input Normalized Ability input.
	 * @param int                  $user_id   Acting principal.
	 * @return MediaUploadResult
	 * The uploader raises `MediaUploadFailed` when the URL is refused, the fetch
	 * fails, or the bytes are not an allowed image.
	 *
	 * @throws Throwable Re-thrown validation, policy, and upload failures.
	 */
	public function execute( array $raw_input, int $user_id ): MediaUploadResult {
		try {
			$request = MediaUploadRequest::from_input( $raw_input );
			$this->media->require_read();

			/*
			 * The replay check runs before the fetch, so a retry costs no
			 * outbound request and cannot produce a second attachment. This is
			 * the whole reason the key is required rather than optional: a
			 * duplicated upload consumes storage and regenerates every image
			 * size, and stays invisible until someone opens the media library.
			 */
			$existing = $this->idempotency->find( $user_id, $request->idempotency_key );
			if ( null !== $existing ) {
				$replayed = $this->reader->get( $existing );
				if ( null !== $replayed ) {
					$this->record( $user_id, $replayed->id, array(), 'success', null );

					return new MediaUploadResult( $replayed, false );
				}
			}

			$item = $this->uploader->upload( $request );
			$this->idempotency->remember( $user_id, $request->idempotency_key, $item->id );
		} catch ( Throwable $error ) {
			[ $outcome, $code ] = $this->classify( $error );
			$this->record( $user_id, null, array(), $outcome, $code );

			throw $error;
		}

		$this->record( $user_id, $item->id, $request->changed_fields(), 'success', null );

		return new MediaUploadResult( $item, true );
	}

	/**
	 * Records one redacted audit row.
	 *
	 * The source URL is never recorded. It is caller-supplied text that would
	 * put an arbitrary external address into the site's audit table, and the
	 * audit contract is field names only.
	 *
	 * @param int         $user_id        Acting principal.
	 * @param int|null    $attachment_id  Attachment the attempt concerned.
	 * @param array       $changed_fields Field names only.
	 * @phpstan-param list<string> $changed_fields
	 * @param string      $outcome        Audit outcome vocabulary.
	 * @param string|null $code           Public error code, when refused.
	 * @return void
	 */
	private function record( int $user_id, ?int $attachment_id, array $changed_fields, string $outcome, ?string $code ): void {
		$this->audit->record(
			new AuditEvent(
				$user_id,
				self::ABILITY,
				$attachment_id,
				null === $attachment_id ? null : 'attachment',
				$changed_fields,
				null,
				null,
				$outcome,
				$code
			)
		);
	}

	/**
	 * Classifies a failure for the stable audit vocabulary.
	 *
	 * @param Throwable $error Failure that ended the attempt.
	 * @return array{0: string, 1: string}
	 */
	private function classify( Throwable $error ): array {
		if ( $error instanceof InvalidArgumentException ) {
			return array( 'invalid', 'wpcb_invalid_input' );
		}
		if ( $error instanceof MediaUnavailable ) {
			return array( 'denied', 'wpcb_media_unavailable' );
		}
		if ( $error instanceof MediaUploadFailed ) {
			return array( 'invalid', $error->error_code() );
		}

		return array( 'failure', 'wpcb_write_failed' );
	}
}

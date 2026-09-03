<?php
/**
 * Update-media use case.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Application\Media;

use InvalidArgumentException;
use IsuDev\WPContentBridge\Application\Mutation\AuditEvent;
use IsuDev\WPContentBridge\Application\Mutation\AuditLog;
use IsuDev\WPContentBridge\Application\Mutation\MutationConflict;
use IsuDev\WPContentBridge\Application\Mutation\MutationWriteFailed;
use IsuDev\WPContentBridge\Domain\Media\MediaMetadataResult;
use IsuDev\WPContentBridge\Domain\Media\MediaMetadataUpdate;
use Throwable;

/**
 * Orchestrates a version-tested edit of one attachment's descriptive fields.
 */
final readonly class UpdateMedia {

	public const ABILITY = 'wp-content-bridge/update-media';

	/**
	 * Creates the use case.
	 *
	 * @param MediaAccessManager           $media      Media feature policy.
	 * @param AttachmentMetadataRepository $repository Concurrency + write port.
	 * @param MediaRepository              $reader     Attachment read port.
	 * @param AuditLog                     $audit      Append-only audit sink.
	 */
	public function __construct(
		private MediaAccessManager $media,
		private AttachmentMetadataRepository $repository,
		private MediaRepository $reader,
		private AuditLog $audit,
	) {}

	/**
	 * Executes the edit and records exactly one redacted audit event.
	 *
	 * `MediaMetadataUpdate::from_input()` raises `InvalidArgumentException` for
	 * a malformed request, and `MediaAccessManager::require_read()` raises
	 * `MediaUnavailable` when the media feature is off. Both propagate after
	 * the audit row is recorded.
	 *
	 * @param array<string, mixed> $raw_input Normalized Ability input.
	 * @param int                  $user_id   Acting principal.
	 * @return MediaMetadataResult
	 * @throws MediaUnavailable When the attachment is absent or unreadable.
	 * @throws MutationConflict When the version token is stale.
	 * @throws MutationWriteFailed When the write or its confirmation fails.
	 * @throws Throwable Re-thrown validation failures.
	 */
	public function execute( array $raw_input, int $user_id ): MediaMetadataResult {
		$attachment_id    = null;
		$expected_version = null;

		try {
			$update           = MediaMetadataUpdate::from_input( $raw_input );
			$attachment_id    = $update->attachment_id;
			$expected_version = $update->expected_version->to_string();

			$this->media->require_read();

			/*
			 * Read access is checked before existence is revealed, and both
			 * answer with the same exception: an attachment the caller may not
			 * read must not be distinguishable from one that is not there.
			 */
			if ( ! $this->reader->can_read( $update->attachment_id ) ) {
				throw new MediaUnavailable( 'Media is unavailable.' );
			}

			$current = $this->repository->current_version( $update->attachment_id );
			if ( null === $current ) {
				throw new MediaUnavailable( 'Media is unavailable.' );
			}
			if ( ! $current->equals( $update->expected_version ) ) {
				throw new MutationConflict( 'The submitted version token is stale.' );
			}

			$version = $this->repository->apply( $update );
			$item    = $this->reader->get( $update->attachment_id );
			if ( null === $item ) {
				throw new MutationWriteFailed( 'The updated attachment could not be re-read.' );
			}

			$result = new MediaMetadataResult( $item, $version, $update->changed_fields() );
		} catch ( Throwable $error ) {
			[ $outcome, $code ] = $this->classify( $error );
			$this->audit->record(
				new AuditEvent(
					$user_id,
					self::ABILITY,
					$attachment_id,
					null === $attachment_id ? null : 'attachment',
					array(),
					$expected_version,
					null,
					$outcome,
					$code
				)
			);

			throw $error;
		}

		$this->audit->record(
			new AuditEvent(
				$user_id,
				self::ABILITY,
				$result->media->id,
				'attachment',
				$result->changed_fields,
				$expected_version,
				$result->version->to_string(),
				'success',
				null
			)
		);

		return $result;
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
			return array( 'invalid', 'wpcb_media_unavailable' );
		}
		if ( $error instanceof MutationConflict ) {
			return array( 'conflict', 'wpcb_conflict' );
		}

		return array( 'failure', 'wpcb_write_failed' );
	}
}

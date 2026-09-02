<?php
/**
 * Update-featured-image use case.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Application\Media;

use InvalidArgumentException;
use IsuDev\WPContentBridge\Application\Content\ContentUnavailable;
use IsuDev\WPContentBridge\Application\ContentAccess\ContentAccessManager;
use IsuDev\WPContentBridge\Application\Mutation\AuditEvent;
use IsuDev\WPContentBridge\Application\Mutation\AuditLog;
use IsuDev\WPContentBridge\Application\Mutation\ContentMutationRepository;
use IsuDev\WPContentBridge\Application\Mutation\MutationConflict;
use IsuDev\WPContentBridge\Application\Mutation\MutationForbidden;
use IsuDev\WPContentBridge\Application\Mutation\MutationWriteFailed;
use IsuDev\WPContentBridge\Domain\ContentAccess\ContentOperation;
use IsuDev\WPContentBridge\Domain\Mutation\FeaturedImageMutationResult;
use IsuDev\WPContentBridge\Domain\Mutation\FeaturedImageUpdate;
use IsuDev\WPContentBridge\Domain\Mutation\MutationResult;
use Throwable;

/**
 * Orchestrates a policy- and version-tested featured-image write.
 */
final readonly class UpdateFeaturedImage {

	public const ABILITY = 'wp-content-bridge/update-featured-image';

	/**
	 * Creates the use case.
	 *
	 * @param ContentAccessManager      $access     Per-post-type write policy.
	 * @param MediaAccessManager        $media      Media feature policy.
	 * @param ContentMutationRepository $repository Post lookup/version/re-read port.
	 * @param FeaturedImageRepository   $featured   Featured-image write port.
	 * @param MediaRepository           $reader     Attachment read port for the effective result.
	 * @param AuditLog                  $audit      Append-only audit sink.
	 */
	public function __construct(
		private ContentAccessManager $access,
		private MediaAccessManager $media,
		private ContentMutationRepository $repository,
		private FeaturedImageRepository $featured,
		private MediaRepository $reader,
		private AuditLog $audit,
	) {}

	/**
	 * Executes the write and records exactly one redacted audit event.
	 *
	 * @param array<string, mixed> $raw_input Normalized Ability input.
	 * @param int                  $user_id   Acting principal.
	 * @return FeaturedImageMutationResult
	 * `FeaturedImageUpdate::from_input()` raises `InvalidArgumentException` for a
	 * malformed request, and `MediaAccessManager::require_read()` raises
	 * `MediaUnavailable` when the media feature is off. Both propagate after the
	 * audit row is recorded.
	 *
	 * @throws ContentUnavailable When the target is absent.
	 * @throws MutationForbidden When policy denies the write, or the attachment is not an assignable image.
	 * @throws MutationConflict When the version token is stale.
	 * @throws MutationWriteFailed When the write, its read-back confirmation, or the post re-read fails.
	 * @throws Throwable Re-thrown validation failures.
	 */
	public function execute( array $raw_input, int $user_id ): FeaturedImageMutationResult {
		$post_id          = null;
		$post_type        = null;
		$expected_version = null;

		try {
			$update           = FeaturedImageUpdate::from_input( $raw_input );
			$post_id          = $update->post_id;
			$expected_version = $update->expected_version->to_string();

			/*
			 * The media feature gate is checked even though this is a write:
			 * the effective result re-reads the attachment through the media
			 * read port, and an operator who has turned media off has not
			 * consented to attachment data leaving the site by any route.
			 */
			$this->media->require_read();

			$post_type = $this->repository->post_type( $update->post_id );
			if ( null === $post_type ) {
				throw new ContentUnavailable( 'Content is unavailable.' );
			}
			if ( ! $this->access->allows( $post_type, ContentOperation::UPDATE_FEATURED ) ) {
				throw new MutationForbidden( 'Featured-image updates are not permitted for this type.' );
			}

			$current = $this->repository->current_version( $update->post_id );
			if ( null === $current ) {
				throw new ContentUnavailable( 'Content is unavailable.' );
			}
			if ( ! $current->equals( $update->expected_version ) ) {
				throw new MutationConflict( 'The submitted version token is stale.' );
			}

			if ( $update->removes() ) {
				$this->featured->remove( $update->post_id );
			} else {
				$attachment_id = (int) $update->attachment_id;
				if ( ! $this->featured->is_assignable_image( $attachment_id ) ) {
					/*
					 * Deliberately the same refusal for "absent", "not an
					 * image", and "not readable by you". Distinguishing them
					 * would let a caller probe which attachment IDs exist and
					 * which are private.
					 */
					throw new MutationForbidden( 'The attachment is not an image this principal may assign.' );
				}
				$this->featured->assign( $update->post_id, $attachment_id );
			}

			$base = $this->repository->result_for( $update->post_id );
			if ( null === $base ) {
				throw new MutationWriteFailed( 'The updated post could not be re-read.' );
			}

			$effective_id = $this->featured->current( $update->post_id );
			$mutation     = new MutationResult(
				$base->post_id,
				$base->post_type,
				$base->status,
				$base->version,
				$update->changed_fields(),
				false
			);
			$result       = new FeaturedImageMutationResult(
				$mutation,
				null === $effective_id ? null : $this->reader->get( $effective_id )
			);
		} catch ( Throwable $error ) {
			[ $outcome, $code ] = $this->classify( $error );
			$this->audit->record(
				new AuditEvent(
					$user_id,
					self::ABILITY,
					$post_id,
					$post_type,
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
				$result->mutation->post_id,
				$result->mutation->post_type,
				$result->mutation->changed_fields,
				$expected_version,
				$result->mutation->version->to_string(),
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
		if ( $error instanceof ContentUnavailable ) {
			return array( 'invalid', 'wpcb_content_unavailable' );
		}
		if ( $error instanceof MediaUnavailable ) {
			return array( 'denied', 'wpcb_media_unavailable' );
		}
		if ( $error instanceof MutationForbidden ) {
			return array( 'denied', 'wpcb_forbidden' );
		}
		if ( $error instanceof MutationConflict ) {
			return array( 'conflict', 'wpcb_conflict' );
		}

		return array( 'failure', 'wpcb_write_failed' );
	}
}

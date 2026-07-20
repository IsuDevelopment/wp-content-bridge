<?php
/**
 * Update-content use case.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Application\Mutation;

use InvalidArgumentException;
use IsuDev\WPContentBridge\Application\Content\ContentUnavailable;
use IsuDev\WPContentBridge\Application\ContentAccess\ContentAccessManager;
use IsuDev\WPContentBridge\Domain\ContentAccess\ContentOperation;
use IsuDev\WPContentBridge\Domain\Mutation\ContentUpdate;
use IsuDev\WPContentBridge\Domain\Mutation\MutationResult;
use Throwable;

/**
 * Orchestrates an update to an existing post with optimistic concurrency. Never
 * changes post status. Records exactly one audit row per attempt.
 */
final readonly class UpdateContent {

	public const ABILITY = 'wp-content-bridge/update-content';

	/**
	 * Creates the use case.
	 *
	 * @param ContentAccessManager      $access     Per-post-type write policy.
	 * @param BlockMarkupValidator      $validator  Block markup validation port.
	 * @param ContentMutationRepository $repository Content write port.
	 * @param AuditLog                  $audit      Audit sink.
	 */
	public function __construct(
		private ContentAccessManager $access,
		private BlockMarkupValidator $validator,
		private ContentMutationRepository $repository,
		private AuditLog $audit,
	) {
	}

	/**
	 * Executes the update-content flow, recording exactly one audit row.
	 *
	 * @param array<string, mixed> $raw_input Normalized ability input.
	 * @param int                  $user_id   Acting principal.
	 * @return MutationResult
	 * @throws ContentUnavailable When the target is absent or ineligible.
	 * @throws MutationForbidden When policy denies the type.
	 * @throws MutationConflict When the version token is stale.
	 * @throws InvalidBlockMarkup When block markup is invalid.
	 * @throws Throwable Re-thrown validation or write failures (InvalidArgumentException, MutationWriteFailed).
	 */
	public function execute( array $raw_input, int $user_id ): MutationResult {
		$post_id          = null;
		$post_type        = null;
		$expected_version = null;

		try {
			$update           = ContentUpdate::from_input( $raw_input );
			$post_id          = $update->post_id;
			$expected_version = $update->expected_version->to_string();

			$post_type = $this->repository->post_type( $update->post_id );
			if ( null === $post_type ) {
				throw new ContentUnavailable( 'Content is unavailable.' );
			}

			if ( ! $this->access->allows( $post_type, ContentOperation::UPDATE ) ) {
				throw new MutationForbidden( 'Content updates are not permitted for this type.' );
			}

			$current = $this->repository->current_version( $update->post_id );
			if ( null === $current ) {
				throw new ContentUnavailable( 'Content is unavailable.' );
			}
			if ( ! $current->equals( $update->expected_version ) ) {
				throw new MutationConflict( 'The submitted version token is stale.' );
			}

			if ( null !== $update->block_markup && '' !== $update->block_markup ) {
				$reasons = $this->validator->validate( $update->block_markup );
				if ( array() !== $reasons ) {
					throw new InvalidBlockMarkup( $reasons );
				}
			}

			$result = $this->repository->update( $update->post_id, $update );

			$this->audit->record(
				new AuditEvent(
					$user_id,
					self::ABILITY,
					$result->post_id,
					$result->post_type,
					$result->changed_fields,
					$expected_version,
					$result->version->to_string(),
					'success',
					null
				)
			);

			return $result;
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
	}

	/**
	 * Classifies a failure into a stable audit outcome and error code.
	 *
	 * @param Throwable $error The failure that ended the attempt.
	 * @return array{0: string, 1: string} Outcome and stable error code.
	 */
	private function classify( Throwable $error ): array {
		if ( $error instanceof InvalidArgumentException ) {
			return array( 'invalid', 'wpcb_invalid_input' );
		}
		if ( $error instanceof ContentUnavailable ) {
			return array( 'invalid', 'wpcb_content_unavailable' );
		}
		if ( $error instanceof MutationForbidden ) {
			return array( 'denied', 'wpcb_forbidden' );
		}
		if ( $error instanceof MutationConflict ) {
			return array( 'conflict', 'wpcb_conflict' );
		}
		if ( $error instanceof InvalidBlockMarkup ) {
			return array( 'invalid', 'wpcb_invalid_blocks' );
		}

		return array( 'failure', 'wpcb_write_failed' );
	}
}

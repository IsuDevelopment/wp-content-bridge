<?php
/**
 * Update-block use case.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Application\Mutation;

use InvalidArgumentException;
use IsuDev\WPContentBridge\Application\Content\ContentUnavailable;
use IsuDev\WPContentBridge\Application\ContentAccess\ContentAccessManager;
use IsuDev\WPContentBridge\Domain\ContentAccess\ContentOperation;
use IsuDev\WPContentBridge\Domain\Mutation\BlockUpdate;
use IsuDev\WPContentBridge\Domain\Mutation\ContentUpdate;
use IsuDev\WPContentBridge\Domain\Mutation\MutationResult;
use Throwable;

/**
 * Replaces exactly one block subtree, addressed by path, leaving every other
 * block byte-identical by construction. Shares update-content's gates
 * exactly: policy, native capability (enforced by the adapter), optimistic
 * concurrency, and exactly one audit row per attempt. A block path is
 * positional detail and never enters the audit row, which records field
 * names only.
 */
final readonly class UpdateBlock {

	public const ABILITY = 'wp-content-bridge/update-block';

	/**
	 * Creates the use case.
	 *
	 * @param ContentAccessManager      $access     Per-post-type write policy.
	 * @param ContentMutationRepository $repository Content write port.
	 * @param ContentSnapshotRepository $snapshots  Current content field read port.
	 * @param BlockTreeSplicer          $splicer    Path resolution and splice port.
	 * @param BlockMarkupValidator      $validator  Block markup validation port.
	 * @param AuditLog                  $audit      Audit sink.
	 */
	public function __construct(
		private ContentAccessManager $access,
		private ContentMutationRepository $repository,
		private ContentSnapshotRepository $snapshots,
		private BlockTreeSplicer $splicer,
		private BlockMarkupValidator $validator,
		private AuditLog $audit,
	) {
	}

	/**
	 * Executes the update-block flow, recording exactly one audit row.
	 *
	 * @param array<string, mixed> $raw_input Normalized ability input.
	 * @param int                  $user_id   Acting principal.
	 * @return MutationResult
	 * @throws ContentUnavailable When the target is absent or ineligible.
	 * @throws MutationForbidden When policy denies the type.
	 * @throws MutationConflict When the version token is stale.
	 * @throws BlockPathNotFound When no block exists at the given path.
	 * @throws BlockMismatch When the block at path is not of the expected type.
	 * @throws InvalidBlockMarkup When block markup is invalid.
	 * @throws Throwable Re-thrown validation or write failures (InvalidArgumentException, MutationWriteFailed).
	 */
	public function execute( array $raw_input, int $user_id ): MutationResult {
		$post_id          = null;
		$post_type        = null;
		$expected_version = null;

		try {
			$update           = BlockUpdate::from_input( $raw_input );
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

			$snapshot = $this->snapshots->content_snapshot( $update->post_id );
			if ( null === $snapshot ) {
				throw new ContentUnavailable( 'Content is unavailable.' );
			}

			$lookup = $this->splicer->resolve( $snapshot['block_markup'], $update->path );
			if ( null === $lookup ) {
				throw new BlockPathNotFound( 'No block exists at the given path.' );
			}
			if ( $lookup->block_name !== $update->expected_block_name ) {
				throw new BlockMismatch( 'The block at the given path is not of the expected type.' );
			}

			if ( '' !== $update->block_markup ) {
				$reasons = $this->validator->validate( $update->block_markup );
				if ( array() !== $reasons ) {
					throw new InvalidBlockMarkup( $reasons );
				}
			}

			$resulting_content = $this->splicer->splice( $snapshot['block_markup'], $update->path, $update->block_markup );

			$content_update = new ContentUpdate( $update->post_id, $update->expected_version, null, $resulting_content, null, null );

			$result = $this->repository->update( $update->post_id, $content_update );
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
		if ( $error instanceof BlockPathNotFound ) {
			return array( 'invalid', 'wpcb_block_path_not_found' );
		}
		if ( $error instanceof BlockMismatch ) {
			return array( 'invalid', 'wpcb_block_mismatch' );
		}
		if ( $error instanceof InvalidBlockMarkup ) {
			return array( 'invalid', 'wpcb_invalid_blocks' );
		}

		return array( 'failure', 'wpcb_write_failed' );
	}
}

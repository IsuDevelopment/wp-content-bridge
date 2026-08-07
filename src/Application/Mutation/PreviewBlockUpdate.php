<?php
/**
 * Preview-update-block use case.
 *
 * @package IsuDev\WPContentBridge
 */

declare( strict_types=1 );

namespace IsuDev\WPContentBridge\Application\Mutation;

use IsuDev\WPContentBridge\Application\Content\ContentUnavailable;
use IsuDev\WPContentBridge\Application\ContentAccess\ContentAccessManager;
use IsuDev\WPContentBridge\Domain\ContentAccess\ContentOperation;
use IsuDev\WPContentBridge\Domain\Mutation\BlockUpdate;
use IsuDev\WPContentBridge\Domain\Mutation\BlockUpdatePreviewResult;

/**
 * Builds a bounded preview of a block-subtree update while performing no
 * mutation. Mirrors update-block's policy and concurrency checks exactly,
 * per ADR 0021, and takes no AuditLog dependency at all, so it structurally
 * cannot audit.
 */
final readonly class PreviewBlockUpdate {

	public const ABILITY = 'wp-content-bridge/preview-update-block';

	/**
	 * Creates the use case.
	 *
	 * @param ContentAccessManager      $access     Per-post-type write policy.
	 * @param ContentMutationRepository $repository Post identity/version lookup port (shared with update-block).
	 * @param ContentSnapshotRepository $snapshots  Current content field read port.
	 * @param BlockTreeSplicer          $splicer    Path resolution and splice port.
	 * @param BlockMarkupValidator      $validator  Block markup validation port.
	 */
	public function __construct(
		private ContentAccessManager $access,
		private ContentMutationRepository $repository,
		private ContentSnapshotRepository $snapshots,
		private BlockTreeSplicer $splicer,
		private BlockMarkupValidator $validator,
	) {
	}

	/**
	 * Previews one validated block-subtree update without writing.
	 *
	 * @param array<string, mixed> $raw_input Ability input.
	 * @return BlockUpdatePreviewResult
	 * @throws ContentUnavailable When the target is absent or ineligible.
	 * @throws MutationForbidden When policy denies the type.
	 * @throws MutationConflict When the version token is stale.
	 * @throws BlockPathNotFound When no block exists at the given path.
	 * @throws BlockMismatch When the block at path is not of the expected type.
	 * @throws InvalidBlockMarkup When block markup is invalid.
	 */
	public function execute( array $raw_input ): BlockUpdatePreviewResult {
		$update = BlockUpdate::from_input( $raw_input );

		$post_type = $this->repository->post_type( $update->post_id );
		if ( null === $post_type ) {
			throw new ContentUnavailable( 'Content is unavailable.' );
		}

		if ( ! $this->access->allows( $post_type, ContentOperation::UPDATE ) ) {
			throw new MutationForbidden( 'Content updates are not permitted for this type.' );
		}

		$current_version = $this->repository->current_version( $update->post_id );
		if ( null === $current_version ) {
			throw new ContentUnavailable( 'Content is unavailable.' );
		}
		if ( ! $current_version->equals( $update->expected_version ) ) {
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
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- structured field names, not rendered output.
				throw new InvalidBlockMarkup( $reasons );
			}
		}

		$preview_content = $this->splicer->splice( $snapshot['block_markup'], $update->path, $update->block_markup );

		return new BlockUpdatePreviewResult(
			$update->post_id,
			$post_type,
			$current_version,
			$snapshot['block_markup'],
			$preview_content
		);
	}
}

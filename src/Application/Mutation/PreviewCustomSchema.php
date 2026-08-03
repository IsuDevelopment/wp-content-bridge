<?php
/**
 * Preview-Custom-schema use case.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Application\Mutation;

use IsuDev\WPContentBridge\Application\Content\ContentUnavailable;
use IsuDev\WPContentBridge\Application\ContentAccess\ContentAccessManager;
use IsuDev\WPContentBridge\Domain\ContentAccess\ContentOperation;
use IsuDev\WPContentBridge\Domain\Mutation\CustomSchemaPreviewResult;
use IsuDev\WPContentBridge\Domain\Mutation\CustomSchemaUpdate;

/**
 * Validates prospective Custom Schema while performing no mutation.
 */
final readonly class PreviewCustomSchema {

	public const ABILITY = 'wp-content-bridge/preview-custom-schema';

	/**
	 * Creates the use case.
	 *
	 * @param ContentAccessManager      $access     Per-post-type SEO policy.
	 * @param ContentMutationRepository $repository Post identity/version lookup port.
	 * @param CustomSchemaReader        $reader     Provider-neutral preview port.
	 */
	public function __construct(
		private ContentAccessManager $access,
		private ContentMutationRepository $repository,
		private CustomSchemaReader $reader,
	) {}

	/**
	 * Previews one validated update without writing.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @throws ContentUnavailable When the target is absent.
	 * @throws MutationForbidden When policy denies SEO configuration access.
	 * @throws MutationConflict When the version token is stale.
	 * @throws CustomSchemaUnavailable When the optional provider cannot handle the target.
	 */
	public function execute( array $input ): CustomSchemaPreviewResult {
		$update    = CustomSchemaUpdate::from_input( $input );
		$post_type = $this->repository->post_type( $update->post_id );
		$current   = $this->repository->current_version( $update->post_id );
		if ( null === $post_type || null === $current ) {
			throw new ContentUnavailable( 'Content is unavailable.' );
		}

		if ( ! $this->access->allows( $post_type, ContentOperation::UPDATE_SEO ) ) {
			throw new MutationForbidden( 'Custom Schema previews are not permitted for this type.' );
		}
		if ( ! $current->equals( $update->expected_version ) ) {
			throw new MutationConflict( 'The submitted version token is stale.' );
		}
		if ( ! $this->reader->is_available() ) {
			throw new CustomSchemaUnavailable( 'Custom Schema is unavailable.' );
		}

		return new CustomSchemaPreviewResult(
			$update->post_id,
			$post_type,
			$current,
			$update->changed_fields(),
			$this->reader->read( $update->post_id ),
			$this->reader->preview( $update->post_id, $update->writable_fields() )
		);
	}
}

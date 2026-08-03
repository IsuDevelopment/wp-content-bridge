<?php
/**
 * Get-Custom-schema use case.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Application\Mutation;

use InvalidArgumentException;
use IsuDev\WPContentBridge\Application\Content\ContentUnavailable;
use IsuDev\WPContentBridge\Application\ContentAccess\ContentAccessManager;
use IsuDev\WPContentBridge\Domain\ContentAccess\ContentOperation;
use IsuDev\WPContentBridge\Domain\Mutation\CustomSchemaReadResult;

/**
 * Reads one policy-authorized effective Custom Schema configuration.
 */
final readonly class GetCustomSchema {

	public const ABILITY = 'wp-content-bridge/get-custom-schema';

	/**
	 * Creates the use case.
	 *
	 * @param ContentAccessManager      $access     Per-post-type SEO policy.
	 * @param ContentMutationRepository $repository Post identity/version lookup port.
	 * @param CustomSchemaReader        $reader     Provider-neutral read port.
	 */
	public function __construct(
		private ContentAccessManager $access,
		private ContentMutationRepository $repository,
		private CustomSchemaReader $reader,
	) {}

	/**
	 * Reads one configuration.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @throws InvalidArgumentException When the request is malformed.
	 * @throws ContentUnavailable When the target is absent.
	 * @throws MutationForbidden When policy denies SEO configuration access.
	 * @throws CustomSchemaUnavailable When the optional provider cannot handle the target.
	 */
	public function execute( array $input ): CustomSchemaReadResult {
		if ( array( 'post_id' ) !== array_keys( $input ) || ! is_int( $input['post_id'] ) || 0 >= $input['post_id'] ) {
			throw new InvalidArgumentException( 'A post ID must be a positive integer.' );
		}

		$post_id   = $input['post_id'];
		$post_type = $this->repository->post_type( $post_id );
		$version   = $this->repository->current_version( $post_id );
		if ( null === $post_type || null === $version ) {
			throw new ContentUnavailable( 'Content is unavailable.' );
		}

		if ( ! $this->access->allows( $post_type, ContentOperation::UPDATE_SEO ) ) {
			throw new MutationForbidden( 'Custom Schema access is not permitted for this type.' );
		}
		if ( ! $this->reader->is_available() ) {
			throw new CustomSchemaUnavailable( 'Custom Schema is unavailable.' );
		}

		return new CustomSchemaReadResult( $post_id, $post_type, $version, $this->reader->read( $post_id ) );
	}
}

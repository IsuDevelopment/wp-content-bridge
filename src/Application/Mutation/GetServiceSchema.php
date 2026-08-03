<?php
/**
 * Get-Service-schema use case.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Application\Mutation;

use InvalidArgumentException;
use IsuDev\WPContentBridge\Application\Content\ContentUnavailable;
use IsuDev\WPContentBridge\Application\ContentAccess\ContentAccessManager;
use IsuDev\WPContentBridge\Domain\ContentAccess\ContentOperation;
use IsuDev\WPContentBridge\Domain\Mutation\ServiceSchemaReadResult;

/**
 * Reads one policy-authorized effective Service schema configuration.
 */
final readonly class GetServiceSchema {

	public const ABILITY = 'wp-content-bridge/get-service-schema';

	/**
	 * Creates the use case.
	 *
	 * @param ContentAccessManager      $access     Per-post-type SEO policy.
	 * @param ContentMutationRepository $repository Post identity/version lookup port.
	 * @param ServiceSchemaReader       $reader     Provider-neutral read port.
	 */
	public function __construct(
		private ContentAccessManager $access,
		private ContentMutationRepository $repository,
		private ServiceSchemaReader $reader,
	) {}

	/**
	 * Reads one configuration.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @throws InvalidArgumentException When the request is malformed.
	 * @throws ContentUnavailable When the target is absent.
	 * @throws MutationForbidden When policy denies SEO configuration access.
	 * @throws ServiceSchemaUnavailable When the optional provider cannot handle the target.
	 */
	public function execute( array $input ): ServiceSchemaReadResult {
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
			throw new MutationForbidden( 'Service schema access is not permitted for this type.' );
		}

		if ( ! $this->reader->is_available() || ! $this->reader->supports_post_type( $post_type ) ) {
			throw new ServiceSchemaUnavailable( 'Service schema is unavailable for this content type.' );
		}

		return new ServiceSchemaReadResult( $post_id, $post_type, $version, $this->reader->read( $post_id ) );
	}
}

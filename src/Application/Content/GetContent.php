<?php
/**
 * Get-content use case.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Application\Content;

use IsuDev\WPContentBridge\Application\ContentAccess\ContentAccessManager;
use IsuDev\WPContentBridge\Domain\Content\ContentDetail;
use IsuDev\WPContentBridge\Domain\ContentAccess\ContentOperation;

/**
 * Reads one object without revealing whether a denial was policy or capability based.
 */
final readonly class GetContent {

	public const MAX_REPRESENTATION_BYTES = 2 * 1024 * 1024;

	/**
	 * Creates the detail use case.
	 *
	 * @param ContentAccessManager $access     Shared content policy.
	 * @param ContentRepository    $repository Content reader port.
	 */
	public function __construct(
		private ContentAccessManager $access,
		private ContentRepository $repository,
	) {
	}

	/**
	 * Reads one content object.
	 *
	 * @param int   $post_id         Object ID.
	 * @param array $representations Requested forms.
	 * @param array $relationships   Requested relationships.
	 * @return ContentDetail
	 * @phpstan-param list<string> $representations
	 * @phpstan-param list<string> $relationships
	 * @throws ContentUnavailable       When missing, disabled, or unreadable.
	 * @throws ContentPayloadTooLarge   When selected representations exceed the limit.
	 */
	public function execute( int $post_id, array $representations, array $relationships ): ContentDetail {
		$post_type = $this->repository->post_type( $post_id );

		if (
			null === $post_type
			|| ! $this->access->allows( $post_type, ContentOperation::READ )
			|| ! $this->repository->can_read( $post_id )
		) {
			throw new ContentUnavailable( 'Content is unavailable.' );
		}

		$content = $this->repository->get( $post_id, $representations, $relationships );
		if ( null === $content ) {
			throw new ContentUnavailable( 'Content is unavailable.' );
		}
		if ( $content->total_representation_bytes() > self::MAX_REPRESENTATION_BYTES ) {
			throw new ContentPayloadTooLarge( 'Selected content representations exceed the size limit.' );
		}

		return $content;
	}
}

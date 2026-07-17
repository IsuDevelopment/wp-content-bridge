<?php
/**
 * WordPress SEO target authorization.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Infrastructure\WordPress;

use IsuDev\WPContentBridge\Application\Content\ContentRepository;
use IsuDev\WPContentBridge\Application\ContentAccess\ContentAccessManager;
use IsuDev\WPContentBridge\Application\Seo\SeoTargetAccess;
use IsuDev\WPContentBridge\Domain\ContentAccess\ContentOperation;
use IsuDev\WPContentBridge\Domain\Seo\SeoTarget;

/**
 * Protects post-backed URLs with the same three gates as content detail.
 */
final readonly class WordPressSeoTargetAccess implements SeoTargetAccess {

	/**
	 * Creates the authorization adapter.
	 *
	 * @param ContentAccessManager $access     Shared post-type policy.
	 * @param ContentRepository    $repository Native object reader.
	 */
	public function __construct(
		private ContentAccessManager $access,
		private ContentRepository $repository,
	) {
	}

	/**
	 * Authorizes a direct post or a URL that resolves to a WordPress post.
	 *
	 * Same-origin non-post URLs (home, archive, legitimate 404) have no object
	 * capability to check and remain available to authenticated bridge readers.
	 *
	 * @param SeoTarget $target Validated same-site target.
	 * Post-backed URLs become post selectors after authorization. This avoids
	 * stale provider URL indexes after a domain migration while keeping archive,
	 * home, and legitimate 404 URLs as URL selectors.
	 *
	 * @return SeoTarget|null Authorized canonical target, or null on denial.
	 */
	public function readable_target( SeoTarget $target ): ?SeoTarget {
		$post_id = $target->post_id;
		if ( null === $post_id && null !== $target->url ) {
			$post_id = url_to_postid( $target->url );
			if ( 0 === $post_id ) {
				return $target;
			}
		}
		if ( null === $post_id ) {
			return null;
		}

		$post_type = $this->repository->post_type( $post_id );
		$allowed   = null !== $post_type
			&& $this->access->allows( $post_type, ContentOperation::READ )
			&& $this->repository->can_read( $post_id );

		return $allowed ? SeoTarget::for_post( $post_id ) : null;
	}
}

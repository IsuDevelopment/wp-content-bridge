<?php
/**
 * Published permalink lookup port.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Application\Redirect;

/**
 * Answers whether a site-relative path is the current canonical permalink of
 * a still-published, non-trashed object — the "live-content shadow guard"
 * from ADR 0026 s5. A redirect is for a URL that no longer resolves to live
 * content, not a way to intercept one that does.
 */
interface PublishedPermalinkLookup {

	/**
	 * Answers whether the path resolves to a currently published object.
	 *
	 * @param string $path Site-relative path, e.g. `/old-page`.
	 * @return bool
	 */
	public function is_published_permalink( string $path ): bool;
}

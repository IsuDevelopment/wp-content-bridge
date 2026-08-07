<?php
/**
 * Llms.txt ownership-conflict detection port.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Application\Llms;

use IsuDev\WPContentBridge\Domain\Llms\LlmsOwnershipState;

/**
 * Detects, and never resolves, who currently owns `/llms.txt` routing.
 *
 * A physical file at the web root wins routing at the web-server level,
 * before WordPress runs, and Yoast SEO's own `llms.txt` feature can write
 * and regenerate exactly such a file. Implementations report both signals
 * without ever deactivating a plugin or deleting, moving, or renaming any
 * file — removal is an administrator's deployment step.
 *
 * Two methods exist because they cost differently. {@see self::inspect()}
 * reads local signals only (a filesystem existence check and one
 * non-autoloaded/one Yoast option key) and is cheap enough for an Ability
 * call or the settings screen. {@see self::inspect_with_verification()}
 * additionally performs a same-site HTTP request to confirm what the public
 * path actually serves; that request must never run on a front-end request
 * and must be triggered explicitly by a caller that wants it, never
 * silently folded into every read.
 */
interface LlmsOwnershipInspector {

	/**
	 * Detects ownership from local signals only. Performs no network request.
	 *
	 * The returned state's `public_verification` is always
	 * {@see \IsuDev\WPContentBridge\Domain\Llms\LlmsPublicVerification::UNKNOWN}
	 * because this method never checks; callers that need the public result
	 * must call {@see self::inspect_with_verification()} explicitly.
	 *
	 * @return LlmsOwnershipState
	 */
	public function inspect(): LlmsOwnershipState;

	/**
	 * Detects ownership from local signals, then confirms it with a bounded,
	 * fail-soft, same-site `GET` of the public `/llms.txt` path.
	 *
	 * The request never throws: an unreachable or slow site resolves to
	 * {@see \IsuDev\WPContentBridge\Domain\Llms\LlmsPublicVerification::UNKNOWN},
	 * never an exception. This method must only be invoked from an
	 * authenticated Ability or the settings screen, never from a front-end
	 * hook.
	 *
	 * @param string      $site_url              Canonical absolute site origin to probe.
	 * @param string|null $expected_content_hash Stored artifact content hash to compare
	 *                                            the response body against, if one exists.
	 * @return LlmsOwnershipState
	 */
	public function inspect_with_verification( string $site_url, ?string $expected_content_hash ): LlmsOwnershipState;
}

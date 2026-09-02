<?php
/**
 * Raised when a redirect provider call is not authorized.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Application\Redirect;

use RuntimeException;

/**
 * Authorization denial raised from inside a provider adapter, kept distinct
 * from `RedirectProviderUnavailable` because "you may not" and "it is not
 * here" are different answers and collapsing them hides both.
 *
 * This exists because of a source finding in ADR 0026's amendment:
 * `WPSEO_Redirect_Manager` performs no capability check of its own, so when
 * this plugin calls it in-process there is no other gate. The roadmap requires
 * Yoast's native `wpseo_manage_redirects` in addition to bridge authority, and
 * this is what refusing it looks like.
 */
final class RedirectProviderForbidden extends RuntimeException {
}

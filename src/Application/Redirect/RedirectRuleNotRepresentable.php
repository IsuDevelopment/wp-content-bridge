<?php
/**
 * Raised when a provider holds a rule this plugin cannot express.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Application\Redirect;

use RuntimeException;

/**
 * A provider rule exists for the requested source but falls outside the
 * provider-neutral contract (ADR 0026 s5): a regex-format rule, an off-site
 * target, or an HTTP status outside the `301`/`302`/`410` allowlist.
 *
 * This is deliberately not answered as "no rule found". A missing answer would
 * let the candidate guard conclude the source is free and create a second rule
 * for a path another engine already claims — exactly the two-plugin failure
 * ADR 0026 s5 was corrected for. It is also not `RedirectProviderUnavailable`:
 * the provider is present and answered; it is this plugin that cannot
 * represent what came back.
 */
final class RedirectRuleNotRepresentable extends RuntimeException {
}

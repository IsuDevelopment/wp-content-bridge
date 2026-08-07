<?php
/**
 * Public llms.txt endpoint verification result.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Domain\Llms;

/**
 * What a same-site `GET` of the public `/llms.txt` path actually observed,
 * as distinct from the locally detected ownership signals.
 *
 * This is deliberately not derived from local state: a site can be locally
 * unblocked and still have something unexpected serving the path, and a
 * blocked site is not required to have anything reachable at all.
 */
enum LlmsPublicVerification: string {

	/**
	 * The response body's content hash matched the bridge's own stored
	 * snapshot hash: the bridge is confirmed to be the one actually serving
	 * the path.
	 */
	case SERVED_BY_BRIDGE = 'served_by_bridge';

	/**
	 * A `200` response was observed, but its body did not match the bridge's
	 * stored snapshot hash (or no snapshot exists to compare against).
	 */
	case SERVED_BY_OTHER = 'served_by_other';

	/**
	 * The path answered `404`: nothing is currently being served there.
	 */
	case NOT_FOUND = 'not_found';

	/**
	 * The check was not performed, or the site could not be reached in time.
	 * This is the safe default and must never be treated as a conflict
	 * signal by itself.
	 */
	case UNKNOWN = 'unknown';
}

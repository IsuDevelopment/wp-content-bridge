<?php
/**
 * Detected llms.txt ownership.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Domain\Llms;

/**
 * Who currently owns `/llms.txt` routing, as best as {@see LlmsOwnershipState}
 * can determine from local signals alone.
 *
 * This is a report, not a resolution: none of these values imply the bridge
 * may act on what it finds.
 */
enum LlmsOwnershipOwner: string {

	/**
	 * The bridge's virtual endpoint is the only claimant detected, and its
	 * publication flag is on.
	 */
	case BRIDGE = 'bridge';

	/**
	 * Yoast SEO's own `llms.txt` feature is enabled and can write and
	 * regenerate a physical file that outranks the bridge's virtual endpoint.
	 */
	case YOAST = 'yoast';

	/**
	 * A physical artifact exists at the web root, but Yoast's feature is not
	 * the reason — some other generator or a manually placed file.
	 */
	case THIRD_PARTY = 'third_party';

	/**
	 * No claimant was detected: no physical artifact, Yoast's feature is off,
	 * and the bridge's own publication flag is off.
	 */
	case NONE = 'none';
}

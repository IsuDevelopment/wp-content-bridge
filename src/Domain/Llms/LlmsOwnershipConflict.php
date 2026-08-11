<?php
/**
 * Blocking llms.txt ownership conflict codes.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Domain\Llms;

/**
 * Stable, machine-readable reasons {@see LlmsOwnershipState} blocks bridge
 * publication from claiming to be the public owner of `/llms.txt`.
 *
 * A conflict here never triggers automatic remediation; it is reported so an
 * administrator can act, matching this slice's read-only detection contract.
 */
enum LlmsOwnershipConflict: string {

	/**
	 * Yoast SEO's `llms.txt` feature is enabled. Yoast writes a physical root
	 * file and schedules its own regeneration, and that file can take
	 * precedence over the bridge's virtual endpoint regardless of whether it
	 * currently exists on disk.
	 */
	case YOAST_LLMS_TXT_ENABLED = 'yoast_llms_txt_enabled';

	/**
	 * A physical artifact exists at the web root that Yoast's feature does
	 * not account for. A physical file wins routing at the web-server level,
	 * before WordPress runs, so it outranks the bridge's virtual endpoint
	 * regardless of who or what created it.
	 */
	case PHYSICAL_ARTIFACT_PRESENT = 'physical_artifact_present';

	/**
	 * The bridge is enabled but WordPress uses plain query-string permalinks,
	 * so the virtual root-path rewrite can never be matched.
	 */
	case BRIDGE_ROUTE_UNROUTABLE = 'bridge_route_unroutable';
}

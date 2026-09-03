<?php
/**
 * Three-state availability of site error statistics.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Domain\Statistics;

/**
 * Whether a statistics answer is an observation, a switched-off log, or a
 * backend that never collected the data at all (ADR 0030 s2).
 *
 * The three never collapse. A Yoast-only site collects no 404 data, a site
 * with `expire_404 = -1` collects none because logging is off, and a healthy
 * site with no 404s collects an empty result - and all three would otherwise
 * be reported as "zero", which reads as "no problems". This is the same
 * defect class as ADR 0027's HTTP 500 on every rejection: a real state
 * rendered indistinguishable from an unrelated one.
 */
enum ErrorStatisticsAvailability: string {

	/**
	 * No provider on this site collects this data. Never reported as zero.
	 */
	case UNAVAILABLE = 'unavailable';

	/**
	 * A provider is present but its logging is switched off, or the specific
	 * field is not recorded. `disabled_by` names the setting responsible.
	 */
	case DISABLED = 'disabled';

	/**
	 * The log is on and the result is the observation, including a
	 * legitimately empty one.
	 */
	case MEASURED = 'measured';

	/**
	 * The provider collects the data but the acting principal may not read
	 * it under the provider's own permission model.
	 *
	 * Distinct from `UNAVAILABLE` for the same reason
	 * `RedirectProviderForbidden` is distinct from
	 * `RedirectProviderUnavailable`: "you may not" and "it is not here" are
	 * different answers, and a caller that cannot tell them apart would ask
	 * an administrator to install a plugin that is already installed.
	 */
	case FORBIDDEN = 'forbidden';
}

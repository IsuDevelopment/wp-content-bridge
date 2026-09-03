<?php
/**
 * Provider-neutral site error statistics port.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Application\Statistics;

use IsuDev\WPContentBridge\Domain\Statistics\ErrorStatisticsProviderStatus;
use IsuDev\WPContentBridge\Domain\Statistics\NotFoundStatistics;

/**
 * The statistics port (ADR 0030 s1).
 *
 * Separate from `RedirectProvider` on purpose, and `RedirectProvider` gains no
 * statistics method: statistics availability does not follow redirect
 * availability. A site using Yoast as its redirect provider has full redirect
 * read/write and zero statistics, and hanging statistics off the redirect port
 * would force that site to answer *something* for "top 404s" - an empty list,
 * which reads as "no problems".
 *
 * This port is read-only by construction. Nothing here deletes, prunes, or
 * resets a log or a counter, even though both known backends can, and no
 * implementation may change a provider's settings: statistics do not enable
 * logging (ADR 0030, "What this is not").
 */
interface ErrorStatisticsProvider {

	/**
	 * Whether this provider is active and its storage vouched for.
	 *
	 * An adapter that reads a provider's table directly must probe the schema
	 * it depends on here and answer false rather than issue a query it cannot
	 * vouch for (ADR 0030 s4).
	 *
	 * @return bool
	 */
	public function is_available(): bool;

	/**
	 * Returns safe provider identity, available or not.
	 *
	 * @return ErrorStatisticsProviderStatus
	 */
	public function status(): ErrorStatisticsProviderStatus;

	/**
	 * Returns aggregated 404 counts, highest first.
	 *
	 * Implementations report `unavailable`, `disabled`, `forbidden`, or
	 * `measured` rather than throwing for any of those four states: they are
	 * the answer, not a failure. Only a malformed or unreadable backend is
	 * an exception.
	 *
	 * @param NotFoundStatisticsQuery $query Bounded query.
	 * @return NotFoundStatistics
	 * @throws ErrorStatisticsUnreadable When the backend cannot be read at all.
	 */
	public function top_not_found( NotFoundStatisticsQuery $query ): NotFoundStatistics;
}

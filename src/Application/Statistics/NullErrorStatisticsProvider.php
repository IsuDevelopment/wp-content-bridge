<?php
/**
 * Null object for the statistics port.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Application\Statistics;

use IsuDev\WPContentBridge\Domain\Statistics\ErrorStatisticsAvailability;
use IsuDev\WPContentBridge\Domain\Statistics\ErrorStatisticsProviderStatus;
use IsuDev\WPContentBridge\Domain\Statistics\ErrorStatisticsWindow;
use IsuDev\WPContentBridge\Domain\Statistics\NotFoundStatistics;

/**
 * Answers `unavailable` for every question, which is the honest state of a
 * site whose redirect plugin collects nothing (ADR 0030 s2).
 *
 * It exists so that "no provider collects this" is a reported result with a
 * named state, instead of an empty count list or a thrown error. A Yoast-only
 * site is the normal case for this class, not an edge case.
 */
final readonly class NullErrorStatisticsProvider implements ErrorStatisticsProvider {

	/**
	 * Never available, by definition.
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return false;
	}

	/**
	 * Returns the "none" identity.
	 *
	 * @return ErrorStatisticsProviderStatus
	 */
	public function status(): ErrorStatisticsProviderStatus {
		return new ErrorStatisticsProviderStatus( 'none', null, false, array() );
	}

	/**
	 * Reports the absence as an unsupported operation, never as zero hits.
	 *
	 * @param NotFoundStatisticsQuery $query Bounded query.
	 * @return NotFoundStatistics
	 */
	public function top_not_found( NotFoundStatisticsQuery $query ): NotFoundStatistics {
		return new NotFoundStatistics(
			$this->status(),
			ErrorStatisticsAvailability::UNAVAILABLE,
			new ErrorStatisticsWindow( null, $query->since_iso(), null, false ),
			array(),
			null,
			'No installed provider collects 404 statistics on this site.'
		);
	}
}

<?php
/**
 * The time window an error-statistics result actually covers.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Domain\Statistics;

use InvalidArgumentException;

/**
 * What a count is a count *of* (ADR 0030 s4).
 *
 * Retention can truncate a requested range: a `since` older than the
 * provider's retention setting returns less than was asked for, because the
 * provider's own pruning cron already deleted the rest. Reported silently, a
 * monitoring caller would read the missing rows as 404s that stopped
 * happening - so every result carries the retention window and an explicit
 * `truncated` signal, and a truncated result also reports the boundary that
 * was actually used.
 */
final readonly class ErrorStatisticsWindow {

	/**
	 * Creates a window description.
	 *
	 * @param int|null    $retention_days   Provider retention in days; null when it keeps rows indefinitely.
	 * @param string|null $requested_since  Caller-requested ISO-8601 UTC boundary, or null for "everything retained".
	 * @param string|null $effective_since  Boundary actually queried, ISO-8601 UTC; differs from the request when retention truncated it.
	 * @param bool        $truncated        Whether retention shortened the requested range.
	 * @throws InvalidArgumentException When the window describes an impossible range.
	 */
	public function __construct(
		public ?int $retention_days,
		public ?string $requested_since,
		public ?string $effective_since,
		public bool $truncated,
	) {
		if ( null !== $retention_days && $retention_days < 1 ) {
			throw new InvalidArgumentException( 'Retention in days must be positive or unlimited.' );
		}
		if ( $truncated && null === $requested_since ) {
			// Nothing was requested, so nothing could be cut short. A
			// truncation flag without a request is a reporting bug, and it
			// would tell a caller its own query was shortened when it never
			// asked for a range.
			throw new InvalidArgumentException( 'A window cannot be truncated without a requested boundary.' );
		}
	}

	/**
	 * Serializes the window.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'retention_days'  => $this->retention_days,
			'requested_since' => $this->requested_since,
			'effective_since' => $this->effective_since,
			'truncated'       => $this->truncated,
		);
	}
}

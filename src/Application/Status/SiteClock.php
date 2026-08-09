<?php
/**
 * Site clock and cron-runnability port.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Application\Status;

use DateTimeImmutable;
use DateTimeZone;

/**
 * The only WordPress-facing boundary `publish_at` validation crosses.
 *
 * {@see \IsuDev\WPContentBridge\Domain\Status\PublishAt} stays pure and
 * testable with an arbitrary zone and instant; this port is where the real
 * `wp_timezone()` and real time actually come from, and where whether a
 * `future`-scheduled post can actually publish itself is decided.
 */
interface SiteClock {

	/**
	 * Returns the current instant, in UTC.
	 *
	 * @return DateTimeImmutable
	 */
	public function now(): DateTimeImmutable;

	/**
	 * Returns the site timezone — a named zone or a fixed UTC offset.
	 *
	 * @return DateTimeZone
	 */
	public function timezone(): DateTimeZone;

	/**
	 * Reports whether a `future`-scheduled post can actually publish itself
	 * on this site, per ADR 0024: a scheduled transition can be permitted by
	 * every other gate and still never take effect if nothing ever runs
	 * `wp-cron.php`.
	 *
	 * @return bool
	 */
	public function scheduled_publication_can_run(): bool;
}

<?php
/**
 * WordPress site clock and cron-runnability adapter.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Infrastructure\WordPress;

use DateTimeImmutable;
use DateTimeZone;
use IsuDev\WPContentBridge\Application\Status\SiteClock;

/**
 * The only place `publish_at` validation reaches `wp_timezone()` or the
 * real clock — {@see \IsuDev\WPContentBridge\Domain\Status\PublishAt}
 * itself stays pure and testable with an arbitrary zone and instant.
 */
final class WordPressSiteClock implements SiteClock {

	/**
	 * Returns the current instant, in UTC.
	 *
	 * @return DateTimeImmutable
	 */
	public function now(): DateTimeImmutable {
		return new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
	}

	/**
	 * Returns the site timezone, whether a named zone or a fixed offset.
	 *
	 * `wp_timezone()` already normalizes both `timezone_string` and the
	 * legacy `gmt_offset` option into one `DateTimeZone`, so no separate
	 * fixed-offset handling is needed here or in
	 * {@see \IsuDev\WPContentBridge\Domain\Status\PublishAt}.
	 *
	 * @return DateTimeZone
	 */
	public function timezone(): DateTimeZone {
		return wp_timezone();
	}

	/**
	 * Reports whether a `future`-scheduled post can actually publish itself.
	 *
	 * `DISABLE_WP_CRON` stops WordPress's own pseudo-cron entirely; nothing
	 * observable from PHP can prove that a separate system cron is invoking
	 * `wp-cron.php` in its place, so this stays conservative rather than
	 * assuming one is configured, per ADR 0024. `ALTERNATE_WP_CRON` changes
	 * how the pseudo-cron request is dispatched, not whether it runs, so it
	 * does not affect this check.
	 *
	 * @return bool
	 */
	public function scheduled_publication_can_run(): bool {
		return ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON );
	}
}

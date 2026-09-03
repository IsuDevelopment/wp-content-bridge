<?php
/**
 * Redirection (John Godley) 404-statistics adapter.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Infrastructure\Redirection;

use IsuDev\WPContentBridge\Application\Statistics\ErrorStatisticsProvider;
use IsuDev\WPContentBridge\Application\Statistics\ErrorStatisticsUnreadable;
use IsuDev\WPContentBridge\Application\Statistics\NotFoundStatisticsQuery;
use IsuDev\WPContentBridge\Domain\Statistics\ErrorStatisticsAvailability;
use IsuDev\WPContentBridge\Domain\Statistics\ErrorStatisticsProviderStatus;
use IsuDev\WPContentBridge\Domain\Statistics\ErrorStatisticsWindow;
use IsuDev\WPContentBridge\Domain\Statistics\NotFoundCount;
use IsuDev\WPContentBridge\Domain\Statistics\NotFoundStatistics;

/**
 * Reads aggregated 404 counts from Redirection's own `{prefix}redirection_404`
 * table, in SQL, in the same process (ADR 0030 s4).
 *
 * Why not its REST API, when `RedirectionProvider` next door uses it: the
 * aggregation this port depends on is reachable in SQL, whereas over REST it
 * rests on an undeclared `groupBy` parameter read straight off `get_params()`
 * in an API its own author calls unstable - and the log routes accept no date
 * filter at all, while the table's `created` column is indexed. Two costs
 * follow, both accepted and both handled here:
 *
 * 1. **Schema coupling.** This class probes for the table and the exact
 *    columns it reads, and reports itself unavailable rather than issuing a
 *    query it cannot vouch for.
 * 2. **The provider's permission model is bypassed.** A direct read never
 *    reaches Redirection's own check, so this adapter performs that check
 *    itself: it resolves the capability Redirection's documented
 *    `redirection_capability_check` filter maps `redirection_cap_404_manage`
 *    to, and requires the acting principal to hold it. The filter is
 *    *queried*, never registered - registering it, the way the redirect
 *    adapter legitimately does around a call into Redirection's own code,
 *    would mean this plugin answering its own permission question.
 *
 *    Consequence worth stating plainly: on a site that has not configured
 *    that filter, the effective requirement is Redirection's default
 *    (`manage_options`), so a non-administrator integration principal is
 *    refused with `forbidden` and not with an empty list. Granting it is the
 *    site's decision, expressed in Redirection's own vocabulary.
 *
 * Aggregate only, by construction: the SELECT names `url` and `COUNT(*)` and
 * nothing else. `ip`, `agent`, `referrer`, and `request_data` are never read
 * into the domain, so no retention or redaction obligation attaches to this
 * plugin - a property of the code, not of a setting (ADR 0030 s3).
 *
 * Nothing here writes, prunes, or resets anything, and nothing changes a
 * Redirection setting: a log the operator turned off is reported as
 * `disabled`, never quietly re-enabled.
 */
final class RedirectionErrorStatisticsProvider implements ErrorStatisticsProvider {

	/**
	 * Redirection's 404 log table, without the site prefix.
	 */
	private const TABLE = 'redirection_404';

	/**
	 * Columns this adapter reads. The probe is over exactly these, so a
	 * future schema that drops or renames one reports unavailable instead of
	 * failing mid-query.
	 */
	private const REQUIRED_COLUMNS = array( 'url', 'created' );

	/**
	 * Redirection's own options row, which holds the retention setting.
	 */
	private const OPTIONS = 'redirection_options';

	/**
	 * Retention setting name, reported verbatim so a `disabled` result names
	 * the switch an operator has to change.
	 */
	private const RETENTION_SETTING = 'expire_404';

	/**
	 * Redirection's permission name for reading the 404 log. Independent of
	 * redirect management in Redirection's own model, which is why ADR 0030
	 * s5 keeps the bridge capabilities separate too.
	 */
	private const NATIVE_PERMISSION = 'redirection_cap_404_manage';

	/**
	 * Capability Redirection requires by default when no site filter answers.
	 */
	private const NATIVE_DEFAULT_CAPABILITY = 'manage_options';

	/**
	 * Memoized schema probe, so one request never repeats it.
	 *
	 * @var bool|null
	 */
	private ?bool $schema_ok = null;

	/**
	 * Available only when Redirection is loaded and its 404 table carries
	 * every column this adapter reads.
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		if ( ! defined( 'REDIRECTION_FILE' ) ) {
			return false;
		}

		return $this->schema_is_vouched_for();
	}

	/**
	 * Returns safe provider identity.
	 *
	 * `collects` is empty unless the schema probe passed, so "Redirection is
	 * installed" never implies "404 counts are readable here".
	 *
	 * @return ErrorStatisticsProviderStatus
	 */
	public function status(): ErrorStatisticsProviderStatus {
		$available = $this->is_available();

		return new ErrorStatisticsProviderStatus(
			'redirection',
			$available ? self::plugin_version() : null,
			$available,
			$available ? array( 'not_found' ) : array()
		);
	}

	/**
	 * Returns aggregated 404 counts, highest first.
	 *
	 * @param NotFoundStatisticsQuery $query Bounded query.
	 * @return NotFoundStatistics
	 * @throws ErrorStatisticsUnreadable When the table cannot be read.
	 */
	public function top_not_found( NotFoundStatisticsQuery $query ): NotFoundStatistics {
		$status = $this->status();

		if ( ! $this->is_available() ) {
			return new NotFoundStatistics(
				$status,
				ErrorStatisticsAvailability::UNAVAILABLE,
				new ErrorStatisticsWindow( null, $query->since_iso(), null, false ),
				array(),
				null,
				'Redirection is not active, or its 404 log table does not carry the columns this read depends on.'
			);
		}

		if ( ! current_user_can( self::native_capability() ) ) {
			return new NotFoundStatistics(
				$status,
				ErrorStatisticsAvailability::FORBIDDEN,
				new ErrorStatisticsWindow( null, $query->since_iso(), null, false ),
				array(),
				null,
				sprintf(
					'Redirection\'s own permission model requires the "%s" capability for %s.',
					self::native_capability(),
					self::NATIVE_PERMISSION
				)
			);
		}

		$retention = self::retention_days();
		if ( self::LOGGING_OFF === $retention ) {
			return new NotFoundStatistics(
				$status,
				ErrorStatisticsAvailability::DISABLED,
				new ErrorStatisticsWindow( null, $query->since_iso(), null, false ),
				array(),
				self::RETENTION_SETTING,
				'Redirection is not logging 404s, so an empty result would not mean the site has none.'
			);
		}

		$window = self::window( $query, $retention );

		return new NotFoundStatistics(
			$status,
			ErrorStatisticsAvailability::MEASURED,
			$window,
			$this->read_counts( $window->effective_since, $query->limit )
		);
	}

	/**
	 * Sentinel Redirection stores in `expire_404` to switch 404 logging off.
	 */
	private const LOGGING_OFF = -1;

	/**
	 * Retention in days: a positive number, `0` for "never pruned", or
	 * {@see self::LOGGING_OFF} when logging is disabled.
	 *
	 * Read from Redirection's own options row. An absent or non-numeric value
	 * is treated as its documented default of 7 days rather than as
	 * unlimited, because over-reporting the window is the failure that makes
	 * a truncated result look complete.
	 *
	 * @return int
	 */
	private static function retention_days(): int {
		$options = get_option( self::OPTIONS );
		if ( ! is_array( $options ) || ! isset( $options[ self::RETENTION_SETTING ] ) ) {
			return 7;
		}

		$value = $options[ self::RETENTION_SETTING ];

		return is_numeric( $value ) ? (int) $value : 7;
	}

	/**
	 * Builds the window a result actually covers, truncating a `since` that
	 * reaches past what retention can still hold (ADR 0030 s4).
	 *
	 * Public because this is the load-bearing arithmetic of the whole read
	 * and is pure: it takes a query and a retention setting and needs no
	 * WordPress, so it is unit-tested rather than left to runtime
	 * verification.
	 *
	 * @param NotFoundStatisticsQuery $query     Requested query.
	 * @param int                     $retention Retention in days, `0` for unlimited.
	 * @return ErrorStatisticsWindow
	 */
	public static function window( NotFoundStatisticsQuery $query, int $retention ): ErrorStatisticsWindow {
		$retention_days = $retention > 0 ? $retention : null;
		$requested      = $query->since_iso();

		if ( null === $query->since || null === $retention_days ) {
			return new ErrorStatisticsWindow( $retention_days, $requested, $requested, false );
		}

		$boundary = $query->since->modify( sprintf( '+%d days', $retention_days ) );
		if ( $boundary >= $query->now ) {
			return new ErrorStatisticsWindow( $retention_days, $requested, $requested, false );
		}

		// The requested range starts before anything retention still holds.
		// Reported silently, a monitoring caller would read the pruned rows
		// as 404s that stopped happening.
		$effective = $query->now->modify( sprintf( '-%d days', $retention_days ) )->format( 'Y-m-d\TH:i:s\Z' );

		return new ErrorStatisticsWindow( $retention_days, $requested, $effective, true );
	}

	/**
	 * Reads the grouped counts.
	 *
	 * `created` is compared in the site's local time, the convention
	 * WordPress plugins use for a `datetime` column written with
	 * `current_time( 'mysql' )`; the boundary is converted from UTC with
	 * `get_date_from_gmt()`. The effective boundary is reported back in the
	 * result, so a caller can always see the range that was actually applied
	 * rather than infer it.
	 *
	 * @param string|null $since_iso Effective ISO-8601 UTC boundary, or null for everything retained.
	 * @param int         $limit     Maximum rows.
	 * @return array Aggregated counts, highest first.
	 * @phpstan-return list<NotFoundCount>
	 * @throws ErrorStatisticsUnreadable When the query fails or does not come back grouped.
	 */
	private function read_counts( ?string $since_iso, int $limit ): array {
		global $wpdb;
		/**
		 * WordPress database abstraction object.
		 *
		 * @var \wpdb $wpdb
		 */

		$table = $wpdb->prefix . self::TABLE;

		if ( null === $since_iso ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- third-party log table, no core API; aggregate-only read.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT url, COUNT(*) AS hits FROM %i GROUP BY url ORDER BY hits DESC, url ASC LIMIT %d',
					$table,
					$limit
				),
				ARRAY_A
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- third-party log table, no core API; aggregate-only read.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT url, COUNT(*) AS hits FROM %i WHERE created >= %s GROUP BY url ORDER BY hits DESC, url ASC LIMIT %d',
					$table,
					self::to_site_datetime( $since_iso ),
					$limit
				),
				ARRAY_A
			);
		}

		if ( ! is_array( $rows ) ) {
			throw new ErrorStatisticsUnreadable( 'Redirection\'s 404 log could not be read.' );
		}

		return self::map_rows( $rows );
	}

	/**
	 * Maps grouped rows to domain counts.
	 *
	 * A row without a positive count would mean the query came back
	 * ungrouped, which must never be mistaken for counts (ADR 0030 s6), so it
	 * fails the read instead of being reported as an observation. Paths are
	 * never aggregated client-side from per-hit rows, because that would mean
	 * reading the personal data s3 forbids.
	 *
	 * @param array $rows Raw grouped rows.
	 * @phpstan-param list<array<array-key, mixed>> $rows
	 * @return array
	 * @phpstan-return list<NotFoundCount>
	 * @throws ErrorStatisticsUnreadable When a row is not a usable aggregate.
	 */
	private static function map_rows( array $rows ): array {
		$counts = array();

		foreach ( $rows as $row ) {
			$url  = $row['url'] ?? null;
			$hits = $row['hits'] ?? null;
			if ( ! is_string( $url ) || '' === trim( $url ) || ! is_numeric( $hits ) || (int) $hits < 1 ) {
				throw new ErrorStatisticsUnreadable( 'Redirection\'s 404 log did not return grouped counts.' );
			}

			$counts[] = new NotFoundCount( $url, (int) $hits );
		}

		return $counts;
	}

	/**
	 * Converts an ISO-8601 UTC boundary to the site-local MySQL datetime the
	 * log column is written in.
	 *
	 * @param string $since_iso ISO-8601 UTC boundary.
	 * @return string
	 */
	public static function to_site_datetime( string $since_iso ): string {
		return get_date_from_gmt( str_replace( array( 'T', 'Z' ), array( ' ', '' ), $since_iso ), 'Y-m-d H:i:s' );
	}

	/**
	 * Probes the table and its columns once per request.
	 *
	 * `SHOW TABLES LIKE` comes first because it answers for a missing table
	 * without raising a database error, which a bare column query would.
	 *
	 * @return bool
	 */
	private function schema_is_vouched_for(): bool {
		if ( null !== $this->schema_ok ) {
			return $this->schema_ok;
		}

		global $wpdb;
		/**
		 * WordPress database abstraction object.
		 *
		 * @var \wpdb $wpdb
		 */

		$table = $wpdb->prefix . self::TABLE;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- schema probe on a third-party table; there is no core API for it.
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $table !== $found ) {
			$this->schema_ok = false;

			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- schema probe on a third-party table; there is no core API for it.
		$columns = $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $table ) );
		$columns = is_array( $columns ) ? array_map( 'strval', $columns ) : array();

		$this->schema_ok = array() === array_diff( self::REQUIRED_COLUMNS, $columns );

		return $this->schema_ok;
	}

	/**
	 * Resolves the capability Redirection's own permission model requires for
	 * reading the 404 log.
	 *
	 * The filter is queried with Redirection's documented default, never
	 * registered: a site that wants a bridge principal to read this grants it
	 * in Redirection's own vocabulary, and this plugin does not answer its own
	 * permission question.
	 *
	 * @return string
	 */
	public static function native_capability(): string {
		/** This filter is documented in the Redirection plugin. */
		$capability = apply_filters( 'redirection_capability_check', self::NATIVE_DEFAULT_CAPABILITY, self::NATIVE_PERMISSION );

		return is_string( $capability ) && '' !== $capability ? $capability : self::NATIVE_DEFAULT_CAPABILITY;
	}

	/**
	 * Reads Redirection's public plugin version from its own file header.
	 *
	 * @return string|null
	 */
	private static function plugin_version(): ?string {
		if ( ! defined( 'REDIRECTION_FILE' ) ) {
			return null;
		}

		$file = constant( 'REDIRECTION_FILE' );
		if ( ! is_string( $file ) || ! is_readable( $file ) ) {
			return null;
		}

		$data = get_file_data( $file, array( 'Version' => 'Version' ) );

		return '' !== $data['Version'] ? $data['Version'] : null;
	}
}

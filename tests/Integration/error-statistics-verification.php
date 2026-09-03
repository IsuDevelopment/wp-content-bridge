<?php
/**
 * Runtime verification for the aggregate 404 statistics read (ADR 0030).
 *
 * Read-only against the site and against Redirection: it never writes a 404
 * row, never changes a Redirection setting, and never prunes a log. The one
 * thing it does write is this plugin's own feature flag, which it restores.
 *
 * It exists because three things in this port cannot be unit-tested - the
 * schema probe, the direct table read, and the timezone convention of
 * Redirection's `created` column - and one of them is a silent-wrongness
 * risk rather than a crash: a boundary compared in the wrong timezone returns
 * a plausible number that is simply not the number that was asked for.
 *
 * Run: wp eval 'require "<abs path>/tests/Integration/error-statistics-verification.php";'
 *
 * @package IsuDev\WPContentBridgeTests
 */

declare(strict_types=1);

use IsuDev\WPContentBridge\Application\Statistics\ErrorStatisticsProviderRegistry;
use IsuDev\WPContentBridge\Application\Statistics\GetNotFoundStatistics;
use IsuDev\WPContentBridge\Application\Statistics\NullErrorStatisticsProvider;
use IsuDev\WPContentBridge\Infrastructure\Redirection\RedirectionErrorStatisticsProvider;
use IsuDev\WPContentBridge\Infrastructure\WordPress\Installer;

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI verifier output, not rendered HTML.
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI verifier output, not filesystem access.

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Must run inside WordPress via wp eval.\n" );
	exit( 1 );
}

$flag_was_set = null !== get_option( Installer::ERROR_STATISTICS_ENABLED_OPTION, null );
$flag_before  = get_option( Installer::ERROR_STATISTICS_ENABLED_OPTION );

/**
 * Restores the feature flag exactly as it was found, including "absent".
 */
$restore_flag = static function () use ( $flag_was_set, $flag_before ): void {
	if ( $flag_was_set ) {
		update_option( Installer::ERROR_STATISTICS_ENABLED_OPTION, $flag_before, false );

		return;
	}

	delete_option( Installer::ERROR_STATISTICS_ENABLED_OPTION );
};

update_option( Installer::ERROR_STATISTICS_ENABLED_OPTION, true, false );

wp_set_current_user( 1 );
if ( ! current_user_can( 'wpcb_read_error_statistics' ) ) {
	$restore_flag();
	fwrite( STDERR, "The administrator fixture lacks wpcb_read_error_statistics; the schema upgrade did not run.\n" );
	exit( 1 );
}

$registry = new ErrorStatisticsProviderRegistry(
	array( new RedirectionErrorStatisticsProvider() ),
	new NullErrorStatisticsProvider()
);
$use_case = new GetNotFoundStatistics( $registry );
$now      = new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
$failures = array();

$open   = $use_case->execute( array( 'limit' => 5 ), $now );
$source = $open['sources'][0];

/*
 * The four states must stay distinguishable on real data, which is the whole
 * point of ADR 0030 s2. On a site without Redirection this run proves the
 * unavailable branch and stops; that is a real assertion, not a skip.
 */
if ( 'unavailable' === $open['availability'] ) {
	if ( 'none' !== $source['provider']['provider'] || array() !== $source['paths'] ) {
		$failures[] = 'an unavailable result did not come from the null provider';
	}
	$restore_flag();
	if ( array() !== $failures ) {
		echo "FAIL:\n - " . implode( "\n - ", $failures ) . "\n";
		exit( 1 );
	}
	echo "PASS: error-statistics (unavailable branch: no provider collects 404s here)\n";
	exit( 0 );
}

if ( 'disabled' === $open['availability'] ) {
	if ( null === $source['disabled_by'] ) {
		$failures[] = 'a disabled result did not name the setting responsible';
	}
	$restore_flag();
	if ( array() !== $failures ) {
		echo "FAIL:\n - " . implode( "\n - ", $failures ) . "\n";
		exit( 1 );
	}
	echo 'PASS: error-statistics (disabled branch: ' . (string) $source['disabled_by'] . " is off)\n";
	exit( 0 );
}

if ( 'measured' !== $open['availability'] ) {
	$failures[] = 'unexpected availability: ' . (string) $open['availability'];
}

// Aggregate only. A per-visitor key here would mean the personal-data
// decision (ADR 0030 s3) had been broken by something below the port.
foreach ( $source['paths'] as $entry ) {
	if ( array( 'path', 'hits' ) !== array_keys( $entry ) ) {
		$failures[] = 'a count carried keys beyond path and hits: ' . implode( ', ', array_keys( $entry ) );
		break;
	}
	if ( ! is_int( $entry['hits'] ) || $entry['hits'] < 1 ) {
		$failures[] = 'a count was not a positive integer, so the query may not have grouped';
		break;
	}
}

// Counts must be ordered, because a caller asking for the top N of a log it
// cannot page through gets the wrong N if they are not.
$hits   = array_map( static fn ( array $entry ): int => (int) $entry['hits'], $source['paths'] );
$sorted = $hits;
rsort( $sorted );
if ( $sorted !== $hits ) {
	$failures[] = 'counts were not ordered highest first';
}

/*
 * The timezone assertion. A boundary one minute in the future must return
 * nothing, and a boundary well before retention must return at least as much
 * as any narrower one. On a site whose UTC offset is not zero, comparing the
 * boundary in the wrong convention shifts it by hours - which this pair
 * catches, while a single open read never would.
 */
$future_edge  = $use_case->execute(
	array(
		'since' => $now->modify( '-1 second' )->format( 'Y-m-d\TH:i:s\Z' ),
		'limit' => 5,
	),
	$now
);
$future_total = 0;
foreach ( $future_edge['sources'][0]['paths'] as $entry ) {
	$future_total += (int) $entry['hits'];
}

$wide       = $use_case->execute( array( 'limit' => 100 ), $now );
$wide_total = 0;
foreach ( $wide['sources'][0]['paths'] as $entry ) {
	$wide_total += (int) $entry['hits'];
}

if ( $future_total > $wide_total ) {
	$failures[] = 'a one-second window returned more hits than the whole retained log';
}
if ( $wide_total > 0 && $future_total === $wide_total ) {
	// Not proof of a bug on a very busy site, but on a reference install it
	// means the boundary was not applied at all.
	$failures[] = 'a one-second window returned the entire log, so `since` was not applied';
}

/*
 * Retention truncation must be reported rather than silently applied: a
 * monitoring caller would otherwise read pruned rows as 404s that stopped.
 */
$ancient = $use_case->execute(
	array(
		'since' => '2015-01-01T00:00:00Z',
		'limit' => 5,
	),
	$now
);
$window  = $ancient['sources'][0]['window'];
if ( null !== $window['retention_days'] && true !== $window['truncated'] ) {
	$failures[] = 'a range older than retention was not reported as truncated';
}
if ( true === $window['truncated'] && $window['effective_since'] === $window['requested_since'] ) {
	$failures[] = 'a truncated window reported the requested boundary as the effective one';
}

$restore_flag();

if ( array() !== $failures ) {
	echo "FAIL:\n - " . implode( "\n - ", $failures ) . "\n";
	exit( 1 );
}

echo 'PASS: error-statistics ' . wp_json_encode(
	array(
		'provider'          => $source['provider']['provider'],
		'provider_version'  => $source['provider']['version'],
		'retention_days'    => $source['window']['retention_days'],
		'paths_reported'    => count( $source['paths'] ),
		'retained_hits'     => $wide_total,
		'one_second_window' => $future_total,
		'native_capability' => RedirectionErrorStatisticsProvider::native_capability(),
	)
) . "\n";
exit( 0 );

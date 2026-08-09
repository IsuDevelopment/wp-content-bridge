<?php
/**
 * Runtime verification for the status transition matrix bulk toggles (0.7.1).
 *
 * Renders the real settings screen through the real service graph and asserts
 * the markup the enqueued script binds to. The point is not that the script
 * works — that is the browser's job — but that the toggles carry no form
 * field, so a submitted matrix means exactly what it meant in 0.7.0.
 *
 * Run with:
 *   wp eval 'require "<abs path>/tests/Integration/status-matrix-bulk-verification.php";'
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- assertion labels are emitted to CLI only.

use IsuDev\WPContentBridge\Adapter\Admin\ContentAccessSettingsPage;
use IsuDev\WPContentBridge\Application\Access\IntegrationAccessManager;
use IsuDev\WPContentBridge\Application\ContentAccess\ContentAccessManager;
use IsuDev\WPContentBridge\Application\Status\StatusTransitionManager;
use IsuDev\WPContentBridge\Domain\Status\StatusTransition;
use IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressContentAccessSettingsRepository;
use IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressContentTypeCatalog;
use IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressIntegrationAccessRepository;
use IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressStatusTransitionRepository;

$wpcb_failures = array();

/**
 * Records one assertion.
 *
 * @param string $label     What is being asserted.
 * @param bool   $condition Assertion result.
 * @return void
 */
$wpcb_assert = static function ( string $label, bool $condition ) use ( &$wpcb_failures ): void {
	if ( $condition ) {
		echo 'PASS ' . $label . "\n";
		return;
	}

	$wpcb_failures[] = $label;
	echo 'FAIL ' . $label . "\n";
};

$wpcb_admin = get_users(
	array(
		'role'   => 'administrator',
		'number' => 1,
		'fields' => 'ID',
	)
);

if ( empty( $wpcb_admin ) ) {
	echo "FAIL no administrator on this site\n";
	return;
}

$wpcb_previous_user = get_current_user_id();
wp_set_current_user( (int) $wpcb_admin[0] );

try {
	$wpcb_page = new ContentAccessSettingsPage(
		new ContentAccessManager(
			new WordPressContentAccessSettingsRepository(),
			new WordPressContentTypeCatalog()
		),
		new IntegrationAccessManager( new WordPressIntegrationAccessRepository() ),
		new StatusTransitionManager( new WordPressStatusTransitionRepository() )
	);

	ob_start();
	$wpcb_page->render();
	$wpcb_html = (string) ob_get_clean();

	$wpcb_types = count(
		( new ContentAccessManager(
			new WordPressContentAccessSettingsRepository(),
			new WordPressContentTypeCatalog()
		) )->content_types()
	);
	$wpcb_pairs = count( StatusTransition::all_possible() );

	$wpcb_assert( 'the matrix renders at least one content type', $wpcb_types > 0 );
	$wpcb_assert( 'the fixed vocabulary still yields twenty ordered pairs', 20 === $wpcb_pairs );

	// The container the script keys off, and the one it must not stray outside.
	$wpcb_assert(
		'the status matrix carries the wpcb-status-transitions container id',
		1 === substr_count( $wpcb_html, 'id="wpcb-status-transitions"' )
	);

	// One "all", one per column, one per rendered row.
	$wpcb_assert(
		'exactly one whole-matrix toggle is rendered',
		1 === substr_count( $wpcb_html, 'data-wpcb-scope="all"' )
	);
	$wpcb_assert(
		'one column toggle per ordered pair',
		substr_count( $wpcb_html, 'data-wpcb-scope="column"' ) === $wpcb_pairs
	);
	$wpcb_assert(
		'one row toggle per content type',
		substr_count( $wpcb_html, 'data-wpcb-scope="row"' ) === $wpcb_types
	);

	/*
	 * Every governed cell is addressable by both axes. The axis attributes
	 * appear on cells only: a toggle names its group through `data-wpcb-key`,
	 * so there is nothing to subtract here.
	 */
	$wpcb_assert(
		'every matrix cell carries both axis attributes',
		( $wpcb_types * $wpcb_pairs ) === substr_count( $wpcb_html, 'data-wpcb-row="' )
		&& ( $wpcb_types * $wpcb_pairs ) === substr_count( $wpcb_html, 'data-wpcb-col="' )
		&& ( $wpcb_pairs + $wpcb_types ) === substr_count( $wpcb_html, 'data-wpcb-key="' )
	);

	/*
	 * The load-bearing assertion. A bulk toggle that carried a `name` would
	 * post a value into `wpcb_status_transitions` and change what the matrix
	 * saves. Every toggle must sit between `<input type="checkbox"` and `>`
	 * with no `name=` in that span.
	 */
	$wpcb_orphans = preg_match_all( '/<input type="checkbox"(?![^>]*\bname=)[^>]*>/', $wpcb_html, $wpcb_matches );
	$wpcb_named   = 0;
	foreach ( $wpcb_matches[0] as $wpcb_tag ) {
		if ( false !== strpos( $wpcb_tag, 'data-wpcb-scope=' ) ) {
			++$wpcb_named;
		}
	}
	$wpcb_assert(
		'no bulk toggle carries a form field name',
		$wpcb_orphans === $wpcb_named && ( 1 + $wpcb_pairs + $wpcb_types ) === $wpcb_named
	);

	// Without the script the toggles must not be visible, because they do nothing.
	$wpcb_assert(
		'every bulk toggle ships inside a hidden wrapper',
		( 1 + $wpcb_pairs + $wpcb_types ) === substr_count( $wpcb_html, '<span class="wpcb-bulk-toggle" hidden>' )
	);

	// The destructive preset button warns before discarding a configured matrix.
	$wpcb_assert(
		'the editorial preset button carries a confirmation prompt',
		1 === substr_count( $wpcb_html, 'data-wpcb-confirm=' )
	);

	// The assets exist where the enqueue points.
	$wpcb_assert(
		'the enqueued script and style exist on disk',
		is_readable( WPCB_PATH . '/assets/admin/status-transitions.js' )
		&& is_readable( WPCB_PATH . '/assets/admin/status-transitions.css' )
	);

	/*
	 * The enqueue itself, on the screen WordPress actually names. The hook
	 * suffix is resolved the same way `add_options_page()` resolves it rather
	 * than spelled out: under WP-CLI the admin menu is never built, so the
	 * suffix is `admin_page_wp-content-bridge` here and
	 * `settings_page_wp-content-bridge` in a browser. A hard-coded string
	 * would pass in one and silently ship nothing in the other.
	 */
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	$wpcb_page->register_page();
	$wpcb_hook = get_plugin_page_hookname( 'wp-content-bridge', 'options-general.php' );

	// Asserted before our own screen, because an enqueue never un-enqueues.
	do_action( 'admin_enqueue_scripts', 'edit.php' );
	$wpcb_assert(
		'no asset is enqueued on an unrelated admin screen',
		! wp_script_is( 'wpcb-status-transitions', 'enqueued' )
		&& ! wp_style_is( 'wpcb-status-transitions', 'enqueued' )
	);

	do_action( 'admin_enqueue_scripts', $wpcb_hook );
	$wpcb_assert(
		'both assets are enqueued on the settings screen at the current version',
		wp_script_is( 'wpcb-status-transitions', 'enqueued' )
		&& wp_style_is( 'wpcb-status-transitions', 'enqueued' )
		&& WPCB_VERSION === ( wp_scripts()->registered['wpcb-status-transitions']->ver ?? null )
	);

	// The content access matrix above must be untouched by all of this.
	$wpcb_assert(
		'the content access matrix gained no bulk attributes',
		false === strpos(
			substr( $wpcb_html, 0, (int) strpos( $wpcb_html, 'id="wpcb-status-transitions"' ) ),
			'data-wpcb-scope'
		)
	);
} finally {
	wp_set_current_user( $wpcb_previous_user );
}

echo empty( $wpcb_failures )
	? "\nstatus-matrix-bulk-verification: OK\n"
	: "\nstatus-matrix-bulk-verification: " . count( $wpcb_failures ) . " FAILED\n";

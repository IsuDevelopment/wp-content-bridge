<?php
/**
 * Plugin Name: WP Content Bridge
 * Plugin URI:  https://github.com/isudevelopment/wp-content-bridge
 * Description: Provider-neutral WordPress content and SEO abilities for MCP and other agent clients.
 * Version:     0.2.3
 * Requires at least: 7.0
 * Requires PHP: 8.2
 * Author:      ISU Development
 * Author URI:  https://isudev.pl
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wp-content-bridge
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WPCB_VERSION', '0.2.3' );
define( 'WPCB_FILE', __FILE__ );
define( 'WPCB_PATH', __DIR__ );

$wpcb_autoloader = WPCB_PATH . '/vendor/autoload.php';

if ( ! is_readable( $wpcb_autoloader ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}

			echo '<div class="notice notice-error"><p>';
			echo esc_html__( 'WP Content Bridge is missing its Composer dependencies. Run composer install or install a packaged release.', 'wp-content-bridge' );
			echo '</p></div>';
		}
	);

	return;
}

require_once $wpcb_autoloader;

register_activation_hook(
	WPCB_FILE,
	array( IsuDev\WPContentBridge\Infrastructure\WordPress\Installer::class, 'activate' )
);

IsuDev\WPContentBridge\Infrastructure\WordPress\GitHubReleaseUpdateChecker::register( WPCB_FILE );

add_action(
	'plugins_loaded',
	static function (): void {
		IsuDev\WPContentBridge\Plugin::boot();
	}
);

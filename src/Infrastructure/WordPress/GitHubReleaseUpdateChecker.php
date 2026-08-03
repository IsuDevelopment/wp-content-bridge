<?php
/**
 * GitHub release update-checker integration.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Infrastructure\WordPress;

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

/**
 * Registers Plugin Update Checker only for packaged, self-managed installs.
 *
 * Source checkouts are excluded so a WordPress update cannot overwrite a Git
 * worktree or a symlink to one. Composer-managed sites can opt out explicitly.
 */
final class GitHubReleaseUpdateChecker {

	private const REPOSITORY_URL = 'https://github.com/isudevelopment/wp-content-bridge/';
	private const PLUGIN_SLUG    = 'wp-content-bridge';

	/**
	 * Registers the GitHub updater when the runtime context is eligible.
	 *
	 * @param string $plugin_file Absolute main plugin file path.
	 */
	public static function register( string $plugin_file ): void {
		$explicitly_disabled = defined( 'WPCB_DISABLE_SELF_UPDATES' ) && (bool) constant( 'WPCB_DISABLE_SELF_UPDATES' );
		$filter_enabled      = (bool) apply_filters( 'wp_content_bridge_self_updates_enabled', true, $plugin_file );

		if (
			! self::should_register(
				is_admin(),
				wp_doing_cron(),
				class_exists( PucFactory::class ),
				is_dir( dirname( $plugin_file ) . '/.git' ),
				$explicitly_disabled,
				$filter_enabled
			)
		) {
			return;
		}

		$checker = PucFactory::buildUpdateChecker(
			self::REPOSITORY_URL,
			$plugin_file,
			self::PLUGIN_SLUG
		);
		if ( ! method_exists( $checker, 'getVcsApi' ) ) {
			return;
		}
		$vcs_api = $checker->getVcsApi();

		// Release ZIPs are uploaded as GitHub release assets. Do not install the
		// repository's source archive because it lacks production dependencies.
		if ( is_object( $vcs_api ) && method_exists( $vcs_api, 'enableReleaseAssets' ) ) {
			$vcs_api->enableReleaseAssets();
		}
	}

	/**
	 * Evaluates the side-effect-free registration policy.
	 *
	 * This is public only so the packaging/runtime matrix can lock the guard
	 * behavior without loading WordPress or contacting GitHub.
	 *
	 * @param bool $is_admin             Whether this is a WordPress admin request.
	 * @param bool $doing_cron           Whether WordPress cron is running.
	 * @param bool $factory_available    Whether Plugin Update Checker is loaded.
	 * @param bool $source_checkout      Whether the plugin directory contains Git metadata.
	 * @param bool $explicitly_disabled Whether the disable constant is enabled.
	 * @param bool $filter_enabled       Whether the site-level filter allows self-updates.
	 */
	public static function should_register(
		bool $is_admin,
		bool $doing_cron,
		bool $factory_available,
		bool $source_checkout,
		bool $explicitly_disabled,
		bool $filter_enabled
	): bool {
		return ( $is_admin || $doing_cron )
			&& $factory_available
			&& ! $source_checkout
			&& ! $explicitly_disabled
			&& $filter_enabled;
	}
}

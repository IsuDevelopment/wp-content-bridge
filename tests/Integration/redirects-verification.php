<?php
/**
 * Runtime verification for the redirect abilities (Slice 5, ADR 0026 as
 * amended 2026-09-01).
 *
 * Encodes what a manual probe proved on the reference site, including the
 * live-content shadow defect that probe found: creating a redirect for `/`
 * succeeded, because `url_to_postid()` answers 0 for the site root.
 *
 * Leaves the site as it found it: the redirect feature flag is restored, and
 * every rule this file creates is deleted through the provider's own manager.
 *
 * Run: wp eval 'require "<abs path>/tests/Integration/redirects-verification.php";'
 *
 * @package IsuDev\WPContentBridgeTests
 */

declare(strict_types=1);

use IsuDev\WPContentBridge\Application\Mutation\AuditEvent;
use IsuDev\WPContentBridge\Application\Mutation\AuditLog;
use IsuDev\WPContentBridge\Application\Redirect\CreateRedirect;
use IsuDev\WPContentBridge\Application\Redirect\NullRedirectProvider;
use IsuDev\WPContentBridge\Application\Redirect\RedirectCandidateGuard;
use IsuDev\WPContentBridge\Application\Redirect\RedirectProviderRegistry;
use IsuDev\WPContentBridge\Application\Redirect\SearchRedirects;
use IsuDev\WPContentBridge\Infrastructure\Redirection\RedirectionProvider;
use IsuDev\WPContentBridge\Infrastructure\WordPress\Installer;
use IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressPublishedPermalinkLookup;
use IsuDev\WPContentBridge\Infrastructure\Yoast\YoastPremiumRedirectProvider;

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI verifier output, not rendered HTML.
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI verifier output, not filesystem access.

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Must run inside WordPress via wp eval.\n" );
	exit( 1 );
}

/**
 * Builds the redirect provider registry the composition root builds.
 *
 * @return RedirectProviderRegistry
 */
function wpcb_verification_redirect_registry(): RedirectProviderRegistry {
	return new RedirectProviderRegistry(
		array(
			new YoastPremiumRedirectProvider( home_url( '/' ) ),
			new RedirectionProvider( home_url( '/' ) ),
		),
		new NullRedirectProvider()
	);
}

/**
 * Builds the create-redirect use case with a discarding audit sink, so a
 * verification run does not append to the site's audit record.
 *
 * @param RedirectProviderRegistry $providers Provider registry.
 * @return CreateRedirect
 */
function wpcb_verification_create_redirect( RedirectProviderRegistry $providers ): CreateRedirect {
	$audit = new class() implements AuditLog {

		/**
		 * Discards the event.
		 *
		 * @param AuditEvent $event Unused event.
		 * @return void
		 */
		public function record( AuditEvent $event ): void {
			unset( $event );
		}
	};

	return new CreateRedirect(
		$providers,
		new RedirectCandidateGuard(),
		new WordPressPublishedPermalinkLookup(),
		$audit,
		home_url( '/' )
	);
}

/**
 * Deletes every rule this verification created, through each provider's own
 * write path, and returns the sources that survived.
 *
 * @param array $sources Source paths to remove.
 * @phpstan-param list<string> $sources
 * @return array Sources still present after cleanup.
 * @phpstan-return list<string>
 */
function wpcb_verification_cleanup_redirects( array $sources ): array {
	$remaining = array();

	foreach ( $sources as $source ) {
		if ( class_exists( 'WPSEO_Redirect_Manager' ) && class_exists( 'WPSEO_Redirect_Formats' ) ) {
			$manager = new WPSEO_Redirect_Manager( WPSEO_Redirect_Formats::PLAIN );
			$stored  = $manager->get_redirect( trim( $source, '/' ) );
			if ( is_object( $stored ) ) {
				$manager->delete_redirects( array( $stored ) );
			}
			if ( is_object( $manager->get_redirect( trim( $source, '/' ) ) ) ) {
				$remaining[] = 'yoast-premium:' . $source;
			}
		}

		if ( function_exists( 'rest_get_server' ) && in_array( 'redirection/v1', rest_get_server()->get_namespaces(), true ) ) {
			$remaining = array_merge( $remaining, wpcb_verification_cleanup_redirection( $source ) );
		}
	}

	return array_values( $remaining );
}

/**
 * Removes one source from Redirection through its own REST route.
 *
 * @param string $source Source path.
 * @return array Sources still present.
 * @phpstan-return list<string>
 */
function wpcb_verification_cleanup_redirection( string $source ): array {
	$role_filter       = static fn (): string => 'wpcb_manage_redirects';
	$capability_filter = static fn (): string => 'wpcb_manage_redirects';

	add_filter( 'redirection_role', $role_filter );
	add_filter( 'redirection_capability_check', $capability_filter, 10, 2 );

	try {
		$list = new WP_REST_Request( 'GET', '/redirection/v1/redirect' );
		$list->set_param( 'filterBy', array( 'url' => $source ) );
		$data  = rest_do_request( $list )->get_data();
		$items = is_array( $data ) && is_array( $data['items'] ?? null ) ? $data['items'] : array();

		$ids = array();
		foreach ( $items as $item ) {
			if ( is_array( $item ) && isset( $item['url'], $item['id'] ) && rtrim( (string) $item['url'], '/' ) === rtrim( $source, '/' ) ) {
				$ids[] = (int) $item['id'];
			}
		}

		if ( array() === $ids ) {
			return array();
		}

		$delete = new WP_REST_Request( 'POST', '/redirection/v1/bulk/redirect/delete' );
		$delete->set_param( 'items', $ids );
		rest_do_request( $delete );

		return array();
	} finally {
		remove_filter( 'redirection_role', $role_filter );
		remove_filter( 'redirection_capability_check', $capability_filter, 10 );
	}
}

$flag_was_set = null !== get_option( Installer::REDIRECTS_ENABLED_OPTION, null );
$flag_before  = (bool) get_option( Installer::REDIRECTS_ENABLED_OPTION );

/**
 * Restores the feature flag exactly as it was found.
 *
 * @return void
 */
$restore_flag = static function () use ( $flag_was_set, $flag_before ): void {
	if ( $flag_was_set ) {
		update_option( Installer::REDIRECTS_ENABLED_OPTION, $flag_before, false );

		return;
	}

	delete_option( Installer::REDIRECTS_ENABLED_OPTION );
};

update_option( Installer::REDIRECTS_ENABLED_OPTION, true, false );

wp_set_current_user( 1 );
if ( ! current_user_can( 'wpcb_manage_redirects' ) ) {
	$restore_flag();
	fwrite( STDERR, "The administrator fixture lacks wpcb_manage_redirects; the schema upgrade did not run.\n" );
	exit( 1 );
}

// The abilities register on this action, and the composition root already ran
// for this request with the flag off, so register the graph directly.
$providers = wpcb_verification_redirect_registry();

$search_case = new SearchRedirects( $providers );
$create      = wpcb_verification_create_redirect( $providers );

$failures  = array();
$available = array();
foreach ( $providers->available() as $provider ) {
	$available[] = $provider->status()->provider;
}

if ( array() === $available ) {
	$restore_flag();
	echo "SKIP: redirects — no redirect provider is active on this site\n";
	exit( 0 );
}

$target_provider = $available[0];
$probe_source    = '/wpcb-redirect-verification-' . wp_generate_password( 8, false, false );

// 1. Every configured provider is reported, available or not, so "no
// provider" stays distinguishable from "no redirects".
$read = $search_case->execute( array( 'source' => $probe_source ) );
if ( count( $read['configured_providers'] ) < 1 ) {
	$failures[] = 'no configured providers were reported';
}
if ( array() !== $read['held_by'] ) {
	$failures[] = 'an unused probe path was reported as held';
}

// 2. A create lands in the named provider and reads back as claimed by it.
$created = $create->execute(
	array(
		'provider' => $target_provider,
		'source'   => $probe_source,
		'target'   => '/',
		'status'   => 301,
	),
	1
);
if ( ( $created['provider']['provider'] ?? null ) !== $target_provider ) {
	$failures[] = 'the created rule did not report the named provider';
}

$after = $search_case->execute( array( 'source' => $probe_source ) );
if ( array( $target_provider ) !== $after['held_by'] ) {
	$failures[] = 'the created rule was not read back as held by its provider';
}

// 3. A second identical create is a collision naming the holder.
try {
	$create->execute(
		array(
			'provider' => $target_provider,
			'source'   => $probe_source,
			'target'   => '/',
			'status'   => 301,
		),
		1
	);
	$failures[] = 'a duplicate create was accepted';
} catch ( Throwable $error ) {
	if ( ! str_contains( $error->getMessage(), $target_provider ) ) {
		$failures[] = 'the collision did not name the provider holding the rule';
	}
}

// 4. The live-content shadow guard covers the site root. This is the
// regression guard for the defect the manual probe found.
$shadow_paths = array( '/' );
foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $wpcb_post_type ) {
	if ( ! $wpcb_post_type->has_archive ) {
		continue;
	}
	$wpcb_link = get_post_type_archive_link( $wpcb_post_type->name );
	$wpcb_path = is_string( $wpcb_link ) ? wp_parse_url( $wpcb_link, PHP_URL_PATH ) : null;
	if ( is_string( $wpcb_path ) && '' !== $wpcb_path ) {
		$shadow_paths[] = $wpcb_path;
	}
}

foreach ( $shadow_paths as $shadow_path ) {
	try {
		$create->execute(
			array(
				'provider' => $target_provider,
				'source'   => $shadow_path,
				'target'   => '/somewhere-else',
				'status'   => 301,
			),
			1
		);
		$failures[] = 'a redirect shadowing live content was accepted: ' . $shadow_path;
	} catch ( Throwable ) {
		$ignored = true;
	}
}

// 5. Reserved paths, including this plugin's own public endpoint.
foreach ( array( '/wp-json/wp/v2/posts', '/wp-admin/edit.php', '/wp-sitemap.xml', '/robots.txt', '/llms.txt', '/wp-login.php' ) as $reserved ) {
	try {
		$create->execute(
			array(
				'provider' => $target_provider,
				'source'   => $reserved,
				'target'   => '/somewhere-else',
				'status'   => 301,
			),
			1
		);
		$failures[] = 'a reserved path was accepted: ' . $reserved;
	} catch ( Throwable ) {
		$ignored = true;
	}
}

// 6. Naming a provider that is not available is refused, never substituted.
$absent = null;
foreach ( $providers->statuses() as $wpcb_status ) {
	if ( ! $wpcb_status->detected && 'none' !== $wpcb_status->provider ) {
		$absent = $wpcb_status->provider;
		break;
	}
}
if ( is_string( $absent ) ) {
	try {
		$create->execute(
			array(
				'provider' => $absent,
				'source'   => $probe_source . '-substitution',
				'target'   => '/',
				'status'   => 301,
			),
			1
		);
		$failures[] = 'a write to an unavailable provider was accepted';
	} catch ( Throwable ) {
		$substitution = $search_case->execute( array( 'source' => $probe_source . '-substitution' ) );
		if ( array() !== $substitution['held_by'] ) {
			$failures[] = 'a refused write was substituted into another provider';
		}
	}
}

$remaining = wpcb_verification_cleanup_redirects( array( $probe_source ) );
if ( array() !== $remaining ) {
	$failures[] = 'verification rules were left behind: ' . implode( ', ', $remaining );
}

$restore_flag();

if ( array() !== $failures ) {
	echo "FAIL:\n - " . implode( "\n - ", $failures ) . "\n";
	exit( 1 );
}

echo 'PASS: redirects ' . wp_json_encode(
	array(
		'available_providers' => $available,
		'written_to'          => $target_provider,
		'shadow_paths_tested' => $shadow_paths,
	)
) . "\n";
exit( 0 );

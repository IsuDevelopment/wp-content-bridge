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
use IsuDev\WPContentBridge\Application\Redirect\DeleteRedirect;
use IsuDev\WPContentBridge\Application\Redirect\NullRedirectProvider;
use IsuDev\WPContentBridge\Application\Redirect\RedirectCandidateGuard;
use IsuDev\WPContentBridge\Application\Redirect\RedirectProviderRegistry;
use IsuDev\WPContentBridge\Application\Redirect\SearchRedirects;
use IsuDev\WPContentBridge\Application\Redirect\UpdateRedirect;
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
	return new CreateRedirect(
		$providers,
		new RedirectCandidateGuard(),
		new WordPressPublishedPermalinkLookup(),
		wpcb_verification_audit_sink(),
		home_url( '/' )
	);
}

/**
 * Returns a discarding audit sink, so a verification run never appends to the
 * site's own audit record.
 *
 * @return AuditLog
 */
function wpcb_verification_audit_sink(): AuditLog {
	return new class() implements AuditLog {

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
}

/**
 * Removes every rule any part of this verification created, matching by the
 * shared name prefix rather than by a list of expected sources, and reports
 * what survived.
 *
 * Matching by prefix is deliberate. An earlier version deleted only the exact
 * sources it expected to have created, then confirmed removal by re-reading
 * the *same* provider object it had just mutated — so a rule left behind by a
 * path the run did not anticipate was reported as clean. It was: two rules
 * survived a passing run. A verifier that lies about leaving state behind is
 * worse than no verifier, so this reads the provider's whole rule set, deletes
 * everything carrying the prefix, and then re-reads through a *fresh* provider
 * object to prove the removal persisted.
 *
 * @param string $prefix Shared source-path prefix of this run's rules.
 * @return array Sources still present after cleanup.
 * @phpstan-return list<string>
 */
function wpcb_verification_cleanup_redirects( string $prefix ): array {
	$remaining = array();

	wpcb_verification_purge_yoast( $prefix );
	wpcb_verification_purge_redirection( $prefix );

	foreach ( wpcb_verification_yoast_origins() as $origin ) {
		if ( str_contains( $origin, $prefix ) ) {
			$remaining[] = 'yoast-premium:' . $origin;
		}
	}

	foreach ( wpcb_verification_redirection_urls() as $url ) {
		if ( str_contains( $url, $prefix ) ) {
			$remaining[] = 'redirection:' . $url;
		}
	}

	return $remaining;
}

/**
 * Returns every origin Yoast Premium currently stores.
 *
 * @return array Stored origins.
 * @phpstan-return list<string>
 */
function wpcb_verification_yoast_origins(): array {
	if ( ! class_exists( 'WPSEO_Redirect_Manager' ) || ! class_exists( 'WPSEO_Redirect_Formats' ) ) {
		return array();
	}

	$origins = array();
	$manager = new WPSEO_Redirect_Manager( WPSEO_Redirect_Formats::PLAIN );
	foreach ( $manager->get_all_redirects() as $redirect ) {
		$origins[] = (string) $redirect->get_origin();
	}

	return $origins;
}

/**
 * Removes every Yoast Premium rule carrying the prefix.
 *
 * @param string $prefix Shared source-path prefix.
 * @return void
 */
function wpcb_verification_purge_yoast( string $prefix ): void {
	if ( ! class_exists( 'WPSEO_Redirect_Manager' ) || ! class_exists( 'WPSEO_Redirect_Formats' ) ) {
		return;
	}

	$manager = new WPSEO_Redirect_Manager( WPSEO_Redirect_Formats::PLAIN );
	$doomed  = array();
	foreach ( $manager->get_all_redirects() as $redirect ) {
		if ( str_contains( (string) $redirect->get_origin(), $prefix ) ) {
			$doomed[] = $redirect;
		}
	}

	if ( array() !== $doomed ) {
		// One call with the whole set: Yoast's option layer saves the snapshot
		// it read at construction, so deleting one rule per fresh manager
		// invites a later save to write an earlier one back.
		$manager->delete_redirects( $doomed );
	}
}

/**
 * Returns every source URL Redirection currently stores.
 *
 * @return array Stored source URLs.
 * @phpstan-return list<string>
 */
function wpcb_verification_redirection_urls(): array {
	if ( ! wpcb_verification_redirection_available() ) {
		return array();
	}

	return array_map(
		static fn ( array $item ): string => (string) ( $item['url'] ?? '' ),
		wpcb_verification_redirection_items()
	);
}

/**
 * Removes every Redirection rule carrying the prefix.
 *
 * @param string $prefix Shared source-path prefix.
 * @return void
 */
function wpcb_verification_purge_redirection( string $prefix ): void {
	if ( ! wpcb_verification_redirection_available() ) {
		return;
	}

	$ids = array();
	foreach ( wpcb_verification_redirection_items() as $item ) {
		if ( isset( $item['id'] ) && str_contains( (string) ( $item['url'] ?? '' ), $prefix ) ) {
			$ids[] = (int) $item['id'];
		}
	}

	if ( array() === $ids ) {
		return;
	}

	wpcb_verification_with_redirection_capability(
		static function () use ( $ids ): void {
			$delete = new WP_REST_Request( 'POST', '/redirection/v1/bulk/redirect/delete' );
			$delete->set_param( 'items', $ids );
			rest_do_request( $delete );
		}
	);
}

/**
 * Whether Redirection's REST namespace is registered.
 *
 * @return bool
 */
function wpcb_verification_redirection_available(): bool {
	return function_exists( 'rest_get_server' )
		&& in_array( 'redirection/v1', rest_get_server()->get_namespaces(), true );
}

/**
 * Lists Redirection's stored rules.
 *
 * @return array Response items.
 * @phpstan-return list<array<string, mixed>>
 */
function wpcb_verification_redirection_items(): array {
	return wpcb_verification_with_redirection_capability(
		static function (): array {
			$list = new WP_REST_Request( 'GET', '/redirection/v1/redirect' );
			$list->set_param( 'per_page', 200 );
			$data = rest_do_request( $list )->get_data();

			if ( ! is_array( $data ) || ! is_array( $data['items'] ?? null ) ) {
				return array();
			}

			$items = array();
			foreach ( $data['items'] as $item ) {
				if ( is_array( $item ) ) {
					$items[] = $item;
				}
			}

			return $items;
		}
	);
}

/**
 * Runs one callable with Redirection's permission filters scoped to this
 * plugin's own capability, and always removes them afterwards.
 *
 * @template T
 * @param callable $call Scoped call.
 * @phpstan-param callable(): T $call
 * @phpstan-return T
 * @return mixed
 */
function wpcb_verification_with_redirection_capability( callable $call ): mixed {
	$role_filter       = static fn (): string => 'wpcb_manage_redirects';
	$capability_filter = static fn (): string => 'wpcb_manage_redirects';

	add_filter( 'redirection_role', $role_filter );
	add_filter( 'redirection_capability_check', $capability_filter, 10, 2 );

	try {
		return $call();
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
$update      = new UpdateRedirect( $providers, new RedirectCandidateGuard(), wpcb_verification_audit_sink(), home_url( '/' ) );
$delete      = new DeleteRedirect( $providers, wpcb_verification_audit_sink() );

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
$probe_prefix    = 'wpcb-redirect-verification-' . wp_generate_password( 8, false, false );
$probe_source    = '/' . $probe_prefix;

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

// 6b. The rest of the lifecycle: a rule can be changed and removed through the
// bridge, in every available engine. Without these an operator can create a
// redirect and then has to leave the bridge to fix a typo in it.
foreach ( $available as $engine ) {
	$lifecycle_source = $probe_source . '-lifecycle-' . $engine;

	$create->execute(
		array(
			'provider' => $engine,
			'source'   => $lifecycle_source,
			'target'   => '/',
			'status'   => 301,
		),
		1
	);

	$changed = $update->execute(
		array(
			'provider' => $engine,
			'source'   => $lifecycle_source,
			'target'   => '/?wpcb=updated',
			'status'   => 302,
		),
		1
	);
	if ( 302 !== $changed['status'] ) {
		$failures[] = 'an update did not change the status in ' . $engine;
	}

	$reread = $search_case->execute( array( 'source' => $lifecycle_source ) );
	$stored = null;
	foreach ( $reread['claims'] as $claim ) {
		if ( $claim['provider']['provider'] === $engine ) {
			$stored = $claim['rule'];
		}
	}
	if ( null === $stored || 302 !== $stored['status'] ) {
		$failures[] = 'an update did not persist in ' . $engine;
	}

	$removed = $delete->execute(
		array(
			'provider' => $engine,
			'source'   => $lifecycle_source,
		),
		1
	);
	if ( true !== $removed['deleted'] ) {
		$failures[] = 'a delete did not report removal in ' . $engine;
	}

	$after_delete = $search_case->execute( array( 'source' => $lifecycle_source ) );
	if ( array() !== $after_delete['held_by'] ) {
		$failures[] = 'a deleted rule was still held in ' . $engine;
	}

	// Removing something that is not there must be an error, not a quiet
	// success: a caller would read success as "the path is clear".
	try {
		$delete->execute(
			array(
				'provider' => $engine,
				'source'   => $lifecycle_source,
			),
			1
		);
		$failures[] = 'removing an absent rule reported success in ' . $engine;
	} catch ( Throwable ) {
		$ignored = true;
	}
}

// 7. The two-engine case, which is the whole reason reads and the guard span
// providers. Only runs when the site actually has two engines active; with
// one, this is unprovable and is honestly skipped rather than faked.
if ( count( $available ) > 1 ) {
	$first_engine  = $available[0];
	$second_engine = $available[1];
	$cross_source  = $probe_source . '-cross';
	$hop_a         = $probe_source . '-hop-a';
	$hop_b         = $probe_source . '-hop-b';

	$create->execute(
		array(
			'provider' => $first_engine,
			'source'   => $cross_source,
			'target'   => '/',
			'status'   => 301,
		),
		1
	);

	// The same path, written to the *other* engine. Both engines serve
	// redirects and whichever hooks first wins, so this must be refused even
	// though the second engine itself holds nothing.
	try {
		$create->execute(
			array(
				'provider' => $second_engine,
				'source'   => $cross_source,
				'target'   => '/',
				'status'   => 301,
			),
			1
		);
		$failures[] = 'a path held by one engine was accepted by the other';
	} catch ( Throwable $error ) {
		if ( ! str_contains( $error->getMessage(), $first_engine ) ) {
			$failures[] = 'the cross-engine collision did not name the engine holding the rule';
		}
	}

	$cross_read = $search_case->execute( array( 'source' => $cross_source ) );
	if ( array( $first_engine ) !== $cross_read['held_by'] ) {
		$failures[] = 'a cross-engine read did not attribute the rule to the engine holding it';
	}

	// The trailing-slash forms differ between engines: one stores a plain
	// origin with both slashes trimmed, the other keeps the leading slash. The
	// same logical path must still collide in both directions.
	try {
		$create->execute(
			array(
				'provider' => $second_engine,
				'source'   => rtrim( $cross_source, '/' ) . '/',
				'target'   => '/',
				'status'   => 301,
			),
			1
		);
		$failures[] = 'a trailing-slash variant of a held path was accepted by the other engine';
	} catch ( Throwable ) {
		$ignored = true;
	}

	// A chain that hops between engines: neither plugin can see this alone.
	$create->execute(
		array(
			'provider' => $first_engine,
			'source'   => $hop_a,
			'target'   => $hop_b,
			'status'   => 301,
		),
		1
	);

	try {
		$create->execute(
			array(
				'provider' => $second_engine,
				'source'   => $hop_b,
				'target'   => $hop_a,
				'status'   => 301,
			),
			1
		);
		$failures[] = 'a loop spanning two engines was accepted';
	} catch ( Throwable ) {
		$ignored = true;
	}
}

$remaining = wpcb_verification_cleanup_redirects( $probe_prefix );
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
		'two_engine_case'     => count( $available ) > 1 ? 'verified' : 'skipped (one engine active)',
	)
) . "\n";
exit( 0 );

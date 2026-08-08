<?php
/**
 * Runtime verification for the llms.txt slice (0.6.0).
 *
 * Run: wp eval 'require "<abs path>/tests/Integration/llms-txt-verification.php";'
 *
 * @package IsuDev\WPContentBridgeTests
 */

declare(strict_types=1);

use IsuDev\WPContentBridge\Adapter\Abilities\LlmsAbilities;
use IsuDev\WPContentBridge\Application\Llms\GetLlmsTxt;
use IsuDev\WPContentBridge\Application\Llms\PreviewUpdateLlmsTxt;
use IsuDev\WPContentBridge\Application\Llms\RegenerateLlmsTxt;
use IsuDev\WPContentBridge\Application\Llms\UpdateLlmsTxt;
use IsuDev\WPContentBridge\Application\Seo\NullSeoProvider;
use IsuDev\WPContentBridge\Application\Seo\SeoProvider;
use IsuDev\WPContentBridge\Application\Seo\SeoProviderRegistry;
use IsuDev\WPContentBridge\Domain\Llms\LlmsConfig;
use IsuDev\WPContentBridge\Domain\Llms\LlmsDocumentBuilder;
use IsuDev\WPContentBridge\Domain\Llms\LlmsOwnershipConflict;
use IsuDev\WPContentBridge\Domain\Llms\LlmsSourceEntry;
use IsuDev\WPContentBridge\Infrastructure\WordPress\Installer;
use IsuDev\WPContentBridge\Infrastructure\WordPress\LlmsTxtEndpoint;
use IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressAuditLog;
use IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressLlmsArtifactStore;
use IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressLlmsOwnershipInspector;
use IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressLlmsSourceSelector;
use IsuDev\WPContentBridge\Infrastructure\Yoast\YoastSeoProvider;
use IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressRenderedSchemaReader;

// phpcs:disable Squiz.Commenting.FunctionCommentThrowTag.Missing -- assertion helpers intentionally fail the runtime harness fast.
// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON is emitted to CLI only.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception messages are CLI diagnostics.
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI diagnostic output, not a filesystem write.
// phpcs:disable WordPress.WP.AlternativeFunctions.file_get_contents -- reads a temp probe file, not remote content.
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_read_file_get_contents -- reads a temp probe file, not remote content.
// phpcs:disable WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads a temp probe file, not remote content.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery -- isolated CLI verifier reads options and audit rows directly.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching -- caching one-off verifier queries would be pointless.
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- writes the temporary probe MU-plugin and synthetic llms.txt fixtures this CLI verifier creates and always removes; WP_Filesystem is not available this early and is not warranted for files this verifier owns end to end.
// phpcs:disable WordPress.WP.AlternativeFunctions.unlink_unlink -- removes only the exact temporary files this verifier itself created.
// phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_var_export -- var_export() here safely encodes a string as a PHP literal for the generated probe file, not debug output.

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Must run inside WordPress via wp eval.\n" );
	exit( 1 );
}

Installer::activate();

/**
 * Exercises the whole llms.txt slice per the execution plan's task 8: the
 * off-means-never-installed rewrite rule, exact byte/ETag/Last-Modified
 * fidelity, the no-post-query/no-write proof on the front-end path, the leak
 * matrix, de-publish staleness, preview purity, stale-token rejection,
 * regeneration idempotency, the physical-artifact ownership conflict and its
 * ABSPATH-vs-web-root regression, and deterministic bound truncation. Needs
 * WordPress core plus a licensed Yoast install for the noindex leg of the
 * leak proof (falls back to a recorded warning, not a failure, when Yoast is
 * unavailable).
 */
final class WPCB_Llms_Txt_Verification {

	private const WEBROOT_LLMS_REWRITE_REGEX = '^llms\.txt$';
	private const WEBROOT_LLMS_REWRITE_QUERY = 'index.php?wpcb_llms_txt=1';

	/**
	 * Exact fixture post IDs for cleanup.
	 *
	 * @var list<int>
	 */
	private array $post_ids = array();

	/**
	 * Administrator fixture user ID.
	 *
	 * @var int
	 */
	private int $admin_id = 0;

	/**
	 * Current user ID from before the run.
	 *
	 * @var int
	 */
	private int $original_user_id = 0;

	/**
	 * Snapshots of every option this run touches, keyed by option name.
	 * Value is the raw stored value, or the string "__absent__" when the
	 * option did not exist before the run.
	 *
	 * @var array<string, mixed>
	 */
	private array $original_options = array();

	/**
	 * Highest audit row ID existing before the run.
	 *
	 * @var int
	 */
	private int $audit_baseline_id = 0;

	/**
	 * Unique fixture marker embedded in every fixture title, so leak
	 * assertions cannot pass by accident against unrelated site content.
	 *
	 * @var string
	 */
	private string $token = '';

	/**
	 * ETag captured by {@see self::verify_exact_byte_and_cache_headers()} and
	 * consumed by {@see self::verify_conditional_get_304()}.
	 *
	 * @var string
	 */
	private string $stored_fixture_etag = '';

	/**
	 * Absolute path to the temporary query-count probe MU-plugin. Deleted in
	 * `finally` unconditionally; must never survive a failure.
	 *
	 * @var string
	 */
	private string $probe_mu_plugin_path = '';

	/**
	 * Absolute path to the probe's temporary output file.
	 *
	 * @var string
	 */
	private string $probe_output_path = '';

	/**
	 * Absolute path of the synthetic web-root llms.txt fixture (task 8 check 10).
	 *
	 * @var string
	 */
	private string $webroot_artifact_path = '';

	/**
	 * Absolute path of the synthetic ABSPATH llms.txt fixture (task 8 check 10, inverse).
	 *
	 * @var string
	 */
	private string $abspath_artifact_path = '';

	/**
	 * Snapshot store shared by every collaborator.
	 *
	 * @var WordPressLlmsArtifactStore
	 */
	private WordPressLlmsArtifactStore $store;

	/**
	 * Real source selector, wired against a real SEO provider registry.
	 *
	 * @var WordPressLlmsSourceSelector
	 */
	private WordPressLlmsSourceSelector $selector;

	/**
	 * Pure document builder.
	 *
	 * @var LlmsDocumentBuilder
	 */
	private LlmsDocumentBuilder $builder;

	/**
	 * Get-llms-txt ability, flag-independent.
	 *
	 * @var WP_Ability
	 */
	private WP_Ability $get_ability;

	/**
	 * Preview-update-llms-txt ability.
	 *
	 * @var WP_Ability
	 */
	private WP_Ability $preview_ability;

	/**
	 * Update-llms-txt ability.
	 *
	 * @var WP_Ability
	 */
	private WP_Ability $update_ability;

	/**
	 * Regenerate-llms-txt ability.
	 *
	 * @var WP_Ability
	 */
	private WP_Ability $regenerate_ability;

	/**
	 * Runs the complete verifier and always restores the prior installation state.
	 *
	 * @return void
	 */
	public function run(): void {
		$this->original_user_id = get_current_user_id();
		$this->token            = 'wpcbllms' . strtolower( wp_generate_password( 8, false, false ) );

		global $wpdb;
		/**
		 * WordPress database abstraction object.
		 *
		 * @var wpdb $wpdb
		 */
		$this->audit_baseline_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(MAX(id), 0) FROM %i', Installer::audit_table_name() ) );

		$this->snapshot_option( Installer::LLMS_ENABLED_OPTION );
		$this->snapshot_option( WordPressLlmsArtifactStore::CONFIG_OPTION );
		$this->snapshot_option( WordPressLlmsArtifactStore::ARTIFACT_OPTION );
		$this->snapshot_option( Installer::LLMS_REGEN_CURSOR_OPTION );
		$this->snapshot_option( Installer::LLMS_REGEN_STAGING_OPTION );
		$this->snapshot_option( Installer::LLMS_REGEN_DIRTY_OPTION );
		$this->snapshot_option( Installer::LLMS_FLUSH_NEEDED_OPTION );

		$scratch                     = getenv( 'TMPDIR' );
		$scratch                     = is_string( $scratch ) && '' !== $scratch ? rtrim( $scratch, '/' ) : '/tmp';
		$this->probe_output_path     = $scratch . '/wpcb-llms-probe-' . $this->token . '.txt';
		$this->probe_mu_plugin_path  = WPMU_PLUGIN_DIR . '/wpcb-llms-query-probe.php';
		$this->webroot_artifact_path = self::web_root() . 'llms.txt';
		$this->abspath_artifact_path = rtrim( ABSPATH, '/\\' ) . '/llms.txt';

		try {
			$this->resolve_admin();
			$this->wire_collaborators();

			$this->verify_flag_off_is_never_installed();

			$this->force_publication( true );
			$this->register_abilities( true );

			$this->verify_exact_byte_and_cache_headers();
			$this->verify_conditional_get_304();
			$this->verify_no_post_query_and_no_write();
			$this->verify_leak_matrix();
			$this->verify_depublish_staleness();
			$this->verify_preview_purity();
			$this->verify_stale_token_rejected_before_write();
			$this->verify_regenerate_idempotent();
			$this->verify_ownership_conflict_and_no_path_leak();
			$this->verify_bounds_truncate_deterministically();
		} finally {
			$this->cleanup();
		}
	}

	/** Resolves the administrator fixture and makes it the acting principal. */
	private function resolve_admin(): void {
		$administrators = get_users(
			array(
				'role'   => 'administrator',
				'number' => 1,
				'fields' => 'ids',
			)
		);
		$this->assert_true( array() !== $administrators && is_numeric( $administrators[0] ), 'An administrator fixture is required.' );
		$this->admin_id = (int) $administrators[0];
		wp_set_current_user( $this->admin_id );
		$this->assert_true( current_user_can( 'wpcb_manage_llms' ), 'The administrator fixture lacks wpcb_manage_llms.' );
	}

	/** Builds the real, non-faked infrastructure this verifier drives directly. */
	private function wire_collaborators(): void {
		$providers      = array( new YoastSeoProvider( new WordPressRenderedSchemaReader( home_url( '/' ) ) ) );
		$providers      = array_values( array_filter( $providers, static fn ( mixed $p ): bool => $p instanceof SeoProvider ) );
		$seo_providers  = new SeoProviderRegistry( $providers, new NullSeoProvider() );
		$this->store    = new WordPressLlmsArtifactStore();
		$this->selector = new WordPressLlmsSourceSelector( $seo_providers );
		$this->builder  = new LlmsDocumentBuilder();
	}

	/**
	 * Registers the four llms.txt Abilities directly from real infrastructure,
	 * independent of whatever the flag was when the current process booted —
	 * mirrors block-edits-verification.php's own direct registration.
	 *
	 * @param bool $publication_enabled Whether the flag must read true at
	 *                                   registration time so the three writes
	 *                                   register (see `LlmsAbilities::register_abilities()`).
	 * @return void
	 */
	private function register_abilities( bool $publication_enabled ): void {
		foreach ( array( GetLlmsTxt::ABILITY, PreviewUpdateLlmsTxt::ABILITY, UpdateLlmsTxt::ABILITY, RegenerateLlmsTxt::ABILITY ) as $ability_id ) {
			if ( wp_has_ability( $ability_id ) ) {
				wp_unregister_ability( $ability_id );
			}
		}

		$actual_flag = (bool) get_option( Installer::LLMS_ENABLED_OPTION, false );
		$this->assert_true(
			$actual_flag === $publication_enabled,
			'register_abilities() called with a publication_enabled flag that does not match the stored option.'
		);

		$audit    = new WordPressAuditLog();
		$site_url = home_url( '/' );

		$adapter = new LlmsAbilities(
			new GetLlmsTxt( $this->store, new WordPressLlmsOwnershipInspector() ),
			new PreviewUpdateLlmsTxt( $this->store, $this->selector, $this->builder, $site_url ),
			new UpdateLlmsTxt( $this->store, $this->selector, $this->builder, $audit, $site_url ),
			new RegenerateLlmsTxt( $this->store, $this->selector, $this->builder, $audit )
		);

		global $wp_current_filter;
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- scopes doing_action() to this direct registration and restores immediately.
		$wp_current_filter[] = 'wp_abilities_api_init';
		try {
			$adapter->register_abilities();
		} finally {
			array_pop( $wp_current_filter );
		}

		$get = wp_get_ability( GetLlmsTxt::ABILITY );
		$this->assert_true( $get instanceof WP_Ability, 'get-llms-txt did not register.' );
		$this->get_ability = $get;

		if ( ! $publication_enabled ) {
			return;
		}

		$preview    = wp_get_ability( PreviewUpdateLlmsTxt::ABILITY );
		$update     = wp_get_ability( UpdateLlmsTxt::ABILITY );
		$regenerate = wp_get_ability( RegenerateLlmsTxt::ABILITY );
		$this->assert_true( $preview instanceof WP_Ability, 'preview-update-llms-txt did not register while the flag was on.' );
		$this->assert_true( $update instanceof WP_Ability, 'update-llms-txt did not register while the flag was on.' );
		$this->assert_true( $regenerate instanceof WP_Ability, 'regenerate-llms-txt did not register while the flag was on.' );
		$this->preview_ability    = $preview;
		$this->update_ability     = $update;
		$this->regenerate_ability = $regenerate;
	}

	/**
	 * Sets `wpcb_llms_enabled` and keeps the real, live rewrite rule set in
	 * sync with it, in-process.
	 *
	 * `add_rewrite_rule()` was already called (or not) by `LlmsTxtEndpoint::register_hooks()`
	 * at this process's own `init`, before this script ran — so flipping the
	 * option here does not, by itself, change what this process's `$wp_rewrite`
	 * would flush. This method makes the in-memory rule set agree with the
	 * requested flag directly, then flushes, so the *separate* nginx/php-fpm
	 * process that will actually serve `/llms.txt` reads a `rewrite_rules`
	 * option consistent with the flag this verifier just set — exactly what a
	 * real flag flip achieves across the next real request in production, per
	 * `LlmsTxtEndpoint::register_flush_watcher()`.
	 *
	 * @param bool $enabled Desired publication state.
	 * @return void
	 */
	private function force_publication( bool $enabled ): void {
		update_option( Installer::LLMS_ENABLED_OPTION, $enabled, false );
		$this->sync_live_rewrite_rule( $enabled );
	}

	/**
	 * Makes this process's in-memory rewrite rule set (and, after flushing,
	 * the `rewrite_rules` option a real front-end request reads) agree with
	 * a given publication state, without writing the publication option
	 * itself. Split out from {@see self::force_publication()} so
	 * {@see self::cleanup()} can re-sync the rule to match an already-restored
	 * option value without re-writing that option — writing it again would
	 * turn a restored "absent" option back into a stored "false", which is
	 * exactly the distinction task 8 requires preserving.
	 *
	 * @param bool $enabled Desired publication state.
	 * @return void
	 */
	private function sync_live_rewrite_rule( bool $enabled ): void {
		global $wp_rewrite;
		if ( $enabled ) {
			add_rewrite_rule( self::WEBROOT_LLMS_REWRITE_REGEX, self::WEBROOT_LLMS_REWRITE_QUERY, 'top' );
		} else {
			unset( $wp_rewrite->extra_rules_top[ self::WEBROOT_LLMS_REWRITE_REGEX ] );
		}
		flush_rewrite_rules( false );
	}

	/**
	 * Check 1: with the flag off, `/llms.txt` 404s and no rewrite rule exists.
	 *
	 * @return void
	 */
	private function verify_flag_off_is_never_installed(): void {
		$this->force_publication( false );

		$rules = get_option( 'rewrite_rules', array() );
		$this->assert_true( is_array( $rules ) && ! array_key_exists( self::WEBROOT_LLMS_REWRITE_REGEX, $rules ), 'A rewrite rule exists while the publication flag is off.' );

		$response = wp_remote_get( home_url( '/llms.txt' ), array( 'timeout' => 10 ) );
		$this->assert_true( ! is_wp_error( $response ), 'The flag-off /llms.txt request failed: ' . ( is_wp_error( $response ) ? $response->get_error_message() : '' ) );
		$this->assert_true( 404 === wp_remote_retrieve_response_code( $response ), '/llms.txt did not 404 while the publication flag is off.' );
	}

	/**
	 * Checks 2 and 3: with the flag on and a snapshot stored, the route
	 * returns the stored bytes exactly with the correct `Content-Type`,
	 * `ETag`, and `Last-Modified`; `If-None-Match` with the current `ETag`
	 * answers `304` with an empty body.
	 *
	 * @return void
	 */
	private function verify_exact_byte_and_cache_headers(): void {
		$artifact = $this->builder->build(
			$this->minimal_config(),
			array( new LlmsSourceEntry( $this->token . ' Byte Fixture', home_url( '/' . $this->token . '-byte-fixture/' ), 'An excerpt.', 'page' ) ),
			new DateTimeImmutable( '2026-01-02T03:04:05Z' )
		);
		$this->store->replace_artifact( $artifact );

		$response      = wp_remote_get( home_url( '/llms.txt' ), array( 'timeout' => 10 ) );
		$response_body = wp_remote_retrieve_body( $response );
		$this->assert_true( ! is_wp_error( $response ), 'The stored-snapshot /llms.txt request failed.' );
		$this->assert_true( 200 === wp_remote_retrieve_response_code( $response ), '/llms.txt did not return 200 for a stored snapshot.' );
		$this->assert_true( $artifact->content === $response_body, '/llms.txt body did not match the stored snapshot exactly.' );

		$headers       = wp_remote_retrieve_headers( $response );
		$observed_etag = (string) self::header( $headers, 'etag' );
		// LlmsTxtEndpoint::respond() always sends a strong ETag
		// ('"{hash}"', no W/ prefix); this LocalWP install's nginx transparently
		// gzips the text/plain response (see Content-Encoding below) and, per
		// RFC 7232 section 2.1, downgrades the validator to weak on the way out
		// because the compressed representation differs from the one the
		// strong ETag was computed from. That is env-level middleware
		// rewriting our header, not this endpoint emitting a weak one, so an
		// optional "W/" prefix is stripped before comparing.
		$expected_etag   = '"' . $artifact->content_hash . '"';
		$normalized_etag = preg_replace( '/^W\//', '', $observed_etag );
		$this->assert_true( $expected_etag === $normalized_etag, 'ETag did not match the snapshot content hash.' );
		$this->assert_true( 'text/plain; charset=utf-8' === self::header( $headers, 'content-type' ), 'Content-Type was not text/plain; charset=utf-8.' );
		$this->assert_true(
			LlmsTxtEndpoint::http_date_from_generated_at( $artifact->generated_at ) === self::header( $headers, 'last-modified' ),
			'Last-Modified did not match the snapshot generation time.'
		);
		$this->assert_true( str_contains( (string) self::header( $headers, 'cache-control' ), 'public' ), 'Cache-Control was not public.' );

		$this->stored_fixture_etag = '"' . $artifact->content_hash . '"';
	}

	/**
	 * Check 3 continuation: `If-None-Match` with the current ETag.
	 *
	 * @return void
	 */
	private function verify_conditional_get_304(): void {
		$response = wp_remote_get(
			home_url( '/llms.txt' ),
			array(
				'timeout' => 10,
				'headers' => array( 'If-None-Match' => $this->stored_fixture_etag ),
			)
		);
		$this->assert_true( ! is_wp_error( $response ), 'The conditional /llms.txt request failed.' );
		$this->assert_true( 304 === wp_remote_retrieve_response_code( $response ), 'If-None-Match with the current ETag did not return 304.' );
		$this->assert_true( '' === wp_remote_retrieve_body( $response ), 'A 304 response carried a non-empty body.' );
	}

	/**
	 * Check 4, the one hard assertion. Three independent proofs:
	 *
	 * 1. Query-count proof (best-effort): a temporary MU-plugin writes
	 *    `$wpdb->num_queries` at `shutdown` to a file, not an option or
	 *    transient, so the measurement cannot contaminate what it measures.
	 *    `/llms.txt` is compared against a real page render.
	 * 2. The artifact option's `option_id` and value are byte-identical
	 *    before and after the request, read directly from `$wpdb->options`.
	 * 3. Behavioural leak proof: a post published after generation, before
	 *    any cron run, is absent from the served bytes.
	 *
	 * @return void
	 */
	private function verify_no_post_query_and_no_write(): void {
		$artifact = $this->builder->build(
			$this->minimal_config(),
			array( new LlmsSourceEntry( $this->token . ' Query Proof Fixture', home_url( '/' . $this->token . '-query-proof/' ), null, 'page' ) )
		);
		$this->store->replace_artifact( $artifact );

		global $wpdb;
		/**
		 * WordPress database abstraction object.
		 *
		 * @var wpdb $wpdb
		 */
		$row_before = $wpdb->get_row(
			$wpdb->prepare( 'SELECT option_id, option_value FROM %i WHERE option_name = %s', $wpdb->options, WordPressLlmsArtifactStore::ARTIFACT_OPTION )
		);
		$this->assert_true( null !== $row_before, 'The artifact option row could not be read before the request.' );

		$query_probe_installed = $this->install_query_probe();

		$llms_count    = null;
		$control_count = null;
		$probe_note    = '';

		if ( $query_probe_installed ) {
			$this->delete_probe_output();
			$llms_response = wp_remote_get( home_url( '/llms.txt' ), array( 'timeout' => 10 ) );
			$this->assert_true( ! is_wp_error( $llms_response ), 'The query-proof /llms.txt request failed.' );
			$this->assert_true( 200 === wp_remote_retrieve_response_code( $llms_response ), 'The query-proof /llms.txt request did not return 200.' );
			$llms_count = $this->read_probe_output();

			$this->delete_probe_output();
			$control_response = wp_remote_get( home_url( '/' ), array( 'timeout' => 10 ) );
			$this->assert_true( ! is_wp_error( $control_response ), 'The control page request failed.' );
			$control_count = $this->read_probe_output();

			if ( null === $llms_count || null === $control_count ) {
				$probe_note = 'WARN: query-count probe file was not written by the separate front-end process; the query-count proof was not achieved. Proceeding on the option-identity and behavioural proofs only.';
			} else {
				$this->assert_true( $llms_count < $control_count, "llms.txt query count ({$llms_count}) was not lower than a real page render ({$control_count})." );
				$this->assert_true(
					( $control_count - $llms_count ) >= 15,
					"llms.txt query count ({$llms_count}) was not far enough below a real page render ({$control_count}) to be consistent with a bootstrap plus one option read rather than a post query."
				);
			}
		} else {
			$probe_note = 'WARN: could not install the query-count probe MU-plugin (' . $this->probe_mu_plugin_path . ' is not writable); the query-count proof was not achieved. Proceeding on the option-identity and behavioural proofs only.';
		}

		if ( '' !== $probe_note ) {
			fwrite( STDERR, $probe_note . "\n" );
		} else {
			fwrite( STDERR, "Query-count proof: /llms.txt={$llms_count} queries, control page={$control_count} queries.\n" );
		}

		// Second, independent proof: the option row itself, read directly.
		$another_response = wp_remote_get( home_url( '/llms.txt' ), array( 'timeout' => 10 ) );
		$this->assert_true( ! is_wp_error( $another_response ), 'The second /llms.txt request failed.' );

		$row_after = $wpdb->get_row(
			$wpdb->prepare( 'SELECT option_id, option_value FROM %i WHERE option_name = %s', $wpdb->options, WordPressLlmsArtifactStore::ARTIFACT_OPTION )
		);
		$this->assert_true( null !== $row_after, 'The artifact option row could not be read after the request.' );
		$this->assert_true( (int) $row_before->option_id === (int) $row_after->option_id, 'The artifact option was replaced (option_id changed) by a front-end request.' );
		$this->assert_true( $row_before->option_value === $row_after->option_value, 'The artifact option value changed as a result of a front-end request.' );

		// Third, independent proof: a post published after generation, before
		// any cron run, must not appear in the bytes actually served.
		$late_marker = $this->token . ' PUBLISHED AFTER SNAPSHOT';
		$late_post   = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_author'  => $this->admin_id,
				'post_title'   => $late_marker,
				'post_content' => 'Published after the snapshot was generated; must never appear on /llms.txt without a regeneration.',
			),
			true
		);
		$this->assert_true( ! is_wp_error( $late_post ), 'Could not create the post-generation fixture post.' );
		$this->post_ids[] = (int) $late_post;

		$served = wp_remote_get( home_url( '/llms.txt' ), array( 'timeout' => 10 ) );
		$this->assert_true( ! is_wp_error( $served ), 'The post-fixture /llms.txt request failed.' );
		$body = wp_remote_retrieve_body( $served );
		$this->assert_true( ! str_contains( $body, $late_marker ), 'A post published after generation, before any regeneration, appeared in the served bytes.' );
	}

	/**
	 * Check 5, the leak proof: a draft, a private, a password-protected, a
	 * `noindex`, and a non-public-post-type post are all absent from a
	 * freshly generated artifact.
	 *
	 * @return void
	 */
	private function verify_leak_matrix(): void {
		$eligible_marker = $this->token . ' ELIGIBLE CONTROL';
		$this->insert_fixture( 'page', 'publish', $eligible_marker );

		$draft_marker = $this->token . ' DRAFT LEAK';
		$this->insert_fixture( 'page', 'draft', $draft_marker );

		$private_marker = $this->token . ' PRIVATE LEAK';
		$this->insert_fixture( 'page', 'private', $private_marker );

		$password_marker = $this->token . ' PASSWORD LEAK';
		$password_post   = $this->insert_fixture( 'page', 'publish', $password_marker );
		$updated         = wp_update_post(
			array(
				'ID'            => $password_post,
				'post_password' => 'wpcb-secret',
			),
			true
		);
		$this->assert_true( ! is_wp_error( $updated ), 'Could not set a post password on the fixture.' );

		$noindex_marker  = $this->token . ' NOINDEX LEAK';
		$noindex_post    = $this->insert_fixture( 'page', 'publish', $noindex_marker );
		$yoast_available = function_exists( 'YoastSEO' ) && defined( 'WPSEO_VERSION' );
		if ( $yoast_available ) {
			update_post_meta( $noindex_post, '_yoast_wpseo_meta-robots-noindex', '1' );
		} else {
			fwrite( STDERR, "WARN: no Yoast install active; the noindex leg of the leak proof was not exercised.\n" );
		}

		$post_type_object = get_post_type_object( 'post' );
		$this->assert_true(
			$post_type_object instanceof WP_Post_Type && false === $post_type_object->public,
			'This check assumes the "post" type is non-public on this site; that assumption no longer holds.'
		);
		$nonpublic_marker = $this->token . ' NONPUBLIC TYPE LEAK';
		$this->insert_fixture( 'post', 'publish', $nonpublic_marker );

		$config = LlmsConfig::from_input(
			array(
				'site_title'            => 'Leak Matrix Fixture',
				'site_summary'          => 'Verifies the leak matrix.',
				'enabled_post_types'    => array( 'page', 'post' ),
				'sections'              => array(
					array(
						'key'   => 'page',
						'label' => 'Pages',
					),
					array(
						'key'   => 'post',
						'label' => 'Posts',
					),
				),
				'group_by_section'      => true,
				'show_excerpts'         => false,
				'excerpt_length'        => 160,
				'max_items_per_section' => 50,
			),
			home_url( '/' )
		);

		$entries  = $this->selector->select( $config );
		$artifact = $this->builder->build( $config, $entries );

		$this->assert_true( str_contains( $artifact->content, $eligible_marker ), 'An eligible published page was missing from a freshly generated artifact.' );
		$this->assert_true( ! str_contains( $artifact->content, $draft_marker ), 'A draft post leaked into a freshly generated artifact.' );
		$this->assert_true( ! str_contains( $artifact->content, $private_marker ), 'A private post leaked into a freshly generated artifact.' );
		$this->assert_true( ! str_contains( $artifact->content, $password_marker ), 'A password-protected post leaked into a freshly generated artifact.' );
		if ( $yoast_available ) {
			// Not a plain assert_true: see the long comment below for why a
			// failure here is recorded as a known issue instead of thrown.
			$this->assert_noindex_not_leaked( $artifact->content, $noindex_marker );
		}
		$this->assert_true( ! str_contains( $artifact->content, $nonpublic_marker ), 'A post of a non-public post type leaked into a freshly generated artifact.' );
	}

	/**
	 * Checks the noindex leg of the leak matrix.
	 *
	 * This is a hard assertion, and it is a regression test for a real defect
	 * this verifier's own development uncovered on 2026-08-08.
	 *
	 * Yoast's own surface API, `YoastSEO()->meta->for_post()`, returns the
	 * **first-resolved post's meta for every subsequent post in the same PHP
	 * request** — reproduced with raw Yoast calls and no plugin code involved:
	 * resolving a noindex post and then a normal one returns `noindex` for
	 * both, and the second post's `title` comes back as the first one's.
	 * Setting `$GLOBALS['post']` and `setup_postdata()` between calls does not
	 * help. `WordPressLlmsSourceSelector::is_noindex()` is the first caller in
	 * this codebase to resolve SEO for many posts in one request — every other
	 * verifier exercises a single target per call — so nothing had triggered it
	 * before, and a `noindex` post leaked into the public document.
	 *
	 * The fix moved the decision off Yoast's rendered presentation and onto its
	 * indexable data (`SeoProvider::is_noindex()`), which is order-independent.
	 * Clearing Yoast's private context-memoizer cache by reflection also works
	 * and was rejected: if Yoast renames that property the filter would fail
	 * silently open, which is the wrong failure mode for something that keeps
	 * content out of a public document.
	 *
	 * `YoastSeoProvider::get()` itself is still subject to Yoast's memoization
	 * for multi-post reads. That is a recorded gap, not a live exposure — no
	 * remaining caller resolves more than one post per request.
	 *
	 * @param string $content        Freshly generated artifact content.
	 * @param string $noindex_marker Unique marker title of the noindex fixture.
	 * @return void
	 */
	private function assert_noindex_not_leaked( string $content, string $noindex_marker ): void {
		$this->assert_true(
			! str_contains( $content, $noindex_marker ),
			'a noindex post is absent from a freshly generated artifact'
		);
	}

	/**
	 * Check 6: a post de-published after generation is absent after regeneration.
	 *
	 * @return void
	 */
	private function verify_depublish_staleness(): void {
		$marker  = $this->token . ' DEPUBLISH STALENESS';
		$post_id = $this->insert_fixture( 'page', 'publish', $marker );

		$config = $this->single_page_config();
		$this->store->replace_config( $config );

		$entries  = $this->selector->select( $config );
		$artifact = $this->builder->build( $config, $entries );
		$this->store->replace_artifact( $artifact );
		$this->assert_true( str_contains( $artifact->content, $marker ), 'The de-publish fixture was missing from its own generation.' );

		$updated = wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'draft',
			),
			true
		);
		$this->assert_true( ! is_wp_error( $updated ), 'Could not de-publish the fixture post.' );

		$entries_after  = $this->selector->select( $config );
		$artifact_after = $this->builder->build( $config, $entries_after );
		$this->assert_true( ! str_contains( $artifact_after->content, $marker ), 'A de-published post remained in the artifact after regeneration.' );
	}

	/**
	 * Check 7: preview-update-llms-txt writes nothing and is deterministic.
	 *
	 * @return void
	 */
	private function verify_preview_purity(): void {
		$marker = $this->token . ' PREVIEW FIXTURE';
		$this->insert_fixture( 'page', 'publish', $marker );

		$config = $this->single_page_config();
		$this->store->replace_config( $config );
		$entries  = $this->selector->select( $config );
		$artifact = $this->builder->build( $config, $entries );
		$this->store->replace_artifact( $artifact );

		global $wpdb;
		/**
		 * WordPress database abstraction object.
		 *
		 * @var wpdb $wpdb
		 */
		$config_before   = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, WordPressLlmsArtifactStore::CONFIG_OPTION ) );
		$artifact_before = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, WordPressLlmsArtifactStore::ARTIFACT_OPTION ) );

		$token = (string) $this->get_ability->execute( array() )['version_token'];
		$input = $this->preview_input( $token, array( 'page' ), $marker . ' PROSPECTIVE TITLE' );

		$first = $this->preview_ability->execute( $input );
		$this->assert_not_error( $first, 'First preview call' );
		$second = $this->preview_ability->execute( $input );
		$this->assert_not_error( $second, 'Second preview call' );
		if ( ! is_array( $first ) || ! is_array( $second ) ) {
			throw new RuntimeException( 'Preview results are not arrays.' );
		}

		$this->assert_true( false === $first['writes_performed'], 'Preview did not report writes_performed = false.' );
		$this->assert_true(
			$first['prospective_artifact']['content_hash'] === $second['prospective_artifact']['content_hash'],
			'Preview was not deterministic across repeated calls.'
		);

		$config_after   = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, WordPressLlmsArtifactStore::CONFIG_OPTION ) );
		$artifact_after = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, WordPressLlmsArtifactStore::ARTIFACT_OPTION ) );
		$this->assert_true( $config_before === $config_after, 'Preview changed the stored configuration.' );
		$this->assert_true( $artifact_before === $artifact_after, 'Preview changed the stored artifact.' );
	}

	/**
	 * Check 8: update-llms-txt rejects a stale version_token before any write.
	 *
	 * @return void
	 */
	private function verify_stale_token_rejected_before_write(): void {
		$config = $this->single_page_config();
		$this->store->replace_config( $config );
		$artifact = $this->builder->build( $config, $this->selector->select( $config ) );
		$this->store->replace_artifact( $artifact );

		$stale_token = (string) $this->get_ability->execute( array() )['version_token'];

		// Out-of-band change: invalidates the token just read, exactly as a
		// concurrent administrator or a cron regeneration would.
		$other_config = $this->single_page_config( 'A changed summary.' );
		$this->store->replace_config( $other_config );

		global $wpdb;
		/**
		 * WordPress database abstraction object.
		 *
		 * @var wpdb $wpdb
		 */
		$config_before   = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, WordPressLlmsArtifactStore::CONFIG_OPTION ) );
		$artifact_before = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, WordPressLlmsArtifactStore::ARTIFACT_OPTION ) );

		$result = $this->update_ability->execute( $this->preview_input( $stale_token, array( 'page' ), 'Attempted stale write' ) );
		$this->assert_error_code( $result, 'wpcb_conflict', 'Stale version_token on update-llms-txt' );

		$config_after   = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, WordPressLlmsArtifactStore::CONFIG_OPTION ) );
		$artifact_after = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, WordPressLlmsArtifactStore::ARTIFACT_OPTION ) );
		$this->assert_true( $config_before === $config_after, 'A rejected stale update wrote the configuration option anyway.' );
		$this->assert_true( $artifact_before === $artifact_after, 'A rejected stale update wrote the artifact option anyway.' );
	}

	/**
	 * Check 9: regenerate-llms-txt is idempotent for unchanged source and configuration.
	 *
	 * @return void
	 */
	private function verify_regenerate_idempotent(): void {
		$marker = $this->token . ' REGENERATE IDEMPOTENT';
		$this->insert_fixture( 'page', 'publish', $marker );

		$config = $this->single_page_config();
		$this->store->replace_config( $config );
		$seed = $this->builder->build( $config, $this->selector->select( $config ) );
		$this->store->replace_artifact( $seed );

		$first = $this->regenerate_ability->execute( array() );
		$this->assert_not_error( $first, 'First regenerate-llms-txt call' );
		if ( ! is_array( $first ) ) {
			throw new RuntimeException( 'First regenerate result is not an array.' );
		}

		$second = $this->regenerate_ability->execute( array() );
		$this->assert_not_error( $second, 'Second regenerate-llms-txt call' );
		if ( ! is_array( $second ) ) {
			throw new RuntimeException( 'Second regenerate result is not an array.' );
		}

		$this->assert_true( array() === $second['changed_fields'], 'An unchanged regeneration reported non-empty changed_fields.' );
		$this->assert_true(
			$first['artifact']['content_hash'] === $second['artifact']['content_hash'],
			'An unchanged regeneration produced a different content hash.'
		);
		$this->assert_true(
			$first['artifact']['generated_at'] === $second['artifact']['generated_at'],
			'An unchanged regeneration churned the stored generation time.'
		);
	}

	/**
	 * Check 10: a synthetic physical llms.txt at the web root is a reported
	 * blocking ownership conflict, with no filesystem path in any response
	 * field; a file at ABSPATH on this subdirectory install is not, because
	 * it does not win routing. Regression for the 2026-08-07 fix.
	 *
	 * @return void
	 */
	private function verify_ownership_conflict_and_no_path_leak(): void {
		$this->assert_true(
			$this->webroot_artifact_path !== $this->abspath_artifact_path,
			'The web root and ABSPATH resolved to the same path; this install is not a subdirectory install and this check cannot run as designed.'
		);

		$inspector = new WordPressLlmsOwnershipInspector();

		$written = file_put_contents( $this->webroot_artifact_path, "synthetic third-party llms.txt\n" );
		$this->assert_true( false !== $written, 'Could not write the synthetic web-root llms.txt fixture.' );

		$state = $inspector->inspect();
		$this->assert_true( $state->physical_artifact_exists, 'A physical llms.txt at the web root was not detected.' );
		$this->assert_true( LlmsOwnershipConflict::PHYSICAL_ARTIFACT_PRESENT === $state->conflict, 'A physical web-root llms.txt was not reported as a blocking conflict.' );
		$this->assert_true( $state->is_blocking(), 'A physical web-root llms.txt did not block publication.' );

		self::assert_no_filesystem_path_leaks( $state->to_array(), array( $this->webroot_artifact_path, $this->abspath_artifact_path ) );

		$read_result = $this->get_ability->execute( array() );
		$this->assert_not_error( $read_result, 'get-llms-txt during the ownership-conflict check' );
		self::assert_no_filesystem_path_leaks( $read_result, array( $this->webroot_artifact_path, $this->abspath_artifact_path ) );

		unlink( $this->webroot_artifact_path );
		$this->webroot_artifact_path = '';

		$written = file_put_contents( $this->abspath_artifact_path, "synthetic file at ABSPATH, not the web root\n" );
		$this->assert_true( false !== $written, 'Could not write the synthetic ABSPATH llms.txt fixture.' );

		$state_abspath = $inspector->inspect();
		$this->assert_true( ! $state_abspath->physical_artifact_exists, 'A file at ABSPATH on a subdirectory install was reported as a physical artifact; it does not win routing.' );
		$this->assert_true(
			LlmsOwnershipConflict::PHYSICAL_ARTIFACT_PRESENT !== $state_abspath->conflict,
			'A file at ABSPATH on a subdirectory install was reported as a blocking physical-artifact conflict.'
		);

		unlink( $this->abspath_artifact_path );
		$this->abspath_artifact_path = '';
	}

	/**
	 * Check 11: bounds truncate deterministically and record a warning
	 * rather than failing generation. Exercised as a pure domain call: no
	 * WordPress posts are needed to prove the generator's own bound logic.
	 *
	 * @return void
	 */
	private function verify_bounds_truncate_deterministically(): void {
		$config = LlmsConfig::from_input(
			array(
				'site_title'            => 'Bounds Fixture',
				'site_summary'          => 'Verifies deterministic truncation.',
				'enabled_post_types'    => array( 'page' ),
				'sections'              => array(
					array(
						'key'   => 'page',
						'label' => 'Pages',
					),
				),
				'group_by_section'      => true,
				'show_excerpts'         => true,
				'excerpt_length'        => 20,
				'max_items_per_section' => LlmsConfig::MAX_ITEMS_PER_SECTION,
			),
			home_url( '/' )
		);

		$entries = array();
		$total   = LlmsConfig::MAX_ITEMS_PER_SECTION + 5;
		for ( $i = 0; $i < $total; ++$i ) {
			$excerpt   = 0 === $i ? str_repeat( 'x', 40 ) : 'short';
			$entries[] = new LlmsSourceEntry(
				$this->token . ' Bound Item ' . $i,
				home_url( '/' . $this->token . '-bound-item-' . $i . '/' ),
				$excerpt,
				'page'
			);
		}

		$artifact = $this->builder->build( $config, $entries );

		$link_count = preg_match_all( '/^- \[/m', $artifact->content );
		$this->assert_true( LlmsConfig::MAX_ITEMS_PER_SECTION === $link_count, 'The item-per-section bound did not truncate to exactly the configured cap.' );
		$this->assert_true(
			str_contains( $artifact->content, $this->token . ' Bound Item 0' ) && ! str_contains( $artifact->content, $this->token . ' Bound Item ' . ( $total - 1 ) . ')' ),
			'Item truncation was not deterministic: expected the first entries kept and the last ones dropped.'
		);
		$this->assert_true(
			array() !== array_filter( $artifact->warnings, static fn ( string $w ): bool => str_contains( $w, (string) LlmsConfig::MAX_ITEMS_PER_SECTION ) && str_contains( $w, 'item' ) ),
			'No warning was recorded for item-per-section truncation.'
		);
		$this->assert_true(
			array() !== array_filter( $artifact->warnings, static fn ( string $w ): bool => str_contains( $w, 'excerpt' ) ),
			'No warning was recorded for excerpt truncation.'
		);
		$this->assert_true( ! str_contains( $artifact->content, str_repeat( 'x', 40 ) ), 'An over-length excerpt was not truncated.' );
		$this->assert_true( str_contains( $artifact->content, str_repeat( 'x', 20 ) ), 'A truncated excerpt was not truncated to exactly the configured length.' );
	}

	/**
	 * Installs the temporary shutdown-hook query-count probe at the site's
	 * real MU-plugins directory, per task 8 check 4. Writes to a temp file
	 * only, never an option or transient.
	 *
	 * @return bool Whether the file was written.
	 */
	private function install_query_probe(): bool {
		$php = "<?php\n"
			. "// Temporary probe installed by llms-txt-verification.php; removed on completion.\n"
			. "add_action( 'shutdown', static function (): void {\n"
			. "\tglobal \$wpdb;\n"
			. "\t\$count = isset( \$wpdb ) && is_object( \$wpdb ) ? (int) \$wpdb->num_queries : -1;\n"
			. "\tfile_put_contents( " . var_export( $this->probe_output_path, true ) . ", (string) \$count );\n"
			. "} );\n";

		return false !== file_put_contents( $this->probe_mu_plugin_path, $php );
	}

	/** Deletes the probe's output file, if present. */
	private function delete_probe_output(): void {
		if ( file_exists( $this->probe_output_path ) ) {
			unlink( $this->probe_output_path );
		}
	}

	/**
	 * Reads the probe's last recorded query count, or null if it never fired.
	 *
	 * @return int|null
	 */
	private function read_probe_output(): ?int {
		if ( ! file_exists( $this->probe_output_path ) ) {
			return null;
		}
		$raw = file_get_contents( $this->probe_output_path );

		return is_string( $raw ) && '' !== $raw && is_numeric( $raw ) ? (int) $raw : null;
	}

	/**
	 * Builds a minimal, valid single-section configuration for direct
	 * `LlmsDocumentBuilder` calls that do not need real post fixtures.
	 *
	 * @return LlmsConfig
	 */
	private function minimal_config(): LlmsConfig {
		return LlmsConfig::from_input(
			array(
				'site_title'            => 'Verifier Fixture Site',
				'site_summary'          => 'A minimal fixture summary.',
				'enabled_post_types'    => array( 'page' ),
				'sections'              => array(
					array(
						'key'   => 'page',
						'label' => 'Pages',
					),
				),
				'group_by_section'      => true,
				'show_excerpts'         => true,
				'excerpt_length'        => 160,
				'max_items_per_section' => 50,
			),
			home_url( '/' )
		);
	}

	/**
	 * Builds a valid single-page-section configuration, used by every check
	 * that generates from real post fixtures.
	 *
	 * @param string $summary Site summary, varied by callers that need two
	 *                         distinct-but-valid configurations.
	 * @return LlmsConfig
	 */
	private function single_page_config( string $summary = 'Runtime verifier configuration.' ): LlmsConfig {
		return LlmsConfig::from_input(
			array(
				'site_title'            => 'Runtime Verifier',
				'site_summary'          => $summary,
				'enabled_post_types'    => array( 'page' ),
				'sections'              => array(
					array(
						'key'   => 'page',
						'label' => 'Pages',
					),
				),
				'group_by_section'      => true,
				'show_excerpts'         => false,
				'excerpt_length'        => 160,
				'max_items_per_section' => 50,
			),
			home_url( '/' )
		);
	}

	/**
	 * Builds a valid update/preview Ability input document.
	 *
	 * @param string $version_token Version token to submit.
	 * @param array  $post_types    Enabled post types.
	 * @param string $site_title    Document title.
	 * @return array
	 * @phpstan-param list<string> $post_types
	 * @phpstan-return array<string, mixed>
	 */
	private function preview_input( string $version_token, array $post_types, string $site_title ): array {
		$sections = array_map(
			static fn ( string $type ): array => array(
				'key'   => $type,
				'label' => ucfirst( $type ),
			),
			$post_types
		);

		return array(
			'version_token'         => $version_token,
			'site_title'            => $site_title,
			'site_summary'          => 'Runtime verifier preview input.',
			'enabled_post_types'    => $post_types,
			'sections'              => $sections,
			'group_by_section'      => true,
			'show_excerpts'         => false,
			'excerpt_length'        => 160,
			'max_items_per_section' => 50,
		);
	}

	/**
	 * Creates one fixture post with a unique, token-prefixed marker title.
	 *
	 * @param string $post_type Post type slug.
	 * @param string $status    Post status.
	 * @param string $marker    Unique marker text; becomes the post title.
	 * @return int
	 */
	private function insert_fixture( string $post_type, string $status, string $marker ): int {
		$post_id = wp_insert_post(
			array(
				'post_type'    => $post_type,
				'post_status'  => $status,
				'post_author'  => $this->admin_id,
				'post_title'   => $marker,
				'post_content' => 'Fixture content for ' . $marker,
			),
			true
		);
		$this->assert_true( ! is_wp_error( $post_id ), "Could not create the '{$marker}' fixture post." );
		$post_id          = (int) $post_id;
		$this->post_ids[] = $post_id;

		return $post_id;
	}

	/**
	 * Resolves the trailing-slashed directory that serves the home URL,
	 * matching `WordPressLlmsOwnershipInspector::web_root()`'s own derivation
	 * so the fixture path this verifier writes to is exactly the one the
	 * inspector under test will probe.
	 *
	 * @return string
	 */
	private static function web_root(): string {
		$root = rtrim( ABSPATH, '/\\' );
		$home = rtrim( (string) home_url(), '/' );
		$site = rtrim( (string) site_url(), '/' );

		if ( $home === $site || ! str_starts_with( $site, $home . '/' ) ) {
			return $root . '/';
		}

		$segments = array_filter( explode( '/', trim( substr( $site, strlen( $home ) ), '/' ) ) );
		foreach ( $segments as $ignored ) {
			$parent = dirname( $root );
			if ( $parent === $root ) {
				break;
			}
			$root = $parent;
		}

		return $root . '/';
	}

	/**
	 * Case-insensitively reads one header from a `wp_remote_get()` result.
	 *
	 * @param mixed  $headers Header collection from `wp_remote_retrieve_headers()`.
	 * @param string $name    Header name, lowercase.
	 * @return string|null
	 */
	private static function header( mixed $headers, string $name ): ?string {
		if ( ! is_array( $headers ) && ! ( $headers instanceof ArrayAccess ) ) {
			return null;
		}

		$value = $headers[ $name ] ?? null;

		return is_string( $value ) ? $value : ( is_array( $value ) ? ( $value[0] ?? null ) : null );
	}

	/**
	 * Recursively asserts that no string anywhere in a response structure
	 * contains a filesystem path this verifier itself planted.
	 *
	 * @param mixed $value          Response structure (array, scalar, or enum-backed value).
	 * @param array $forbidden_paths Absolute filesystem paths that must never appear.
	 * @phpstan-param list<string> $forbidden_paths
	 * @return void
	 */
	private static function assert_no_filesystem_path_leaks( mixed $value, array $forbidden_paths ): void {
		$encoded = wp_json_encode( $value );
		if ( ! is_string( $encoded ) ) {
			throw new RuntimeException( 'Could not encode a response for the path-leak scan.' );
		}

		foreach ( $forbidden_paths as $path ) {
			if ( '' === $path ) {
				continue;
			}
			if ( str_contains( $encoded, $path ) ) {
				throw new RuntimeException( 'A response field contained a filesystem path: ' . $path );
			}
		}
		if ( str_contains( $encoded, ABSPATH ) || 1 === preg_match( '#/Users/[a-zA-Z0-9_.-]+/#', $encoded ) ) {
			throw new RuntimeException( 'A response field appears to contain a filesystem path.' );
		}
	}

	/**
	 * Snapshots one option's current value, recording absence distinctly
	 * from a stored `false`.
	 *
	 * @param string $name Option name.
	 * @return void
	 */
	private function snapshot_option( string $name ): void {
		global $wpdb;
		/**
		 * WordPress database abstraction object.
		 *
		 * @var wpdb $wpdb
		 */
		$exists                          = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE option_name = %s', $wpdb->options, $name ) );
		$this->original_options[ $name ] = ( 0 === (int) $exists ) ? '__absent__' : get_option( $name, '__absent__' );
	}

	/** Restores all prior state and removes only exact verifier fixtures. */
	private function cleanup(): void {
		$administrators = get_users(
			array(
				'role'   => 'administrator',
				'number' => 1,
				'fields' => 'ids',
			)
		);
		if ( array() !== $administrators && is_numeric( $administrators[0] ) ) {
			wp_set_current_user( (int) $administrators[0] );
		}

		if ( '' !== $this->probe_mu_plugin_path && file_exists( $this->probe_mu_plugin_path ) ) {
			unlink( $this->probe_mu_plugin_path );
		}
		$this->delete_probe_output();

		if ( '' !== $this->webroot_artifact_path && file_exists( $this->webroot_artifact_path ) ) {
			unlink( $this->webroot_artifact_path );
		}
		if ( '' !== $this->abspath_artifact_path && file_exists( $this->abspath_artifact_path ) ) {
			unlink( $this->abspath_artifact_path );
		}

		foreach ( $this->post_ids as $post_id ) {
			wp_delete_post( $post_id, true );
		}

		foreach ( $this->original_options as $name => $value ) {
			$this->restore_option( $name, $value );
		}

		// Re-derive the live rewrite-rule state from the flag value just
		// restored above, WITHOUT writing that option again — restore_option()
		// already wrote it (or deliberately left it absent). Calling
		// force_publication() here instead would call update_option() again
		// and turn a correctly-restored absent option back into a stored
		// `false`, which is exactly the distinction this verifier must
		// preserve. sync_live_rewrite_rule() only touches the rewrite rule.
		$restored_flag_value = $this->original_options[ Installer::LLMS_ENABLED_OPTION ] ?? '__absent__';
		$this->sync_live_rewrite_rule( '__absent__' !== $restored_flag_value && (bool) $restored_flag_value );

		wp_clear_scheduled_hook( 'wpcb_llms_regenerate' );

		foreach ( array( GetLlmsTxt::ABILITY, PreviewUpdateLlmsTxt::ABILITY, UpdateLlmsTxt::ABILITY, RegenerateLlmsTxt::ABILITY ) as $ability_id ) {
			if ( wp_has_ability( $ability_id ) ) {
				wp_unregister_ability( $ability_id );
			}
		}

		global $wpdb;
		/**
		 * WordPress database abstraction object.
		 *
		 * @var wpdb $wpdb
		 */
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id > %d', Installer::audit_table_name(), $this->audit_baseline_id ) );
		wp_set_current_user( $this->original_user_id );
	}

	/**
	 * Restores an option, preserving whether it existed before the run.
	 *
	 * @param string $name  Option name.
	 * @param mixed  $value Snapshot value, or the string "__absent__".
	 * @return void
	 */
	private function restore_option( string $name, mixed $value ): void {
		if ( '__absent__' === $value ) {
			delete_option( $name );
			return;
		}
		update_option( $name, $value, false );
	}

	/**
	 * Throws when a verifier invariant is false.
	 *
	 * @param mixed  $condition Invariant outcome.
	 * @param string $message   Failure diagnostic.
	 * @return void
	 */
	private function assert_true( mixed $condition, string $message ): void {
		if ( true !== $condition ) {
			throw new RuntimeException( $message );
		}
	}

	/**
	 * Throws when an ability result unexpectedly is a WP_Error.
	 *
	 * @param mixed  $value Ability execution result.
	 * @param string $label Assertion label for the diagnostic.
	 * @return void
	 */
	private function assert_not_error( mixed $value, string $label ): void {
		if ( $value instanceof WP_Error ) {
			throw new RuntimeException( $label . ' returned ' . $value->get_error_code() . ': ' . $value->get_error_message() );
		}
	}

	/**
	 * Throws unless an ability result is a WP_Error carrying an exact code.
	 *
	 * @param mixed  $value         Ability execution result.
	 * @param string $expected_code Required error code.
	 * @param string $label         Assertion label for the diagnostic.
	 * @return void
	 */
	private function assert_error_code( mixed $value, string $expected_code, string $label ): void {
		if ( ! $value instanceof WP_Error ) {
			throw new RuntimeException( $label . ' did not return a WP_Error.' );
		}
		$this->assert_true( $expected_code === $value->get_error_code(), $label . ' returned ' . $value->get_error_code() . ' instead of ' . $expected_code . '.' );
	}
}

$failures = array();
$verifier = new WPCB_Llms_Txt_Verification();
try {
	$verifier->run();
} catch ( Throwable $error ) {
	$failures[] = $error->getMessage();
}

echo wp_json_encode(
	array(
		'status'   => array() === $failures ? 'PASS' : 'FAIL',
		'failures' => $failures,
	)
), PHP_EOL;
exit( array() === $failures ? 0 : 1 );

<?php
/**
 * Runtime verification for the preview-update-content and preview-update-seo
 * surfaces.
 *
 * Run: wp eval 'require "<abs path>/tests/Integration/preview-verification.php";'
 *
 * The `preview-update-content` half uses only WordPress core and always runs.
 * The `preview-update-seo` half needs a compatible Yoast Free 28.x install; when
 * one is absent those checks are reported in the result's `skipped` list rather
 * than silently passing. See docs/setup/VERIFICATION.md.
 *
 * @package IsuDev\WPContentBridge\Tests
 */

declare(strict_types=1);

use IsuDev\WPContentBridge\Application\ContentAccess\ContentAccessManager;
use IsuDev\WPContentBridge\Application\Mutation\MutationConflict;
use IsuDev\WPContentBridge\Application\Mutation\PreviewContentUpdate;
use IsuDev\WPContentBridge\Application\Mutation\PreviewSeoUpdate;
use IsuDev\WPContentBridge\Application\Mutation\UpdateContent;
use IsuDev\WPContentBridge\Application\Mutation\UpdateSeo;
use IsuDev\WPContentBridge\Infrastructure\WordPress\Installer;
use IsuDev\WPContentBridge\Infrastructure\WordPress\PhpBlockMarkupValidator;
use IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressAuditLog;
use IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressContentAccessSettingsRepository;
use IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressContentMutationRepository;
use IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressContentTypeCatalog;
use IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressSeoImageRepository;
use IsuDev\WPContentBridge\Infrastructure\Yoast\YoastSeoProvider;
use IsuDev\WPContentBridge\Infrastructure\Yoast\YoastSeoWriter;

// phpcs:disable Squiz.Commenting.FunctionCommentThrowTag.Missing -- assertion helpers intentionally fail the runtime harness fast.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception messages are CLI diagnostics, not rendered HTML.
// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- exit code + STDOUT text for a CLI verifier, not rendered HTML.
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI diagnostic output, not a filesystem operation.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery -- one-off reads against the dedicated audit table in a CLI verifier.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off CLI verifier queries; caching would be pointless here.
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the table name is the plugin's own fixed constant, not user input.

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Must run inside WordPress via wp eval.\n" );
	exit( 1 );
}

/**
 * Fail-fast runtime verifier for the two Slice 1A preview Abilities.
 */
final class WPCB_Preview_Verification {

	private const MISSING_OPTION = '__wpcb_preview_verification_missing__';

	/**
	 * Collected failures.
	 *
	 * @var list<string>
	 */
	private array $failures = array();

	/**
	 * Checks this run did not perform, reported alongside the result so a PASS
	 * is never mistaken for complete coverage.
	 *
	 * @var list<string>
	 */
	private array $skipped = array();

	/**
	 * Whether a compatible Yoast Free install backs the SEO half of this run.
	 *
	 * @var bool
	 */
	private bool $yoast_available = false;

	/**
	 * Snapshotted options restored in the teardown.
	 *
	 * @var array<string, mixed>
	 */
	private array $saved_options = array();

	/**
	 * Fixture post ID removed in the teardown.
	 *
	 * @var int
	 */
	private int $post_id = 0;

	/**
	 * Principal restored in the teardown.
	 *
	 * @var int
	 */
	private int $original_user_id = 0;

	/**
	 * Highest audit row ID that existed before this run, used to detect any
	 * row a preview call incorrectly adds.
	 *
	 * @var int
	 */
	private int $audit_baseline_id = 0;

	/**
	 * Runs the verification and prints a machine-readable result.
	 *
	 * @return void
	 */
	public function run(): void {
		$this->original_user_id = get_current_user_id();

		try {
			$this->set_up();

			// `preview-update-content` is a WordPress-core-only surface. It is
			// verified unconditionally so a clean disposable WordPress can sign
			// it off; only the SEO half needs the licensed provider.
			$this->verify_content_preview_causes_no_mutation();
			$this->verify_content_preview_then_write_matches();
			$this->verify_content_preview_stale_token_rejected();

			if ( $this->yoast_available ) {
				$this->verify_seo_preview_causes_no_mutation();
				$this->verify_seo_preview_then_write_matches();
				$this->verify_seo_preview_stale_token_rejected();
			} else {
				$this->skipped[] = 'preview-update-seo: no compatible Yoast Free 28.x install is active. Run this verifier in the provider environment to cover it.';
			}
		} catch ( Throwable $error ) {
			$this->failures[] = $error->getMessage();
		} finally {
			$this->tear_down();
		}

		echo wp_json_encode(
			array(
				'status'   => array() === $this->failures ? 'PASS' : 'FAIL',
				'failures' => $this->failures,
				'skipped'  => $this->skipped,
			)
		) . "\n";

		exit( array() === $this->failures ? 0 : 1 );
	}

	/**
	 * Creates the fixture and enables exactly the required policy.
	 *
	 * @return void
	 */
	private function set_up(): void {
		$this->yoast_available = ( new YoastSeoWriter( new YoastSeoProvider(), new WordPressSeoImageRepository() ) )->is_available();

		$administrators = get_users(
			array(
				'role'   => 'administrator',
				'number' => 1,
				'fields' => 'ID',
			)
		);
		$this->assert_true( array() !== $administrators, 'No administrator is available for the verification.' );
		wp_set_current_user( (int) $administrators[0] );

		foreach ( array( Installer::WRITES_ENABLED_OPTION, WordPressContentAccessSettingsRepository::OPTION_NAME ) as $option ) {
			$this->saved_options[ $option ] = get_option( $option, self::MISSING_OPTION );
		}

		update_option( Installer::WRITES_ENABLED_OPTION, true, false );
		update_option(
			WordPressContentAccessSettingsRepository::OPTION_NAME,
			array(
				'post' => array(
					'get_content'    => true,
					'update_content' => true,
					'update_seo'     => true,
				),
			)
		);

		$post_id = wp_insert_post(
			array(
				'post_type'    => 'post',
				'post_status'  => 'publish',
				'post_title'   => 'WPCB preview fixture',
				'post_content' => '<!-- wp:paragraph --><p>Original content.</p><!-- /wp:paragraph -->',
				'post_excerpt' => 'Original excerpt.',
			),
			true
		);
		$this->assert_true( ! is_wp_error( $post_id ), 'Could not create the preview fixture post.' );
		$this->post_id = (int) $post_id;

		$this->refresh_audit_baseline();
	}

	/**
	 * Proves repeated content previews are deterministic and change nothing.
	 *
	 * @return void
	 */
	private function verify_content_preview_causes_no_mutation(): void {
		$post_before      = get_post( $this->post_id );
		$modified_before  = $post_before->post_modified_gmt;
		$revisions_before = count( wp_get_post_revisions( $this->post_id ) );

		$input = array(
			'post_id'       => $this->post_id,
			'version_token' => $this->current_token(),
			'title'         => 'Previewed title (not written)',
			'excerpt'       => 'Previewed excerpt (not written)',
		);

		$first  = wp_json_encode( $this->content_preview_use_case()->execute( $input )->to_array() );
		$second = wp_json_encode( $this->content_preview_use_case()->execute( $input )->to_array() );
		$this->assert_true( $first === $second, 'Repeated content previews are not deterministic.' );

		$post_after      = get_post( $this->post_id );
		$revisions_after = count( wp_get_post_revisions( $this->post_id ) );
		$this->assert_true( $post_after->post_title === $post_before->post_title, 'Content preview changed the stored title.' );
		$this->assert_true( $post_after->post_modified_gmt === $modified_before, 'Content preview changed post_modified_gmt.' );
		$this->assert_true( $revisions_after === $revisions_before, 'Content preview created a revision.' );
		$this->assert_no_new_audit_rows( 'Content preview recorded an audit row.' );
	}

	/**
	 * Proves a content preview followed by the matching write with the same
	 * token produces exactly the previewed configured state.
	 *
	 * @return void
	 */
	private function verify_content_preview_then_write_matches(): void {
		$fields = array(
			'title'        => 'Accepted title',
			'block_markup' => '<!-- wp:paragraph --><p>Accepted content.</p><!-- /wp:paragraph -->',
			'excerpt'      => 'Accepted excerpt',
		);

		$preview = $this->content_preview_use_case()->execute(
			array_merge(
				array(
					'post_id'       => $this->post_id,
					'version_token' => $this->current_token(),
				),
				$fields
			)
		);
		$this->assert_true( array( 'title', 'content', 'excerpt' ) === $preview->changed_fields, 'Content preview reported unexpected changed fields.' );

		$result = ( new UpdateContent(
			$this->access(),
			new PhpBlockMarkupValidator(),
			new WordPressContentMutationRepository(),
			new WordPressAuditLog()
		) )->execute(
			array_merge(
				array(
					'post_id'       => $this->post_id,
					'version_token' => $preview->version->to_string(),
				),
				$fields
			),
			get_current_user_id()
		);
		$this->assert_true( array( 'title', 'content', 'excerpt' ) === $result->changed_fields, 'update-content reported unexpected changed fields.' );
		$this->refresh_audit_baseline();

		$written = get_post( $this->post_id );
		$this->assert_true( $written->post_title === $preview->preview_content['title'], 'The written title does not match the previewed title.' );
		$this->assert_true( $written->post_content === $preview->preview_content['block_markup'], 'The written content does not match the previewed block markup.' );
		$this->assert_true( $written->post_excerpt === $preview->preview_content['excerpt'], 'The written excerpt does not match the previewed excerpt.' );
	}

	/**
	 * Proves a stale token is rejected before any content mutation.
	 *
	 * @return void
	 */
	private function verify_content_preview_stale_token_rejected(): void {
		$before   = get_post( $this->post_id );
		$stale    = 'deadbeefdeadbeef:2020-01-01 00:00:00';
		$rejected = false;

		try {
			$this->content_preview_use_case()->execute(
				array(
					'post_id'       => $this->post_id,
					'version_token' => $stale,
					'title'         => 'Should never be read against',
				)
			);
		} catch ( MutationConflict $conflict ) {
			$rejected = true;
		}

		$this->assert_true( $rejected, 'A stale content-preview token did not raise a mutation conflict.' );
		$after = get_post( $this->post_id );
		$this->assert_true( $after->post_title === $before->post_title, 'A stale-token content preview changed the stored title.' );
	}

	/**
	 * Proves repeated SEO previews are deterministic and write no Yoast meta.
	 *
	 * @return void
	 */
	private function verify_seo_preview_causes_no_mutation(): void {
		$meta_before = get_post_meta( $this->post_id, '_yoast_wpseo_title', true );

		$input = array(
			'post_id'          => $this->post_id,
			'version_token'    => $this->current_token(),
			'seo_title'        => 'Previewed SEO title (not written)',
			'meta_description' => 'Previewed description (not written).',
		);

		$first  = wp_json_encode( $this->seo_preview_use_case()->execute( $input )->to_array() );
		$second = wp_json_encode( $this->seo_preview_use_case()->execute( $input )->to_array() );
		$this->assert_true( $first === $second, 'Repeated SEO previews are not deterministic.' );

		$meta_after = get_post_meta( $this->post_id, '_yoast_wpseo_title', true );
		$this->assert_true( $meta_after === $meta_before, 'SEO preview wrote Yoast post meta.' );
		$this->assert_no_new_audit_rows( 'SEO preview recorded an audit row.' );
	}

	/**
	 * Proves an SEO preview followed by the matching write with the same
	 * token produces exactly the previewed configured value.
	 *
	 * @return void
	 */
	private function verify_seo_preview_then_write_matches(): void {
		$fields = array(
			'seo_title'        => 'Accepted SEO title',
			'meta_description' => 'Accepted SEO description.',
		);

		$preview = $this->seo_preview_use_case()->execute(
			array_merge(
				array(
					'post_id'       => $this->post_id,
					'version_token' => $this->current_token(),
				),
				$fields
			)
		);
		$this->assert_true( 'Accepted SEO title' === ( $preview->preview_seo['seo_title'] ?? null ), 'SEO preview did not report the sanitized prospective title.' );

		$result = ( new UpdateSeo(
			$this->access(),
			new WordPressContentMutationRepository(),
			new YoastSeoWriter( new YoastSeoProvider(), new WordPressSeoImageRepository() ),
			new WordPressAuditLog()
		) )->execute(
			array_merge(
				array(
					'post_id'       => $this->post_id,
					'version_token' => $preview->version->to_string(),
				),
				$fields
			),
			get_current_user_id()
		);

		$this->refresh_audit_baseline();

		$configured_title = $result->effective_seo['configured']['title']['value'] ?? null;
		$this->assert_true( $preview->preview_seo['seo_title'] === $configured_title, 'The written configured SEO title does not match the previewed value.' );
	}

	/**
	 * Proves a stale token is rejected before any SEO mutation.
	 *
	 * @return void
	 */
	private function verify_seo_preview_stale_token_rejected(): void {
		$meta_before = get_post_meta( $this->post_id, '_yoast_wpseo_title', true );
		$stale       = 'deadbeefdeadbeef:2020-01-01 00:00:00';
		$rejected    = false;

		try {
			$this->seo_preview_use_case()->execute(
				array(
					'post_id'       => $this->post_id,
					'version_token' => $stale,
					'seo_title'     => 'Should never be read against',
				)
			);
		} catch ( MutationConflict $conflict ) {
			$rejected = true;
		}

		$this->assert_true( $rejected, 'A stale SEO-preview token did not raise a mutation conflict.' );
		$meta_after = get_post_meta( $this->post_id, '_yoast_wpseo_title', true );
		$this->assert_true( $meta_after === $meta_before, 'A stale-token SEO preview wrote Yoast post meta.' );
	}

	/**
	 * Restores the site to its pre-verification state.
	 *
	 * @return void
	 */
	private function tear_down(): void {
		if ( 0 !== $this->post_id ) {
			wp_delete_post( $this->post_id, true );
		}

		foreach ( $this->saved_options as $option => $value ) {
			if ( self::MISSING_OPTION === $value ) {
				delete_option( $option );
				continue;
			}

			update_option( $option, $value, false );
		}

		wp_set_current_user( $this->original_user_id );
	}

	/**
	 * Builds the shared access manager.
	 *
	 * @return ContentAccessManager
	 */
	private function access(): ContentAccessManager {
		return new ContentAccessManager(
			new WordPressContentAccessSettingsRepository(),
			new WordPressContentTypeCatalog()
		);
	}

	/**
	 * Builds the content-preview use case from real adapters.
	 *
	 * @return PreviewContentUpdate
	 */
	private function content_preview_use_case(): PreviewContentUpdate {
		$repository = new WordPressContentMutationRepository();

		return new PreviewContentUpdate( $this->access(), new PhpBlockMarkupValidator(), $repository, $repository );
	}

	/**
	 * Builds the SEO-preview use case from real adapters.
	 *
	 * @return PreviewSeoUpdate
	 */
	private function seo_preview_use_case(): PreviewSeoUpdate {
		$writer = new YoastSeoWriter( new YoastSeoProvider(), new WordPressSeoImageRepository() );

		return new PreviewSeoUpdate( $this->access(), new WordPressContentMutationRepository(), $writer );
	}

	/**
	 * Reads the fixture's current optimistic-concurrency token.
	 *
	 * @return string
	 */
	private function current_token(): string {
		$version = ( new WordPressContentMutationRepository() )->current_version( $this->post_id );
		$this->assert_true( null !== $version, 'The fixture post is unexpectedly unavailable.' );

		return $version->to_string();
	}

	/**
	 * Asserts that no audit row was recorded since the baseline.
	 *
	 * @param string $message Failure message.
	 * @return void
	 */
	private function assert_no_new_audit_rows( string $message ): void {
		$this->assert_true( $this->current_audit_max_id() === $this->audit_baseline_id, $message );
	}

	/**
	 * Advances the audit baseline to the current maximum row ID. Called after
	 * an intentional real write (a preview-then-write proof), so that a later
	 * no-mutation assertion is not confused by that legitimate audit row.
	 *
	 * @return void
	 */
	private function refresh_audit_baseline(): void {
		$this->audit_baseline_id = $this->current_audit_max_id();
	}

	/**
	 * Reads the current maximum audit row ID.
	 *
	 * @return int
	 */
	private function current_audit_max_id(): int {
		global $wpdb;

		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(MAX(id), 0) FROM %i', Installer::audit_table_name() ) );
	}

	/**
	 * Records a failure message when the condition does not hold.
	 *
	 * @param bool   $condition Assertion condition.
	 * @param string $message   Failure message.
	 * @return void
	 */
	private function assert_true( bool $condition, string $message ): void {
		if ( ! $condition ) {
			throw new RuntimeException( $message );
		}
	}
}

( new WPCB_Preview_Verification() )->run();

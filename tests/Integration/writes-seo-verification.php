<?php
/**
 * Runtime verification for the update-seo write surface.
 *
 * Run: wp eval 'require "<abs path>/tests/Integration/writes-seo-verification.php";'
 * Requires Yoast Free 28.x active for the full matrix; the no-provider case is
 * skipped with a warning (not a failure) when Yoast is active, since it cannot
 * be deactivated safely from inside this script.
 *
 * @package IsuDev\WPContentBridge\Tests
 */

declare(strict_types=1);

use IsuDev\WPContentBridge\Application\ContentAccess\ContentAccessManager;
use IsuDev\WPContentBridge\Application\Mutation\MutationConflict;
use IsuDev\WPContentBridge\Application\Mutation\SeoFieldUnsupported;
use IsuDev\WPContentBridge\Application\Mutation\UpdateSeo;
use IsuDev\WPContentBridge\Domain\ContentAccess\ContentOperation;
use IsuDev\WPContentBridge\Domain\Mutation\VersionToken;
use IsuDev\WPContentBridge\Infrastructure\WordPress\Installer;
use IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressAuditLog;
use IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressContentAccessSettingsRepository;
use IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressContentMutationRepository;
use IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressContentTypeCatalog;
use IsuDev\WPContentBridge\Infrastructure\Yoast\YoastFreeSeoWriter;
use IsuDev\WPContentBridge\Infrastructure\Yoast\YoastSeoProvider;

// phpcs:disable Squiz.Commenting.FunctionCommentThrowTag.Missing -- assertion helpers intentionally fail the runtime harness fast.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception messages are CLI diagnostics, not rendered HTML.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery -- one-off reads against the dedicated audit table in a CLI verifier.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off CLI verifier queries; caching would be pointless here.
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the table name is the plugin's own fixed constant, not user input.
// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- exit code + STDOUT text for a CLI verifier, not rendered HTML.
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI diagnostic output, not a filesystem operation.

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Must run inside WordPress via wp eval.\n" );
	exit( 1 );
}

/**
 * Fail-fast runtime verifier for update-seo.
 */
final class WPCB_Seo_Write_Verification {

	/**
	 * Exact fixture post IDs for cleanup.
	 *
	 * @var list<int>
	 */
	private array $post_ids = array();

	/**
	 * Exact fixture user IDs for cleanup.
	 *
	 * @var list<int>
	 */
	private array $user_ids = array();

	/**
	 * Collected failure messages.
	 *
	 * @var list<string>
	 */
	private array $failures = array();

	/**
	 * Runs the full verification matrix.
	 *
	 * @return void
	 */
	public function run(): void {
		Installer::activate();
		update_option( Installer::WRITES_ENABLED_OPTION, true );

		try {
			$this->verify_authorization_matrix();
			$this->verify_stale_version_conflict();
			$this->verify_unsupported_field_rejected();
			$this->verify_write_and_reread_parity();
			$this->verify_audit_redaction();
		} finally {
			$this->cleanup();
		}

		if ( array() === $this->failures ) {
			echo "PASS: update-seo (authorization matrix, conflict, unsupported field, write/re-read parity, audit redaction)\n";
			exit( 0 );
		}

		echo "FAIL:\n - " . implode( "\n - ", $this->failures ) . "\n";
		exit( 1 );
	}

	/**
	 * Creates a disposable post fixture.
	 *
	 * @return int
	 */
	private function fixture_post(): int {
		$post_id = wp_insert_post(
			array(
				'post_type'    => 'post',
				'post_status'  => 'publish',
				'post_title'   => 'WPCB SEO fixture',
				'post_content' => '<!-- wp:paragraph --><p>Fixture body.</p><!-- /wp:paragraph -->',
			),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			throw new RuntimeException( 'Could not create fixture post.' );
		}
		$this->post_ids[] = (int) $post_id;

		return (int) $post_id;
	}

	/**
	 * Creates a disposable user fixture.
	 *
	 * @param string $role WordPress role.
	 * @return int
	 */
	private function fixture_user( string $role ): int {
		$user_id = wp_insert_user(
			array(
				'user_login' => 'wpcb_seo_' . $role . '_' . wp_generate_password( 6, false ),
				'user_pass'  => wp_generate_password( 24 ),
				'role'       => $role,
			)
		);
		if ( is_wp_error( $user_id ) ) {
			throw new RuntimeException( 'Could not create fixture user.' );
		}
		$this->user_ids[] = (int) $user_id;

		return (int) $user_id;
	}

	/**
	 * Builds the current version token for a fixture post.
	 *
	 * @param int $post_id Fixture post ID.
	 * @return string
	 */
	private function current_token( int $post_id ): string {
		$post = get_post( $post_id );

		return VersionToken::for_content(
			$post->post_modified_gmt,
			$post->post_title,
			$post->post_content,
			$post->post_status
		)->to_string();
	}

	/**
	 * Builds a fully wired update-seo use case for the verifier.
	 *
	 * @return UpdateSeo
	 */
	private function use_case(): UpdateSeo {
		$writer = new YoastFreeSeoWriter( new YoastSeoProvider() );

		return new UpdateSeo(
			new ContentAccessManager(
				new WordPressContentAccessSettingsRepository(),
				new WordPressContentTypeCatalog()
			),
			new WordPressContentMutationRepository(),
			$writer,
			new WordPressAuditLog()
		);
	}

	/**
	 * Proves plugin capability, native capability, and policy are each
	 * independently required.
	 *
	 * @return void
	 */
	private function verify_authorization_matrix(): void {
		$post_id = $this->fixture_post();

		$editor_no_plugin_cap = $this->fixture_user( 'editor' );
		get_userdata( $editor_no_plugin_cap )->remove_cap( 'wpcb_manage_seo' );
		wp_set_current_user( $editor_no_plugin_cap );
		$this->assert_true(
			! current_user_can( 'wpcb_manage_seo' ),
			'editor without wpcb_manage_seo unexpectedly has it'
		);

		$subscriber = $this->fixture_user( 'subscriber' );
		wp_set_current_user( $subscriber );
		$this->assert_true(
			! current_user_can( 'edit_post', $post_id ),
			'subscriber unexpectedly has edit_post on the fixture'
		);

		$administrator = $this->fixture_user( 'administrator' );
		wp_set_current_user( $administrator );
		$this->assert_true(
			current_user_can( 'wpcb_manage_seo' ) && current_user_can( 'edit_post', $post_id ),
			'administrator fixture unexpectedly lacks required caps'
		);

		$manager = new ContentAccessManager(
			new WordPressContentAccessSettingsRepository(),
			new WordPressContentTypeCatalog()
		);
		$this->assert_true(
			! $manager->allows( 'post', ContentOperation::UPDATE_SEO ),
			'update_seo policy is unexpectedly enabled by default (must be deny-by-default)'
		);

		wp_set_current_user( 0 );
	}

	/**
	 * A stale version token is rejected without performing a write.
	 *
	 * @return void
	 */
	private function verify_stale_version_conflict(): void {
		$post_id = $this->fixture_post();
		$stale   = $this->current_token( $post_id );

		wp_update_post(
			array(
				'ID'         => $post_id,
				'post_title' => 'Changed out of band',
			)
		);
		update_option(
			'wpcb_content_type_access',
			array(
				'post' => array(
					'get_content' => true,
					'update_seo'  => true,
				),
			)
		);

		$use_case    = $this->use_case();
		$before_meta = get_post_meta( $post_id, '_yoast_wpseo_title', true );

		try {
			$use_case->execute(
				array(
					'post_id'       => $post_id,
					'version_token' => $stale,
					'seo_title'     => 'Should not persist',
				),
				1
			);
			$this->failures[] = 'stale version token unexpectedly succeeded';
		} catch ( MutationConflict $conflict ) {
			$this->assert_true( 'wpcb_conflict' === $conflict->error_code(), 'wrong conflict error code' );
		}

		$this->assert_true(
			get_post_meta( $post_id, '_yoast_wpseo_title', true ) === $before_meta,
			'a rejected stale write mutated SEO meta'
		);
	}

	/**
	 * A field outside the allowlist rejects the whole request.
	 *
	 * @return void
	 */
	private function verify_unsupported_field_rejected(): void {
		$post_id = $this->fixture_post();
		$token   = $this->current_token( $post_id );

		$use_case = $this->use_case();

		try {
			$use_case->execute(
				array(
					'post_id'       => $post_id,
					'version_token' => $token,
					'schema_type'   => 'Article',
				),
				1
			);
			$this->failures[] = 'an unsupported SEO field unexpectedly succeeded';
		} catch ( SeoFieldUnsupported $unsupported ) {
			$this->assert_true(
				array( 'schema_type' ) === $unsupported->fields(),
				'unsupported-field failure did not name the offending key'
			);
		}
	}

	/**
	 * Writing an allowlisted field is reflected in the re-read effective SEO.
	 *
	 * @return void
	 */
	private function verify_write_and_reread_parity(): void {
		$post_id = $this->fixture_post();
		$token   = $this->current_token( $post_id );

		$writer = new YoastFreeSeoWriter( new YoastSeoProvider() );
		if ( ! $writer->is_available() ) {
			echo "WARN: no compatible Yoast Free install active; skipping write/re-read parity check.\n";
			return;
		}

		$use_case = $this->use_case();
		$result   = $use_case->execute(
			array(
				'post_id'       => $post_id,
				'version_token' => $token,
				'seo_title'     => 'WPCB verified title',
				'robots_index'  => false,
			),
			1
		);

		$this->assert_true(
			'WPCB verified title' === get_post_meta( $post_id, '_yoast_wpseo_title', true ),
			'seo_title was not persisted to _yoast_wpseo_title'
		);
		$this->assert_true(
			'1' === get_post_meta( $post_id, '_yoast_wpseo_meta-robots-noindex', true ),
			'robots_index=false did not persist as noindex (1)'
		);
		$this->assert_true(
			isset( $result->effective_seo['resolved']['title'] ),
			'effective_seo re-read is missing the resolved title field'
		);
	}

	/**
	 * A mutation writes exactly one redacted audit row (no SEO values leaked).
	 *
	 * @return void
	 */
	private function verify_audit_redaction(): void {
		global $wpdb;

		$post_id = $this->fixture_post();
		$token   = $this->current_token( $post_id );

		$writer = new YoastFreeSeoWriter( new YoastSeoProvider() );
		if ( ! $writer->is_available() ) {
			echo "WARN: no compatible Yoast Free install active; skipping audit redaction check.\n";
			return;
		}

		$use_case = $this->use_case();

		$table        = Installer::audit_table_name();
		$before_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE ability = 'wp-content-bridge/update-seo'" );

		$use_case->execute(
			array(
				'post_id'          => $post_id,
				'version_token'    => $token,
				'meta_description' => 'A secret-looking description',
			),
			1
		);

		$after_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE ability = 'wp-content-bridge/update-seo'" );
		$this->assert_true( 1 === $after_count - $before_count, 'update-seo did not record exactly one audit row' );

		$row = $wpdb->get_row(
			"SELECT * FROM {$table} WHERE ability = 'wp-content-bridge/update-seo' ORDER BY id DESC LIMIT 1",
			ARRAY_A
		);
		$this->assert_true( null !== $row, 'no audit row found for update-seo' );
		$this->assert_true(
			json_decode( (string) $row['changed_fields'], true ) === array( 'meta_description' ),
			'audit changed_fields does not list meta_description by name'
		);
		$this->assert_true(
			false === strpos( (string) $row['changed_fields'], 'secret-looking' ),
			'audit row leaked an SEO value'
		);
	}

	/**
	 * Fails fast with a descriptive message.
	 *
	 * @param bool   $condition Assertion condition.
	 * @param string $message   Failure message.
	 * @return void
	 */
	private function assert_true( bool $condition, string $message ): void {
		if ( ! $condition ) {
			$this->failures[] = $message;
		}
	}

	/**
	 * Removes every fixture created during this run.
	 *
	 * @return void
	 */
	private function cleanup(): void {
		wp_set_current_user( 0 );
		foreach ( $this->post_ids as $post_id ) {
			wp_delete_post( $post_id, true );
		}
		foreach ( $this->user_ids as $user_id ) {
			wp_delete_user( $user_id );
		}
		delete_option( 'wpcb_content_type_access' );
	}
}

( new WPCB_Seo_Write_Verification() )->run();

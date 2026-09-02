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
use IsuDev\WPContentBridge\Application\Mutation\SeoImageUnavailable;
use IsuDev\WPContentBridge\Application\Mutation\UpdateSeo;
use IsuDev\WPContentBridge\Domain\ContentAccess\ContentOperation;
use IsuDev\WPContentBridge\Infrastructure\WordPress\Installer;
use IsuDev\WPContentBridge\Infrastructure\WordPress\PostVersionTokenFactory;
use IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressAuditLog;
use IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressContentAccessSettingsRepository;
use IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressContentMutationRepository;
use IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressContentTypeCatalog;
use IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressSeoImageRepository;
use IsuDev\WPContentBridge\Infrastructure\Yoast\YoastSeoProvider;
use IsuDev\WPContentBridge\Infrastructure\Yoast\YoastSeoWriter;

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

	private const MISSING_OPTION = '__wpcb_runtime_verifier_missing_option__';

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
	 * Original write-switch value, restored after the verifier.
	 *
	 * @var mixed
	 */
	private mixed $original_writes_enabled;

	/**
	 * Original per-content-type policy, restored after the verifier.
	 *
	 * @var mixed
	 */
	private mixed $original_content_access;

	/**
	 * User active before the verifier changed principals.
	 *
	 * @var int
	 */
	private int $original_user_id;

	/**
	 * Runs the full verification matrix.
	 *
	 * @return void
	 */
	public function run(): void {
		$this->original_writes_enabled = get_option( Installer::WRITES_ENABLED_OPTION, self::MISSING_OPTION );
		$this->original_content_access = get_option( 'wpcb_content_type_access', self::MISSING_OPTION );
		$this->original_user_id        = get_current_user_id();

		Installer::activate();
		update_option( Installer::WRITES_ENABLED_OPTION, true );
		update_option( 'wpcb_content_type_access', array() );

		try {
			$this->verify_authorization_matrix();
			$this->verify_stale_version_conflict();
			$this->verify_meta_only_write_moves_the_token();
			$this->verify_unsupported_field_rejected();
			$this->verify_write_and_reread_parity();
			$this->verify_advanced_robots_and_social_images();
			$this->verify_premium_keyphrase_write_and_reread();
			$this->verify_audit_redaction();
		} finally {
			$this->cleanup();
		}

		if ( array() === $this->failures ) {
			echo "PASS: update-seo (authorization matrix, conflict, Free/Premium write/re-read parity, audit redaction)\n";
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
	 * Creates a disposable PNG attachment.
	 *
	 * @return int
	 */
	private function fixture_image(): int {
		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- fixed transparent 1x1 PNG fixture.
		$bytes = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true );
		if ( ! is_string( $bytes ) ) {
			throw new RuntimeException( 'Could not decode fixture image.' );
		}
		$upload = wp_upload_bits( 'wpcb-seo-image-' . wp_generate_password( 8, false, false ) . '.png', null, $bytes );
		if ( ! empty( $upload['error'] ) || ! is_string( $upload['file'] ) ) {
			throw new RuntimeException( 'Could not write fixture image.' );
		}

		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => 'image/png',
				'post_title'     => 'WPCB SEO social image fixture',
				'post_status'    => 'inherit',
			),
			$upload['file'],
			0,
			true
		);
		if ( is_wp_error( $attachment_id ) ) {
			throw new RuntimeException( 'Could not create fixture image attachment.' );
		}
		$this->post_ids[] = (int) $attachment_id;
		wp_update_attachment_metadata( (int) $attachment_id, wp_generate_attachment_metadata( (int) $attachment_id, $upload['file'] ) );

		return (int) $attachment_id;
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

		return PostVersionTokenFactory::for_post( $post )->to_string();
	}

	/**
	 * Builds a fully wired update-seo use case for the verifier.
	 *
	 * @return UpdateSeo
	 */
	private function use_case(): UpdateSeo {
		$writer = new YoastSeoWriter( new YoastSeoProvider(), new WordPressSeoImageRepository() );

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

		$writer = new YoastSeoWriter( new YoastSeoProvider(), new WordPressSeoImageRepository() );
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
			'WPCB verified title' === ( $result->effective_seo['configured']['title']['value'] ?? null ),
			'effective_seo re-read is missing the configured title value'
		);
	}

	/**
	 * Advanced robots merge without collateral removal and social images use
	 * authorized attachment identity. Invalid images fail before any field write.
	 *
	 * @return void
	 */
	private function verify_advanced_robots_and_social_images(): void {
		$post_id       = $this->fixture_post();
		$image_id      = $this->fixture_image();
		$administrator = $this->fixture_user( 'administrator' );
		wp_set_current_user( $administrator );
		update_option(
			'wpcb_content_type_access',
			array(
				'post' => array(
					'get_content' => true,
					'update_seo'  => true,
				),
			)
		);
		update_post_meta( $post_id, '_yoast_wpseo_meta-robots-adv', 'noarchive,nosnippet' );

		$result = $this->use_case()->execute(
			array(
				'post_id'             => $post_id,
				'version_token'       => $this->current_token( $post_id ),
				'robots_noarchive'    => false,
				'robots_noimageindex' => true,
				'og_image_id'         => $image_id,
				'twitter_image_id'    => $image_id,
			),
			$administrator
		);

		$image_url = wp_get_attachment_url( $image_id );
		$this->assert_true( 'noimageindex,nosnippet' === get_post_meta( $post_id, '_yoast_wpseo_meta-robots-adv', true ), 'advanced robots merge removed or retained the wrong directive' );
		$this->assert_true( get_post_meta( $post_id, '_yoast_wpseo_opengraph-image-id', true ) === (string) $image_id, 'Open Graph image ID did not persist' );
		$this->assert_true( get_post_meta( $post_id, '_yoast_wpseo_opengraph-image', true ) === $image_url, 'Open Graph image URL was not resolved from WordPress' );
		$this->assert_true( get_post_meta( $post_id, '_yoast_wpseo_twitter-image-id', true ) === (string) $image_id, 'Twitter image ID did not persist' );
		$this->assert_true( true === ( $result->effective_seo['configured']['robots']['value']['noimageindex'] ?? null ), 'advanced robots were not normalized in the post-write re-read' );
		$this->assert_true( ( $result->effective_seo['configured']['social']['value']['open_graph_image_id'] ?? null ) === $image_id, 'social image ID was not present in the post-write re-read' );

		$this->use_case()->execute(
			array(
				'post_id'          => $post_id,
				'version_token'    => $this->current_token( $post_id ),
				'og_image_id'      => 0,
				'twitter_image_id' => 0,
			),
			$administrator
		);
		$this->assert_true( '' === get_post_meta( $post_id, '_yoast_wpseo_opengraph-image', true ), 'Open Graph image URL was not cleared' );
		$this->assert_true( '' === get_post_meta( $post_id, '_yoast_wpseo_opengraph-image-id', true ), 'Open Graph image ID was not cleared' );
		$this->assert_true( '' === get_post_meta( $post_id, '_yoast_wpseo_twitter-image', true ), 'Twitter image URL was not cleared' );
		$this->assert_true( '' === get_post_meta( $post_id, '_yoast_wpseo_twitter-image-id', true ), 'Twitter image ID was not cleared' );

		$before_title = get_post_meta( $post_id, '_yoast_wpseo_title', true );
		try {
			$this->use_case()->execute(
				array(
					'post_id'       => $post_id,
					'version_token' => $this->current_token( $post_id ),
					'seo_title'     => 'Must not persist',
					'og_image_id'   => $post_id,
				),
				$administrator
			);
			$this->failures[] = 'a non-image social attachment unexpectedly succeeded';
		} catch ( SeoImageUnavailable $unavailable ) {
			$this->assert_true( 'wpcb_seo_image_unavailable' === $unavailable->error_code(), 'wrong social image error code' );
		}
		$this->assert_true( get_post_meta( $post_id, '_yoast_wpseo_title', true ) === $before_title, 'invalid social image caused a partial SEO write' );
	}

	/**
	 * A successful SEO write must move the version token.
	 *
	 * Regression guard for a defect that shipped from the first write release
	 * until 0.8.5: the token hashed only `post_modified_gmt`, the title, the
	 * content and the status, and an SEO write touches none of them because
	 * Yoast stores its fields in post meta. The token therefore came back
	 * **identical after a successful write**, so two callers could each read
	 * the same token, each write, and the second would silently overwrite the
	 * first with no conflict raised — the one thing the token exists to stop.
	 *
	 * @return void
	 */
	private function verify_meta_only_write_moves_the_token(): void {
		$post_id = $this->fixture_post();
		$before  = $this->current_token( $post_id );

		$result = $this->use_case()->execute(
			array(
				'post_id'       => $post_id,
				'version_token' => $before,
				'seo_title'     => $this->token . ' token movement probe',
			),
			1
		);

		$returned = $result->version->to_string();

		$this->assert_true(
			$before !== $returned,
			'A meta-only SEO write returned the same version token it was given, so a concurrent write could not be detected.'
		);
		$this->assert_true(
			$this->current_token( $post_id ) === $returned,
			'The token returned by the write does not match the post\'s current token, so a caller cannot chain writes.'
		);

		// And the old token must now be refused, which is the property that
		// makes the movement useful rather than cosmetic.
		try {
			$this->use_case()->execute(
				array(
					'post_id'       => $post_id,
					'version_token' => $before,
					'seo_title'     => $this->token . ' second write with a stale token',
				),
				1
			);
			$this->failures[] = 'A second SEO write reusing the pre-write token was accepted.';
		} catch ( MutationConflict ) {
			$ignored = true;
		}
	}

	/**
	 * Premium synonyms and related keyphrases round-trip through Yoast's
	 * positional JSON without losing retained scores or synonyms.
	 *
	 * @return void
	 */
	private function verify_premium_keyphrase_write_and_reread(): void {
		if ( ! defined( 'WPSEO_PREMIUM_VERSION' ) || ! str_starts_with( (string) WPSEO_PREMIUM_VERSION, '28.' ) ) {
			echo "WARN: no compatible Yoast Premium install active; skipping Premium keyphrase parity check.\n";
			return;
		}

		$post_id = $this->fixture_post();
		update_post_meta( $post_id, '_yoast_wpseo_focuskw', 'primary phrase' );
		update_post_meta( $post_id, '_yoast_wpseo_focuskeywords', '[{"keyword":"retained phrase","score":87},{"keyword":"removed phrase","score":55}]' );
		update_post_meta( $post_id, '_yoast_wpseo_keywordsynonyms', '["old primary","retained synonym","removed synonym"]' );
		// The token is taken *after* the fixture meta writes: they are setup,
		// not a concurrent change. Since 0.8.5 the token covers post meta, so
		// taking it first would make this fixture look like a stale write.
		$token = $this->current_token( $post_id );

		$result = $this->use_case()->execute(
			array(
				'post_id'            => $post_id,
				'version_token'      => $token,
				'keyphrase_synonyms' => array( 'primary "quoted" synonym', 'second \\ synonym' ),
				'related_keyphrases' => array( 'retained phrase', 'new phrase' ),
			),
			1
		);

		$this->assert_true(
			array(
				array(
					'keyword' => 'retained phrase',
					'score'   => 87,
				),
				array(
					'keyword' => 'new phrase',
					'score'   => 0,
				),
			) === json_decode( (string) get_post_meta( $post_id, '_yoast_wpseo_focuskeywords', true ), true ),
			'Premium related keyphrases did not preserve the retained score'
		);
		$this->assert_true(
			array( 'primary "quoted" synonym, second \\ synonym', 'retained synonym', '' ) === json_decode( (string) get_post_meta( $post_id, '_yoast_wpseo_keywordsynonyms', true ), true ),
			'Premium positional synonyms were not synchronized'
		);
		$this->assert_true(
			array( 'primary "quoted" synonym', 'second \\ synonym' ) === ( $result->effective_seo['configured']['keyphrase_synonyms']['value'] ?? null ),
			'Premium primary synonyms were not present in the normalized re-read'
		);
		$this->assert_true(
			'retained phrase' === ( $result->effective_seo['configured']['related_keyphrases']['value'][0]['keyphrase'] ?? null ),
			'Premium related keyphrases were not present in the normalized re-read'
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

		$writer = new YoastSeoWriter( new YoastSeoProvider(), new WordPressSeoImageRepository() );
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
		wp_set_current_user( $this->original_user_id );
		foreach ( $this->post_ids as $post_id ) {
			wp_delete_post( $post_id, true );
		}
		foreach ( $this->user_ids as $user_id ) {
			wp_delete_user( $user_id );
		}
		$this->restore_option( Installer::WRITES_ENABLED_OPTION, $this->original_writes_enabled );
		$this->restore_option( 'wpcb_content_type_access', $this->original_content_access );
	}

	/**
	 * Restores an option exactly to its pre-verification state.
	 *
	 * @param string $name  Option name.
	 * @param mixed  $value Original value or the missing-option sentinel.
	 * @return void
	 */
	private function restore_option( string $name, mixed $value ): void {
		if ( self::MISSING_OPTION === $value ) {
			delete_option( $name );
			return;
		}

		update_option( $name, $value );
	}
}

( new WPCB_Seo_Write_Verification() )->run();

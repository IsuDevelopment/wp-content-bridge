<?php
/**
 * Runtime write verifier: authorization matrix + write invariants.
 *
 * Run: wp eval 'require "/absolute/path/to/wp-content-bridge/tests/Integration/writes-mutation-verification.php";'
 *
 * @package IsuDev\WPContentBridge\Tests
 */

declare(strict_types=1);

use IsuDev\WPContentBridge\Adapter\Abilities\MutationAbilities;
use IsuDev\WPContentBridge\Application\ContentAccess\ContentAccessManager;
use IsuDev\WPContentBridge\Application\Mutation\CreateDraft;
use IsuDev\WPContentBridge\Application\Mutation\PreviewContentUpdate;
use IsuDev\WPContentBridge\Application\Mutation\PreviewSeoUpdate;
use IsuDev\WPContentBridge\Application\Mutation\UpdateContent;
use IsuDev\WPContentBridge\Application\Mutation\UpdateSeo;
use IsuDev\WPContentBridge\Application\Seo\NullSeoProvider;
use IsuDev\WPContentBridge\Application\Seo\SeoProviderRegistry;
use IsuDev\WPContentBridge\Infrastructure\WordPress\Installer;
use IsuDev\WPContentBridge\Infrastructure\WordPress\PhpBlockMarkupValidator;
use IsuDev\WPContentBridge\Infrastructure\WordPress\PostVersionTokenFactory;
use IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressAuditLog;
use IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressContentAccessSettingsRepository;
use IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressContentMutationRepository;
use IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressContentTypeCatalog;
use IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressSeoImageRepository;
use IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressTransientIdempotencyStore;
use IsuDev\WPContentBridge\Infrastructure\Yoast\YoastSeoWriter;

// phpcs:disable Squiz.Commenting.FunctionCommentThrowTag.Missing -- assertion helpers intentionally fail the runtime harness fast.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception messages are CLI diagnostics, not rendered HTML.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery -- one-off reads/writes against the dedicated audit table in a CLI verifier.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off CLI verifier queries; caching would be pointless here.
// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- exit code + STDOUT JSON for a CLI verifier, not rendered HTML.
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI diagnostic output, not a filesystem operation.

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Must run inside WordPress via wp eval.\n" );
	exit( 1 );
}

Installer::activate();

/**
 * Creates isolated fixtures, exercises the write authorization matrix and
 * invariants, and removes exact fixtures.
 */
final class WPCB_Mutation_Verification {

	private const DISALLOWED_STATUSES = array( 'publish', 'future', 'pending' );

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
	 * Original per-type policy option, restored after the run.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $original_policy;

	/**
	 * Original writes-enabled flag, restored after the run.
	 *
	 * @var bool
	 */
	private bool $original_writes_enabled;

	/**
	 * Highest audit row id that existed before this run.
	 *
	 * @var int
	 */
	private int $audit_baseline_id = 0;

	/**
	 * Unique fixture marker.
	 *
	 * @var string
	 */
	private string $token = '';

	/**
	 * Ephemeral fixture post type.
	 *
	 * @var string
	 */
	private string $fixture_post_type = '';

	/**
	 * Create-draft use case wired with real infrastructure.
	 *
	 * @var CreateDraft
	 */
	private CreateDraft $create_use_case;

	/**
	 * Update-content use case wired with real infrastructure.
	 *
	 * @var UpdateContent
	 */
	private UpdateContent $update_use_case;

	/**
	 * SEO write use case required by the MutationAbilities adapter.
	 *
	 * @var UpdateSeo
	 */
	private UpdateSeo $update_seo_use_case;

	/**
	 * Preview-update-content use case required by the MutationAbilities adapter.
	 *
	 * @var PreviewContentUpdate
	 */
	private PreviewContentUpdate $preview_content_use_case;

	/**
	 * Preview-update-seo use case required by the MutationAbilities adapter.
	 *
	 * @var PreviewSeoUpdate
	 */
	private PreviewSeoUpdate $preview_seo_use_case;

	/**
	 * Create-draft ability, resolved once registration is proven.
	 *
	 * @var WP_Ability
	 */
	private WP_Ability $create_ability;

	/**
	 * Update-content ability, resolved once registration is proven.
	 *
	 * @var WP_Ability
	 */
	private WP_Ability $update_ability;

	/**
	 * Read-side get-content ability, used for the block round-trip check.
	 *
	 * @var WP_Ability
	 */
	private WP_Ability $get_ability;

	/**
	 * Runs the complete verifier.
	 *
	 * @return void
	 */
	public function run(): void {
		$this->original_writes_enabled = (bool) get_option( Installer::WRITES_ENABLED_OPTION, false );
		$stored_policy                 = get_option( WordPressContentAccessSettingsRepository::OPTION_NAME, null );
		$this->original_policy         = is_array( $stored_policy ) ? $stored_policy : null;
		$this->token                   = 'wpcbmut' . strtolower( wp_generate_password( 8, false, false ) );
		$this->fixture_post_type       = 'wpcbmfx' . strtolower( wp_generate_password( 6, false, false ) );

		global $wpdb;
		/**
		 * WordPress database abstraction object.
		 *
		 * @var \wpdb $wpdb
		 */
		$this->audit_baseline_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(MAX(id), 0) FROM %i', Installer::audit_table_name() ) );

		try {
			$this->register_fixture_post_type();
			$this->build_use_cases();
			$this->resolve_get_ability();
			$this->verify_flag_gates_registration();

			$principals = $this->create_principals();
			$posts      = $this->create_matrix_posts( $principals );
			$this->enable_fixture_policies();

			$this->verify_authorization_matrix( $principals, $posts );
			$this->verify_create_happy_path_and_audit_redaction();
			$this->verify_update_revision_and_no_publish();
			$this->verify_stale_version_conflict();
			$this->verify_block_round_trip();
			$this->verify_idempotent_create();
			$this->verify_recursive_block_validation();
		} finally {
			$this->cleanup();
		}
	}

	/**
	 * Registers a public CPT that uses post capabilities and keeps revisions.
	 *
	 * @return void
	 */
	private function register_fixture_post_type(): void {
		register_post_type(
			$this->fixture_post_type,
			array(
				'label'           => 'WPCB Mutation Fixture',
				'public'          => true,
				'show_ui'         => true,
				'show_in_rest'    => true,
				'capability_type' => 'post',
				'map_meta_cap'    => true,
				'supports'        => array( 'title', 'editor', 'author', 'revisions' ),
			)
		);
	}

	/**
	 * Builds the write use cases from real infrastructure adapters, mirroring
	 * Plugin::boot()'s wiring when the writes-enabled flag is on.
	 *
	 * @return void
	 */
	private function build_use_cases(): void {
		$manager     = new ContentAccessManager( new WordPressContentAccessSettingsRepository(), new WordPressContentTypeCatalog() );
		$validator   = new PhpBlockMarkupValidator();
		$repository  = new WordPressContentMutationRepository();
		$idempotency = new WordPressTransientIdempotencyStore();
		$audit       = new WordPressAuditLog();

		$this->create_use_case = new CreateDraft( $manager, $validator, $repository, $idempotency, $audit );
		$this->update_use_case = new UpdateContent( $manager, $validator, $repository, $audit );

		$seo_writer = new YoastSeoWriter(
			( new SeoProviderRegistry( array(), new NullSeoProvider() ) )->active(),
			new WordPressSeoImageRepository()
		);

		// MutationAbilities also owns update-seo. This verifier does not exercise
		// it (writes-seo-verification.php does), but the adapter requires it.
		$this->update_seo_use_case = new UpdateSeo( $manager, $repository, $seo_writer, $audit );

		// MutationAbilities also owns both previews. This verifier does not
		// exercise them (preview-verification.php does), but the adapter
		// requires them.
		$this->preview_content_use_case = new PreviewContentUpdate( $manager, $validator, $repository, $repository );
		$this->preview_seo_use_case     = new PreviewSeoUpdate( $manager, $repository, $seo_writer );
	}

	/**
	 * Resolves the always-registered read ability used for the round-trip check.
	 *
	 * @return void
	 */
	private function resolve_get_ability(): void {
		$get = wp_get_ability( 'wp-content-bridge/get-content' );
		if ( ! $get instanceof WP_Ability ) {
			throw new RuntimeException( 'Required wp-content-bridge/get-content ability is not registered.' );
		}

		$this->get_ability = $get;
	}

	/**
	 * Check 1: proves MutationAbilities::register_abilities() correctly
	 * registers/unregisters the write abilities when driven directly.
	 *
	 * SCOPE: this proves the registration *mechanism* only. It does NOT
	 * re-exercise Plugin::boot()'s own `if ( get_option( Installer::WRITES_ENABLED_OPTION ) )`
	 * gate in src/Plugin.php. A fresh request is required to observe that
	 * boot-time conditional (Plugin::boot() is guarded by a static "booted"
	 * flag and cannot be re-run mid-process), so this instead drives the
	 * exact same MutationAbilities::register_abilities() production code
	 * directly: first proving absence with the abilities unregistered (the
	 * off state), then proving presence by invoking that same registration
	 * method with a fresh instance bound to the flag-on branch's real
	 * dependencies (the on state). A regression in Plugin.php's own
	 * flag-gating `if` statement would NOT be caught by this check.
	 *
	 * Registration is invoked directly (not via do_action('wp_abilities_api_init'))
	 * because that hook has already fired once during this request's real boot;
	 * re-firing it would also re-invoke every other plugin's registration
	 * callback already attached to it. Instead, the 'wp_abilities_api_init'
	 * name is pushed onto the current-filter stack for the duration of the
	 * direct call, satisfying wp_register_ability()'s doing_action() guard
	 * without disturbing any other listener.
	 *
	 * @return void
	 */
	private function verify_flag_gates_registration(): void {
		if ( wp_has_ability( CreateDraft::ABILITY ) ) {
			wp_unregister_ability( CreateDraft::ABILITY );
		}
		if ( wp_has_ability( UpdateContent::ABILITY ) ) {
			wp_unregister_ability( UpdateContent::ABILITY );
		}
		if ( wp_has_ability( UpdateSeo::ABILITY ) ) {
			wp_unregister_ability( UpdateSeo::ABILITY );
		}

		update_option( Installer::WRITES_ENABLED_OPTION, false, false );
		$this->assert_true( ! wp_has_ability( CreateDraft::ABILITY ), 'create-draft ability is registered while wpcb_writes_enabled is off.' );
		$this->assert_true( ! wp_has_ability( UpdateContent::ABILITY ), 'update-content ability is registered while wpcb_writes_enabled is off.' );

		update_option( Installer::WRITES_ENABLED_OPTION, true, false );
		$mutation_abilities = new MutationAbilities(
			$this->create_use_case,
			$this->update_use_case,
			$this->update_seo_use_case,
			$this->preview_content_use_case,
			$this->preview_seo_use_case
		);

		global $wp_current_filter;
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- CLI verifier scopes doing_action() to this call only, restored immediately below.
		$wp_current_filter[] = 'wp_abilities_api_init';
		try {
			$mutation_abilities->register_abilities();
		} finally {
			array_pop( $wp_current_filter );
		}

		$create = wp_get_ability( CreateDraft::ABILITY );
		$update = wp_get_ability( UpdateContent::ABILITY );
		$this->assert_true( $create instanceof WP_Ability, 'create-draft ability did not register while wpcb_writes_enabled is on.' );
		$this->assert_true( $update instanceof WP_Ability, 'update-content ability did not register while wpcb_writes_enabled is on.' );

		$this->create_ability = $create;
		$this->update_ability = $update;
	}

	/**
	 * Creates users for every authorization role.
	 *
	 * @return array<string, int>
	 */
	private function create_principals(): array {
		$administrators = get_users(
			array(
				'role'   => 'administrator',
				'number' => 1,
				'fields' => 'ids',
			)
		);
		if ( array() === $administrators || ! is_numeric( $administrators[0] ) ) {
			throw new RuntimeException( 'An administrator fixture is required.' );
		}
		$administrator_id = (int) $administrators[0];
		$this->assert_true( user_can( $administrator_id, 'wpcb_edit_content' ), 'Administrator lacks wpcb_edit_content.' );

		return array(
			'admin'         => $administrator_id,
			'subscriber'    => $this->create_user( 'subscriber', false ),
			'author_no_cap' => $this->create_user( 'author', false ),
			'author_a'      => $this->create_user( 'author', true ),
			'author_b'      => $this->create_user( 'author', true ),
			'editor'        => $this->create_user( 'editor', true ),
		);
	}

	/**
	 * Creates one temporary user.
	 *
	 * @param string $role           WordPress role.
	 * @param bool   $grant_wpcb_cap Whether to grant the plugin's write capability.
	 * @return int
	 */
	private function create_user( string $role, bool $grant_wpcb_cap ): int {
		$login   = $this->token . '_' . $role . '_' . count( $this->user_ids );
		$user_id = wp_insert_user(
			array(
				'user_login' => $login,
				'user_pass'  => wp_generate_password( 32, true, true ),
				'user_email' => $login . '@example.invalid',
				'role'       => $role,
			)
		);
		if ( is_wp_error( $user_id ) ) {
			throw new RuntimeException( 'Could not create mutation fixture user: ' . $user_id->get_error_message() );
		}

		$this->user_ids[] = $user_id;
		if ( $grant_wpcb_cap ) {
			$user = get_user_by( 'id', $user_id );
			if ( ! $user instanceof WP_User ) {
				throw new RuntimeException( 'Could not resolve mutation fixture user.' );
			}
			$user->add_cap( 'wpcb_edit_content' );
		}

		return $user_id;
	}

	/**
	 * Creates the fixture posts driving the authorization matrix.
	 *
	 * @param array<string, int> $principals Principal IDs.
	 * @return array<string, int>
	 */
	private function create_matrix_posts( array $principals ): array {
		$content = '<!-- wp:paragraph --><p>' . $this->token . ' matrix</p><!-- /wp:paragraph -->';

		return array(
			'no_cap'   => $this->create_post( $principals['author_no_cap'], $content ),
			'author_a' => $this->create_post( $principals['author_a'], $content ),
			'author_b' => $this->create_post( $principals['author_b'], $content ),
		);
	}

	/**
	 * Creates one exact fixture post of the fixture type.
	 *
	 * @param int    $author_id Author ID.
	 * @param string $content   Block content.
	 * @return int
	 */
	private function create_post( int $author_id, string $content ): int {
		$post_id = wp_insert_post(
			array(
				'post_type'    => $this->fixture_post_type,
				'post_status'  => 'draft',
				'post_author'  => $author_id,
				'post_title'   => $this->token . ' fixture ' . count( $this->post_ids ),
				'post_content' => $content,
			),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			throw new RuntimeException( 'Could not create mutation fixture post: ' . $post_id->get_error_message() );
		}

		$this->post_ids[] = $post_id;

		return $post_id;
	}

	/**
	 * Enables create/update policy (with its read prerequisite) for the fixture type.
	 *
	 * @return void
	 */
	private function enable_fixture_policies(): void {
		update_option(
			WordPressContentAccessSettingsRepository::OPTION_NAME,
			array(
				$this->fixture_post_type => array(
					'get_content'    => true,
					'search_content' => true,
					'create_draft'   => true,
					'update_content' => true,
				),
			),
			false
		);
	}

	/**
	 * Disables create/update policy for the fixture type while keeping read enabled.
	 *
	 * @return void
	 */
	private function disable_fixture_write_policies(): void {
		update_option(
			WordPressContentAccessSettingsRepository::OPTION_NAME,
			array(
				$this->fixture_post_type => array(
					'get_content'    => true,
					'search_content' => true,
					'create_draft'   => false,
					'update_content' => false,
				),
			),
			false
		);
	}

	/**
	 * Check 2: proves plugin cap, native cap, and policy are each an
	 * independent gate for both create-draft and update-content.
	 *
	 * @param array<string, int> $principals Principal IDs.
	 * @param array<string, int> $posts      Fixture post IDs.
	 * @return void
	 */
	private function verify_authorization_matrix( array $principals, array $posts ): void {
		$create_input = array( 'post_type' => $this->fixture_post_type );

		wp_set_current_user( 0 );
		$this->assert_false( $this->create_ability->check_permissions( $create_input ), 'Anonymous create permission was granted.' );
		$this->assert_false( $this->update_ability->check_permissions( array( 'post_id' => $posts['author_a'] ) ), 'Anonymous update permission was granted.' );

		wp_set_current_user( $principals['subscriber'] );
		$this->assert_false( $this->create_ability->check_permissions( $create_input ), 'Subscriber create permission was granted.' );
		$this->assert_false( $this->update_ability->check_permissions( array( 'post_id' => $posts['author_a'] ) ), 'Subscriber update permission was granted.' );

		// Plugin cap gate: native "author" role can edit_posts, but lacks wpcb_edit_content.
		wp_set_current_user( $principals['author_no_cap'] );
		$this->assert_false( $this->create_ability->check_permissions( $create_input ), 'Create permission was granted without the plugin capability.' );
		$this->assert_false( $this->update_ability->check_permissions( array( 'post_id' => $posts['no_cap'] ) ), 'Update permission on an own post was granted without the plugin capability.' );

		// Native cap gate: author has the plugin cap, but lacks edit_others_posts.
		wp_set_current_user( $principals['author_a'] );
		$this->assert_true( $this->create_ability->check_permissions( $create_input ), 'Author with the plugin capability was denied create permission.' );
		$this->assert_true( $this->update_ability->check_permissions( array( 'post_id' => $posts['author_a'] ) ), 'Author was denied update permission on their own post.' );
		$this->assert_false( $this->update_ability->check_permissions( array( 'post_id' => $posts['author_b'] ) ), 'Author was granted update permission on a foreign post.' );

		wp_set_current_user( $principals['editor'] );
		$this->assert_true( $this->create_ability->check_permissions( $create_input ), 'Editor was denied create permission.' );
		$this->assert_true( $this->update_ability->check_permissions( array( 'post_id' => $posts['author_b'] ) ), 'Editor was denied update permission on a foreign post.' );

		wp_set_current_user( $principals['admin'] );
		$this->assert_true( $this->create_ability->check_permissions( $create_input ), 'Administrator was denied create permission.' );
		$this->assert_true( $this->update_ability->check_permissions( array( 'post_id' => $posts['author_b'] ) ), 'Administrator was denied update permission on a foreign post.' );

		// Policy gate: caps satisfied, but the type's write policy is off.
		$policy_target = get_post( $posts['author_a'] );
		$version       = $policy_target instanceof WP_Post
			? PostVersionTokenFactory::for_post( $policy_target )->to_string()
			: '';

		$this->disable_fixture_write_policies();
		$denied_create = $this->create_ability->execute(
			array(
				'post_type' => $this->fixture_post_type,
				'title'     => $this->token . ' policy-denied',
			)
		);
		$this->assert_true( is_wp_error( $denied_create ) && 'wpcb_forbidden' === $denied_create->get_error_code(), 'Create was not denied while the type policy is disabled.' );

		$denied_update = $this->update_ability->execute(
			array(
				'post_id'       => $posts['author_a'],
				'version_token' => $version,
				'title'         => $this->token . ' policy-denied',
			)
		);
		$this->assert_true( is_wp_error( $denied_update ) && 'wpcb_forbidden' === $denied_update->get_error_code(), 'Update was not denied while the type policy is disabled.' );

		$this->enable_fixture_policies();
	}

	/**
	 * Checks 3, 4 (create side), and 9: happy-path create, its no-publish
	 * invariant, and the audit row's redaction of title/content/excerpt values.
	 *
	 * @return void
	 */
	private function verify_create_happy_path_and_audit_redaction(): void {
		$secret_title   = $this->token . '-secret-title';
		$secret_body    = $this->token . '-secret-body';
		$secret_excerpt = $this->token . '-secret-excerpt';
		$markup         = "<!-- wp:paragraph -->\n<p>" . $secret_body . "</p>\n<!-- /wp:paragraph -->";

		wp_set_current_user( $this->id_of_role( 'administrator' ) );
		$result = $this->create_ability->execute(
			array(
				'post_type'    => $this->fixture_post_type,
				'title'        => $secret_title,
				'block_markup' => $markup,
				'excerpt'      => $secret_excerpt,
			)
		);
		$this->assert_not_error( $result, 'Create happy path.' );
		if ( ! is_array( $result ) ) {
			throw new RuntimeException( 'Create happy path result is not an array.' );
		}

		$this->assert_true( 'draft' === $result['status'], 'Create result status was not draft.' );
		$this->assert_true( true === $result['created'], 'Create result created flag was not true.' );
		$this->assert_true( is_string( $result['version_token'] ) && '' !== $result['version_token'], 'Create result is missing a version token.' );

		$post_id          = (int) $result['post_id'];
		$this->post_ids[] = $post_id;

		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			throw new RuntimeException( 'The created draft could not be re-read.' );
		}
		$this->assert_true( 'draft' === $post->post_status, 'The stored post status was not draft.' );
		$this->assert_true( ! in_array( $post->post_status, self::DISALLOWED_STATUSES, true ), 'Create violated the no-publish invariant.' );

		global $wpdb;
		/**
		 * WordPress database abstraction object.
		 *
		 * @var \wpdb $wpdb
		 */
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC LIMIT 1', Installer::audit_table_name() ), ARRAY_A );
		if ( ! is_array( $row ) ) {
			throw new RuntimeException( 'No audit row was recorded for the create attempt.' );
		}

		$this->assert_true( CreateDraft::ABILITY === $row['ability'], 'Audit row ability mismatch.' );
		$this->assert_true( $post_id === (int) $row['object_id'], 'Audit row object_id mismatch.' );
		$this->assert_true( 'success' === $row['outcome'], 'Audit row outcome was not success.' );

		$changed_fields = json_decode( (string) $row['changed_fields'], true );
		$this->assert_true(
			is_array( $changed_fields ) && array() !== $changed_fields && array() === array_diff( $changed_fields, array( 'title', 'content', 'excerpt', 'taxonomies' ) ),
			'Audit changed_fields was not a name list.'
		);

		foreach ( $row as $column => $value ) {
			if ( ! is_string( $value ) ) {
				continue;
			}
			$this->assert_true(
				! str_contains( $value, $secret_title ) && ! str_contains( $value, $secret_body ) && ! str_contains( $value, $secret_excerpt ),
				"Audit column {$column} leaked a redacted value."
			);
		}
	}

	/**
	 * Checks 4 (update side) and 6: a successful update never changes post
	 * status away from draft, and creates a new revision.
	 *
	 * @return void
	 */
	private function verify_update_revision_and_no_publish(): void {
		$admin_id = $this->id_of_role( 'administrator' );
		$post_id  = $this->create_post( $admin_id, '<!-- wp:paragraph --><p>' . $this->token . ' before update</p><!-- /wp:paragraph -->' );

		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			throw new RuntimeException( 'The update-target fixture post could not be read.' );
		}
		$token0 = PostVersionTokenFactory::for_post( $post )->to_string();

		$revisions_before = count( wp_get_post_revisions( $post_id ) );

		wp_set_current_user( $admin_id );
		$new_markup = "<!-- wp:paragraph -->\n<p>" . $this->token . " after update</p>\n<!-- /wp:paragraph -->";
		$new_title  = $this->token . ' updated title';
		$result     = $this->update_ability->execute(
			array(
				'post_id'       => $post_id,
				'version_token' => $token0,
				'title'         => $new_title,
				'block_markup'  => $new_markup,
			)
		);
		$this->assert_not_error( $result, 'Update happy path.' );
		if ( ! is_array( $result ) ) {
			throw new RuntimeException( 'Update happy path result is not an array.' );
		}

		$this->assert_true( false === $result['created'], 'Update result created flag was not false.' );
		$this->assert_true( ! in_array( $result['status'], self::DISALLOWED_STATUSES, true ), 'Update result violated the no-publish invariant.' );

		$post_after = get_post( $post_id );
		if ( ! $post_after instanceof WP_Post ) {
			throw new RuntimeException( 'The updated post could not be re-read.' );
		}
		$this->assert_true( 'draft' === $post_after->post_status, 'Update changed the stored post status away from draft.' );
		$this->assert_true( $new_title === $post_after->post_title, 'Update did not apply the new title.' );
		$this->assert_true( $new_markup === $post_after->post_content, 'Update did not apply the new content.' );

		$revisions_after = count( wp_get_post_revisions( $post_id ) );
		$this->assert_true( $revisions_after > $revisions_before, 'A successful update did not create a new revision.' );
	}

	/**
	 * Check 5: a stale version token is rejected, and the out-of-band edit
	 * that made it stale is left untouched by the rejected attempt.
	 *
	 * @return void
	 */
	private function verify_stale_version_conflict(): void {
		$admin_id = $this->id_of_role( 'administrator' );
		$post_id  = $this->create_post( $admin_id, '<!-- wp:paragraph --><p>' . $this->token . ' original</p><!-- /wp:paragraph -->' );

		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			throw new RuntimeException( 'The conflict-target fixture post could not be read.' );
		}
		$stale_token = PostVersionTokenFactory::for_post( $post )->to_string();

		$out_of_band_title   = $this->token . ' out-of-band title';
		$out_of_band_content = '<!-- wp:paragraph --><p>' . $this->token . ' out-of-band content</p><!-- /wp:paragraph -->';
		$out_of_band         = wp_update_post(
			array(
				'ID'           => $post_id,
				'post_title'   => $out_of_band_title,
				'post_content' => $out_of_band_content,
			),
			true
		);
		$this->assert_true( ! is_wp_error( $out_of_band ), 'The out-of-band fixture edit failed.' );

		wp_set_current_user( $admin_id );
		$result = $this->update_ability->execute(
			array(
				'post_id'       => $post_id,
				'version_token' => $stale_token,
				'title'         => $this->token . ' stale-attempt',
			)
		);
		$this->assert_true( is_wp_error( $result ) && 'wpcb_conflict' === $result->get_error_code(), 'A stale version token was not rejected as a conflict.' );

		$post_after = get_post( $post_id );
		if ( ! $post_after instanceof WP_Post ) {
			throw new RuntimeException( 'The conflict-target post could not be re-read.' );
		}
		$this->assert_true(
			$out_of_band_title === $post_after->post_title && $out_of_band_content === $post_after->post_content,
			'The rejected stale update altered the out-of-band edit.'
		);
	}

	/**
	 * Check 7: valid block markup survives a create + get-content round trip,
	 * and an unregistered block is rejected at create time.
	 *
	 * @return void
	 */
	private function verify_block_round_trip(): void {
		$admin_id = $this->id_of_role( 'administrator' );
		wp_set_current_user( $admin_id );

		$valid_markup = "<!-- wp:paragraph -->\n<p>" . $this->token . " round trip.</p>\n<!-- /wp:paragraph -->";
		$created      = $this->create_ability->execute(
			array(
				'post_type'    => $this->fixture_post_type,
				'title'        => $this->token . ' block round trip',
				'block_markup' => $valid_markup,
			)
		);
		$this->assert_not_error( $created, 'Block round-trip create.' );
		if ( ! is_array( $created ) ) {
			throw new RuntimeException( 'Block round-trip create result is not an array.' );
		}

		$post_id          = (int) $created['post_id'];
		$this->post_ids[] = $post_id;

		$read = $this->get_ability->execute(
			array(
				'post_id'         => $post_id,
				'representations' => array( 'raw' ),
			)
		);
		$this->assert_not_error( $read, 'Block round-trip read.' );
		if ( ! is_array( $read ) ) {
			throw new RuntimeException( 'Block round-trip read result is not an array.' );
		}

		$raw = $read['representations']['raw'] ?? null;
		$this->assert_true( is_string( $raw ), 'Block round-trip raw representation is missing.' );

		$names_before = $this->block_names( parse_blocks( $valid_markup ) );
		$names_after  = $this->block_names( parse_blocks( (string) $raw ) );
		$this->assert_true( array( 'core/paragraph' ) === $names_before, 'Block round-trip fixture markup did not parse as expected.' );
		$this->assert_true( $names_before === $names_after, 'The block sequence did not survive the round trip.' );

		$invalid = $this->create_ability->execute(
			array(
				'post_type'    => $this->fixture_post_type,
				'title'        => $this->token . ' invalid block',
				'block_markup' => '<!-- wp:acme/nope /-->',
			)
		);
		$this->assert_true( is_wp_error( $invalid ) && 'wpcb_invalid_blocks' === $invalid->get_error_code(), 'Unregistered block markup was not rejected.' );
	}

	/**
	 * Extracts the top-level block-name sequence.
	 *
	 * @param array $blocks Parsed blocks.
	 * @phpstan-param array<int, array{blockName: string|null}> $blocks
	 * @return list<string|null>
	 */
	private function block_names( array $blocks ): array {
		return array_values(
			array_map(
				static fn ( array $block ): ?string => $block['blockName'],
				$blocks
			)
		);
	}

	/**
	 * Check 8: two create-draft calls with the same idempotency key produce
	 * exactly one post, and the replay reports created = false.
	 *
	 * @return void
	 */
	private function verify_idempotent_create(): void {
		$admin_id = $this->id_of_role( 'administrator' );
		wp_set_current_user( $admin_id );

		$key   = 'wpcbmut-idem-' . strtolower( wp_generate_password( 10, false, false ) );
		$title = $this->token . ' idempotent create';
		$input = array(
			'post_type'       => $this->fixture_post_type,
			'title'           => $title,
			'block_markup'    => '<!-- wp:paragraph --><p>' . $this->token . ' idempotent body</p><!-- /wp:paragraph -->',
			'idempotency_key' => $key,
		);

		$first = $this->create_ability->execute( $input );
		$this->assert_not_error( $first, 'Idempotent create, first call.' );
		if ( ! is_array( $first ) ) {
			throw new RuntimeException( 'Idempotent create first result is not an array.' );
		}
		$this->assert_true( true === $first['created'], 'The first idempotent create call did not report created = true.' );

		$first_post_id    = (int) $first['post_id'];
		$this->post_ids[] = $first_post_id;

		$second = $this->create_ability->execute( $input );
		$this->assert_not_error( $second, 'Idempotent create, second call.' );
		if ( ! is_array( $second ) ) {
			throw new RuntimeException( 'Idempotent create second result is not an array.' );
		}
		$this->assert_true( $first_post_id === (int) $second['post_id'], 'The replayed idempotent create returned a different post ID.' );
		$this->assert_true( false === $second['created'], 'The replayed idempotent create did not report created = false.' );

		$query    = new WP_Query(
			array(
				'post_type'      => $this->fixture_post_type,
				'post_status'    => 'draft',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);
		$matching = array_filter(
			$query->posts,
			static function ( $candidate_id ) use ( $title ): bool {
				$candidate = get_post( (int) $candidate_id );
				return $candidate instanceof WP_Post && $title === $candidate->post_title;
			}
		);
		$this->assert_true( 1 === count( $matching ), 'The idempotent create key resulted in more than one post.' );
	}

	/**
	 * Check 9: PhpBlockMarkupValidator walks innerBlocks recursively (task 1
	 * of the block-edits slice). An unregistered block nested two levels
	 * deep is rejected with its full tree path, not just a top-level index,
	 * and an otherwise-identical validly nested tree is accepted.
	 *
	 * @return void
	 */
	private function verify_recursive_block_validation(): void {
		$validator = new PhpBlockMarkupValidator();

		$valid_nested = '<!-- wp:group --><!-- wp:group --><!-- wp:paragraph --><p>'
			. $this->token . ' nested.</p><!-- /wp:paragraph --><!-- /wp:group --><!-- /wp:group -->';
		$this->assert_true( array() === $validator->validate( $valid_nested ), 'A validly nested block tree was rejected.' );

		$invalid_nested = '<!-- wp:group --><!-- wp:group --><!-- wp:acme/nope /--><!-- /wp:group --><!-- /wp:group -->';
		$reasons        = $validator->validate( $invalid_nested );
		$this->assert_true( 1 === count( $reasons ), 'An unregistered nested block did not produce exactly one reason.' );
		$this->assert_true(
			1 === preg_match( '/^Block \[\d+,\d+,\d+\]: unregistered block type\.$/', $reasons[0] ?? '' ),
			'An unregistered block nested two levels deep was not reported with its full tree path: ' . ( $reasons[0] ?? '(none)' )
		);
	}

	/**
	 * Resolves the first user ID for a role.
	 *
	 * @param string $role WordPress role.
	 * @return int
	 */
	private function id_of_role( string $role ): int {
		$users = get_users(
			array(
				'role'   => $role,
				'number' => 1,
				'fields' => 'ids',
			)
		);
		if ( array() === $users || ! is_numeric( $users[0] ) ) {
			throw new RuntimeException( "No {$role} fixture is available." );
		}

		return (int) $users[0];
	}

	/**
	 * Asserts a true value.
	 *
	 * @param mixed  $value   Value to check.
	 * @param string $message Failure message.
	 * @return void
	 */
	private function assert_true( mixed $value, string $message ): void {
		if ( true !== $value ) {
			throw new RuntimeException( $message );
		}
	}

	/**
	 * Asserts a denied permission result.
	 *
	 * @param mixed  $value   Value to check.
	 * @param string $message Failure message.
	 * @return void
	 */
	private function assert_false( mixed $value, string $message ): void {
		if ( false !== $value && ! is_wp_error( $value ) ) {
			throw new RuntimeException( $message );
		}
	}

	/**
	 * Asserts an ability result is not an error.
	 *
	 * @param mixed  $value Value to check.
	 * @param string $label Assertion label.
	 * @return void
	 */
	private function assert_not_error( mixed $value, string $label ): void {
		if ( $value instanceof WP_Error ) {
			throw new RuntimeException( $label . ' ' . $value->get_error_code() . ': ' . $value->get_error_message() );
		}
	}

	/**
	 * Check 10: removes exact fixture IDs, unregisters the fixture type,
	 * restores prior options, and prunes fixture-created audit rows.
	 *
	 * @return void
	 */
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

		foreach ( $this->post_ids as $post_id ) {
			wp_delete_post( $post_id, true );
		}

		require_once ABSPATH . 'wp-admin/includes/user.php';
		foreach ( $this->user_ids as $user_id ) {
			wp_delete_user( $user_id );
		}

		if ( null === $this->original_policy ) {
			delete_option( WordPressContentAccessSettingsRepository::OPTION_NAME );
		} else {
			update_option( WordPressContentAccessSettingsRepository::OPTION_NAME, $this->original_policy, false );
		}
		update_option( Installer::WRITES_ENABLED_OPTION, $this->original_writes_enabled, false );

		if ( wp_has_ability( CreateDraft::ABILITY ) ) {
			wp_unregister_ability( CreateDraft::ABILITY );
		}
		if ( wp_has_ability( UpdateContent::ABILITY ) ) {
			wp_unregister_ability( UpdateContent::ABILITY );
		}

		if ( '' !== $this->fixture_post_type && post_type_exists( $this->fixture_post_type ) ) {
			unregister_post_type( $this->fixture_post_type );
		}

		global $wpdb;
		/**
		 * WordPress database abstraction object.
		 *
		 * @var \wpdb $wpdb
		 */
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id > %d', Installer::audit_table_name(), $this->audit_baseline_id ) );
	}
}

$failures = array();

try {
	( new WPCB_Mutation_Verification() )->run();
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

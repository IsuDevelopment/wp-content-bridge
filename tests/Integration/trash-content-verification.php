<?php
/**
 * Runtime verification for reversible content trash.
 *
 * Run: wp eval 'require "<abs path>/tests/Integration/trash-content-verification.php";'
 *
 * @package IsuDev\WPContentBridgeTests
 */

declare(strict_types=1);

use IsuDev\WPContentBridge\Adapter\Abilities\TrashAbilities;
use IsuDev\WPContentBridge\Application\ContentAccess\ContentAccessManager;
use IsuDev\WPContentBridge\Application\Mutation\TrashContent;
use IsuDev\WPContentBridge\Infrastructure\WordPress\Installer;
use IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressAuditLog;
use IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressContentAccessSettingsRepository;
use IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressContentTrashRepository;
use IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressContentTypeCatalog;

// phpcs:disable Squiz.Commenting.FunctionCommentThrowTag.Missing -- assertion helpers intentionally fail the runtime harness fast.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery -- isolated CLI verifier reads and prunes the dedicated audit table.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching -- caching one-off verifier queries would be pointless.
// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON is emitted to CLI only.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception messages are CLI diagnostics.
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI diagnostic output, not a filesystem write.

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Must run inside WordPress via wp eval.\n" );
	exit( 1 );
}

Installer::activate();

/**
 * Exercises trash registration, authorization, concurrency, policy, and audit.
 */
final class WPCB_Trash_Content_Verification {

	/**
	 * Exact fixture post IDs for cleanup.
	 *
	 * @var list<int>
	 */
	private array $post_ids = array();

	/**
	 * Temporary author user ID.
	 *
	 * @var int
	 */
	private int $user_id = 0;

	/**
	 * Current user ID from before the run.
	 *
	 * @var int
	 */
	private int $original_user_id = 0;

	/**
	 * Highest audit row ID existing before the run.
	 *
	 * @var int
	 */
	private int $audit_baseline_id = 0;

	/**
	 * Original content-access policy option.
	 *
	 * @var mixed
	 */
	private mixed $original_policy;

	/**
	 * Original global writes option.
	 *
	 * @var mixed
	 */
	private mixed $original_writes_enabled;

	/**
	 * Original global trash option.
	 *
	 * @var mixed
	 */
	private mixed $original_trash_enabled;

	/**
	 * Production repository under verification.
	 *
	 * @var WordPressContentTrashRepository
	 */
	private WordPressContentTrashRepository $repository;

	/**
	 * Registered production ability under verification.
	 *
	 * @var WP_Ability
	 */
	private WP_Ability $ability;

	/**
	 * Runs the complete verifier and always restores the prior installation state.
	 *
	 * @return void
	 */
	public function run(): void {
		$this->original_user_id        = get_current_user_id();
		$this->original_policy         = get_option( WordPressContentAccessSettingsRepository::OPTION_NAME, null );
		$this->original_writes_enabled = get_option( Installer::WRITES_ENABLED_OPTION, false );
		$this->original_trash_enabled  = get_option( Installer::TRASH_ENABLED_OPTION, false );

		global $wpdb;
		/**
		 * WordPress database abstraction object.
		 *
		 * @var wpdb $wpdb
		 */
		$this->audit_baseline_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(MAX(id), 0) FROM %i', Installer::audit_table_name() ) );

		try {
			$this->enable_policy();
			$this->create_user();
			$this->register_ability();
			$this->verify_contract_and_authorization();
			$this->verify_happy_path_and_audit();
			$this->verify_stale_version_conflict();
			$this->verify_policy_denial();
		} finally {
			$this->cleanup();
		}
	}

	/** Enables both global gates and the per-type read/trash policy. */
	private function enable_policy(): void {
		update_option( Installer::WRITES_ENABLED_OPTION, true, false );
		update_option( Installer::TRASH_ENABLED_OPTION, true, false );
		update_option(
			WordPressContentAccessSettingsRepository::OPTION_NAME,
			array(
				'post' => array(
					'get_content'   => true,
					'trash_content' => true,
				),
			),
			false
		);
	}

	/** Creates an author fixture without the plugin delete capability. */
	private function create_user(): void {
		$suffix  = strtolower( wp_generate_password( 8, false, false ) );
		$user_id = wp_insert_user(
			array(
				'user_login' => 'wpcb-trash-' . $suffix,
				'user_pass'  => wp_generate_password( 32, true, true ),
				'user_email' => 'wpcb-trash-' . $suffix . '@example.invalid',
				'role'       => 'author',
			)
		);
		$this->assert_true( ! is_wp_error( $user_id ), 'Could not create the trash fixture user.' );
		$this->user_id = (int) $user_id;
	}

	/** Registers the production adapter without replaying the global abilities hook. */
	private function register_ability(): void {
		if ( wp_has_ability( TrashContent::ABILITY ) ) {
			wp_unregister_ability( TrashContent::ABILITY );
		}

		$manager          = new ContentAccessManager( new WordPressContentAccessSettingsRepository(), new WordPressContentTypeCatalog() );
		$this->repository = new WordPressContentTrashRepository();
		$adapter          = new TrashAbilities( new TrashContent( $manager, $this->repository, new WordPressAuditLog() ) );

		global $wp_current_filter;
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- scopes doing_action() to this direct registration and restores immediately.
		$wp_current_filter[] = 'wp_abilities_api_init';
		try {
			$adapter->register_ability();
		} finally {
			array_pop( $wp_current_filter );
		}

		$ability = wp_get_ability( TrashContent::ABILITY );
		$this->assert_true( $ability instanceof WP_Ability, 'trash-content did not register.' );
		$this->ability = $ability;
	}

	/** Proves strict schemas, destructive annotations, and both permission gates. */
	private function verify_contract_and_authorization(): void {
		$annotations = $this->ability->get_meta()['annotations'] ?? array();
		$this->assert_true( false === ( $annotations['readonly'] ?? null ), 'trash-content must be a write.' );
		$this->assert_true( true === ( $annotations['destructive'] ?? null ), 'trash-content must be destructive.' );
		$this->assert_true( false === ( $annotations['idempotent'] ?? null ), 'trash-content must not claim idempotency.' );
		$this->assert_true( false === ( $this->ability->get_input_schema()['additionalProperties'] ?? null ), 'trash-content input must reject unknown fields.' );

		$post_id = $this->create_post();
		wp_set_current_user( $this->user_id );
		$this->assert_true( ! $this->ability->check_permissions( array( 'post_id' => $post_id ) ), 'Native delete_post alone bypassed wpcb_delete_content.' );

		$user = get_user_by( 'id', $this->user_id );
		$this->assert_true( $user instanceof WP_User, 'Could not resolve the trash fixture user.' );
		$user->add_cap( 'wpcb_delete_content' );

		// wp_set_current_user() early-returns for the same ID, so the global principal
		// still holds the capabilities cached before add_cap(). Force a rebuild.
		wp_set_current_user( 0 );
		wp_set_current_user( $this->user_id );

		$this->assert_true( $this->ability->check_permissions( array( 'post_id' => $post_id ) ), 'Authorized author was denied own-post trash.' );
	}

	/** Proves reversible trash and a redacted success audit event. */
	private function verify_happy_path_and_audit(): void {
		$post_id = $this->create_post();
		$target  = $this->repository->target( $post_id );
		$this->assert_true( null !== $target, 'Could not resolve the trash target.' );

		$result = $this->ability->execute(
			array(
				'post_id'       => $post_id,
				'version_token' => $target->version->to_string(),
			)
		);
		$this->assert_true( is_array( $result ), 'Authorized trash returned an error.' );
		$this->assert_true( 'trash' === ( $result['status'] ?? null ), 'Trash result did not report the trash status.' );
		$this->assert_true( 'trash' === get_post_status( $post_id ), 'WordPress did not retain the post in trash.' );
		$this->assert_true( 'draft' === get_post_meta( $post_id, '_wp_trash_meta_status', true ), 'WordPress did not retain the restorable prior status.' );

		global $wpdb;
		/**
		 * WordPress database abstraction object.
		 *
		 * @var wpdb $wpdb
		 */
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT ability, changed_fields, outcome FROM %i ORDER BY id DESC LIMIT 1', Installer::audit_table_name() ), ARRAY_A );
		$this->assert_true( is_array( $row ), 'Trash did not create an audit event.' );
		$this->assert_true( TrashContent::ABILITY === ( $row['ability'] ?? null ), 'Trash audit recorded the wrong ability.' );
		$this->assert_true( '["status"]' === ( $row['changed_fields'] ?? null ), 'Trash audit exposed unexpected fields.' );
		$this->assert_true( 'success' === ( $row['outcome'] ?? null ), 'Trash audit did not record success.' );
	}

	/** Proves a stale token cannot trash newer content. */
	private function verify_stale_version_conflict(): void {
		$post_id = $this->create_post();
		$target  = $this->repository->target( $post_id );
		$this->assert_true( null !== $target, 'Could not resolve the stale-token fixture.' );
		wp_update_post(
			array(
				'ID'         => $post_id,
				'post_title' => 'Newer fixture title',
			)
		);

		$result = $this->ability->execute(
			array(
				'post_id'       => $post_id,
				'version_token' => $target->version->to_string(),
			)
		);
		$this->assert_true( is_wp_error( $result ) && 'wpcb_conflict' === $result->get_error_code(), 'Stale trash did not return wpcb_conflict.' );
		$this->assert_true( 'draft' === get_post_status( $post_id ), 'Stale trash changed the post status.' );
	}

	/** Proves type policy is enforced after native and integration capabilities. */
	private function verify_policy_denial(): void {
		$post_id = $this->create_post();
		$target  = $this->repository->target( $post_id );
		$this->assert_true( null !== $target, 'Could not resolve the policy fixture.' );
		update_option(
			WordPressContentAccessSettingsRepository::OPTION_NAME,
			array(
				'post' => array(
					'get_content'   => true,
					'trash_content' => false,
				),
			),
			false
		);

		$result = $this->ability->execute(
			array(
				'post_id'       => $post_id,
				'version_token' => $target->version->to_string(),
			)
		);
		$this->assert_true( is_wp_error( $result ) && 'wpcb_forbidden' === $result->get_error_code(), 'Policy denial did not return wpcb_forbidden.' );
		$this->assert_true( 'draft' === get_post_status( $post_id ), 'Policy-denied trash changed the post status.' );
	}

	/** Creates one exact draft fixture owned by the temporary author. */
	private function create_post(): int {
		$post_id = wp_insert_post(
			array(
				'post_type'    => 'post',
				'post_status'  => 'draft',
				'post_author'  => $this->user_id,
				'post_title'   => 'WPCB trash fixture ' . count( $this->post_ids ),
				'post_content' => '<!-- wp:paragraph --><p>Trash fixture</p><!-- /wp:paragraph -->',
			),
			true
		);
		$this->assert_true( ! is_wp_error( $post_id ), 'Could not create the trash fixture post.' );
		$this->post_ids[] = (int) $post_id;

		return (int) $post_id;
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
		foreach ( $this->post_ids as $post_id ) {
			wp_delete_post( $post_id, true );
		}
		if ( 0 < $this->user_id ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
			wp_delete_user( $this->user_id );
		}

		$this->restore_option( WordPressContentAccessSettingsRepository::OPTION_NAME, $this->original_policy );
		$this->restore_option( Installer::WRITES_ENABLED_OPTION, $this->original_writes_enabled );
		$this->restore_option( Installer::TRASH_ENABLED_OPTION, $this->original_trash_enabled );
		if ( wp_has_ability( TrashContent::ABILITY ) ) {
			wp_unregister_ability( TrashContent::ABILITY );
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
	 * @param mixed  $value Original option value, or null when absent.
	 * @return void
	 */
	private function restore_option( string $name, mixed $value ): void {
		if ( null === $value ) {
			delete_option( $name );
			return;
		}
		update_option( $name, $value, false );
	}

	/**
	 * Throws when a verifier invariant is false.
	 *
	 * @param bool   $condition Invariant outcome.
	 * @param string $message   Failure diagnostic.
	 * @return void
	 */
	private function assert_true( bool $condition, string $message ): void {
		if ( ! $condition ) {
			throw new RuntimeException( $message );
		}
	}
}

$failures = array();
try {
	( new WPCB_Trash_Content_Verification() )->run();
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

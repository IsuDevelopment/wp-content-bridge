<?php
/**
 * Runtime verification for reversible content restoration.
 *
 * Run: wp eval 'require "<abs path>/tests/Integration/restore-trashed-content-verification.php";'
 *
 * @package IsuDev\WPContentBridgeTests
 */

declare(strict_types=1);

use IsuDev\WPContentBridge\Adapter\Abilities\RestoreTrashedContentAbilities;
use IsuDev\WPContentBridge\Application\ContentAccess\ContentAccessManager;
use IsuDev\WPContentBridge\Application\Mutation\RestoreTrashedContent;
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
 * Exercises restore registration, authorization, concurrency, policy, audit,
 * and the never-`publish`/`future` status guarantee.
 */
final class WPCB_Restore_Trashed_Content_Verification {

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
			$this->verify_publish_pretrash_lands_on_draft();
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
				'user_login' => 'wpcb-restore-' . $suffix,
				'user_pass'  => wp_generate_password( 32, true, true ),
				'user_email' => 'wpcb-restore-' . $suffix . '@example.invalid',
				'role'       => 'author',
			)
		);
		$this->assert_true( ! is_wp_error( $user_id ), 'Could not create the restore fixture user.' );
		$this->user_id = (int) $user_id;
	}

	/** Registers the production adapter without replaying the global abilities hook. */
	private function register_ability(): void {
		if ( wp_has_ability( RestoreTrashedContent::ABILITY ) ) {
			wp_unregister_ability( RestoreTrashedContent::ABILITY );
		}

		$manager          = new ContentAccessManager( new WordPressContentAccessSettingsRepository(), new WordPressContentTypeCatalog() );
		$this->repository = new WordPressContentTrashRepository();
		$adapter          = new RestoreTrashedContentAbilities( new RestoreTrashedContent( $manager, $this->repository, new WordPressAuditLog() ) );

		global $wp_current_filter;
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- scopes doing_action() to this direct registration and restores immediately.
		$wp_current_filter[] = 'wp_abilities_api_init';
		try {
			$adapter->register_ability();
		} finally {
			array_pop( $wp_current_filter );
		}

		$ability = wp_get_ability( RestoreTrashedContent::ABILITY );
		$this->assert_true( $ability instanceof WP_Ability, 'restore-trashed-content did not register.' );
		$this->ability = $ability;
	}

	/** Proves strict schemas, safe annotations, and both permission gates. */
	private function verify_contract_and_authorization(): void {
		$annotations = $this->ability->get_meta()['annotations'] ?? array();
		$this->assert_true( false === ( $annotations['readonly'] ?? null ), 'restore-trashed-content must be a write.' );
		$this->assert_true( false === ( $annotations['destructive'] ?? null ), 'restore-trashed-content must not be marked destructive.' );
		$this->assert_true( false === ( $annotations['idempotent'] ?? null ), 'restore-trashed-content must not claim idempotency.' );
		$this->assert_true( false === ( $this->ability->get_input_schema()['additionalProperties'] ?? null ), 'restore-trashed-content input must reject unknown fields.' );

		$post_id = $this->create_trashed_post( 'draft' );
		wp_set_current_user( $this->user_id );
		$this->assert_true( ! $this->ability->check_permissions( array( 'post_id' => $post_id ) ), 'Native delete_post alone bypassed wpcb_delete_content.' );

		$user = get_user_by( 'id', $this->user_id );
		$this->assert_true( $user instanceof WP_User, 'Could not resolve the restore fixture user.' );
		$user->add_cap( 'wpcb_delete_content' );

		// wp_set_current_user() early-returns for the same ID, so the global principal
		// still holds the capabilities cached before add_cap(). Force a rebuild.
		wp_set_current_user( 0 );
		wp_set_current_user( $this->user_id );

		$this->assert_true( $this->ability->check_permissions( array( 'post_id' => $post_id ) ), 'Authorized author was denied own-post restore.' );
	}

	/** Proves trash-to-draft restoration and a redacted success audit event. */
	private function verify_happy_path_and_audit(): void {
		$post_id = $this->create_trashed_post( 'draft' );
		$target  = $this->repository->target( $post_id );
		$this->assert_true( null !== $target, 'Could not resolve the restore target.' );

		$result = $this->ability->execute(
			array(
				'post_id'       => $post_id,
				'version_token' => $target->version->to_string(),
			)
		);
		$this->assert_true( is_array( $result ), 'Authorized restore returned an error.' );
		$this->assert_true( 'draft' === ( $result['status'] ?? null ), 'Restore result did not report the draft status.' );
		$this->assert_true( 'draft' === get_post_status( $post_id ), 'WordPress did not restore the post to draft.' );
		$this->assert_true( '' === (string) get_post_meta( $post_id, '_wp_trash_meta_status', true ), 'WordPress did not clear the pre-trash status meta.' );

		global $wpdb;
		/**
		 * WordPress database abstraction object.
		 *
		 * @var wpdb $wpdb
		 */
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT ability, changed_fields, outcome FROM %i ORDER BY id DESC LIMIT 1', Installer::audit_table_name() ), ARRAY_A );
		$this->assert_true( is_array( $row ), 'Restore did not create an audit event.' );
		$this->assert_true( RestoreTrashedContent::ABILITY === ( $row['ability'] ?? null ), 'Restore audit recorded the wrong ability.' );
		$this->assert_true( '["status"]' === ( $row['changed_fields'] ?? null ), 'Restore audit exposed unexpected fields.' );
		$this->assert_true( 'success' === ( $row['outcome'] ?? null ), 'Restore audit did not record success.' );
	}

	/** Proves a `publish` pre-trash status still lands on `draft`, never `publish`/`future`. */
	private function verify_publish_pretrash_lands_on_draft(): void {
		$post_id = $this->create_trashed_post( 'publish' );
		$meta    = get_post_meta( $post_id, '_wp_trash_meta_status', true );
		$this->assert_true( 'publish' === $meta, 'WordPress trash did not record the publish pre-trash status.' );

		$target = $this->repository->target( $post_id );
		$this->assert_true( null !== $target, 'Could not resolve the publish-pretrash restore target.' );

		$result = $this->ability->execute(
			array(
				'post_id'       => $post_id,
				'version_token' => $target->version->to_string(),
			)
		);
		$this->assert_true( is_array( $result ), 'Authorized restore of a publish-pretrash fixture returned an error.' );
		$this->assert_true( 'draft' === ( $result['status'] ?? null ), 'A publish pre-trash status did not land on draft.' );

		$status = get_post_status( $post_id );
		$this->assert_true( 'draft' === $status, 'WordPress restored a publish pre-trash post to something other than draft.' );
		$this->assert_true( 'publish' !== $status && 'future' !== $status, 'Restore reached a forbidden status.' );
	}

	/** Proves a stale token is rejected before any mutation. */
	private function verify_stale_version_conflict(): void {
		$post_id = $this->create_trashed_post( 'draft' );
		$target  = $this->repository->target( $post_id );
		$this->assert_true( null !== $target, 'Could not resolve the stale-token fixture.' );
		wp_update_post(
			array(
				'ID'         => $post_id,
				'post_title' => 'Newer trashed fixture title',
			)
		);

		$result = $this->ability->execute(
			array(
				'post_id'       => $post_id,
				'version_token' => $target->version->to_string(),
			)
		);
		$this->assert_true( is_wp_error( $result ) && 'wpcb_conflict' === $result->get_error_code(), 'Stale restore did not return wpcb_conflict.' );
		$this->assert_true( 'trash' === get_post_status( $post_id ), 'Stale restore changed the post status.' );
	}

	/** Proves type policy is enforced after native and integration capabilities. */
	private function verify_policy_denial(): void {
		$post_id = $this->create_trashed_post( 'draft' );
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
		$this->assert_true( 'trash' === get_post_status( $post_id ), 'Policy-denied restore changed the post status.' );
	}

	/**
	 * Creates one exact fixture with the given pre-trash status, already moved to trash.
	 *
	 * @param string $pre_status Post status to assign before trashing.
	 * @return int
	 */
	private function create_trashed_post( string $pre_status ): int {
		$post_id = wp_insert_post(
			array(
				'post_type'    => 'post',
				'post_status'  => $pre_status,
				'post_author'  => $this->user_id,
				'post_title'   => 'WPCB restore fixture ' . count( $this->post_ids ),
				'post_content' => '<!-- wp:paragraph --><p>Restore fixture</p><!-- /wp:paragraph -->',
			),
			true
		);
		$this->assert_true( ! is_wp_error( $post_id ), 'Could not create the restore fixture post.' );
		$post_id          = (int) $post_id;
		$this->post_ids[] = $post_id;

		$trashed = wp_trash_post( $post_id );
		$this->assert_true( $trashed instanceof WP_Post, 'Could not trash the restore fixture.' );
		$this->assert_true( 'trash' === get_post_status( $post_id ), 'Restore fixture did not land in trash.' );

		return $post_id;
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
		if ( wp_has_ability( RestoreTrashedContent::ABILITY ) ) {
			wp_unregister_ability( RestoreTrashedContent::ABILITY );
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
	( new WPCB_Restore_Trashed_Content_Verification() )->run();
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

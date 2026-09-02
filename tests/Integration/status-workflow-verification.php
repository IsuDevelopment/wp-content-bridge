<?php
/**
 * Runtime verification for the status-workflow slice (0.7.0).
 *
 * Run: wp eval 'require "<abs path>/tests/Integration/status-workflow-verification.php";'
 *
 * @package IsuDev\WPContentBridgeTests
 */

declare(strict_types=1);

use IsuDev\WPContentBridge\Adapter\Abilities\GetStatusTransitionsAbilities;
use IsuDev\WPContentBridge\Adapter\Abilities\TransitionContentStatusAbilities;
use IsuDev\WPContentBridge\Application\ContentAccess\ContentAccessManager;
use IsuDev\WPContentBridge\Application\Mutation\MutationWriteFailed;
use IsuDev\WPContentBridge\Application\Status\GetStatusTransitions;
use IsuDev\WPContentBridge\Application\Status\StatusTransitionManager;
use IsuDev\WPContentBridge\Application\Status\TransitionContentStatus;
use IsuDev\WPContentBridge\Infrastructure\WordPress\Installer;
use IsuDev\WPContentBridge\Infrastructure\WordPress\PostVersionTokenFactory;
use IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressAuditLog;
use IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressContentAccessSettingsRepository;
use IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressContentMutationRepository;
use IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressContentTypeCatalog;
use IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressSiteClock;
use IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressStatusTransitionRepository;
use IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressStatusTransitionTargetRepository;

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
 * Exercises get-status-transitions and transition-content-status per the
 * 0.7.0 execution plan's task 6: registration gating, the empty-graph
 * deny-all default, that a stored status is read back from storage rather
 * than echoed from the request, ADR 0024's "may unpublish but not publish"
 * asymmetry, the three publication gates, optimistic concurrency, publish_at
 * validation (including DST gap/fold correctness against the real
 * Europe/Warsaw tz database), the revision and field-names-only audit
 * invariants, the full draft -> pending -> publish roadmap flow, the
 * deliberate per-target gates semantics for non-privileged targets, and the
 * mutation repository's own read-back defence against a WordPress-rewritten
 * transition. Needs WordPress core only.
 */
final class WPCB_Status_Workflow_Verification {

	/**
	 * Exact fixture post IDs for cleanup.
	 *
	 * @var list<int>
	 */
	private array $post_ids = array();

	/**
	 * Administrator fixture user ID, resolved once and reused throughout.
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
	 * Unique fixture marker embedded in every fixture title.
	 *
	 * @var string
	 */
	private string $token = '';

	/**
	 * Get-status-transitions ability, rebuilt by {@see self::register_abilities()}.
	 *
	 * @var WP_Ability|null
	 */
	private ?WP_Ability $get_ability = null;

	/**
	 * Transition-content-status ability, rebuilt by
	 * {@see self::register_abilities()}; null while `wpcb_writes_enabled` is off.
	 *
	 * @var WP_Ability|null
	 */
	private ?WP_Ability $transition_ability = null;

	/**
	 * Runs the complete verifier and always restores the prior installation state.
	 *
	 * @return void
	 */
	public function run(): void {
		$this->original_user_id = get_current_user_id();
		$this->token            = 'wpcbstatus' . strtolower( wp_generate_password( 8, false, false ) );

		global $wpdb;
		/**
		 * WordPress database abstraction object.
		 *
		 * @var wpdb $wpdb
		 */
		$this->audit_baseline_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(MAX(id), 0) FROM %i', Installer::audit_table_name() ) );

		$this->snapshot_option( Installer::WRITES_ENABLED_OPTION );
		$this->snapshot_option( Installer::PUBLISH_ENABLED_OPTION );
		$this->snapshot_option( WordPressContentAccessSettingsRepository::OPTION_NAME );
		$this->snapshot_option( WordPressStatusTransitionRepository::OPTION_NAME );
		$this->snapshot_option( 'timezone_string' );
		$this->snapshot_option( 'gmt_offset' );

		try {
			$this->resolve_admin();
			$this->enable_policy();

			$this->verify_writes_disabled_leaves_write_ability_absent();
			$this->verify_empty_graph_denies_everything();
			$this->verify_configured_pair_reports_stored_status();
			$this->verify_unlisted_reverse_pair_is_refused();
			$this->verify_publish_gates_block_when_publish_disabled();
			$this->verify_stale_version_token_rejected_before_write();
			$this->verify_past_publish_at_is_refused();
			$this->verify_scheduled_transition_stores_exact_future_date();
			$this->verify_dst_publish_at_correctness();
			$this->verify_transition_creates_revision_and_audits_field_names_only();
			$this->verify_end_to_end_roadmap_flow();
			$this->verify_gates_are_trivially_satisfied_for_nonprivileged_targets();
			$this->verify_repository_read_back_defence_catches_a_rewritten_transition();
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
	}

	/**
	 * Enables the `post` type's Read and Transition-status policy (with its
	 * Read prerequisite -- see `ContentOperation::TRANSITION_STATUS::prerequisites()`).
	 *
	 * @return void
	 */
	private function enable_policy(): void {
		update_option(
			WordPressContentAccessSettingsRepository::OPTION_NAME,
			array(
				'post' => array(
					'get_content'               => true,
					'transition_content_status' => true,
				),
			),
			false
		);
	}

	/**
	 * Rebuilds get-status-transitions and, while `$writes_enabled`,
	 * transition-content-status directly from real infrastructure, mirroring
	 * `Plugin::boot()`'s own conditional wiring.
	 *
	 * Both abilities close over `$publish_enabled` at construction time --
	 * changing `wpcb_publish_enabled` (or `wpcb_writes_enabled`) after this
	 * call does nothing to the abilities already built here, exactly as
	 * `Plugin::boot()` reads each option exactly once. Every check that needs
	 * a different flag combination calls this again first, rather than
	 * mutating an option under an already-constructed ability.
	 *
	 * @param bool $writes_enabled  Whether to register transition-content-status at all.
	 * @param bool $publish_enabled Value to store in, and read back from, `wpcb_publish_enabled`.
	 * @return void
	 */
	private function register_abilities( bool $writes_enabled, bool $publish_enabled ): void {
		foreach ( array( GetStatusTransitions::ABILITY, TransitionContentStatus::ABILITY ) as $ability_id ) {
			if ( wp_has_ability( $ability_id ) ) {
				wp_unregister_ability( $ability_id );
			}
		}

		update_option( Installer::WRITES_ENABLED_OPTION, $writes_enabled, false );
		update_option( Installer::PUBLISH_ENABLED_OPTION, $publish_enabled, false );
		$actual_writes_enabled  = (bool) get_option( Installer::WRITES_ENABLED_OPTION );
		$actual_publish_enabled = (bool) get_option( Installer::PUBLISH_ENABLED_OPTION );
		$this->assert_true( $actual_writes_enabled === $writes_enabled, 'wpcb_writes_enabled did not read back what was just stored.' );
		$this->assert_true( $actual_publish_enabled === $publish_enabled, 'wpcb_publish_enabled did not read back what was just stored.' );

		$manager            = new ContentAccessManager( new WordPressContentAccessSettingsRepository(), new WordPressContentTypeCatalog() );
		$status_transitions = new StatusTransitionManager( new WordPressStatusTransitionRepository() );
		$target_repository  = new WordPressStatusTransitionTargetRepository();
		$clock              = new WordPressSiteClock();

		$get_adapter = new GetStatusTransitionsAbilities(
			new GetStatusTransitions( $manager, $target_repository, $status_transitions, $clock, $actual_publish_enabled )
		);

		global $wp_current_filter;
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- scopes doing_action() to this direct registration and restores immediately.
		$wp_current_filter[] = 'wp_abilities_api_init';
		try {
			$get_adapter->register_ability();
			if ( $actual_writes_enabled ) {
				$transition_adapter = new TransitionContentStatusAbilities(
					new TransitionContentStatus(
						$manager,
						$target_repository,
						$status_transitions,
						new WordPressContentMutationRepository(),
						$clock,
						$actual_publish_enabled,
						new WordPressAuditLog()
					)
				);
				$transition_adapter->register_ability();
			}
		} finally {
			array_pop( $wp_current_filter );
		}

		$get = wp_get_ability( GetStatusTransitions::ABILITY );
		$this->assert_true( $get instanceof WP_Ability, 'get-status-transitions did not register.' );
		$this->get_ability = $get;

		$this->transition_ability = null;
		if ( $actual_writes_enabled ) {
			$transition = wp_get_ability( TransitionContentStatus::ABILITY );
			$this->assert_true( $transition instanceof WP_Ability, 'transition-content-status did not register while wpcb_writes_enabled was on.' );
			$this->transition_ability = $transition;
		}
	}

	/**
	 * Check 1: with `wpcb_writes_enabled` off, the write ability is absent
	 * rather than registered-and-refusing (Trap 5). get-status-transitions is
	 * a read (task 3) and, per `Plugin::boot()` and the 7d4110f commit
	 * message ("writes off leaves the write ability absent"), is registered
	 * unconditionally regardless of that flag -- so this reconciles the
	 * plan's "neither ability" wording with the shipped, measured behaviour:
	 * only the write half is gated.
	 *
	 * @return void
	 */
	private function verify_writes_disabled_leaves_write_ability_absent(): void {
		$this->register_abilities( false, false );

		$this->assert_true( wp_has_ability( GetStatusTransitions::ABILITY ), 'get-status-transitions was not registered while wpcb_writes_enabled was off.' );
		$this->assert_true( ! wp_has_ability( TransitionContentStatus::ABILITY ), 'transition-content-status was registered while wpcb_writes_enabled was off.' );
		$this->assert_true( null === $this->transition_ability, 'The verifier resolved a transition-content-status ability while writes were disabled.' );
	}

	/**
	 * Check 2: with an empty graph (never configured), every transition is
	 * refused and nothing is written; get-status-transitions reports no
	 * permitted targets either.
	 *
	 * @return void
	 */
	private function verify_empty_graph_denies_everything(): void {
		$this->register_abilities( true, true );
		$this->clear_graph();

		$post_id = $this->create_fixture_post( 'draft' );

		$read = $this->get_ability->execute( array( 'post_id' => $post_id ) );
		$this->assert_not_error( $read, 'get-status-transitions against an unconfigured graph' );
		if ( ! is_array( $read ) ) {
			throw new RuntimeException( 'get-status-transitions result is not an array.' );
		}
		$this->assert_true( array() === $read['targets'], 'An unconfigured transition graph reported a permitted target.' );

		$result = $this->transition_ability->execute(
			array(
				'post_id'       => $post_id,
				'version_token' => $this->current_version_token( $post_id ),
				'target_status' => 'pending',
			)
		);
		$this->assert_error_code( $result, 'wpcb_invalid_state', 'A transition attempted against an unconfigured graph' );

		$after = get_post( $post_id );
		$this->assert_true( $after instanceof WP_Post && 'draft' === $after->post_status, 'A transition rejected for an unconfigured graph changed post_status anyway.' );
	}

	/**
	 * Check 3: a configured pair transitions, and the response reports the
	 * status read back from storage -- re-read independently here, not
	 * merely trusted from the ability's own report of itself (Trap 3).
	 *
	 * @return void
	 */
	private function verify_configured_pair_reports_stored_status(): void {
		$this->register_abilities( true, true );
		$this->set_graph(
			array(
				array(
					'from' => 'draft',
					'to'   => 'pending',
				),
			)
		);

		$post_id = $this->create_fixture_post( 'draft' );
		$result  = $this->transition_ability->execute(
			array(
				'post_id'       => $post_id,
				'version_token' => $this->current_version_token( $post_id ),
				'target_status' => 'pending',
			)
		);
		$this->assert_not_error( $result, 'A configured draft -> pending transition' );
		if ( ! is_array( $result ) ) {
			throw new RuntimeException( 'transition-content-status result is not an array.' );
		}

		$stored = get_post( $post_id );
		$this->assert_true( $stored instanceof WP_Post, 'Transitioned fixture could not be re-read.' );
		$this->assert_true( 'pending' === $stored->post_status, 'The transitioned post was not actually stored as pending.' );
		$this->assert_true( $stored->post_status === $result['status'], "The response's status did not match the status independently read back from storage." );
	}

	/**
	 * Check 4, ADR 0024's motivating case: with only `publish -> draft`
	 * configured, `draft -> publish` (the unlisted reverse pair) is refused --
	 * asserting the stored row, not just the error code.
	 *
	 * @return void
	 */
	private function verify_unlisted_reverse_pair_is_refused(): void {
		$this->register_abilities( true, true );
		$this->set_graph(
			array(
				array(
					'from' => 'publish',
					'to'   => 'draft',
				),
			)
		);

		$post_id = $this->create_fixture_post( 'draft' );
		$before  = get_post( $post_id );
		$this->assert_true( $before instanceof WP_Post, 'Fixture post could not be read before the attempt.' );

		$result = $this->transition_ability->execute(
			array(
				'post_id'       => $post_id,
				'version_token' => $this->current_version_token( $post_id ),
				'target_status' => 'publish',
			)
		);
		$this->assert_error_code( $result, 'wpcb_invalid_state', 'draft -> publish attempted while only publish -> draft is configured' );

		$after = get_post( $post_id );
		$this->assert_true( $after instanceof WP_Post, 'Fixture post could not be re-read after the attempt.' );
		$this->assert_true( 'draft' === $after->post_status, 'An unlisted reverse transition changed post_status anyway.' );
		$this->assert_true( $before->post_date_gmt === $after->post_date_gmt, 'An unlisted reverse transition changed post_date_gmt anyway.' );
	}

	/**
	 * Check 5: `publish` and `future` are both refused while
	 * `wpcb_publish_enabled` is off, even with the pair listed and
	 * `wpcb_publish_content` held by the acting principal.
	 *
	 * @return void
	 */
	private function verify_publish_gates_block_when_publish_disabled(): void {
		$this->register_abilities( true, false );
		$this->set_graph(
			array(
				array(
					'from' => 'draft',
					'to'   => 'publish',
				),
				array(
					'from' => 'draft',
					'to'   => 'future',
				),
			)
		);
		$this->assert_true( current_user_can( 'wpcb_publish_content' ), 'The administrator fixture unexpectedly lacks wpcb_publish_content.' );

		$post_id = $this->create_fixture_post( 'draft' );

		foreach ( array( 'publish', 'future' ) as $target_status ) {
			$input = array(
				'post_id'       => $post_id,
				'version_token' => $this->current_version_token( $post_id ),
				'target_status' => $target_status,
			);
			if ( 'future' === $target_status ) {
				$input['publish_at'] = $this->future_local_wire( 2 );
			}

			$result = $this->transition_ability->execute( $input );
			$this->assert_error_code( $result, 'wpcb_forbidden', "target_status={$target_status} while wpcb_publish_enabled is off, the pair configured, and the capability held" );
		}

		$after = get_post( $post_id );
		$this->assert_true( $after instanceof WP_Post && 'draft' === $after->post_status, 'A refused publish/future transition changed post_status anyway.' );
	}

	/**
	 * Check 6: a stale `version_token` is refused before any write -- assert
	 * the stored row (status and scheduled date), not just the error code.
	 *
	 * @return void
	 */
	private function verify_stale_version_token_rejected_before_write(): void {
		$this->register_abilities( true, true );
		$this->set_graph(
			array(
				array(
					'from' => 'draft',
					'to'   => 'pending',
				),
			)
		);

		$post_id     = $this->create_fixture_post( 'draft' );
		$stale_token = $this->current_version_token( $post_id );

		$out_of_band = wp_update_post(
			array(
				'ID'         => $post_id,
				'post_title' => 'WPCB status out-of-band ' . $this->token,
			),
			true
		);
		$this->assert_true( ! is_wp_error( $out_of_band ), 'The out-of-band fixture edit failed.' );

		$before = get_post( $post_id );
		$this->assert_true( $before instanceof WP_Post, 'Fixture post could not be read before the stale attempt.' );

		$result = $this->transition_ability->execute(
			array(
				'post_id'       => $post_id,
				'version_token' => $stale_token,
				'target_status' => 'pending',
			)
		);
		$this->assert_error_code( $result, 'wpcb_conflict', 'A stale version_token on transition-content-status' );

		$after = get_post( $post_id );
		$this->assert_true( $after instanceof WP_Post, 'Fixture post could not be re-read after the stale attempt.' );
		$this->assert_true( $before->post_status === $after->post_status, 'A rejected stale transition changed post_status anyway.' );
		$this->assert_true( $before->post_date_gmt === $after->post_date_gmt, 'A rejected stale transition changed post_date_gmt anyway.' );
	}

	/**
	 * Check 7: `publish_at` in the past is refused outright, never downgraded
	 * to an immediate publish (ADR 0024) -- assert both post_status and
	 * post_date_gmt are untouched.
	 *
	 * @return void
	 */
	private function verify_past_publish_at_is_refused(): void {
		$this->register_abilities( true, true );
		$this->set_graph(
			array(
				array(
					'from' => 'draft',
					'to'   => 'future',
				),
			)
		);

		$post_id = $this->create_fixture_post( 'draft' );
		$before  = get_post( $post_id );
		$this->assert_true( $before instanceof WP_Post, 'Fixture post could not be read before the attempt.' );

		$result = $this->transition_ability->execute(
			array(
				'post_id'       => $post_id,
				'version_token' => $this->current_version_token( $post_id ),
				'target_status' => 'future',
				'publish_at'    => '2020-01-01T00:00:00',
			)
		);
		$this->assert_error_code( $result, 'wpcb_invalid_input', 'A past publish_at on transition-content-status' );

		$after = get_post( $post_id );
		$this->assert_true( $after instanceof WP_Post, 'Fixture post could not be re-read after the attempt.' );
		$this->assert_true( 'draft' === $after->post_status, 'A refused past-dated publish_at changed post_status anyway (it must never downgrade to an immediate publish).' );
		$this->assert_true( $before->post_date_gmt === $after->post_date_gmt, 'A refused past-dated publish_at changed post_date_gmt anyway.' );
	}

	/**
	 * Check 8: a scheduled transition stores `future` with the exact
	 * requested `post_date_gmt`, and the response returns matching site-local
	 * and UTC times.
	 *
	 * @return void
	 */
	private function verify_scheduled_transition_stores_exact_future_date(): void {
		$this->register_abilities( true, true );
		$this->set_graph(
			array(
				array(
					'from' => 'draft',
					'to'   => 'future',
				),
			)
		);

		$post_id = $this->create_fixture_post( 'draft' );

		$clock               = new WordPressSiteClock();
		$expected_utc        = $clock->now()->modify( '+2 days' );
		$expected_local      = $expected_utc->setTimezone( $clock->timezone() );
		$local_wire          = $expected_local->format( 'Y-m-d\TH:i:s' );
		$expected_local_wire = $expected_local->format( 'Y-m-d\TH:i:sP' );

		$result = $this->transition_ability->execute(
			array(
				'post_id'       => $post_id,
				'version_token' => $this->current_version_token( $post_id ),
				'target_status' => 'future',
				'publish_at'    => $local_wire,
			)
		);
		$this->assert_not_error( $result, 'A valid scheduled draft -> future transition' );
		if ( ! is_array( $result ) ) {
			throw new RuntimeException( 'transition-content-status result is not an array.' );
		}

		$stored = get_post( $post_id );
		$this->assert_true( $stored instanceof WP_Post, 'Scheduled fixture could not be re-read.' );
		$this->assert_true( 'future' === $stored->post_status, 'A validly scheduled transition did not store future.' );
		$this->assert_true( $expected_utc->format( 'Y-m-d H:i:s' ) === $stored->post_date_gmt, 'The stored post_date_gmt did not match the requested UTC instant exactly.' );

		$this->assert_true( is_array( $result['publish_at'] ?? null ), 'The response omitted publish_at for a future transition.' );
		$this->assert_true( $expected_utc->format( 'Y-m-d\TH:i:s\Z' ) === $result['publish_at']['utc'], "The response's publish_at.utc did not match the requested instant." );
		$this->assert_true( $expected_local_wire === $result['publish_at']['local'], "The response's publish_at.local did not match the requested site-local instant." );
	}

	/**
	 * Check 9: DST correctness, driven against the real Europe/Warsaw tz
	 * database rather than any hard-coded calendar date. A local `publish_at`
	 * inside the spring-forward gap (a wall-clock time that does not exist)
	 * is rejected; a `publish_at` on the autumn-fold side (a wall-clock time
	 * that exists twice) and an ordinary DST-observing instant both round-trip
	 * to the intended UTC instant.
	 *
	 * The gap/fold boundaries are derived from `DateTimeZone::getTransitions()`
	 * -- an independent source of truth from `PublishAt`, which never calls
	 * it -- so this check does not compare production output against itself.
	 *
	 * @return void
	 */
	private function verify_dst_publish_at_correctness(): void {
		update_option( 'timezone_string', 'Europe/Warsaw' );
		update_option( 'gmt_offset', 0 );

		$this->register_abilities( true, true );
		$this->set_graph(
			array(
				array(
					'from' => 'draft',
					'to'   => 'future',
				),
			)
		);

		$this->assert_true( 'Europe/Warsaw' === wp_timezone()->getName(), 'wp_timezone() did not report Europe/Warsaw after setting timezone_string.' );

		$timezone = new DateTimeZone( 'Europe/Warsaw' );
		$now_ts   = time();
		$upcoming = array_values(
			array_filter(
				$timezone->getTransitions( $now_ts, $now_ts + 400 * DAY_IN_SECONDS ),
				static fn ( array $transition ): bool => $transition['ts'] > $now_ts
			)
		);
		$this->assert_true( 2 <= count( $upcoming ), 'Could not resolve two upcoming Europe/Warsaw DST transitions from the real tz database.' );
		$fall_back      = false === $upcoming[0]['isdst'] ? $upcoming[0] : $upcoming[1];
		$spring_forward = true === $upcoming[0]['isdst'] ? $upcoming[0] : $upcoming[1];
		$this->assert_true(
			$fall_back !== $spring_forward && $fall_back['ts'] < $spring_forward['ts'],
			'Could not distinguish the next Europe/Warsaw fall-back and spring-forward transitions.'
		);

		// Spring-forward gap: the wall-clock range this transition skips,
		// derived purely from the transitions' own offsets.
		$gap_start = $spring_forward['ts'] + $fall_back['offset'];
		$gap_end   = $spring_forward['ts'] + $spring_forward['offset'];
		$this->assert_true( $gap_end > $gap_start, 'The resolved spring-forward transition does not skip any wall-clock time.' );
		$gap_wire = gmdate( 'Y-m-d\TH:i:s', intdiv( $gap_start + $gap_end, 2 ) );

		$gap_post   = $this->create_fixture_post( 'draft', 'dst-gap' );
		$gap_result = $this->transition_ability->execute(
			array(
				'post_id'       => $gap_post,
				'version_token' => $this->current_version_token( $gap_post ),
				'target_status' => 'future',
				'publish_at'    => $gap_wire,
			)
		);
		$this->assert_error_code( $gap_result, 'wpcb_invalid_input', 'A publish_at inside the Europe/Warsaw spring-forward gap' );
		$gap_after = get_post( $gap_post );
		$this->assert_true( $gap_after instanceof WP_Post && 'draft' === $gap_after->post_status, 'A rejected DST-gap publish_at changed post_status anyway.' );

		// Autumn fold: an unambiguous absolute instant 30 minutes after the
		// fall-back moment (the post-transition / standard-time side).
		// PublishAt documents resolving the ambiguous wall-clock hour to
		// exactly this side; round-tripping it back must return this same
		// UTC instant.
		$fold_utc_ts = $fall_back['ts'] + 1800;
		$fold_wire   = gmdate( 'Y-m-d\TH:i:s', $fold_utc_ts + $fall_back['offset'] );
		$fold_utc    = new DateTimeImmutable( '@' . $fold_utc_ts );

		$fold_post   = $this->create_fixture_post( 'draft', 'dst-fold' );
		$fold_result = $this->transition_ability->execute(
			array(
				'post_id'       => $fold_post,
				'version_token' => $this->current_version_token( $fold_post ),
				'target_status' => 'future',
				'publish_at'    => $fold_wire,
			)
		);
		$this->assert_not_error( $fold_result, 'A publish_at inside the Europe/Warsaw autumn fold' );
		if ( ! is_array( $fold_result ) ) {
			throw new RuntimeException( 'Fold transition result is not an array.' );
		}
		$this->assert_true( $fold_utc->format( 'Y-m-d\TH:i:s\Z' ) === $fold_result['publish_at']['utc'], 'A fold-side publish_at did not round-trip to the intended UTC instant.' );
		$fold_stored = get_post( $fold_post );
		$this->assert_true( $fold_stored instanceof WP_Post && 'future' === $fold_stored->post_status, 'A fold-side scheduled transition did not store future.' );
		$this->assert_true( $fold_utc->format( 'Y-m-d H:i:s' ) === $fold_stored->post_date_gmt, 'A fold-side scheduled transition did not store the intended UTC instant.' );

		// Ordinary instant: 20 days out, safely clear of any transition --
		// proving normal DST-observing offsets round-trip too, not only the
		// two edge cases above.
		$ordinary_utc_ts = $now_ts + ( 20 * DAY_IN_SECONDS );
		$this->assert_true( $ordinary_utc_ts < ( $fall_back['ts'] - DAY_IN_SECONDS ), 'The ordinary DST fixture instant is not safely clear of the fall-back transition.' );
		$ordinary_offset = $timezone->getOffset( new DateTimeImmutable( '@' . $ordinary_utc_ts ) );
		$ordinary_wire   = gmdate( 'Y-m-d\TH:i:s', $ordinary_utc_ts + $ordinary_offset );
		$ordinary_utc    = new DateTimeImmutable( '@' . $ordinary_utc_ts );

		$ordinary_post   = $this->create_fixture_post( 'draft', 'dst-ordinary' );
		$ordinary_result = $this->transition_ability->execute(
			array(
				'post_id'       => $ordinary_post,
				'version_token' => $this->current_version_token( $ordinary_post ),
				'target_status' => 'future',
				'publish_at'    => $ordinary_wire,
			)
		);
		$this->assert_not_error( $ordinary_result, 'A publish_at at an ordinary Europe/Warsaw instant' );
		if ( ! is_array( $ordinary_result ) ) {
			throw new RuntimeException( 'Ordinary transition result is not an array.' );
		}
		$this->assert_true( $ordinary_utc->format( 'Y-m-d\TH:i:s\Z' ) === $ordinary_result['publish_at']['utc'], 'An ordinary publish_at did not round-trip to the intended UTC instant.' );
		$ordinary_stored = get_post( $ordinary_post );
		$this->assert_true(
			$ordinary_stored instanceof WP_Post && $ordinary_utc->format( 'Y-m-d H:i:s' ) === $ordinary_stored->post_date_gmt,
			'An ordinary publish_at did not store the intended UTC instant.'
		);
	}

	/**
	 * Check 10: a status-only transition creates or preserves a revision
	 * (measured: 0 -> 1), and its audit row records field names only, never
	 * values -- a deliberately identifiable marker planted in the fixture's
	 * title must never appear in `changed_fields`, matching
	 * `writes-seo-verification.php`'s own redaction check.
	 *
	 * @return void
	 */
	private function verify_transition_creates_revision_and_audits_field_names_only(): void {
		$this->register_abilities( true, true );
		$this->set_graph(
			array(
				array(
					'from' => 'draft',
					'to'   => 'pending',
				),
			)
		);

		$secret_marker = 'WPCBSECRET' . strtoupper( $this->token );
		$post_id       = $this->create_fixture_post( 'draft', $secret_marker );

		$revisions_before = count( wp_get_post_revisions( $post_id ) );

		global $wpdb;
		/**
		 * WordPress database abstraction object.
		 *
		 * @var wpdb $wpdb
		 */
		$table        = Installer::audit_table_name();
		$before_count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE ability = %s', $table, TransitionContentStatus::ABILITY ) );

		$result = $this->transition_ability->execute(
			array(
				'post_id'       => $post_id,
				'version_token' => $this->current_version_token( $post_id ),
				'target_status' => 'pending',
			)
		);
		$this->assert_not_error( $result, 'The revision/audit fixture transition' );

		$revisions_after = count( wp_get_post_revisions( $post_id ) );
		$this->assert_true( $revisions_after > $revisions_before, 'A status-only transition did not create or preserve a revision.' );

		$after_count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE ability = %s', $table, TransitionContentStatus::ABILITY ) );
		$this->assert_true( 1 === $after_count - $before_count, 'The transition did not record exactly one transition-content-status audit row.' );

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE ability = %s ORDER BY id DESC LIMIT 1', $table, TransitionContentStatus::ABILITY ),
			ARRAY_A
		);
		$this->assert_true( null !== $row, 'No audit row was found for transition-content-status.' );
		$this->assert_true( array( 'status' ) === json_decode( (string) $row['changed_fields'], true ), 'audit changed_fields did not list exactly ["status"].' );
		$this->assert_true( false === strpos( (string) $row['changed_fields'], $secret_marker ), 'The audit row leaked a field value (the fixture title marker) instead of only field names.' );
	}

	/**
	 * Check 11: the whole roadmap flow end to end -- draft ->
	 * get-status-transitions -> pending -> get-status-transitions -> publish.
	 *
	 * @return void
	 */
	private function verify_end_to_end_roadmap_flow(): void {
		$this->register_abilities( true, true );
		$this->set_graph(
			array(
				array(
					'from' => 'draft',
					'to'   => 'pending',
				),
				array(
					'from' => 'pending',
					'to'   => 'publish',
				),
			)
		);

		$post_id = $this->create_fixture_post( 'draft' );

		$read_draft = $this->get_ability->execute( array( 'post_id' => $post_id ) );
		$this->assert_not_error( $read_draft, 'get-status-transitions from draft' );
		if ( ! is_array( $read_draft ) ) {
			throw new RuntimeException( 'get-status-transitions (draft) result is not an array.' );
		}
		$this->assert_true( 'draft' === $read_draft['current_status'], "get-status-transitions did not report the draft fixture's current status." );
		$pending_target = $this->find_target( $read_draft['targets'], 'pending' );
		$this->assert_true( null !== $pending_target, 'get-status-transitions from draft did not offer pending.' );
		$this->assert_true( false === $pending_target['requires_publish_gates'], 'pending was reported as requiring publish gates.' );
		$this->assert_true( null === $this->find_target( $read_draft['targets'], 'publish' ), 'get-status-transitions from draft offered publish, which is not configured from draft.' );

		$step1 = $this->transition_ability->execute(
			array(
				'post_id'       => $post_id,
				'version_token' => $read_draft['version_token'],
				'target_status' => 'pending',
			)
		);
		$this->assert_not_error( $step1, 'draft -> pending' );
		$after_step1 = get_post( $post_id );
		$this->assert_true( $after_step1 instanceof WP_Post && 'pending' === $after_step1->post_status, 'draft -> pending did not store pending.' );

		$read_pending = $this->get_ability->execute( array( 'post_id' => $post_id ) );
		$this->assert_not_error( $read_pending, 'get-status-transitions from pending' );
		if ( ! is_array( $read_pending ) ) {
			throw new RuntimeException( 'get-status-transitions (pending) result is not an array.' );
		}
		$this->assert_true( 'pending' === $read_pending['current_status'], 'get-status-transitions did not report pending after the first transition.' );
		$publish_target = $this->find_target( $read_pending['targets'], 'publish' );
		$this->assert_true( null !== $publish_target, 'get-status-transitions from pending did not offer publish.' );
		$this->assert_true( true === $publish_target['requires_publish_gates'], 'publish was not reported as requiring publish gates.' );
		$this->assert_true(
			true === $publish_target['gates']['publish_enabled']
				&& true === $publish_target['gates']['publish_capability']
				&& true === $publish_target['gates']['native_publish_post'],
			'publish gates were not all reported satisfied for the administrator fixture with wpcb_publish_enabled on.'
		);

		$step2 = $this->transition_ability->execute(
			array(
				'post_id'       => $post_id,
				'version_token' => $read_pending['version_token'],
				'target_status' => 'publish',
			)
		);
		$this->assert_not_error( $step2, 'pending -> publish' );
		if ( ! is_array( $step2 ) ) {
			throw new RuntimeException( 'pending -> publish result is not an array.' );
		}
		$published = get_post( $post_id );
		$this->assert_true( $published instanceof WP_Post && 'publish' === $published->post_status, 'pending -> publish did not store publish.' );
		$this->assert_true( 'publish' === $step2['status'], 'The pending -> publish response did not report the stored status.' );
	}

	/**
	 * Extra check (beyond the plan): pins the deliberate per-target gates
	 * semantics in `GetStatusTransitions::describe_target()`. For a
	 * non-privileged target (`draft`), `requires_publish_gates` is false and
	 * all three `gates` report true regardless of the real
	 * `wpcb_publish_enabled` value; for a privileged target (`publish`) they
	 * reflect the real flag and the real held capabilities. A future edit
	 * must not silently flip either half of this.
	 *
	 * @return void
	 */
	private function verify_gates_are_trivially_satisfied_for_nonprivileged_targets(): void {
		$this->register_abilities( true, false );
		$this->set_graph(
			array(
				array(
					'from' => 'pending',
					'to'   => 'draft',
				),
				array(
					'from' => 'pending',
					'to'   => 'publish',
				),
			)
		);

		$post_id = $this->create_fixture_post( 'pending' );
		$read    = $this->get_ability->execute( array( 'post_id' => $post_id ) );
		$this->assert_not_error( $read, 'get-status-transitions for the gates-semantics fixture' );
		if ( ! is_array( $read ) ) {
			throw new RuntimeException( 'get-status-transitions result is not an array.' );
		}

		$draft_target = $this->find_target( $read['targets'], 'draft' );
		$this->assert_true( null !== $draft_target, 'The non-privileged draft target was not offered.' );
		$this->assert_true( false === $draft_target['requires_publish_gates'], 'draft was reported as requiring publish gates.' );
		$this->assert_true(
			true === $draft_target['gates']['publish_enabled']
				&& true === $draft_target['gates']['publish_capability']
				&& true === $draft_target['gates']['native_publish_post'],
			'A non-privileged target did not report all three gates as trivially satisfied despite wpcb_publish_enabled being off.'
		);

		$publish_target = $this->find_target( $read['targets'], 'publish' );
		$this->assert_true( null !== $publish_target, 'The privileged publish target was not offered.' );
		$this->assert_true( true === $publish_target['requires_publish_gates'], 'publish was not reported as requiring publish gates.' );
		$this->assert_true( false === $publish_target['gates']['publish_enabled'], 'A privileged target did not reflect the real (off) wpcb_publish_enabled value.' );
		$this->assert_true(
			true === $publish_target['gates']['publish_capability'] && true === $publish_target['gates']['native_publish_post'],
			"A privileged target did not reflect the administrator fixture's real (held) capabilities."
		);
	}

	/**
	 * Extra check (beyond the plan): the mutation repository's own read-back
	 * defence, exercised directly against
	 * `WordPressContentMutationRepository::transition_status()` rather than
	 * through the ability. Per the measured fact that `wp_update_post()`
	 * with `future` and a past date silently stores `publish`, this defence
	 * *detects* that rewrite and throws rather than reporting success -- it
	 * does not roll the write back, so the post is left exactly as WordPress
	 * itself stored it. Gate 7 (PublishAt rejecting a non-future publish_at
	 * before any write is attempted) is what keeps this path unreachable
	 * through transition-content-status; this check is the only place the
	 * repository's own defence in depth is exercised.
	 *
	 * @return void
	 */
	private function verify_repository_read_back_defence_catches_a_rewritten_transition(): void {
		$post_id    = $this->create_fixture_post( 'draft', 'repo-defence' );
		$repository = new WordPressContentMutationRepository();

		$past = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );

		$threw = false;
		try {
			$repository->transition_status(
				$post_id,
				'future',
				array(
					'local' => $past,
					'utc'   => $past,
				)
			);
		} catch ( MutationWriteFailed ) {
			$threw = true;
		}
		$this->assert_true( $threw, 'transition_status() with a past-dated future target did not throw when WordPress rewrote the stored status.' );

		$stored = get_post( $post_id );
		$this->assert_true(
			$stored instanceof WP_Post && 'publish' === $stored->post_status,
			'WordPress did not exhibit the measured rewrite (future + past date -> publish); this defence could not be exercised as designed.'
		);
	}

	/**
	 * Replaces the stored transition graph for the `post` type used by every fixture.
	 *
	 * @param array $pairs List of `{from, to}` pairs.
	 * @phpstan-param list<array{from: string, to: string}> $pairs
	 * @return void
	 */
	private function set_graph( array $pairs ): void {
		update_option( WordPressStatusTransitionRepository::OPTION_NAME, array( 'post' => $pairs ), false );
	}

	/** Removes the stored transition graph option entirely (never configured). */
	private function clear_graph(): void {
		delete_option( WordPressStatusTransitionRepository::OPTION_NAME );
	}

	/**
	 * Creates one fixture post with a unique, token-prefixed marker title.
	 *
	 * @param string $status Initial post status.
	 * @param string $suffix Extra marker text appended to the title, for
	 *                        checks that need to identify one particular fixture.
	 * @return int
	 */
	private function create_fixture_post( string $status = 'draft', string $suffix = '' ): int {
		$post_id = wp_insert_post(
			array(
				'post_type'    => 'post',
				'post_status'  => $status,
				'post_author'  => $this->admin_id,
				'post_title'   => 'WPCB status fixture ' . $this->token . ( '' !== $suffix ? ' ' . $suffix : '' ) . ' ' . count( $this->post_ids ),
				'post_content' => 'Fixture content for status-workflow verification.',
			),
			true
		);
		$this->assert_true( ! is_wp_error( $post_id ), 'Could not create a status-workflow fixture post.' );
		$post_id          = (int) $post_id;
		$this->post_ids[] = $post_id;

		return $post_id;
	}

	/**
	 * Reads the current optimistic-concurrency token for a fixture post.
	 *
	 * @param int $post_id Fixture post ID.
	 * @return string
	 */
	private function current_version_token( int $post_id ): string {
		$post = get_post( $post_id );
		$this->assert_true( $post instanceof WP_Post, 'Fixture post could not be re-read.' );

		return PostVersionTokenFactory::for_post( $post )->to_string();
	}

	/**
	 * Formats a site-local `publish_at` wire string a fixed number of days
	 * from the real current instant, using the real site clock.
	 *
	 * @param int $days Days from now.
	 * @return string
	 */
	private function future_local_wire( int $days ): string {
		$clock = new WordPressSiteClock();

		return $clock->now()->modify( "+{$days} days" )->setTimezone( $clock->timezone() )->format( 'Y-m-d\TH:i:s' );
	}

	/**
	 * Finds one target descriptor by its `target_status`.
	 *
	 * @param array  $targets       Targets array from a get-status-transitions result.
	 * @param string $target_status Status to find.
	 * @return array|null
	 * @phpstan-param list<mixed> $targets
	 * @phpstan-return array<string, mixed>|null
	 */
	private function find_target( array $targets, string $target_status ): ?array {
		foreach ( $targets as $target ) {
			if ( is_array( $target ) && ( $target['target_status'] ?? null ) === $target_status ) {
				return $target;
			}
		}

		return null;
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

		foreach ( array( GetStatusTransitions::ABILITY, TransitionContentStatus::ABILITY ) as $ability_id ) {
			if ( wp_has_ability( $ability_id ) ) {
				wp_unregister_ability( $ability_id );
			}
		}

		foreach ( $this->original_options as $name => $value ) {
			$this->restore_option( $name, $value );
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
try {
	( new WPCB_Status_Workflow_Verification() )->run();
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

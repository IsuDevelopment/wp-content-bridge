<?php
/**
 * Debounced llms.txt regeneration trigger wiring.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Infrastructure\WordPress;

use IsuDev\WPContentBridge\Application\Llms\LlmsArtifactStore;
use IsuDev\WPContentBridge\Domain\Llms\LlmsRegenerationTrigger;
use WP_Post;

/**
 * Enqueues a debounced {@see LlmsRegenerationRunner::CRON_HOOK} run whenever
 * a content or SEO-eligibility transition can change what belongs in the
 * published llms.txt snapshot, per ADR 0023 and the llms.txt execution
 * plan's task 6. This class only ever decides *whether* and *when* to
 * enqueue; {@see LlmsRegenerationRunner} is the only thing that ever reads
 * posts or writes the artifact.
 *
 * **Entry points, and why there are only these.** `transition_post_status`
 * and the post-meta actions below fire only from authenticated
 * WordPress admin/editing flows and from this plugin's own Abilities acting
 * through `wp_insert_post()`/`update_post_meta()`; nothing here is a REST
 * route, a query var, or an `init` hook a visitor's request could reach.
 * Combined with the fixed-deadline debounce in {@see self::maybe_enqueue()},
 * an anonymous visitor can observe that a run happened but can never cause
 * one, directly or by cache-busting.
 *
 * **`transition_post_status`, not `save_post`.** `save_post` never exposes
 * the status a post is *leaving*, only the one it now has, so "did this post
 * just stop being eligible" is not decidable from it — see
 * {@see \IsuDev\WPContentBridge\Domain\Llms\LlmsRegenerationTrigger} for the
 * concrete LLMagnet bug this avoids. `$old_status` is exactly what
 * `transition_post_status` adds.
 *
 * **The SEO-meta trigger is intentionally narrow.**
 * {@see WordPressLlmsSourceSelector} resolves `noindex` eligibility through
 * the provider-neutral `SeoProviderRegistry::active()->get()->resolved['robots']`
 * field, which does not correspond to any single WordPress hook: Yoast
 * computes it from several inputs (per-post override, taxonomy archive
 * settings, sitewide search-result settings). This class hooks only the
 * per-post explicit override meta key, {@see self::YOAST_NOINDEX_META_KEY} —
 * the common editorial action of an author toggling "no index" on one post
 * — the same way {@see WordPressLlmsOwnershipInspector} reads exactly one key
 * of Yoast's own option rather than the whole surface. Sitewide settings
 * changes are out of scope: they are rare administrator actions rather than
 * routine editorial ones, and reaching them would mean this trigger class
 * depending on Yoast's settings schema directly, which the rest of this
 * plugin deliberately avoids in favor of the provider-neutral port. That gap
 * is accepted, not hidden: an administrator who changes a sitewide indexing
 * setting can still reach eligibility staleness through
 * `regenerate-llms-txt`.
 */
final class LlmsRegenerationScheduler {

	/**
	 * Yoast's per-post explicit "no index" override meta key. Narrow and
	 * version-tied on purpose; see the class docblock for why only this one
	 * key, of everything that can affect the resolved `robots` field, is
	 * hooked.
	 *
	 * @var string
	 */
	private const YOAST_NOINDEX_META_KEY = '_yoast_wpseo_meta-robots-noindex';

	/**
	 * Default debounce window, in seconds, between the first trigger and the
	 * scheduled run.
	 *
	 * 90 seconds: long enough to collapse the burst of `transition_post_status`
	 * and meta-write calls one editorial save can produce (draft autosave
	 * cleanup, the actual status transition, a Yoast meta write, a REST
	 * autosave) into the single run the roadmap requires, while keeping the
	 * ADR 0023 staleness window — a post that leaves `publish` stays in the
	 * public snapshot until this runs — short enough that it never reads as
	 * "the plugin is broken." Within the plan's 60–120 second range, chosen
	 * closer to the middle than either edge: short debounce windows on a
	 * frequently edited site would refire on every save; long ones widen the
	 * exact leak window ADR 0023 names as a real cost.
	 *
	 * @var int
	 */
	private const DEFAULT_DEBOUNCE_SECONDS = 90;

	/**
	 * Creates the scheduler.
	 *
	 * @param LlmsArtifactStore $store Configuration read port; used only to
	 *                                 read the currently enabled post types,
	 *                                 never written to from this class.
	 */
	public function __construct(
		private readonly LlmsArtifactStore $store,
	) {
	}

	/**
	 * Registers every trigger hook.
	 *
	 * Registered unconditionally, matching this codebase's existing pattern
	 * for optional feature areas (for example `LlmsAbilities`): the
	 * `wpcb_llms_enabled` check lives inside each callback, not at the
	 * registration call site, so a flag flip takes effect on the next
	 * request without this method needing to be called conditionally.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'transition_post_status', array( $this, 'on_transition_post_status' ), 10, 3 );
		add_action( 'added_post_meta', array( $this, 'on_seo_meta_change' ), 10, 3 );
		add_action( 'updated_post_meta', array( $this, 'on_seo_meta_change' ), 10, 3 );
		add_action( 'deleted_post_meta', array( $this, 'on_seo_meta_change' ), 10, 3 );
	}

	/**
	 * Registers the watcher that unschedules a pending run — and discards any
	 * in-progress cursor and staging — the moment publication is disabled.
	 *
	 * Mirrors {@see LlmsTxtEndpoint::register_flush_watcher()}'s pattern of
	 * hooking both `add_option_…` and `update_option_…` for the same reason:
	 * `add_option` fires only the first time an option is created, and
	 * `update_option` never fires for that first write, so both are needed to
	 * observe every way the flag's value can settle to false.
	 *
	 * @return void
	 */
	public static function register_flag_watcher(): void {
		add_action( 'add_option_' . Installer::LLMS_ENABLED_OPTION, array( self::class, 'handle_flag_change' ) );
		add_action( 'update_option_' . Installer::LLMS_ENABLED_OPTION, array( self::class, 'handle_flag_change' ) );
	}

	/**
	 * Unschedules a pending run and discards partial-run state once
	 * publication is disabled.
	 *
	 * Discarding the cursor and staging, not only the scheduled event, is
	 * deliberate: resuming a paused run later, under a configuration that may
	 * have changed while publication was off, would let stale eligibility
	 * decisions leak into a future run's staged entries. A fresh run started
	 * after publication is re-enabled costs nothing this plugin cannot
	 * already afford — {@see LlmsRegenerationRunner} bounds every run
	 * regardless of history.
	 *
	 * @return void
	 */
	public static function handle_flag_change(): void {
		if ( get_option( Installer::LLMS_ENABLED_OPTION, false ) ) {
			return;
		}

		wp_clear_scheduled_hook( LlmsRegenerationRunner::CRON_HOOK );
		delete_option( Installer::LLMS_REGEN_CURSOR_OPTION );
		delete_option( Installer::LLMS_REGEN_STAGING_OPTION );
		delete_option( Installer::LLMS_REGEN_DIRTY_OPTION );
	}

	/**
	 * Enqueues regeneration when a post transitions into or out of
	 * eligibility for a currently enabled post type.
	 *
	 * @param string  $new_status Post status after the transition.
	 * @param string  $old_status Post status before the transition.
	 * @param WP_Post $post       The transitioning post.
	 * @return void
	 */
	public function on_transition_post_status( string $new_status, string $old_status, WP_Post $post ): void {
		if ( wp_is_post_autosave( $post ) || wp_is_post_revision( $post ) ) {
			return;
		}
		if ( ! get_option( Installer::LLMS_ENABLED_OPTION, false ) ) {
			return;
		}

		$config = $this->store->config();
		if ( null === $config ) {
			return;
		}

		if ( LlmsRegenerationTrigger::transition_warrants_regeneration( $old_status, $new_status, $post->post_type, $config->enabled_post_types ) ) {
			self::maybe_enqueue();
		}
	}

	/**
	 * Enqueues regeneration when the per-post explicit `noindex` override
	 * changes on a currently published post of a currently enabled post
	 * type. See the class docblock for why only this one meta key is
	 * watched.
	 *
	 * @param mixed  $meta_id_or_ids Meta row ID (`added_post_meta`, `updated_post_meta`)
	 *                               or list of deleted meta row IDs (`deleted_post_meta`); unused.
	 * @param int    $post_id        Post the changed meta belongs to.
	 * @param string $meta_key       Changed meta key.
	 * @return void
	 * @phpstan-param int|list<int> $meta_id_or_ids
	 */
	public function on_seo_meta_change( mixed $meta_id_or_ids, int $post_id, string $meta_key ): void {
		unset( $meta_id_or_ids );

		if ( self::YOAST_NOINDEX_META_KEY !== $meta_key ) {
			return;
		}
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( ! get_option( Installer::LLMS_ENABLED_OPTION, false ) ) {
			return;
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status ) {
			return;
		}

		$config = $this->store->config();
		if ( null === $config || ! in_array( $post->post_type, $config->enabled_post_types, true ) ) {
			return;
		}

		self::maybe_enqueue();
	}

	/**
	 * Enqueues a regeneration run using a **fixed-deadline** debounce, never
	 * a sliding window.
	 *
	 * If a run is already scheduled, this does nothing — it does not push the
	 * scheduled time later. That is the entire design decision this method
	 * exists to protect, and it must not be "improved" into rescheduling on
	 * every call:
	 *
	 * A sliding-window debounce (cancel-and-reschedule on every trigger, so
	 * the run always fires N seconds after the *most recent* trigger) makes
	 * the staleness window unbounded on any site with steady editing
	 * activity: as long as triggers keep arriving faster than the window,
	 * the run never fires at all, and ADR 0023's "a post that leaves
	 * `publish` stays in the snapshot until regeneration runs" staleness cost
	 * grows without limit. A fixed deadline — schedule once, at
	 * `time() + window`, and leave it alone — caps staleness at the window
	 * length regardless of how many further triggers arrive before it fires.
	 * `wp_next_scheduled()` already returning a timestamp is precisely the
	 * signal that a deadline is already set and this trigger's effect will be
	 * included in that run once it starts scanning; there is nothing to move.
	 *
	 * **The in-progress case, which that signal does not cover.** A batched
	 * run self-reschedules onto the same cron hook between ticks
	 * ({@see LlmsRegenerationRunner::run()}), so for the whole duration of a
	 * multi-tick run `wp_next_scheduled()` is truthy for a reason that has
	 * nothing to do with a debounce deadline. Returning early on that signal
	 * alone would silently drop every trigger arriving mid-run, and a run goes
	 * multi-tick as soon as a site has more candidate posts than one batch —
	 * not a rare shape. The dropped trigger is not merely late: a post
	 * un-published after an earlier tick already staged it stays staged, the
	 * run finalizes with it still in the document, and nothing further is
	 * queued, so withdrawn content remains public indefinitely until some
	 * unrelated edit happens to trigger another run. That is the same class of
	 * failure the plan documents in the reference implementation, reached
	 * through a different door.
	 *
	 * The presence of a cursor is what distinguishes the two cases: a run that
	 * has written one is mid-scan and owns the hook. Triggers arriving then
	 * set {@see Installer::LLMS_REGEN_DIRTY_OPTION}, and
	 * {@see LlmsRegenerationRunner::finalize()} consumes it to enqueue a fresh
	 * run. Staleness therefore stays bounded by the run's own length plus one
	 * debounce window, rather than becoming unbounded.
	 *
	 * @return void
	 */
	public static function maybe_enqueue(): void {
		if ( false !== get_option( Installer::LLMS_REGEN_CURSOR_OPTION, false ) ) {
			update_option( Installer::LLMS_REGEN_DIRTY_OPTION, true, false );

			return;
		}

		if ( false !== wp_next_scheduled( LlmsRegenerationRunner::CRON_HOOK ) ) {
			return;
		}

		wp_schedule_single_event( time() + self::debounce_window_seconds(), LlmsRegenerationRunner::CRON_HOOK );
	}

	/**
	 * Returns the filtered debounce window in seconds.
	 *
	 * @return int
	 */
	public static function debounce_window_seconds(): int {
		/**
		 * Filters the llms.txt debounced regeneration window, in seconds.
		 *
		 * @param int $seconds Default window.
		 */
		$seconds = apply_filters( 'wpcb_llms_regenerate_debounce_seconds', self::DEFAULT_DEBOUNCE_SECONDS );

		return is_int( $seconds ) && 0 < $seconds ? $seconds : self::DEFAULT_DEBOUNCE_SECONDS;
	}
}

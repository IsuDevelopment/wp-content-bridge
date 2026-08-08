<?php
/**
 * Batched llms.txt regeneration cron handler.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Infrastructure\WordPress;

use IsuDev\WPContentBridge\Application\Llms\LlmsArtifactStore;
use IsuDev\WPContentBridge\Domain\Llms\LlmsConfig;
use IsuDev\WPContentBridge\Domain\Llms\LlmsDocumentBuilder;
use IsuDev\WPContentBridge\Domain\Llms\LlmsSourceEntry;
use WP_Query;

/**
 * Executes the debounced `wpcb_llms_regenerate` cron event
 * ({@see LlmsRegenerationScheduler} enqueues it) in bounded batches, per ADR
 * 0023 task 6.
 *
 * **Why cursor-based batching at all.** {@see WordPressLlmsSourceSelector::select()}
 * is already internally bounded (never `-1`, never the whole site at once),
 * but that bound is per single PHP execution: a site whose eligible content
 * requires scanning many candidates to fill its quotas could still make one
 * cron tick run long enough to risk a `max_execution_time` abort. This class
 * adopts the shape the llms.txt execution plan directs studying in LLMagnet
 * 3.4.3's `Generator`: a non-autoloaded cursor option holding `offset` and
 * `started`, a bounded batch per tick, a hard per-run ceiling as a safety
 * valve, self-rescheduling one minute out while work remains, and clearing
 * the cursor when a run completes.
 *
 * **Where this deliberately does not copy LLMagnet.** Its batches accumulate
 * by writing and later pruning individual `.md` files under a physical
 * directory — a whole class of orphan-file bookkeeping this plugin has no
 * use for, because ADR 0023 stores one document in one option. Here, batches
 * instead accumulate into a **separate staging option**
 * (`Installer::LLMS_REGEN_STAGING_OPTION`) and the public snapshot
 * (`LlmsArtifactStore::replace_artifact()`, backed by
 * `WordPressLlmsArtifactStore::ARTIFACT_OPTION` — the same option
 * {@see LlmsTxtEndpoint} reads on every front-end request) is replaced
 * exactly once, atomically, in {@see self::finalize()}, only after every
 * batch for the run has been gathered. A reader hitting `/llms.txt` mid-run
 * therefore always gets the previous complete snapshot, or none, and never a
 * partially assembled document — the one requirement task 6 calls out as the
 * deliberate difference from the reference implementation.
 *
 * **No unbounded work.** `$max_posts` per run is a hard ceiling, not a
 * target: once the cumulative scanned-post count reaches it, this finalizes
 * with whatever was staged so far rather than looping indefinitely. Combined
 * with the fixed-deadline debounce in {@see LlmsRegenerationScheduler}, a run
 * always starts, always finishes, and never grows without bound no matter how
 * large the site or how often triggers arrive while it runs.
 */
final class LlmsRegenerationRunner {

	/**
	 * The one scheduled cron hook this slice introduces. Fixed by the
	 * execution plan; {@see LlmsRegenerationScheduler} and `Installer`
	 * reference this constant rather than repeating the literal.
	 *
	 * @var string
	 */
	public const CRON_HOOK = 'wpcb_llms_regenerate';

	/**
	 * Default number of candidate posts scanned per cron tick. Filterable;
	 * matches the reference implementation's own default, which this plan
	 * explicitly directs adopting the shape of.
	 *
	 * @var int
	 */
	private const DEFAULT_BATCH_SIZE = 200;

	/**
	 * Default hard ceiling on candidate posts scanned across one run.
	 * Filterable; a safety valve for a very large site, not a target to
	 * reach on every run — most runs finish long before it.
	 *
	 * @var int
	 */
	private const DEFAULT_MAX_POSTS_PER_RUN = 1000;

	/**
	 * Creates the runner.
	 *
	 * @param LlmsArtifactStore           $store    Configuration and snapshot read/write port.
	 * @param WordPressLlmsSourceSelector $selector Concrete selector: this class calls its
	 *                                               public batching helpers, which are not part
	 *                                               of the {@see \IsuDev\WPContentBridge\Application\Llms\LlmsSourceSelector}
	 *                                               port because no other caller needs them.
	 * @param LlmsDocumentBuilder         $builder  Pure document generator.
	 */
	public function __construct(
		private readonly LlmsArtifactStore $store,
		private readonly WordPressLlmsSourceSelector $selector,
		private readonly LlmsDocumentBuilder $builder,
	) {
	}

	/**
	 * Registers the cron callback.
	 *
	 * Registered unconditionally, matching this codebase's existing pattern
	 * for optional feature areas (for example `LlmsAbilities`): the flag and
	 * configuration checks live inside {@see self::run()} itself, not at the
	 * registration call site, so a flag flip takes effect on the next request
	 * without needing this method called conditionally.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( self::CRON_HOOK, array( $this, 'run' ) );
	}

	/**
	 * Executes one cron tick: scans one bounded batch of candidate posts,
	 * accumulates eligible entries into the staging option, and either
	 * finalizes the run or self-reschedules the next tick.
	 *
	 * @return void
	 */
	public function run(): void {
		if ( ! get_option( Installer::LLMS_ENABLED_OPTION, false ) ) {
			$this->clear_cursor();
			return;
		}

		$config = $this->store->config();
		if ( null === $config ) {
			$this->clear_cursor();
			return;
		}

		$cursor     = $this->read_cursor();
		$staged     = $this->read_staging();
		$batch_size = self::filtered_positive_int( 'wpcb_llms_regenerate_batch_size', self::DEFAULT_BATCH_SIZE );
		$max_posts  = self::filtered_positive_int( 'wpcb_llms_regenerate_max_posts', self::DEFAULT_MAX_POSTS_PER_RUN );
		$post_types = $this->selector->public_configured_post_types( $config );

		if ( array() === $post_types || $cursor['offset'] >= $max_posts ) {
			$this->finalize( $config, $staged );
			return;
		}

		$limit    = min( $batch_size, $max_posts - $cursor['offset'] );
		$post_ids = $this->batch_post_ids( $post_types, $cursor['offset'], $limit );
		$staged   = $this->accumulate( $staged, $post_ids, $config );

		$next_offset = $cursor['offset'] + count( $post_ids );
		$exhausted   = count( $post_ids ) < $limit;

		if ( $exhausted || $next_offset >= $max_posts ) {
			$this->finalize( $config, $staged );
			return;
		}

		$this->write_cursor(
			array(
				'offset'  => $next_offset,
				'started' => $cursor['started'],
			)
		);
		$this->write_staging( $staged );

		// See class docblock: one minute out, not sooner, matching the shape
		// this plan directs adopting; the fixed one-minute spacing (rather
		// than a filterable delay) keeps consecutive ticks of the *same* run
		// cheap and predictable without reopening the debounce question the
		// scheduler already answers for *starting* a run.
		wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::CRON_HOOK );
	}

	/**
	 * Queries one bounded page of candidate post IDs across every publicly
	 * configured post type, ordered and offset so repeated calls advance
	 * through the same candidate set without overlap or gaps.
	 *
	 * @param array $post_types Publicly configured post types.
	 * @param int   $offset     Cursor offset to resume from.
	 * @param int   $limit      Bounded page size.
	 * @return array
	 * @phpstan-param list<string> $post_types
	 * @phpstan-return list<int>
	 */
	private function batch_post_ids( array $post_types, int $offset, int $limit ): array {
		$query = new WP_Query(
			array(
				'post_type'              => $post_types,
				'post_status'            => 'publish',
				'has_password'           => false,
				'fields'                 => 'ids',
				'posts_per_page'         => $limit,
				'offset'                 => $offset,
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'ignore_sticky_posts'    => true,
				'cache_results'          => false,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		$post_ids = array();
		foreach ( $query->posts as $post_id ) {
			if ( is_int( $post_id ) ) {
				$post_ids[] = $post_id;
			}
		}

		return $post_ids;
	}

	/**
	 * Extends the staged entry list with every eligible post from one batch,
	 * respecting the same per-section item cap
	 * {@see WordPressLlmsSourceSelector::select()} enforces so a batched run
	 * and a synchronous Ability-triggered run agree on what a "full" section
	 * looks like.
	 *
	 * @param array      $staged   Entries staged so far, wire-shaped.
	 * @param array      $post_ids Candidate post IDs from the current batch.
	 * @param LlmsConfig $config   Effective configuration.
	 * @return array
	 * @phpstan-param list<array{title: string, url: string, excerpt: string|null, section: string}> $staged
	 * @phpstan-param list<int> $post_ids
	 * @phpstan-return list<array{title: string, url: string, excerpt: string|null, section: string}>
	 */
	private function accumulate( array $staged, array $post_ids, LlmsConfig $config ): array {
		$item_cap = min( $config->max_items_per_section, LlmsConfig::MAX_ITEMS_PER_SECTION );
		$counts   = self::section_counts( $staged );

		foreach ( $post_ids as $post_id ) {
			$post_type = get_post_type( $post_id );
			if ( ! is_string( $post_type ) ) {
				continue;
			}

			$entry = $this->selector->eligible_entry( $post_id, $post_type, $config );
			if ( null === $entry ) {
				continue;
			}

			if ( ( $counts[ $entry->section ] ?? 0 ) >= $item_cap ) {
				continue;
			}

			$counts[ $entry->section ] = ( $counts[ $entry->section ] ?? 0 ) + 1;
			$staged[]                  = array(
				'title'   => $entry->title,
				'url'     => $entry->url,
				'excerpt' => $entry->excerpt,
				'section' => $entry->section,
			);
		}

		return $staged;
	}

	/**
	 * Counts already-staged entries by section key.
	 *
	 * @param array $staged Entries staged so far, wire-shaped.
	 * @return array
	 * @phpstan-param list<array{title: string, url: string, excerpt: string|null, section: string}> $staged
	 * @phpstan-return array<string, int>
	 */
	private static function section_counts( array $staged ): array {
		$counts = array();
		foreach ( $staged as $item ) {
			$counts[ $item['section'] ] = ( $counts[ $item['section'] ] ?? 0 ) + 1;
		}

		return $counts;
	}

	/**
	 * Builds the final document from every staged entry and atomically
	 * replaces the public snapshot — the one and only write to
	 * `LlmsArtifactStore::replace_artifact()` this class ever performs, and
	 * always with the whole, completed document. Idempotent for an unchanged
	 * result, matching {@see \IsuDev\WPContentBridge\Application\Llms\RegenerateLlmsTxt}:
	 * an unchanged rebuild must not churn the stored hash or generation time.
	 *
	 * @param LlmsConfig $config Effective configuration.
	 * @param array      $staged Every entry staged across the whole run.
	 * @phpstan-param list<array{title: string, url: string, excerpt: string|null, section: string}> $staged
	 * @return void
	 */
	private function finalize( LlmsConfig $config, array $staged ): void {
		$entries = array_map(
			static fn ( array $item ): LlmsSourceEntry => new LlmsSourceEntry(
				$item['title'],
				$item['url'],
				$item['excerpt'],
				$item['section']
			),
			$staged
		);

		$candidate = $this->builder->build( $config, $entries );
		$current   = $this->store->artifact();

		if ( null === $current || $current->content_hash !== $candidate->content_hash ) {
			$this->store->replace_artifact( $candidate );
		}

		/*
		 * Consume the mid-run trigger marker before clearing state, and queue a
		 * fresh run if one was set.
		 *
		 * Anything that changed eligibility while this run was scanning may
		 * already have been staged under its pre-change state — a post
		 * un-published after an earlier tick staged it is the case that
		 * matters, because the document just written still lists it. Reading
		 * the marker here, rather than letting the scheduler's
		 * `wp_next_scheduled()` check absorb those triggers, is what keeps that
		 * from persisting until an unrelated edit. See
		 * `LlmsRegenerationScheduler::maybe_enqueue()`.
		 */
		$retrigger = (bool) get_option( Installer::LLMS_REGEN_DIRTY_OPTION, false );

		$this->clear_cursor();

		if ( $retrigger ) {
			LlmsRegenerationScheduler::maybe_enqueue();
		}
	}

	/**
	 * Reads the cursor, initializing a fresh one — and discarding any stale
	 * staging left by a differently-shaped or aborted prior cursor — when
	 * none is stored or the stored value does not shape-check.
	 *
	 * @return array
	 * @phpstan-return array{offset: int, started: string}
	 */
	private function read_cursor(): array {
		$value = get_option( Installer::LLMS_REGEN_CURSOR_OPTION, false );
		if (
			is_array( $value )
			&& isset( $value['offset'], $value['started'] )
			&& is_int( $value['offset'] )
			&& is_string( $value['started'] )
		) {
			return array(
				'offset'  => $value['offset'],
				'started' => $value['started'],
			);
		}

		$this->write_staging( array() );

		return array(
			'offset'  => 0,
			'started' => gmdate( 'Y-m-d\TH:i:s\Z' ),
		);
	}

	/**
	 * Reads the staged entry list, treating anything that does not
	 * shape-check as absent rather than throwing, matching this codebase's
	 * other option-backed adapters.
	 *
	 * @return array
	 * @phpstan-return list<array{title: string, url: string, excerpt: string|null, section: string}>
	 */
	private function read_staging(): array {
		$value = get_option( Installer::LLMS_REGEN_STAGING_OPTION, false );
		if ( ! is_array( $value ) ) {
			return array();
		}

		$entries = array();
		foreach ( $value as $item ) {
			if (
				is_array( $item )
				&& isset( $item['title'], $item['url'], $item['section'] )
				&& is_string( $item['title'] )
				&& is_string( $item['url'] )
				&& is_string( $item['section'] )
				&& ( ! array_key_exists( 'excerpt', $item ) || is_string( $item['excerpt'] ) || null === $item['excerpt'] )
			) {
				$entries[] = array(
					'title'   => $item['title'],
					'url'     => $item['url'],
					'excerpt' => is_string( $item['excerpt'] ?? null ) ? $item['excerpt'] : null,
					'section' => $item['section'],
				);
			}
		}

		return $entries;
	}

	/**
	 * Persists the cursor.
	 *
	 * @param array $cursor Cursor to persist.
	 * @phpstan-param array{offset: int, started: string} $cursor
	 * @return void
	 */
	private function write_cursor( array $cursor ): void {
		update_option( Installer::LLMS_REGEN_CURSOR_OPTION, $cursor, false );
	}

	/**
	 * Persists the staged entry list.
	 *
	 * @param array $staged Entries staged so far, wire-shaped.
	 * @phpstan-param list<array{title: string, url: string, excerpt: string|null, section: string}> $staged
	 * @return void
	 */
	private function write_staging( array $staged ): void {
		update_option( Installer::LLMS_REGEN_STAGING_OPTION, $staged, false );
	}

	/**
	 * Deletes the cursor and staging options, leaving no partial-run state
	 * behind. Called on every terminal path: flag off, no configuration, and
	 * a completed {@see self::finalize()}.
	 *
	 * @return void
	 */
	private function clear_cursor(): void {
		delete_option( Installer::LLMS_REGEN_CURSOR_OPTION );
		delete_option( Installer::LLMS_REGEN_STAGING_OPTION );
		delete_option( Installer::LLMS_REGEN_DIRTY_OPTION );
	}

	/**
	 * Reads a filterable positive-integer tuning value, falling back to its
	 * default on anything that is not a positive integer.
	 *
	 * @param string $filter   Filter name.
	 * @param int    $fallback Fallback value.
	 * @return int
	 * @phpstan-param non-empty-string $filter
	 */
	private static function filtered_positive_int( string $filter, int $fallback ): int {
		/**
		 * Filters a batching tuning value for the llms.txt regeneration cron job.
		 *
		 * @param int $value Default value.
		 */
		$value = apply_filters( $filter, $fallback );

		return is_int( $value ) && 0 < $value ? $value : $fallback;
	}
}

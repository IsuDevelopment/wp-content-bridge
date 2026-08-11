<?php
/**
 * Virtual /llms.txt endpoint.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Infrastructure\WordPress;

use DateTimeImmutable;
use DateTimeZone;
use IsuDev\WPContentBridge\Domain\Llms\LlmsArtifact;
use WP;

/**
 * Serves the stored llms.txt snapshot at `/llms.txt` through a rewrite rule
 * and a `parse_request` handler, per ADR 0023
 * (`docs/adr/0023-llms-txt-is-published-through-a-virtual-endpoint.md`).
 *
 * Three rules from that ADR shape every method here and must not be relaxed
 * by a future edit:
 *
 * 1. **Off means never installed.** {@see register_hooks()} is called by the
 *    composition root only while `Installer::LLMS_ENABLED_OPTION` is true. No
 *    rewrite rule, no query var, no `parse_request` listener exists while the
 *    flag is off, so the path 404s exactly the way any other unmapped URL
 *    does — not because this class decided to answer 403 or 404, but because
 *    nothing here answers at all.
 * 2. **The request path is one option read.** {@see maybe_handle_request()}
 *    calls `LlmsArtifactStore::artifact()` and nothing else that touches the
 *    database, the filesystem, or an SEO provider. It never builds a
 *    snapshot, never schedules one, and never writes anything. Generation is
 *    exclusively an authenticated Ability's or a scheduled job's job.
 * 3. **No stored snapshot still 404s**, and does so through WordPress's own
 *    404 machinery rather than a hand-rolled one; see
 *    {@see maybe_handle_request()} for which mechanism and why.
 */
final class LlmsTxtEndpoint {

	/**
	 * Rewrite pattern for the canonical path, and the value
	 * {@see maybe_handle_request()} compares `WP::$matched_rule` against.
	 *
	 * The two uses must stay the same literal: matching on the rule is what
	 * makes `/llms.txt` the *only* URL that serves this document — see
	 * {@see maybe_handle_request()} for why that matters.
	 *
	 * @var string
	 */
	private const REWRITE_REGEX = '^llms\.txt$';

	/**
	 * Internal query var the rewrite rule targets.
	 *
	 * Deliberately **not** registered through the `query_vars` filter. A
	 * registered public query var is accepted on every URL of the site, so
	 * registering this one would make `/?wpcb_llms_txt=1`,
	 * `/any-page/?wpcb_llms_txt=1`, and every other variant serve the same
	 * document — an unbounded set of distinct, individually cacheable URLs all
	 * returning identical bytes. That is a duplicate-content surface for
	 * crawlers and pure fragmentation for shared caches, and it contradicts
	 * ADR 0023's single canonical path. Leaving it unregistered means
	 * `WP::parse_request()` drops it from `$wp->query_vars`, which costs
	 * nothing here because the handler keys off `WP::$matched_rule` instead.
	 *
	 * @var string
	 */
	private const QUERY_VAR = 'wpcb_llms_txt';

	/**
	 * Upper bound on how long a shared cache or browser may serve a response
	 * without revalidating.
	 *
	 * Five minutes. The snapshot only ever changes through a debounced,
	 * queued regeneration (Task 6), never synchronously with a request, so
	 * there is no cost to a cache holding a slightly stale copy — but the ADR
	 * also names a real staleness window (a post that leaves `publish` stays
	 * in the snapshot until regeneration runs), and an unbounded `max-age`
	 * would let a shared cache extend that window on top of the debounce
	 * itself, indefinitely, with no way for this plugin to shorten it short
	 * of a cache-buster it doesn't have. Five minutes keeps that compounding
	 * bounded and small while still absorbing the overwhelming majority of
	 * repeat crawler traffic; the strong `ETag` below makes every request
	 * that does revalidate a cheap `304` rather than a re-fetch.
	 *
	 * @var int
	 */
	private const CACHE_MAX_AGE_SECONDS = 300;

	/**
	 * Creates the endpoint.
	 *
	 * @param WordPressLlmsArtifactStore $store Snapshot store; the endpoint's only collaborator.
	 */
	public function __construct(
		private readonly WordPressLlmsArtifactStore $store,
	) {
	}

	/**
	 * Registers the rewrite rule, query var, and request handler.
	 *
	 * Callers must only invoke this while `Installer::LLMS_ENABLED_OPTION` is
	 * true. It is deliberately not self-gating on that option so the
	 * composition root's conditional call site is the single, auditable
	 * place that decides whether this route exists at all — see class
	 * docblock, rule 1.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'init', array( $this, 'add_rewrite_rule' ) );
		add_action( 'parse_request', array( $this, 'maybe_handle_request' ) );
	}

	/**
	 * Registers the flush machinery that keeps the rewrite rule in sync with
	 * `Installer::LLMS_ENABLED_OPTION`. Unlike {@see register_hooks()}, the
	 * composition root calls this unconditionally — a flag flip in either
	 * direction (adding the rule, or removing it) needs a flush, and this is
	 * also what notices the flag turning off.
	 *
	 * **Flush strategy, and why.** A naive `flush_rewrite_rules()` called
	 * directly from an `update_option_…` hook would flush the *stale* rule
	 * set: that hook fires mid-request, after this class's own `init` hook
	 * (priority 10, added — or not — based on the *old* flag value) has
	 * already run, and before anything re-evaluates the new one. Flushing
	 * unconditionally at `init` on every request was also rejected — it is
	 * the specific anti-pattern the task calls out, and it would turn every
	 * page load into a rewrite-rule rebuild.
	 *
	 * The one-shot flag {@see Installer::LLMS_FLUSH_NEEDED_OPTION} avoids
	 * both problems: `schedule_flush()` only ever records that a flush is
	 * owed, and {@see maybe_flush_rewrite_rules()} — hooked at `init`
	 * priority 20, after every rule-registering `init` callback in this
	 * plugin has run at the default priority 10 — consumes it on the
	 * *next* request, by which point `Plugin::boot()` has already read the
	 * current flag value and registered (or not) the matching rule for that
	 * request. `Installer::activate()` sets the same flag for the same
	 * reason: activation also happens mid-request, after that request's
	 * `init` already fired.
	 *
	 * @return void
	 */
	public static function register_flush_watcher(): void {
		add_action( 'add_option_' . Installer::LLMS_ENABLED_OPTION, array( self::class, 'schedule_flush' ) );
		add_action( 'update_option_' . Installer::LLMS_ENABLED_OPTION, array( self::class, 'schedule_flush' ) );
	}

	/**
	 * Records that a rewrite-rule flush is owed on the next request. Hooked
	 * onto `Installer::LLMS_ENABLED_OPTION` changing; never called directly
	 * from a request the flag change itself did not cause.
	 *
	 * @return void
	 */
	public static function schedule_flush(): void {
		update_option( Installer::LLMS_FLUSH_NEEDED_OPTION, true, false );
	}

	/**
	 * Consumes an owed rewrite-rule flush, if one was recorded.
	 *
	 * Must run at an `init` priority after every callback that registers or
	 * withholds this endpoint's rewrite rule for the *current* flag value —
	 * see {@see register_flush_watcher()} for why that ordering is what
	 * makes the flush correct rather than a no-op or a flush of stale rules.
	 *
	 * @return void
	 */
	public static function maybe_flush_rewrite_rules(): void {
		if ( ! get_option( Installer::LLMS_FLUSH_NEEDED_OPTION, false ) ) {
			return;
		}

		delete_option( Installer::LLMS_FLUSH_NEEDED_OPTION );
		flush_rewrite_rules( false );
	}

	/**
	 * Maps `/llms.txt` onto the internal query var, ahead of other rules.
	 *
	 * @return void
	 */
	public function add_rewrite_rule(): void {
		add_rewrite_rule( self::REWRITE_REGEX, 'index.php?' . self::QUERY_VAR . '=1', 'top' );
	}

	/**
	 * Reports whether the rewrite rule this class registers can ever fire.
	 *
	 * A site with plain (query-string) permalinks has no rewrite rules at
	 * all, so {@see add_rewrite_rule()} runs but the rule it adds is never
	 * consulted — the endpoint looks installed while `/llms.txt` 404s for a
	 * reason that has nothing to do with the publication flag or the stored
	 * snapshot. This class does not surface that as an admin notice itself
	 * (out of scope for this task); it only reports the fact so a caller can.
	 *
	 * @return bool
	 */
	public static function is_routable(): bool {
		$permalink_structure = get_option( 'permalink_structure', '' );

		return is_string( $permalink_structure ) && '' !== $permalink_structure;
	}

	/**
	 * Serves the stored snapshot for a matched `/llms.txt` request, or hands
	 * the request back to WordPress's normal 404 handling.
	 *
	 * **404 mechanism, and why.** On a cache miss this sets
	 * `$wp->query_vars = array( 'error' => '404' )` and returns, rather than
	 * calling `status_header( 404 )` and `exit` itself. That is the exact
	 * shape core's own `ms-files.php` rewrite fallback uses for "this rewrite
	 * matched but there is nothing to serve," and it is chosen deliberately
	 * over a hand-rolled response: `WP::main()` still runs `query_posts()`
	 * and `handle_404()` afterwards, so core sets the 404 status header
	 * itself, the active theme's own `404.php` renders, and any plugin that
	 * hooks the real `404_template` filter or `template_redirect` behaves
	 * exactly as it would for a URL that genuinely does not exist. A
	 * self-issued `status_header( 404 ); exit;` would skip all of that and
	 * would therefore be a *worse* match for "indistinguishable from a
	 * feature that was never installed" than deferring to core.
	 *
	 * **Why not `$wp->handle_404()` directly.** That method inspects query
	 * results core has not produced yet at the `parse_request` stage this
	 * class hooks; setting the `error` query var and letting `WP::main()`'s
	 * own later call to it run is the documented, core-sanctioned way to
	 * force that outcome from this early.
	 *
	 * **Why the gate is `WP::$matched_rule`, not a query var.** The obvious
	 * implementation registers `self::QUERY_VAR` through the `query_vars`
	 * filter and keys off `isset( $wp->query_vars[ … ] )`. That was measured
	 * on a live install and rejected: a registered query var is accepted on
	 * every URL of the site, so `/?wpcb_llms_txt=1`, `/any-page/?wpcb_llms_txt=1`
	 * and every other variant all returned the document with `200` and a
	 * five-minute `Cache-Control` — an unbounded set of distinct URLs serving
	 * identical bytes, which is a duplicate-content surface for crawlers and
	 * pure key fragmentation for shared caches. `WP::$matched_rule` is set by
	 * `WP::parse_request()` before this action fires and holds the rewrite
	 * pattern that actually matched, so comparing against it makes the
	 * canonical path the only URL that can reach this handler. On a site with
	 * plain permalinks no rule matches and nothing here fires — the same
	 * condition {@see is_routable()} reports.
	 *
	 * **Environment guards.** Returns immediately in admin, AJAX, cron, REST,
	 * and WP-CLI contexts. `/llms.txt` is a front-end route; none of those
	 * request kinds should ever be answered by it, and `parse_request` can
	 * fire during `wp-cron.php` and WP-CLI bootstraps that otherwise look
	 * like ordinary front-end requests.
	 *
	 * @param WP $wp Current request, as passed by the `parse_request` action.
	 * @return void
	 */
	public function maybe_handle_request( WP $wp ): void {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || defined( 'REST_REQUEST' ) || defined( 'WP_CLI' ) ) {
			return;
		}

		if ( self::REWRITE_REGEX !== $wp->matched_rule ) {
			return;
		}

		// Rule 2: the one and only read on this path.
		$artifact = $this->store->artifact();

		if ( null === $artifact ) {
			$wp->query_vars = array( 'error' => '404' );

			return;
		}

		$this->respond( $artifact );
	}

	/**
	 * Writes the snapshot's bytes, or a bodyless `304`, and exits.
	 *
	 * `exit` runs in every branch so nothing else in WordPress — the main
	 * query, template loading, any theme code — executes after this class
	 * has answered.
	 *
	 * No `Vary: Cookie` is sent, and none must be added later without first
	 * revisiting ADR 0023's cache-semantics section: the snapshot is public,
	 * anonymous, and identical for every requester, so varying the cache key
	 * on a per-visitor signal would only fragment shared caches for no
	 * behavioural difference. That stops being true the moment this response
	 * depends on the requester in any way.
	 *
	 * @param LlmsArtifact $artifact Stored snapshot to serve.
	 * @return void
	 */
	private function respond( LlmsArtifact $artifact ): void {
		$etag          = '"' . $artifact->content_hash . '"';
		$last_modified = self::http_date_from_generated_at( $artifact->generated_at );

		if ( self::request_is_not_modified( $etag, $last_modified ) ) {
			status_header( 304 );
			$this->send_cache_headers( $etag, $last_modified );
			exit;
		}

		status_header( 200 );
		header( 'Content-Type: text/plain; charset=utf-8' );

		/*
		 * Measured from the bytes actually being written, not from the stored
		 * `byte_count`. The generator sets that field to `strlen( $content )`
		 * so the two agree today, but they are two independently deserialized
		 * fields of one option: any future divergence — a partial write, a
		 * hand-edited row, a filter on the stored array — would announce a
		 * length the body does not have, and a client would either truncate
		 * the document or hang waiting for bytes that never arrive.
		 */
		header( 'Content-Length: ' . (string) strlen( $artifact->content ) );
		$this->send_cache_headers( $etag, $last_modified );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Content-Type above is text/plain; the stored Markdown document is the literal response body, and HTML-escaping it would corrupt it.
		echo $artifact->content;
		exit;
	}

	/**
	 * Sends the headers common to both the `200` and `304` branches.
	 *
	 * @param string      $etag          Strong, quoted `ETag` value.
	 * @param string|null $last_modified Pre-formatted `Last-Modified` value, if computable.
	 * @return void
	 */
	private function send_cache_headers( string $etag, ?string $last_modified ): void {
		header( 'ETag: ' . $etag );
		if ( null !== $last_modified ) {
			header( 'Last-Modified: ' . $last_modified );
		}
		header( 'Cache-Control: public, max-age=' . self::CACHE_MAX_AGE_SECONDS );
	}

	/**
	 * Decides whether the current request already holds a current copy,
	 * from `If-None-Match` and `If-Modified-Since`, without reading the
	 * snapshot body.
	 *
	 * @param string      $etag          Strong, quoted `ETag` for the current snapshot.
	 * @param string|null $last_modified Current snapshot's `Last-Modified` value, if computable.
	 * @return bool
	 */
	private static function request_is_not_modified( string $etag, ?string $last_modified ): bool {
		if ( isset( $_SERVER['HTTP_IF_NONE_MATCH'] ) && is_string( $_SERVER['HTTP_IF_NONE_MATCH'] ) ) {
			$if_none_match = sanitize_text_field( wp_unslash( $_SERVER['HTTP_IF_NONE_MATCH'] ) );
			if ( self::if_none_match_matches( $if_none_match, $etag ) ) {
				return true;
			}
		}

		if (
			null !== $last_modified
			&& isset( $_SERVER['HTTP_IF_MODIFIED_SINCE'] )
			&& is_string( $_SERVER['HTTP_IF_MODIFIED_SINCE'] )
		) {
			$if_modified_since = sanitize_text_field( wp_unslash( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) );

			return self::if_modified_since_satisfied( $if_modified_since, $last_modified );
		}

		return false;
	}

	/**
	 * Pure `If-None-Match` matcher, extracted so it can be unit-tested
	 * without a WordPress runtime.
	 *
	 * Handles the three shapes a real client sends: a bare `*`, a
	 * comma-separated list of quoted tags, and weak tags carrying a `W/`
	 * prefix. `If-None-Match` allows weak comparison, so the prefix is
	 * stripped before comparing rather than treated as a non-match.
	 *
	 * @param string $header_value Raw `If-None-Match` header value.
	 * @param string $etag         Current strong, quoted `ETag` value.
	 * @return bool
	 */
	public static function if_none_match_matches( string $header_value, string $etag ): bool {
		$header_value = trim( $header_value );

		if ( '' === $header_value ) {
			return false;
		}

		if ( '*' === $header_value ) {
			return true;
		}

		foreach ( explode( ',', $header_value ) as $candidate ) {
			$candidate = trim( $candidate );

			if ( str_starts_with( $candidate, 'W/' ) ) {
				$candidate = substr( $candidate, 2 );
			}

			if ( $candidate === $etag ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Pure `If-Modified-Since` comparator, extracted for the same reason as
	 * {@see if_none_match_matches()}.
	 *
	 * @param string $if_modified_since_header Raw `If-Modified-Since` header value.
	 * @param string $last_modified_http_date  Current snapshot's `Last-Modified` value, HTTP-date format.
	 * @return bool
	 */
	public static function if_modified_since_satisfied( string $if_modified_since_header, string $last_modified_http_date ): bool {
		$requested = strtotime( $if_modified_since_header );
		$actual    = strtotime( $last_modified_http_date );

		if ( false === $requested || false === $actual ) {
			return false;
		}

		return $requested >= $actual;
	}

	/**
	 * Converts a stored `generated_at` timestamp to RFC 7231 HTTP-date
	 * (GMT), extracted as a pure function for the same reason as
	 * {@see if_none_match_matches()}.
	 *
	 * @param string $generated_at Snapshot's `generated_at`, `Y-m-d\TH:i:s\Z` (UTC).
	 * @return string|null Null if `$generated_at` does not parse.
	 */
	public static function http_date_from_generated_at( string $generated_at ): ?string {
		$date = DateTimeImmutable::createFromFormat( 'Y-m-d\TH:i:s\Z', $generated_at, new DateTimeZone( 'UTC' ) );

		if ( false === $date ) {
			return null;
		}

		return $date->format( 'D, d M Y H:i:s \G\M\T' );
	}
}

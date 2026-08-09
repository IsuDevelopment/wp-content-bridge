<?php
/**
 * Per-post-type status transition allowlist.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Domain\Status;

use InvalidArgumentException;

/**
 * The explicit `(from, to)` allowlist ADR 0024 requires, one list of pairs
 * per post type.
 *
 * This is a *second*, separate gate from
 * {@see \IsuDev\WPContentBridge\Domain\ContentAccess\ContentTypePolicy}: that
 * policy answers "is `transition_content_status` enabled for this type at
 * all", this graph answers "which specific status moves are allowed". A
 * future task must check both, in that order, never fold one into the
 * other.
 *
 * Bounds (at most {@see self::MAX_PAIRS_PER_POST_TYPE} pairs per type, at
 * most {@see self::MAX_POST_TYPES} types) are enforced here, in the one
 * place pairs are grouped by type, rather than in
 * {@see StatusTransitionConfig}: exceeding either is a construction-time
 * rejection, never a truncation. A silently dropped pair would look
 * identical to "administrator did not permit this", which is exactly the
 * shape of an authorization hole this codebase's other bounded value
 * objects (for example {@see \IsuDev\WPContentBridge\Domain\Llms\LlmsConfig})
 * do not have to worry about, because their bounds only affect what a
 * public document renders, not what a write is allowed to do.
 *
 * A post type key here is never checked against WordPress's registered
 * types. A type can be renamed or unregistered after it was configured
 * ({@see \IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressLlmsSourceSelector}
 * re-checks post-type registration for the same reason in the llms.txt
 * slice); this class keeps that type's rows rather than dropping them, so
 * the configuration an administrator wrote is never silently rewritten out
 * from under them. Whether a post type still exists and is usable is a
 * WordPress concern re-checked at the point a transition is attempted, not
 * here.
 */
final readonly class StatusTransitionGraph {

	/**
	 * Hard ceiling on configured pairs per post type. Also the exact size of
	 * {@see StatusTransition::all_possible()} (5 x 4), so a settings matrix
	 * submission can never exceed this by construction.
	 *
	 * @var int
	 */
	public const MAX_PAIRS_PER_POST_TYPE = 20;

	/**
	 * Hard ceiling on configured post types.
	 *
	 * @var int
	 */
	public const MAX_POST_TYPES = 50;

	/**
	 * Creates a validated graph.
	 *
	 * @param array $transitions_by_post_type Configured pairs, keyed by post type.
	 * @phpstan-param array<string, list<StatusTransition>> $transitions_by_post_type
	 * @throws InvalidArgumentException When a bound is exceeded.
	 */
	public function __construct(
		private array $transitions_by_post_type = array(),
	) {
		if ( self::MAX_POST_TYPES < count( $this->transitions_by_post_type ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- bound constant, not rendered output.
			throw new InvalidArgumentException( 'Status transition configuration exceeds the maximum of ' . self::MAX_POST_TYPES . ' post types.' );
		}

		foreach ( $this->transitions_by_post_type as $post_type => $transitions ) {
			if ( self::MAX_PAIRS_PER_POST_TYPE < count( $transitions ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- structured post type name and bound constant, not rendered output.
				throw new InvalidArgumentException( "Post type '{$post_type}' exceeds the maximum of " . self::MAX_PAIRS_PER_POST_TYPE . ' status transition pairs.' );
			}
		}
	}

	/**
	 * Returns a graph that permits nothing.
	 *
	 * An absent or empty configuration must deny every transition; this
	 * factory and an empty stored array produce an identical graph, so
	 * callers of this class see no difference between "never configured"
	 * and "configured to nothing" — that distinction matters only to the
	 * settings screen, which reads it from the store directly. See
	 * {@see \IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressStatusTransitionRepository}.
	 *
	 * @return self
	 */
	public static function empty(): self {
		return new self();
	}

	/**
	 * The documented editorial preset from ADR 0024: draft and pending may
	 * move to each other, and either may move directly to private.
	 *
	 * Deliberately excludes `publish` and `future`: this preset is meant to
	 * be a convenience for the transitions that do not need the additional
	 * publication gates, not a shortcut around them. It is never applied
	 * automatically — only a settings-screen action the administrator
	 * chooses to press builds a configuration from it.
	 *
	 * @return array
	 * @phpstan-return list<StatusTransition>
	 */
	public static function editorial_preset_pairs(): array {
		return array(
			new StatusTransition( ContentStatus::DRAFT, ContentStatus::PENDING ),
			new StatusTransition( ContentStatus::PENDING, ContentStatus::DRAFT ),
			new StatusTransition( ContentStatus::DRAFT, ContentStatus::PRIVATE ),
			new StatusTransition( ContentStatus::PENDING, ContentStatus::PRIVATE ),
		);
	}

	/**
	 * Checks whether one exact ordered pair is permitted for a post type.
	 *
	 * `$from` and `$to` are raw strings, not {@see ContentStatus}, because
	 * callers compare against a live post's current status string and a
	 * requested target string; a value outside the fixed five can never
	 * match any configured pair, so it correctly falls through to `false`
	 * without a separate validity check.
	 *
	 * @param string $post_type Post type of the object being transitioned.
	 * @param string $from      Candidate current status.
	 * @param string $to        Candidate target status.
	 * @return bool
	 */
	public function permits( string $post_type, string $from, string $to ): bool {
		foreach ( $this->transitions_by_post_type[ $post_type ] ?? array() as $transition ) {
			if ( $transition->from->value === $from && $transition->to->value === $to ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Lists the targets permitted from a given `(post_type, from)`.
	 *
	 * @param string $post_type Post type of the object being transitioned.
	 * @param string $from      Current status.
	 * @return array
	 * @phpstan-return list<string>
	 */
	public function permitted_targets( string $post_type, string $from ): array {
		$targets = array();

		foreach ( $this->transitions_by_post_type[ $post_type ] ?? array() as $transition ) {
			if ( $transition->from->value === $from ) {
				$targets[] = $transition->to->value;
			}
		}

		return $targets;
	}

	/**
	 * Returns every configured pair, grouped by post type, for serialization.
	 *
	 * @return array
	 * @phpstan-return array<string, list<StatusTransition>>
	 */
	public function all(): array {
		return $this->transitions_by_post_type;
	}
}

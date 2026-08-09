<?php
/**
 * Validated status transition configuration.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Domain\Status;

use InvalidArgumentException;

/**
 * Immutable, provider-neutral status transition configuration, built from
 * untrusted input.
 *
 * This is administrator-authored authorization configuration, not public
 * content: matching {@see \IsuDev\WPContentBridge\Domain\Llms\LlmsConfig}'s
 * documented rationale for the same choice, invalid input is rejected
 * outright rather than dropped or truncated. Unlike that class's rendering
 * bounds, the bounds enforced here (via {@see StatusTransitionGraph}) are
 * an authorization boundary: a truncated pair would be indistinguishable
 * from a pair the administrator never granted, silently narrowing what
 * looks configured without narrowing what actually is. Rejecting the whole
 * input instead makes the mistake visible immediately, at save time.
 *
 * @phpstan-type PairInput array{from: string, to: string}
 */
final readonly class StatusTransitionConfig {

	private const MAX_POST_TYPE_LENGTH = 20;

	/**
	 * Creates a configuration from an already-validated graph. Callers should
	 * normally use {@see self::from_input()} or {@see self::empty()}; this
	 * constructor performs no validation of its own, matching the codebase's
	 * other domain value objects.
	 *
	 * @param StatusTransitionGraph $graph Validated per-post-type allowlist.
	 */
	public function __construct(
		public StatusTransitionGraph $graph,
	) {
	}

	/**
	 * Returns the deny-all configuration.
	 *
	 * Used both for a genuinely empty input and, by
	 * {@see \IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressStatusTransitionRepository},
	 * as the in-memory stand-in when the option is absent. The two cases
	 * behave identically here on purpose — see that class's docblock for
	 * where the difference is still tracked.
	 *
	 * @return self
	 */
	public static function empty(): self {
		return new self( StatusTransitionGraph::empty() );
	}

	/**
	 * Builds a validated configuration from untrusted input.
	 *
	 * Expected shape: a map of post type slug to a bounded list of
	 * `{from, to}` pairs, exactly what {@see self::to_array()} produces, so
	 * the stored option round-trips unchanged.
	 *
	 * @param array $input Raw input; keys are only expected to be strings, never trusted to be.
	 * @phpstan-param array<int|string, mixed> $input
	 * @return self
	 * @throws InvalidArgumentException When the input is malformed or out of bounds.
	 */
	public static function from_input( array $input ): self {
		if ( StatusTransitionGraph::MAX_POST_TYPES < count( $input ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- bound constant, not rendered output.
			throw new InvalidArgumentException( 'Status transition configuration exceeds the maximum of ' . StatusTransitionGraph::MAX_POST_TYPES . ' post types.' );
		}

		$transitions_by_post_type = array();

		foreach ( $input as $post_type => $pairs ) {
			if ( ! is_string( $post_type ) || 1 !== preg_match( '/^[a-z0-9_-]{1,' . self::MAX_POST_TYPE_LENGTH . '}$/', $post_type ) ) {
				throw new InvalidArgumentException( 'A configured post type name is invalid.' );
			}

			if ( ! is_array( $pairs ) || ! array_is_list( $pairs ) || StatusTransitionGraph::MAX_PAIRS_PER_POST_TYPE < count( $pairs ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- structured post type name, not rendered output.
				throw new InvalidArgumentException( "Post type '{$post_type}' has a malformed or oversized transition pair list." );
			}

			$transitions_by_post_type[ $post_type ] = self::pairs( $pairs, $post_type );
		}

		return new self( new StatusTransitionGraph( $transitions_by_post_type ) );
	}

	/**
	 * Serializes the configuration back to the wire shape `from_input()`
	 * accepts unchanged.
	 *
	 * @return array
	 * @phpstan-return array<string, list<PairInput>>
	 */
	public function to_array(): array {
		$out = array();

		foreach ( $this->graph->all() as $post_type => $transitions ) {
			$out[ $post_type ] = array_map(
				static fn ( StatusTransition $transition ): array => array(
					'from' => $transition->from->value,
					'to'   => $transition->to->value,
				),
				$transitions
			);
		}

		return $out;
	}

	/**
	 * Validates one post type's list of pair entries.
	 *
	 * @param array  $pairs     Candidate pair list, already known to be a bounded list.
	 * @param string $post_type Post type the pairs belong to, for error messages only.
	 * @phpstan-param list<mixed> $pairs
	 * @return array
	 * @phpstan-return list<StatusTransition>
	 * @throws InvalidArgumentException When a pair entry is malformed.
	 */
	private static function pairs( array $pairs, string $post_type ): array {
		$transitions = array();

		foreach ( $pairs as $pair ) {
			if ( ! is_array( $pair ) || array() !== array_diff( array_keys( $pair ), array( 'from', 'to' ) ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- structured post type name, not rendered output.
				throw new InvalidArgumentException( "A status transition pair for post type '{$post_type}' is invalid." );
			}

			$from = $pair['from'] ?? null;
			$to   = $pair['to'] ?? null;

			if ( ! is_string( $from ) || ! is_string( $to ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- structured post type name, not rendered output.
				throw new InvalidArgumentException( "A status transition pair for post type '{$post_type}' is invalid." );
			}

			$transitions[] = StatusTransition::from_strings( $from, $to );
		}

		return $transitions;
	}
}

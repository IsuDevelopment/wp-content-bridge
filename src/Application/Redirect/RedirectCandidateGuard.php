<?php
/**
 * Redirect candidate guard.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Application\Redirect;

use InvalidArgumentException;
use IsuDev\WPContentBridge\Domain\Redirect\RedirectRule;
use IsuDev\WPContentBridge\Domain\Redirect\RedirectSourcePath;

/**
 * Enforces the provider-neutral invariants ADR 0026 s5 requires of every
 * redirect candidate before any adapter's `create()` runs, so both backends
 * are held to identical editorial-safety rules regardless of their own
 * (looser) native validation.
 */
final class RedirectCandidateGuard {

	/**
	 * A redirect can never shadow a core or plugin-owned endpoint.
	 */
	private const RESERVED_PREFIXES = array( 'wp-json/', 'wp-admin/', 'wp-content/', 'feed/' );

	/**
	 * Bounded chain resolution depth (ADR 0026 s5).
	 */
	private const MAX_CHAIN_HOPS = 3;

	/**
	 * Rejects a candidate that fails any provider-neutral safety invariant.
	 *
	 * @param RedirectRule             $candidate  Candidate rule (id must be null).
	 * @param RedirectRuleLookup       $existing   Cross-provider lookup over every
	 *                                              available provider, used for
	 *                                              collision and chain resolution.
	 * @param PublishedPermalinkLookup $permalinks Live-content shadow lookup.
	 * @return void
	 * @throws RedirectSourceRejected When any invariant fails.
	 */
	public function assert_creatable(
		RedirectRule $candidate,
		RedirectRuleLookup $existing,
		PublishedPermalinkLookup $permalinks
	): void {
		$source = $candidate->source->value();

		self::assert_not_reserved( $source );

		if ( $permalinks->is_published_permalink( $source ) ) {
			throw new RedirectSourceRejected( 'Redirect source path resolves to published content.' );
		}

		$claimants = $existing->claimants( $candidate->source );
		if ( array() !== $claimants ) {
			// Any available provider counts, not just the one this write is
			// addressed to (ADR 0026 s5, corrected 2026-09-01). On a site
			// running both plugins, both engines serve redirects and whichever
			// hooks first wins, so a rule written "successfully" into one
			// backend can be shadowed by the other's existing rule.
			throw new RedirectSourceRejected(
				sprintf(
					'A redirect rule for this source path already exists in: %s.',
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- provider slugs are regex-bounded tokens, not rendered output.
					implode( ', ', $claimants )
				)
			);
		}

		if ( null !== $candidate->target ) {
			self::assert_no_loop( $source, $candidate->target->value(), $existing );
		}
	}

	/**
	 * Rejects a source under a reserved prefix.
	 *
	 * @param string $source Candidate source path.
	 * @return void
	 * @throws RedirectSourceRejected When the source is reserved.
	 */
	private static function assert_not_reserved( string $source ): void {
		$relative = ltrim( $source, '/' );
		foreach ( self::RESERVED_PREFIXES as $prefix ) {
			if ( str_starts_with( $relative, $prefix ) ) {
				throw new RedirectSourceRejected( 'Redirect source path is reserved.' );
			}
		}
	}

	/**
	 * Resolves the candidate's target through existing rules up to the
	 * bounded hop count, rejecting a loop back to the original source or a
	 * chain that has not terminated within the bound.
	 *
	 * Resolution spans every available provider, because a chain can hop
	 * between backends: `/a -> /b` in one plugin and `/b -> /a` in the other
	 * is a loop neither plugin can see on its own.
	 *
	 * @param string             $original_source Candidate's own source path.
	 * @param string             $target          Candidate's target (path, optionally with a query string).
	 * @param RedirectRuleLookup $existing        Cross-provider lookup.
	 * @return void
	 * @throws RedirectSourceRejected When a loop or an unresolvable chain is found.
	 */
	private static function assert_no_loop( string $original_source, string $target, RedirectRuleLookup $existing ): void {
		$seen    = array( $original_source );
		$current = self::path_only( $target );

		for ( $hop = 0; $hop < self::MAX_CHAIN_HOPS; $hop++ ) {
			if ( in_array( $current, $seen, true ) ) {
				throw new RedirectSourceRejected( 'Redirect target would create a loop back to an existing source.' );
			}
			$seen[] = $current;

			try {
				$rule = $existing->first( new RedirectSourcePath( $current ) );
			} catch ( InvalidArgumentException ) {
				// The resolved target is not itself a valid bounded source shape
				// (e.g. it carries a query string), so no provider rule can
				// possibly key on it and the chain terminates here.
				return;
			}

			if ( null === $rule || null === $rule->target ) {
				return;
			}

			$current = self::path_only( $rule->target->value() );
		}

		throw new RedirectSourceRejected( 'Redirect target chain exceeds the safety bound.' );
	}

	/**
	 * Strips a trailing query string for chain-lookup comparison.
	 *
	 * @param string $target Target value.
	 * @return string
	 */
	private static function path_only( string $target ): string {
		return explode( '?', $target )[0];
	}
}

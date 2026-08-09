<?php
/**
 * One ordered status pair.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Domain\Status;

use InvalidArgumentException;

/**
 * An immutable, ordered `(from, to)` status pair.
 *
 * ADR 0024 chose pairs over a per-status allowlist of targets specifically
 * because a target-only list cannot express "may unpublish but not
 * publish" — the arrangement an editorial integration typically wants.
 * `publish → draft` and `draft → publish` are two different instances of
 * this class; neither implies the other, and nothing in this codebase ever
 * treats them as a symmetric relationship.
 *
 * Both ends are typed as {@see ContentStatus}, so the fixed five-status
 * vocabulary is enforced by the type system itself for any pair built
 * through the ordinary constructor. Untrusted string input (settings
 * storage, Ability payloads) must go through {@see self::from_strings()},
 * which is the only place a status name is looked up against that
 * vocabulary and can fail.
 */
final readonly class StatusTransition {

	/**
	 * Creates a validated ordered pair.
	 *
	 * @param ContentStatus $from Status the object is currently in.
	 * @param ContentStatus $to   Status the transition would set.
	 * @throws InvalidArgumentException When `$from` and `$to` are the same status.
	 */
	public function __construct(
		public ContentStatus $from,
		public ContentStatus $to,
	) {
		if ( $from === $to ) {
			throw new InvalidArgumentException( 'A status transition must not target its own starting status.' );
		}
	}

	/**
	 * Builds a pair from untrusted status names.
	 *
	 * @param string $from Candidate starting status name.
	 * @param string $to   Candidate target status name.
	 * @return self
	 * @throws InvalidArgumentException When either name is outside the fixed five statuses, or the pair is a no-op.
	 */
	public static function from_strings( string $from, string $to ): self {
		$from_status = ContentStatus::tryFrom( $from );
		$to_status   = ContentStatus::tryFrom( $to );

		if ( null === $from_status || null === $to_status ) {
			throw new InvalidArgumentException( 'A status transition pair must use only the five permitted statuses.' );
		}

		return new self( $from_status, $to_status );
	}

	/**
	 * Enumerates every syntactically valid ordered pair over the fixed five
	 * statuses (5 x 4 = 20 pairs), independent of any configuration.
	 *
	 * This is a fact about the vocabulary, not about what any administrator
	 * has permitted; it exists so the settings matrix and its submission
	 * handler can both iterate the same fixed, bounded grid instead of
	 * re-deriving it, which is also what keeps a submitted matrix
	 * inherently within {@see StatusTransitionGraph::MAX_PAIRS_PER_POST_TYPE}
	 * without a separate bound check.
	 *
	 * @return array
	 * @phpstan-return list<self>
	 */
	public static function all_possible(): array {
		$pairs = array();

		foreach ( ContentStatus::cases() as $from ) {
			foreach ( ContentStatus::cases() as $to ) {
				if ( $from !== $to ) {
					$pairs[] = new self( $from, $to );
				}
			}
		}

		return $pairs;
	}
}

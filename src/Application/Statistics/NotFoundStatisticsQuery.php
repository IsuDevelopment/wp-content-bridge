<?php
/**
 * Bounded query for aggregated 404 statistics.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Application\Statistics;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * The whole input surface of the statistics read: how far back, and how many
 * paths.
 *
 * `since` is supported because the data supports it even where the provider's
 * own REST API does not (ADR 0030 s4): Redirection's log table has an indexed
 * `created` column, and this plugin runs in the same process. Polling is
 * precisely why it matters - an agent that cannot say `since` re-reads the
 * same top-404 list on every pass and cannot tell a new problem from one it
 * already reported.
 *
 * There is no field for including per-visitor data, by construction rather
 * than by default (ADR 0030 s3).
 */
final readonly class NotFoundStatisticsQuery {

	public const DEFAULT_LIMIT = 20;
	public const MAX_LIMIT     = 100;

	/**
	 * Creates the query.
	 *
	 * `now` travels with the query rather than being read from the clock
	 * inside an adapter, because retention truncation is computed against it
	 * (ADR 0030 s4) and a boundary decided by two different clocks in the same
	 * read is untestable.
	 *
	 * @param DateTimeImmutable      $now   Current UTC time.
	 * @param DateTimeImmutable|null $since Inclusive lower bound, or null for everything retained.
	 * @param int                    $limit Maximum paths to return.
	 * @throws InvalidArgumentException When the query is out of bounds.
	 */
	public function __construct(
		public DateTimeImmutable $now,
		public ?DateTimeImmutable $since = null,
		public int $limit = self::DEFAULT_LIMIT,
	) {
		if ( $limit < 1 || $limit > self::MAX_LIMIT ) {
			throw new InvalidArgumentException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- bounded integer in a message, never rendered output.
				sprintf( 'limit must be between 1 and %d.', self::MAX_LIMIT )
			);
		}
	}

	/**
	 * Builds a query from validated ability input.
	 *
	 * A `since` in the future is rejected rather than normalized: it would
	 * return an empty measured result, which is indistinguishable from a site
	 * with no 404s, and the caller would have no way to notice its own
	 * mistake.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @param DateTimeImmutable    $now   Current UTC time.
	 * @return self
	 * @throws InvalidArgumentException When input is not a usable query.
	 */
	public static function from_input( array $input, DateTimeImmutable $now ): self {
		$limit = $input['limit'] ?? self::DEFAULT_LIMIT;
		if ( ! is_int( $limit ) ) {
			throw new InvalidArgumentException( 'limit must be an integer.' );
		}

		$raw = $input['since'] ?? null;
		if ( null === $raw || '' === $raw ) {
			return new self( $now, null, $limit );
		}
		if ( ! is_string( $raw ) ) {
			throw new InvalidArgumentException( 'since must be an ISO 8601 date-time string.' );
		}

		try {
			$since = new DateTimeImmutable( $raw, new DateTimeZone( 'UTC' ) );
		} catch ( \Exception ) {
			throw new InvalidArgumentException( 'since must be an ISO 8601 date-time string.' );
		}

		$since = $since->setTimezone( new DateTimeZone( 'UTC' ) );
		if ( $since > $now ) {
			throw new InvalidArgumentException( 'since must not be in the future.' );
		}

		return new self( $now, $since, $limit );
	}

	/**
	 * Returns the requested boundary in ISO-8601 UTC, or null.
	 *
	 * @return string|null
	 */
	public function since_iso(): ?string {
		return $this->since?->format( 'Y-m-d\TH:i:s\Z' );
	}
}

<?php
/**
 * Outcome of one rendered-graph capture attempt.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Domain\Seo;

use InvalidArgumentException;

/**
 * Carries the captured nodes together with why the capture ended as it did.
 *
 * The reader used to answer every failure with an empty node list, which made a
 * blocked or timing-out loopback request indistinguishable from a page that
 * genuinely emits no JSON-LD. Those two need opposite responses: the first is a
 * host problem to fix, the second is a correct answer. Self-requests are exactly
 * what a firewall, HTTP auth, or an edge proxy blocks, so the ambiguous case is
 * the common one in production, not a corner case.
 */
final readonly class RenderedGraph {

	public const CAPTURED        = 'captured';
	public const CACHED          = 'cached';
	public const EMPTY_GRAPH     = 'empty_graph';
	public const NOT_SAME_ORIGIN = 'not_same_origin';
	public const REQUEST_FAILED  = 'request_failed';
	public const HTTP_ERROR      = 'http_error';
	public const BODY_TOO_LARGE  = 'body_too_large';
	public const NO_HTTP_API     = 'no_http_api';

	private const OUTCOMES = array(
		self::CAPTURED,
		self::CACHED,
		self::EMPTY_GRAPH,
		self::NOT_SAME_ORIGIN,
		self::REQUEST_FAILED,
		self::HTTP_ERROR,
		self::BODY_TOO_LARGE,
		self::NO_HTTP_API,
	);

	private const MAX_DETAIL_LENGTH = 500;

	/**
	 * Creates the outcome.
	 *
	 * @param list<array<string, mixed>> $nodes       Captured graph nodes; empty for every failure.
	 * @param string                     $outcome     One of the fixed outcome constants.
	 * @param int                        $elapsed_ms  Wall-clock cost of the attempt.
	 * @param int|null                   $status_code Upstream HTTP status when a response arrived.
	 * @param string|null                $detail      Bounded transport message, never rendered markup.
	 * @throws InvalidArgumentException When the outcome is unknown or contradicts the node list.
	 */
	public function __construct(
		public array $nodes,
		public string $outcome,
		public int $elapsed_ms = 0,
		public ?int $status_code = null,
		public ?string $detail = null,
	) {
		if ( ! in_array( $outcome, self::OUTCOMES, true ) ) {
			throw new InvalidArgumentException( 'Unknown rendered-graph outcome.' );
		}
		if ( array() !== $nodes && ! $this->is_success() ) {
			throw new InvalidArgumentException( 'A failed rendered-graph capture cannot carry nodes.' );
		}
		if ( null !== $detail && self::MAX_DETAIL_LENGTH < strlen( $detail ) ) {
			throw new InvalidArgumentException( 'The rendered-graph detail exceeds the output bound.' );
		}
	}

	/**
	 * Builds a failure outcome.
	 *
	 * @param string      $outcome     One of the fixed failure constants.
	 * @param int         $elapsed_ms  Wall-clock cost of the attempt.
	 * @param int|null    $status_code Upstream HTTP status when a response arrived.
	 * @param string|null $detail      Bounded transport message.
	 */
	public static function failed( string $outcome, int $elapsed_ms = 0, ?int $status_code = null, ?string $detail = null ): self {
		return new self( array(), $outcome, $elapsed_ms, $status_code, $detail );
	}

	/**
	 * Whether the attempt reached a page and read its graph.
	 */
	public function is_success(): bool {
		return self::CAPTURED === $this->outcome || self::CACHED === $this->outcome;
	}

	/**
	 * Whether usable nodes were captured.
	 */
	public function has_nodes(): bool {
		return array() !== $this->nodes;
	}

	/**
	 * Returns an operator-facing explanation, or null when the attempt worked.
	 *
	 * The wording distinguishes "we could not look" from "we looked and the
	 * page emits nothing", because only the first is a problem to fix.
	 */
	public function diagnosis(): ?string {
		$suffix = sprintf( ' (outcome: %s, %d ms)', $this->outcome, $this->elapsed_ms );

		return match ( $this->outcome ) {
			self::CAPTURED, self::CACHED => null,
			self::EMPTY_GRAPH            => 'The page was fetched successfully but emits no JSON-LD graph.' . $suffix,
			self::NOT_SAME_ORIGIN        => 'The target URL is not same-origin with this site, so it was never requested.' . $suffix,
			self::REQUEST_FAILED         => 'The site could not fetch its own page. Loopback HTTP requests are commonly blocked by a firewall, HTTP authentication, or an edge proxy; the resolved surface was used instead.' . $suffix . ( null !== $this->detail ? ' Transport: ' . $this->detail : '' ),
			self::HTTP_ERROR             => sprintf( 'The site fetched its own page and received HTTP %d rather than 200.', (int) $this->status_code ) . $suffix,
			self::BODY_TOO_LARGE         => 'The page exceeded the response size bound and was discarded unread.' . $suffix,
			self::NO_HTTP_API            => 'The WordPress HTTP API is unavailable in this runtime, so no request was attempted.' . $suffix,
			default                      => null,
		};
	}
}

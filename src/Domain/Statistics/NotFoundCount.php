<?php
/**
 * One aggregated 404 path and its hit count.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Domain\Statistics;

use InvalidArgumentException;

/**
 * The whole projected surface of a 404 observation: which path was requested
 * and how many times (ADR 0030 s3).
 *
 * There is deliberately no field for `ip`, `agent`, `referrer`, or
 * `request_data`, and no parameter that could add one. "Where is a redirect
 * missing" is fully answered by path and count; the per-visitor fields add
 * nothing to it and would hand a model the site's traffic logs. An option to
 * include them is a thing an agent can be talked into setting, so the option
 * does not exist.
 */
final readonly class NotFoundCount {

	public const MAX_PATH_BYTES = 2048;

	/**
	 * Creates one aggregated count.
	 *
	 * @param string $path Requested path or URL as the provider recorded it.
	 * @param int    $hits Number of hits within the reported window.
	 * @throws InvalidArgumentException When the pair is not a usable aggregate.
	 */
	public function __construct(
		public string $path,
		public int $hits,
	) {
		if ( '' === trim( $path ) ) {
			throw new InvalidArgumentException( 'A 404 count must name a path.' );
		}
		if ( strlen( $path ) > self::MAX_PATH_BYTES ) {
			throw new InvalidArgumentException( 'A 404 path exceeds the reportable length.' );
		}
		if ( $hits < 1 ) {
			// A grouped count is never zero: a row exists because it was hit.
			// A zero here would mean the aggregation did not group, which
			// ADR 0030 s6 requires be reported as unavailable rather than
			// mistaken for counts.
			throw new InvalidArgumentException( 'A 404 count must be at least one hit.' );
		}
	}

	/**
	 * Serializes the aggregate.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'path' => $this->path,
			'hits' => $this->hits,
		);
	}
}

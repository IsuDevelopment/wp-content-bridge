<?php
/**
 * Read aggregated 404 statistics across every statistics provider.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Application\Statistics;

use DateTimeImmutable;
use IsuDev\WPContentBridge\Domain\Statistics\ErrorStatisticsAvailability;
use IsuDev\WPContentBridge\Domain\Statistics\NotFoundStatistics;

/**
 * Answers the other half of the operator's redirect question: not "create a
 * redirect", but *which* redirect is missing (ADR 0030).
 *
 * The result is per provider and deliberately not merged into one list. Each
 * backend has its own retention window, its own logging switch, and its own
 * notion of the recorded path, so a merged top-N would be an ordering over
 * counts that cover different periods - a number that looks authoritative and
 * is not. The overall `availability` is reported alongside, so a caller that
 * only wants "is anything measured here" does not have to derive it.
 */
final readonly class GetNotFoundStatistics {

	public const ABILITY = 'wp-content-bridge/get-404-statistics';

	public const SCHEMA_VERSION = '1.0';

	/**
	 * Creates the use case.
	 *
	 * @param ErrorStatisticsProviderRegistry $registry Provider registry.
	 */
	public function __construct( private ErrorStatisticsProviderRegistry $registry ) {
	}

	/**
	 * Reads aggregated 404 counts.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @param DateTimeImmutable    $now   Current UTC time.
	 * @return array<string, mixed>
	 * @throws \InvalidArgumentException When the query is not usable.
	 * @throws ErrorStatisticsUnreadable When a backend fails outright.
	 */
	public function execute( array $input, DateTimeImmutable $now ): array {
		$query = NotFoundStatisticsQuery::from_input( $input, $now );

		$results = array();
		foreach ( $this->registry->to_ask() as $provider ) {
			$results[] = $provider->top_not_found( $query );
		}

		return array(
			'schema_version' => self::SCHEMA_VERSION,
			'availability'   => self::overall( $results )->value,
			'requested'      => array(
				'since' => $query->since_iso(),
				'limit' => $query->limit,
			),
			'sources'        => array_map(
				static fn ( NotFoundStatistics $result ): array => $result->to_array(),
				$results
			),
		);
	}

	/**
	 * Reduces per-provider states to one overall state.
	 *
	 * Precedence is measured > disabled > forbidden > unavailable, because a
	 * single provider that *is* recording makes the site measurable whatever
	 * the others report, while "nothing is being collected here" must not be
	 * announced when a switched-off log or a permission denial is the actual
	 * reason - those two are fixable, and unavailable is not.
	 *
	 * @param array $results Per-provider results.
	 * @phpstan-param non-empty-list<NotFoundStatistics> $results
	 * @return ErrorStatisticsAvailability
	 */
	private static function overall( array $results ): ErrorStatisticsAvailability {
		$states = array();
		foreach ( $results as $result ) {
			$states[] = $result->availability;
		}

		foreach ( array(
			ErrorStatisticsAvailability::MEASURED,
			ErrorStatisticsAvailability::DISABLED,
			ErrorStatisticsAvailability::FORBIDDEN,
		) as $candidate ) {
			if ( in_array( $candidate, $states, true ) ) {
				return $candidate;
			}
		}

		return ErrorStatisticsAvailability::UNAVAILABLE;
	}
}

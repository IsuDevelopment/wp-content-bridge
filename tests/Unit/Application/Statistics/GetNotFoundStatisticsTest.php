<?php
/**
 * Aggregate 404 read tests.
 *
 * @package IsuDev\WPContentBridge\Tests
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Tests\Unit\Application\Statistics;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use IsuDev\WPContentBridge\Application\Statistics\ErrorStatisticsProvider;
use IsuDev\WPContentBridge\Application\Statistics\ErrorStatisticsProviderRegistry;
use IsuDev\WPContentBridge\Application\Statistics\GetNotFoundStatistics;
use IsuDev\WPContentBridge\Application\Statistics\NotFoundStatisticsQuery;
use IsuDev\WPContentBridge\Application\Statistics\NullErrorStatisticsProvider;
use IsuDev\WPContentBridge\Domain\Statistics\ErrorStatisticsAvailability;
use IsuDev\WPContentBridge\Domain\Statistics\ErrorStatisticsProviderStatus;
use IsuDev\WPContentBridge\Domain\Statistics\ErrorStatisticsWindow;
use IsuDev\WPContentBridge\Domain\Statistics\NotFoundCount;
use IsuDev\WPContentBridge\Domain\Statistics\NotFoundStatistics;
use PHPUnit\Framework\TestCase;

/**
 * Covers the distinction ADR 0030 exists to preserve: a site that collects
 * nothing, a log that is switched off, and a site with no 404s must never
 * produce the same answer.
 */
final class GetNotFoundStatisticsTest extends TestCase {

	/**
	 * A site with no statistics provider reports `unavailable`, not an empty
	 * measurement. This is the Yoast-only site, and it is the normal case.
	 */
	public function test_a_site_with_no_provider_reports_unavailable(): void {
		$result = $this->read( array() );

		self::assertSame( 'unavailable', $result['availability'] );
		self::assertSame( 'unavailable', $result['sources'][0]['availability'] );
		self::assertSame( 'none', $result['sources'][0]['provider']['provider'] );
		self::assertSame( array(), $result['sources'][0]['paths'] );
	}

	/**
	 * A switched-off log is not a healthy site, and the result names the
	 * setting responsible so the operator can act on it.
	 */
	public function test_a_switched_off_log_is_reported_as_disabled(): void {
		$result = $this->read( array( $this->disabled_provider( 'redirection', 'expire_404' ) ) );

		self::assertSame( 'disabled', $result['availability'] );
		self::assertSame( 'expire_404', $result['sources'][0]['disabled_by'] );
	}

	/**
	 * An empty measurement is a real observation and stays distinct from both
	 * of the above.
	 */
	public function test_an_empty_measurement_is_still_measured(): void {
		$result = $this->read( array( $this->measuring_provider( 'redirection', array() ) ) );

		self::assertSame( 'measured', $result['availability'] );
		self::assertSame( array(), $result['sources'][0]['paths'] );
	}

	/**
	 * One provider actually recording makes the site measurable, whatever the
	 * others report; the per-provider states stay visible underneath.
	 */
	public function test_one_measuring_provider_decides_the_overall_state(): void {
		$result = $this->read(
			array(
				$this->disabled_provider( 'other', 'expire_404' ),
				$this->measuring_provider( 'redirection', array( '/old' => 9 ) ),
			)
		);

		self::assertSame( 'measured', $result['availability'] );
		self::assertSame( 'disabled', $result['sources'][0]['availability'] );
		self::assertSame( 'measured', $result['sources'][1]['availability'] );
	}

	/**
	 * A permission denial outranks "nothing collects this": one is fixable by
	 * a grant, the other would send the operator to install a plugin that is
	 * already installed.
	 */
	public function test_a_denial_is_not_reported_as_unavailable(): void {
		$result = $this->read( array( $this->forbidding_provider( 'redirection' ) ) );

		self::assertSame( 'forbidden', $result['availability'] );
	}

	/**
	 * Counts are reported per provider with the path and hit count only.
	 */
	public function test_counts_are_reported_per_provider(): void {
		$result = $this->read( array( $this->measuring_provider( 'redirection', array( '/old' => 9 ) ) ) );

		self::assertSame(
			array(
				array(
					'path' => '/old',
					'hits' => 9,
				),
			),
			$result['sources'][0]['paths']
		);
	}

	/**
	 * The applied query is echoed, so a polling caller can see the boundary
	 * it actually asked for rather than assume the one it sent.
	 */
	public function test_the_applied_query_is_echoed(): void {
		$result = $this->read(
			array( $this->measuring_provider( 'redirection', array() ) ),
			array(
				'since' => '2026-09-01T00:00:00Z',
				'limit' => 5,
			)
		);

		self::assertSame( '2026-09-01T00:00:00Z', $result['requested']['since'] );
		self::assertSame( 5, $result['requested']['limit'] );
	}

	/**
	 * A `since` in the future would return an empty measured result, which is
	 * indistinguishable from a site with no 404s, so it is refused instead.
	 */
	public function test_a_future_since_is_refused(): void {
		$this->expectException( InvalidArgumentException::class );

		$this->read(
			array( $this->measuring_provider( 'redirection', array() ) ),
			array( 'since' => '2030-01-01T00:00:00Z' )
		);
	}

	/**
	 * The limit is bounded, so one call can never ask for the whole log.
	 */
	public function test_an_out_of_range_limit_is_refused(): void {
		$this->expectException( InvalidArgumentException::class );

		$this->read(
			array( $this->measuring_provider( 'redirection', array() ) ),
			array( 'limit' => 500 )
		);
	}

	/**
	 * Runs the use case over the given providers.
	 *
	 * @param array                $providers Provider adapters.
	 * @param array<string, mixed> $input     Ability input.
	 * @phpstan-param list<ErrorStatisticsProvider> $providers
	 * @return array<string, mixed>
	 */
	private function read( array $providers, array $input = array() ): array {
		$use_case = new GetNotFoundStatistics(
			new ErrorStatisticsProviderRegistry( $providers, new NullErrorStatisticsProvider() )
		);

		return $use_case->execute( $input, new DateTimeImmutable( '2026-09-03T12:00:00Z', new DateTimeZone( 'UTC' ) ) );
	}

	/**
	 * Returns a provider that measures the given path counts.
	 *
	 * @param string             $slug   Provider slug.
	 * @param array<string, int> $counts Path counts.
	 * @return ErrorStatisticsProvider
	 */
	private function measuring_provider( string $slug, array $counts ): ErrorStatisticsProvider {
		$paths = array();
		foreach ( $counts as $path => $hits ) {
			$paths[] = new NotFoundCount( (string) $path, $hits );
		}

		return $this->provider( $slug, ErrorStatisticsAvailability::MEASURED, $paths );
	}

	/**
	 * Returns a provider whose logging is switched off.
	 *
	 * @param string $slug    Provider slug.
	 * @param string $setting Setting responsible.
	 * @return ErrorStatisticsProvider
	 */
	private function disabled_provider( string $slug, string $setting ): ErrorStatisticsProvider {
		return $this->provider( $slug, ErrorStatisticsAvailability::DISABLED, array(), $setting );
	}

	/**
	 * Returns a provider that refuses the acting principal.
	 *
	 * @param string $slug Provider slug.
	 * @return ErrorStatisticsProvider
	 */
	private function forbidding_provider( string $slug ): ErrorStatisticsProvider {
		return $this->provider( $slug, ErrorStatisticsAvailability::FORBIDDEN );
	}

	/**
	 * Builds a fake provider with a fixed answer.
	 *
	 * @param string                      $slug         Provider slug.
	 * @param ErrorStatisticsAvailability $availability Answer state.
	 * @param array                       $paths        Counts for a measured answer.
	 * @param string|null                 $disabled_by  Setting for a disabled answer.
	 * @phpstan-param list<NotFoundCount> $paths
	 * @return ErrorStatisticsProvider
	 */
	private function provider( string $slug, ErrorStatisticsAvailability $availability, array $paths = array(), ?string $disabled_by = null ): ErrorStatisticsProvider {
		return new class( $slug, $availability, $paths, $disabled_by ) implements ErrorStatisticsProvider {

			/**
			 * Creates the fake.
			 *
			 * @param string                      $slug         Provider slug.
			 * @param ErrorStatisticsAvailability $availability Answer state.
			 * @param array                       $paths        Counts.
			 * @param string|null                 $disabled_by  Disabling setting.
			 * @phpstan-param list<NotFoundCount> $paths
			 */
			public function __construct(
				private string $slug,
				private ErrorStatisticsAvailability $availability,
				private array $paths,
				private ?string $disabled_by,
			) {
			}

			/**
			 * Always available; unavailability is exercised through the null object.
			 *
			 * @return bool
			 */
			public function is_available(): bool {
				return true;
			}

			/**
			 * Returns fake identity.
			 *
			 * @return ErrorStatisticsProviderStatus
			 */
			public function status(): ErrorStatisticsProviderStatus {
				return new ErrorStatisticsProviderStatus( $this->slug, '1.0.0', true, array( 'not_found' ) );
			}

			/**
			 * Returns the fixed answer.
			 *
			 * @param NotFoundStatisticsQuery $query Bounded query.
			 * @return NotFoundStatistics
			 */
			public function top_not_found( NotFoundStatisticsQuery $query ): NotFoundStatistics {
				return new NotFoundStatistics(
					$this->status(),
					$this->availability,
					new ErrorStatisticsWindow( 7, $query->since_iso(), $query->since_iso(), false ),
					$this->paths,
					$this->disabled_by
				);
			}
		};
	}
}

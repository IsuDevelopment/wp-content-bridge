<?php
/**
 * Statistics registry tests.
 *
 * @package IsuDev\WPContentBridge\Tests
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Tests\Unit\Application\Statistics;

use IsuDev\WPContentBridge\Application\Statistics\ErrorStatisticsProvider;
use IsuDev\WPContentBridge\Application\Statistics\ErrorStatisticsProviderRegistry;
use IsuDev\WPContentBridge\Application\Statistics\NotFoundStatisticsQuery;
use IsuDev\WPContentBridge\Application\Statistics\NullErrorStatisticsProvider;
use IsuDev\WPContentBridge\Domain\Statistics\ErrorStatisticsAvailability;
use IsuDev\WPContentBridge\Domain\Statistics\ErrorStatisticsProviderStatus;
use IsuDev\WPContentBridge\Domain\Statistics\ErrorStatisticsWindow;
use IsuDev\WPContentBridge\Domain\Statistics\NotFoundStatistics;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Covers the registry rules that keep an absent provider from being reported
 * as a healthy one (ADR 0030 s1, s2).
 */
final class ErrorStatisticsProviderRegistryTest extends TestCase {

	/**
	 * With nothing available the registry hands back the null object, not an
	 * empty list: an empty list is what a caller would serialize as "no 404s".
	 */
	public function test_an_empty_registry_asks_the_null_provider(): void {
		$registry = new ErrorStatisticsProviderRegistry( array(), new NullErrorStatisticsProvider() );

		$asked = $registry->to_ask();

		self::assertCount( 1, $asked );
		self::assertInstanceOf( NullErrorStatisticsProvider::class, $asked[0] );
	}

	/**
	 * An unavailable provider is reported in `statuses()` - so diagnostics can
	 * show it is configured but absent - while never being asked for counts.
	 */
	public function test_an_unavailable_provider_is_reported_but_not_asked(): void {
		$registry = new ErrorStatisticsProviderRegistry(
			array( $this->provider( 'redirection', false ) ),
			new NullErrorStatisticsProvider()
		);

		self::assertSame( array(), $registry->available() );
		self::assertSame( 'redirection', $registry->statuses()[0]->provider );
		self::assertSame( 'none', $registry->statuses()[1]->provider );
		self::assertInstanceOf( NullErrorStatisticsProvider::class, $registry->to_ask()[0] );
	}

	/**
	 * The fallback status is appended only when nothing else can answer.
	 */
	public function test_the_fallback_is_absent_once_a_provider_answers(): void {
		$registry = new ErrorStatisticsProviderRegistry(
			array( $this->provider( 'redirection', true ) ),
			new NullErrorStatisticsProvider()
		);

		self::assertCount( 1, $registry->statuses() );
		self::assertSame( 'redirection', $registry->statuses()[0]->provider );
	}

	/**
	 * There is deliberately no implicit active-provider accessor, for the same
	 * reason the redirect registry has none: a first-available pick would
	 * silently report one backend's switched-off log as the site's answer
	 * while another was recording.
	 */
	public function test_the_registry_exposes_no_implicit_active_provider(): void {
		$methods = array();
		foreach ( ( new ReflectionClass( ErrorStatisticsProviderRegistry::class ) )->getMethods() as $method ) {
			$methods[] = $method->getName();
		}

		self::assertNotContains( 'active', $methods );
	}

	/**
	 * The null object answers unavailable rather than an empty measurement.
	 */
	public function test_the_null_provider_answers_unavailable(): void {
		$result = ( new NullErrorStatisticsProvider() )->top_not_found(
			new NotFoundStatisticsQuery( new \DateTimeImmutable( '2026-09-03T12:00:00Z' ) )
		);

		self::assertSame( ErrorStatisticsAvailability::UNAVAILABLE, $result->availability );
		self::assertFalse( $result->is_measured() );
	}

	/**
	 * Builds a fake provider with a fixed availability.
	 *
	 * @param string $slug      Provider slug.
	 * @param bool   $available Whether it can answer.
	 * @return ErrorStatisticsProvider
	 */
	private function provider( string $slug, bool $available ): ErrorStatisticsProvider {
		return new class( $slug, $available ) implements ErrorStatisticsProvider {

			/**
			 * Creates the fake.
			 *
			 * @param string $slug      Provider slug.
			 * @param bool   $available Whether it can answer.
			 */
			public function __construct( private string $slug, private bool $available ) {
			}

			/**
			 * Returns the fixed availability.
			 *
			 * @return bool
			 */
			public function is_available(): bool {
				return $this->available;
			}

			/**
			 * Returns fake identity.
			 *
			 * @return ErrorStatisticsProviderStatus
			 */
			public function status(): ErrorStatisticsProviderStatus {
				return new ErrorStatisticsProviderStatus(
					$this->slug,
					$this->available ? '5.9.0' : null,
					$this->available,
					$this->available ? array( 'not_found' ) : array()
				);
			}

			/**
			 * Answers an empty measurement; the registry tests never read it.
			 *
			 * @param NotFoundStatisticsQuery $query Bounded query.
			 * @return NotFoundStatistics
			 */
			public function top_not_found( NotFoundStatisticsQuery $query ): NotFoundStatistics {
				return new NotFoundStatistics(
					$this->status(),
					ErrorStatisticsAvailability::MEASURED,
					new ErrorStatisticsWindow( 7, $query->since_iso(), $query->since_iso(), false )
				);
			}
		};
	}
}

<?php
/**
 * SEO provider registry tests.
 *
 * @package IsuDev\WPContentBridge\Tests
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Tests\Unit\Application\Seo;

use IsuDev\WPContentBridge\Application\Seo\NullSeoProvider;
use IsuDev\WPContentBridge\Application\Seo\SeoProvider;
use IsuDev\WPContentBridge\Application\Seo\SeoProviderRegistry;
use IsuDev\WPContentBridge\Domain\Seo\SeoCompleteness;
use IsuDev\WPContentBridge\Domain\Seo\SeoDocument;
use IsuDev\WPContentBridge\Domain\Seo\SeoProviderStatus;
use IsuDev\WPContentBridge\Domain\Seo\SeoTarget;
use PHPUnit\Framework\TestCase;

/**
 * Verifies explicit provider priority and the no-plugin fallback.
 */
final class SeoProviderRegistryTest extends TestCase {

	/**
	 * The first available configured provider wins without merging provenance.
	 */
	public function test_selects_first_available_provider(): void {
		$unavailable = $this->provider( 'first', false );
		$available   = $this->provider( 'second', true );
		$registry    = new SeoProviderRegistry( array( $unavailable, $available ), new NullSeoProvider() );

		self::assertSame( 'second', $registry->active()->status()->provider );
	}

	/**
	 * Missing integrations return an explicit unavailable document.
	 */
	public function test_uses_null_provider_when_no_integration_is_available(): void {
		$registry = new SeoProviderRegistry( array(), new NullSeoProvider() );
		$provider = $registry->active();
		$document = $provider->get( SeoTarget::for_post( 1 ) )->to_array();

		self::assertSame( 'none', $provider->status()->provider );
		self::assertFalse( $provider->status()->detected );
		self::assertSame(
			array(
				'provider'                     => $provider->status()->to_array(),
				'normalization_schema_version' => '1.1',
				'completeness'                 => 'unavailable',
			),
			$document['provenance']
		);
		self::assertNotEmpty( $document['warnings'] );
	}

	/**
	 * Creates a provider fake with deterministic status.
	 *
	 * @param string $name      Provider slug.
	 * @param bool   $available Availability flag.
	 * @return SeoProvider
	 */
	private function provider( string $name, bool $available ): SeoProvider {
		return new readonly class( $name, $available ) implements SeoProvider {

			/**
			 * Creates the fake.
			 *
			 * @param string $name      Provider slug.
			 * @param bool   $available Availability flag.
			 */
			public function __construct( private string $name, private bool $available ) {
			}

			/**
			 * Returns configured availability.
			 *
			 * @return bool
			 */
			public function is_available(): bool {
				return $this->available;
			}

			/**
			 * Returns fake status.
			 *
			 * @return SeoProviderStatus
			 */
			public function status(): SeoProviderStatus {
				return new SeoProviderStatus( $this->name, '1.0', true, array(), array( 'resolved' ) );
			}

			/**
			 * Returns an empty complete document.
			 *
			 * @param SeoTarget $target Unused target.
			 * @return SeoDocument
			 */
			public function get( SeoTarget $target ): SeoDocument {
				unset( $target );

				return new SeoDocument( array(), array(), array(), array(), $this->status(), SeoCompleteness::COMPLETE, array() );
			}
		};
	}
}

<?php
/**
 * Provider-neutral SEO use-case tests.
 *
 * @package IsuDev\WPContentBridge\Tests
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Tests\Unit\Application\Seo;

use IsuDev\WPContentBridge\Application\Content\ContentUnavailable;
use IsuDev\WPContentBridge\Application\Seo\GetSeo;
use IsuDev\WPContentBridge\Application\Seo\NullSeoProvider;
use IsuDev\WPContentBridge\Application\Seo\SeoProviderRegistry;
use IsuDev\WPContentBridge\Application\Seo\SeoTargetAccess;
use IsuDev\WPContentBridge\Domain\Seo\SeoTarget;
use PHPUnit\Framework\TestCase;

/**
 * Locks authorization ordering ahead of provider access.
 */
final class GetSeoTest extends TestCase {

	/**
	 * A denied target is non-enumerating and never reaches the provider.
	 */
	public function test_denied_target_throws_content_unavailable(): void {
		$use_case = new GetSeo(
			new SeoProviderRegistry( array(), new NullSeoProvider() ),
			$this->access( false )
		);

		$this->expectException( ContentUnavailable::class );
		$use_case->execute( SeoTarget::for_post( 123 ) );
	}

	/**
	 * An authorized target returns the explicit null-provider document.
	 */
	public function test_authorized_target_uses_active_provider(): void {
		$use_case = new GetSeo(
			new SeoProviderRegistry( array(), new NullSeoProvider() ),
			$this->access( true )
		);

		$result = $use_case->execute( SeoTarget::for_post( 123 ) )->to_array();

		self::assertSame( 'none', $result['provenance']['provider']['provider'] );
		self::assertSame( 'unavailable', $result['provenance']['completeness'] );
	}

	/**
	 * Creates a deterministic access fake.
	 *
	 * @param bool $allowed Result returned by the fake.
	 * @return SeoTargetAccess
	 */
	private function access( bool $allowed ): SeoTargetAccess {
		return new class( $allowed ) implements SeoTargetAccess {

			/**
			 * Creates the access fake.
			 *
			 * @param bool $allowed Configured decision.
			 */
			public function __construct( private bool $allowed ) {
			}

			/**
			 * Returns the configured decision.
			 *
			 * @param SeoTarget $target Unused target.
			 * @return bool
			 */
			public function readable_target( SeoTarget $target ): ?SeoTarget {
				return $this->allowed ? $target : null;
			}
		};
	}
}

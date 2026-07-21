<?php
/**
 * List-block-patterns use-case tests.
 *
 * @package IsuDev\WPContentBridgeTests
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Tests\Unit\Application\Pattern;

use IsuDev\WPContentBridge\Application\Pattern\BlockPatternAccess;
use IsuDev\WPContentBridge\Application\Pattern\BlockPatternCatalog;
use IsuDev\WPContentBridge\Application\Pattern\ListBlockPatterns;
use IsuDev\WPContentBridge\Application\Pattern\PatternAccessManager;
use IsuDev\WPContentBridge\Application\Pattern\PatternUnavailable;
use IsuDev\WPContentBridge\Domain\Pattern\PatternQuery;
use IsuDev\WPContentBridge\Domain\Pattern\PatternSearchResult;
use PHPUnit\Framework\TestCase;

/**
 * Verifies feature and native authorization before catalog access.
 */
final class ListBlockPatternsTest extends TestCase {

	/**
	 * Disabled feature prevents native checks and catalog reads.
	 */
	public function test_disabled_feature_blocks_before_catalog_access(): void {
		$access  = $this->access( true );
		$catalog = $this->catalog();
		$service = new ListBlockPatterns( new PatternAccessManager( false, $access ), $catalog );

		try {
			$service->execute( PatternQuery::from_input( array() ) );
			self::fail( 'Disabled pattern reads were bypassed.' );
		} catch ( PatternUnavailable ) {
			self::assertSame( 0, $access->calls );
			self::assertSame( 0, $catalog->calls );
		}
	}

	/**
	 * Native denial prevents catalog reads.
	 */
	public function test_native_denial_blocks_catalog_access(): void {
		$catalog = $this->catalog();
		$service = new ListBlockPatterns( new PatternAccessManager( true, $this->access( false ) ), $catalog );

		try {
			$service->execute( PatternQuery::from_input( array() ) );
			self::fail( 'Native editor denial was bypassed.' );
		} catch ( PatternUnavailable ) {
			self::assertSame( 0, $catalog->calls );
		}
	}

	/**
	 * Authorized requests reach the catalog exactly once.
	 */
	public function test_authorized_request_reaches_catalog(): void {
		$catalog = $this->catalog();
		$service = new ListBlockPatterns( new PatternAccessManager( true, $this->access( true ) ), $catalog );

		$result = $service->execute( PatternQuery::from_input( array() ) );

		self::assertSame( 1, $catalog->calls );
		self::assertSame( 0, $result->total_items );
	}

	/**
	 * Creates a recording native-access port.
	 *
	 * @param bool $allowed Access decision.
	 * @return BlockPatternAccess&object{calls: int}
	 */
	private function access( bool $allowed ): BlockPatternAccess {
		return new class( $allowed ) implements BlockPatternAccess {
			/**
			 * Native-access invocation count.
			 *
			 * @var int
			 */
			public int $calls = 0;

			/**
			 * Creates the access spy.
			 *
			 * @param bool $allowed Access decision.
			 */
			public function __construct( private bool $allowed ) {
			}

			/**
			 * Returns the configured access decision.
			 *
			 * @return bool
			 */
			public function can_read(): bool {
				++$this->calls;

				return $this->allowed;
			}
		};
	}

	/**
	 * Creates a recording empty catalog.
	 *
	 * @return BlockPatternCatalog&object{calls: int}
	 */
	private function catalog(): BlockPatternCatalog {
		return new class() implements BlockPatternCatalog {
			/**
			 * Catalog invocation count.
			 *
			 * @var int
			 */
			public int $calls = 0;

			/**
			 * Returns an empty result page.
			 *
			 * @param PatternQuery $query Listing criteria.
			 * @return PatternSearchResult
			 */
			public function list( PatternQuery $query ): PatternSearchResult {
				++$this->calls;

				return new PatternSearchResult( array(), $query->page, $query->per_page, 0, 0, true, false, 1000, 2097152 );
			}
		};
	}
}

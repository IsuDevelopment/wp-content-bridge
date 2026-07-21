<?php
/**
 * Search-content use-case tests.
 *
 * @package IsuDev\WPContentBridge\Tests
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Tests\Unit\Application\Content;

use InvalidArgumentException;
use IsuDev\WPContentBridge\Application\Content\ContentRepository;
use IsuDev\WPContentBridge\Application\Content\SearchContent;
use IsuDev\WPContentBridge\Application\Content\TaxonomyCatalog;
use IsuDev\WPContentBridge\Application\ContentAccess\ContentAccessManager;
use IsuDev\WPContentBridge\Application\ContentAccess\ContentAccessSettingsRepository;
use IsuDev\WPContentBridge\Application\ContentAccess\ContentTypeCatalog;
use IsuDev\WPContentBridge\Domain\Content\ContentDetail;
use IsuDev\WPContentBridge\Domain\Content\ContentQuery;
use IsuDev\WPContentBridge\Domain\Content\ContentSearchResult;
use IsuDev\WPContentBridge\Domain\ContentAccess\ContentTypeDefinition;
use PHPUnit\Framework\TestCase;

/**
 * Verifies type-policy and taxonomy gates before repository execution.
 */
final class SearchContentTest extends TestCase {

	/**
	 * An eligible taxonomy assigned to every effective type reaches the repository.
	 */
	public function test_executes_with_taxonomy_supported_by_effective_types(): void {
		$repository = $this->repository();
		$service    = new SearchContent(
			$this->access_manager(),
			$repository,
			$this->taxonomy_catalog( array( 'category' => array( 'post' ) ) )
		);
		$query      = ContentQuery::from_input(
			array(
				'post_types' => array( 'post' ),
				'taxonomy'   => array(
					array(
						'taxonomy' => 'category',
						'term_ids' => array( 3 ),
					),
				),
			)
		);

		$result = $service->execute( $query );

		self::assertSame( 0, $result->total_items );
		self::assertNotNull( $repository->received );
		self::assertSame( array( 'post' ), $repository->received->post_types );
	}

	/**
	 * A taxonomy assigned to only one of several effective types is rejected.
	 */
	public function test_rejects_taxonomy_not_supported_by_every_effective_type(): void {
		$repository = $this->repository();
		$service    = new SearchContent(
			$this->access_manager(),
			$repository,
			$this->taxonomy_catalog( array( 'category' => array( 'post' ) ) )
		);
		$query      = ContentQuery::from_input(
			array(
				'taxonomy' => array(
					array(
						'taxonomy' => 'category',
						'term_ids' => array( 3 ),
					),
				),
			)
		);

		$this->expectException( InvalidArgumentException::class );

		$service->execute( $query );
	}

	/**
	 * Creates built-in post/page access defaults.
	 */
	private function access_manager(): ContentAccessManager {
		$settings = new class() implements ContentAccessSettingsRepository {

			/**
			 * Loads empty in-memory settings.
			 *
			 * @return array<string, array<string, mixed>>
			 */
			public function load(): array {
				return array();
			}
		};
		$catalog  = new class() implements ContentTypeCatalog {

			/**
			 * Lists built-in content types.
			 *
			 * @return list<ContentTypeDefinition>
			 */
			public function list_eligible(): array {
				return array(
					new ContentTypeDefinition( 'post', 'Post', true, true, true ),
					new ContentTypeDefinition( 'page', 'Page', true, true, true ),
				);
			}
		};

		return new ContentAccessManager( $settings, $catalog );
	}

	/**
	 * Creates a recording content repository.
	 *
	 * @return ContentRepository&object{received: ContentQuery|null}
	 */
	private function repository(): ContentRepository {
		return new class() implements ContentRepository {

			/**
			 * Last search query.
			 *
			 * @var ContentQuery|null
			 */
			public ?ContentQuery $received = null;

			/**
			 * Records and returns an empty search page.
			 *
			 * @param ContentQuery $query Search query.
			 * @return ContentSearchResult
			 */
			public function search( ContentQuery $query ): ContentSearchResult {
				$this->received = $query;

				return new ContentSearchResult( array(), $query->page, $query->per_page, 0, 0 );
			}

			/**
			 * Returns no content type in this search-only fake.
			 *
			 * @param int $post_id Object ID.
			 * @return string|null
			 */
			public function post_type( int $post_id ): ?string {
				unset( $post_id );

				return null;
			}

			/**
			 * Denies unused detail reads.
			 *
			 * @param int $post_id Object ID.
			 * @return bool
			 */
			public function can_read( int $post_id ): bool {
				unset( $post_id );

				return false;
			}

			/**
			 * Returns no detail in this search-only fake.
			 *
			 * @param int   $post_id         Object ID.
			 * @param array $representations Requested forms.
			 * @param array $relationships   Requested relationships.
			 * @return ContentDetail|null
			 */
			public function get( int $post_id, array $representations, array $relationships ): ?ContentDetail {
				unset( $post_id, $representations, $relationships );

				return null;
			}
		};
	}

	/**
	 * Creates an in-memory taxonomy assignment catalog.
	 *
	 * @param array $assignments Allowed types by taxonomy.
	 * @return TaxonomyCatalog
	 * @phpstan-param array<string, list<string>> $assignments
	 */
	private function taxonomy_catalog( array $assignments ): TaxonomyCatalog {
		return new class( $assignments ) implements TaxonomyCatalog {

			/**
			 * Creates the taxonomy fake.
			 *
			 * @param array<string, list<string>> $assignments Allowed types by taxonomy.
			 */
			public function __construct( private array $assignments ) {
			}

			/**
			 * Checks all effective type assignments.
			 *
			 * @param string $taxonomy  Taxonomy name.
			 * @param array  $post_types Effective types.
			 * @return bool
			 * @phpstan-param non-empty-list<string> $post_types
			 */
			public function supports( string $taxonomy, array $post_types ): bool {
				$allowed = $this->assignments[ $taxonomy ] ?? array();

				return array() === array_diff( $post_types, $allowed );
			}
		};
	}
}

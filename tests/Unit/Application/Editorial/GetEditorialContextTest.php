<?php
/**
 * Editorial-context service tests.
 *
 * @package IsuDev\WPContentBridge\Tests
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Tests\Unit\Application\Editorial;

use InvalidArgumentException;
use IsuDev\WPContentBridge\Application\Content\ContentRepository;
use IsuDev\WPContentBridge\Application\Content\SearchContent;
use IsuDev\WPContentBridge\Application\Content\TaxonomyCatalog;
use IsuDev\WPContentBridge\Application\ContentAccess\ContentAccessManager;
use IsuDev\WPContentBridge\Application\ContentAccess\ContentAccessSettingsRepository;
use IsuDev\WPContentBridge\Application\ContentAccess\ContentTypeCatalog;
use IsuDev\WPContentBridge\Application\Editorial\EditorialContextRepository;
use IsuDev\WPContentBridge\Application\Editorial\GetEditorialContext;
use IsuDev\WPContentBridge\Application\Seo\NullSeoProvider;
use IsuDev\WPContentBridge\Application\Seo\SeoProviderRegistry;
use IsuDev\WPContentBridge\Domain\Content\ContentDetail;
use IsuDev\WPContentBridge\Domain\Content\ContentQuery;
use IsuDev\WPContentBridge\Domain\Content\ContentSearchResult;
use IsuDev\WPContentBridge\Domain\Content\ContentSummary;
use IsuDev\WPContentBridge\Domain\ContentAccess\ContentTypeDefinition;
use IsuDev\WPContentBridge\Domain\Editorial\EditorialContextQuery;
use PHPUnit\Framework\TestCase;

/**
 * Verifies policy filtering and non-enumerating author composition.
 */
final class GetEditorialContextTest extends TestCase {

	/**
	 * Only requested sections and authors from readable summaries are returned.
	 */
	public function test_composes_requested_bounded_sections(): void {
		$service = $this->service();
		$result  = $service->execute(
			EditorialContextQuery::from_input(
				array(
					'sections'           => array( 'post_types', 'terms', 'authors', 'recent_content', 'local_businesses' ),
					'post_types'         => array( 'post' ),
					'taxonomies'         => array( 'category' ),
					'recent_limit'       => 5,
					'terms_per_taxonomy' => 2,
				)
			)
		)->to_array();

		self::assertSame( array( 'post' ), array_column( $result['context']['post_types'], 'name' ) );
		self::assertSame( 7, $result['context']['authors'][0]['id'] );
		self::assertSame( 'category', $result['context']['terms'][0]['taxonomy'] );
		self::assertCount( 1, $result['context']['recent_content'] );
		self::assertSame( 'unsupported', $result['context']['local_businesses']['state'] );
		self::assertArrayNotHasKey( 'taxonomies', $result['context'] );
	}

	/**
	 * A disabled or unknown requested type is rejected instead of silently omitted.
	 */
	public function test_rejects_unavailable_requested_type(): void {
		$this->expectException( InvalidArgumentException::class );

		$this->service()->execute( EditorialContextQuery::from_input( array( 'post_types' => array( 'secret' ) ) ) );
	}

	/**
	 * Builds the service over in-memory ports.
	 */
	private function service(): GetEditorialContext {
		$settings = new class() implements ContentAccessSettingsRepository {

			/**
			 * Returns empty stored settings.
			 *
			 * @return array<string, array<string, mixed>>
			 */
			public function load(): array {
				return array();
			}
		};
		$catalog  = new class() implements ContentTypeCatalog {

			/**
			 * Returns built-in eligible types.
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
		$access   = new ContentAccessManager( $settings, $catalog );
		$search   = new SearchContent( $access, $this->content_repository(), $this->taxonomy_catalog() );

		return new GetEditorialContext(
			$access,
			$search,
			$this->editorial_repository(),
			new SeoProviderRegistry( array(), new NullSeoProvider() )
		);
	}

	/**
	 * Returns a single authorization-filtered summary.
	 */
	private function content_repository(): ContentRepository {
		return new class() implements ContentRepository {

			/**
			 * Returns one readable summary.
			 *
			 * @param ContentQuery $query Search query.
			 * @return ContentSearchResult
			 */
			public function search( ContentQuery $query ): ContentSearchResult {
				$item = new ContentSummary( 12, 'post', 'publish', 'Title', 'title', 'https://example.com/title', 'Excerpt', 7, '2026-07-17T10:00:00+00:00', '2026-07-17T11:00:00+00:00' );

				return new ContentSearchResult( array( $item ), 1, $query->per_page, 1, 1 );
			}

			/**
			 * Returns no detail type.
			 *
			 * @param int $post_id Unused ID.
			 * @return string|null
			 */
			public function post_type( int $post_id ): ?string {
				unset( $post_id );
				return null;
			}

			/**
			 * Denies the unused detail path.
			 *
			 * @param int $post_id Unused ID.
			 * @return bool
			 */
			public function can_read( int $post_id ): bool {
				unset( $post_id );
				return false;
			}

			/**
			 * Returns no unused content detail.
			 *
			 * @param int   $post_id         Unused ID.
			 * @param array $representations Unused representations.
			 * @param array $relationships   Unused relationships.
			 * @return ContentDetail|null
			 */
			public function get( int $post_id, array $representations, array $relationships ): ?ContentDetail {
				unset( $post_id, $representations, $relationships );
				return null;
			}
		};
	}

	/**
	 * Allows unused search filters in the fake.
	 */
	private function taxonomy_catalog(): TaxonomyCatalog {
		return new class() implements TaxonomyCatalog {

			/**
			 * Allows every fake taxonomy assignment.
			 *
			 * @param string $taxonomy  Taxonomy slug.
			 * @param array  $post_types Effective types.
			 * @return bool
			 */
			public function supports( string $taxonomy, array $post_types ): bool {
				unset( $taxonomy, $post_types );
				return true;
			}
		};
	}

	/**
	 * Returns bounded vocabulary and labels without WordPress dependencies.
	 */
	private function editorial_repository(): EditorialContextRepository {
		return new class() implements EditorialContextRepository {

			/**
			 * Returns one taxonomy and term.
			 *
			 * @param array $post_types      Effective types.
			 * @param array $requested       Requested taxonomies.
			 * @param bool  $include_terms   Whether terms are selected.
			 * @param int   $terms_per_taxonomy Term bound.
			 * @return list<array<string, mixed>>
			 */
			public function taxonomies( array $post_types, array $requested, bool $include_terms, int $terms_per_taxonomy ): array {
				unset( $post_types, $requested, $include_terms, $terms_per_taxonomy );
				return array(
					array(
						'name'            => 'category',
						'label'           => 'Categories',
						'hierarchical'    => true,
						'object_types'    => array( 'post' ),
						'terms'           => array(
							array(
								'id'     => 1,
								'name'   => 'News',
								'slug'   => 'news',
								'parent' => 0,
							),
						),
						'terms_truncated' => false,
					),
				);
			}

			/**
			 * Labels observed author IDs.
			 *
			 * @param array $author_ids Observed IDs.
			 * @return list<array{id: int, display_name: string}>
			 */
			public function authors( array $author_ids ): array {
				return array_map(
					static fn ( int $id ): array => array(
						'id'           => $id,
						'display_name' => 'Observed Author',
					),
					$author_ids
				);
			}
		};
	}
}

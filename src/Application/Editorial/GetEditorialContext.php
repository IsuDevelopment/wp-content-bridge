<?php
/**
 * Get-editorial-context use case.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Application\Editorial;

use InvalidArgumentException;
use IsuDev\WPContentBridge\Application\Content\SearchContent;
use IsuDev\WPContentBridge\Application\ContentAccess\ContentAccessManager;
use IsuDev\WPContentBridge\Application\Seo\SeoProviderRegistry;
use IsuDev\WPContentBridge\Domain\Content\ContentQuery;
use IsuDev\WPContentBridge\Domain\Content\ContentSummary;
use IsuDev\WPContentBridge\Domain\ContentAccess\ContentOperation;
use IsuDev\WPContentBridge\Domain\ContentAccess\ContentTypeDefinition;
use IsuDev\WPContentBridge\Domain\Editorial\EditorialContext;
use IsuDev\WPContentBridge\Domain\Editorial\EditorialContextQuery;
use IsuDev\WPContentBridge\Domain\Seo\SeoTarget;
use Throwable;

/**
 * Composes bounded vocabulary, inventory, authors, and normalized Local data.
 */
final readonly class GetEditorialContext {

	public const MAX_TAXONOMIES = 20;

	/**
	 * Creates the editorial context service.
	 *
	 * @param ContentAccessManager       $access     Shared content policy.
	 * @param SearchContent              $search     Authorization-aware search.
	 * @param EditorialContextRepository $repository WordPress metadata port.
	 * @param SeoProviderRegistry        $providers  Provider-neutral SEO registry.
	 */
	public function __construct(
		private ContentAccessManager $access,
		private SearchContent $search,
		private EditorialContextRepository $repository,
		private SeoProviderRegistry $providers,
	) {
	}

	/**
	 * Returns only requested context sections.
	 *
	 * @param EditorialContextQuery $query Normalized request.
	 * @return EditorialContext
	 * @throws InvalidArgumentException When a requested type or taxonomy is unavailable.
	 */
	public function execute( EditorialContextQuery $query ): EditorialContext {
		$definitions = $this->effective_definitions( $query->post_types );
		$post_types  = array_map( static fn ( ContentTypeDefinition $definition ): string => $definition->name, $definitions );
		$context     = array();
		$warnings    = array();

		if ( $query->includes( 'post_types' ) ) {
			$context['post_types'] = array_map( array( self::class, 'serialize_post_type' ), $definitions );
		}

		if ( $query->includes( 'taxonomies' ) || $query->includes( 'terms' ) ) {
			$taxonomy_rows = $this->repository->taxonomies(
				$post_types,
				$query->taxonomies,
				$query->includes( 'terms' ),
				$query->terms_per_taxonomy
			);
			if ( array() !== $query->taxonomies ) {
				$found = array();
				foreach ( $taxonomy_rows as $taxonomy_row ) {
					if ( isset( $taxonomy_row['name'] ) && is_string( $taxonomy_row['name'] ) ) {
						$found[] = $taxonomy_row['name'];
					}
				}
				if ( array() !== array_diff( $query->taxonomies, $found ) ) {
					throw new InvalidArgumentException( 'A requested taxonomy is unavailable for the effective content types.' );
				}
			}
			if ( $query->includes( 'taxonomies' ) ) {
				$context['taxonomies'] = array_map(
					static fn ( array $row ): array => array(
						'name'         => $row['name'],
						'label'        => $row['label'],
						'hierarchical' => $row['hierarchical'],
						'object_types' => $row['object_types'],
					),
					$taxonomy_rows
				);
			}
			if ( $query->includes( 'terms' ) ) {
				$context['terms'] = array_map(
					static fn ( array $row ): array => array(
						'taxonomy'  => $row['name'],
						'items'     => $row['terms'],
						'truncated' => $row['terms_truncated'],
					),
					$taxonomy_rows
				);
			}
		}

		$recent = null;
		if ( $query->includes( 'recent_content' ) || $query->includes( 'authors' ) || $query->includes( 'local_businesses' ) ) {
			$recent = $this->search->execute(
				ContentQuery::from_input(
					array(
						'post_types' => $post_types,
						'statuses'   => array( 'publish' ),
						'per_page'   => $query->recent_limit,
						'order_by'   => 'modified',
						'order'      => 'desc',
					)
				)
			);
		}

		if ( $query->includes( 'recent_content' ) && null !== $recent ) {
			$context['recent_content'] = array_map( static fn ( ContentSummary $item ): array => $item->to_array(), $recent->items );
			if ( $recent->has_more ) {
				$warnings[] = 'Recent content is bounded; more readable objects are available.';
			}
		}

		if ( $query->includes( 'authors' ) && null !== $recent ) {
			$author_ids         = array_values( array_unique( array_map( static fn ( ContentSummary $item ): int => $item->author_id, $recent->items ) ) );
			$context['authors'] = $this->repository->authors( $author_ids );
		}

		if ( $query->includes( 'local_businesses' ) ) {
			$context['local_businesses'] = $this->local_businesses( null !== $recent && isset( $recent->items[0] ) ? $recent->items[0]->id : null, $warnings );
		}

		return new EditorialContext(
			$query->sections,
			$context,
			array(
				'recent_content_limit' => $query->recent_limit,
				'taxonomy_limit'       => self::MAX_TAXONOMIES,
				'terms_per_taxonomy'   => $query->terms_per_taxonomy,
			),
			array_slice( $warnings, 0, EditorialContext::MAX_WARNINGS )
		);
	}

	/**
	 * Resolves content types permitted for both discovery and reading.
	 *
	 * @param array $requested Requested slugs.
	 * @return non-empty-list<ContentTypeDefinition>
	 * @phpstan-param list<string> $requested
	 * @throws InvalidArgumentException When no exact policy-approved type set exists.
	 */
	private function effective_definitions( array $requested ): array {
		$allowed = array_values(
			array_filter(
				$this->access->content_types(),
				fn ( ContentTypeDefinition $definition ): bool => $this->access->allows( $definition->name, ContentOperation::READ )
					&& $this->access->allows( $definition->name, ContentOperation::SEARCH )
			)
		);

		if ( array() !== $requested ) {
			$allowed = array_values( array_filter( $allowed, static fn ( ContentTypeDefinition $definition ): bool => in_array( $definition->name, $requested, true ) ) );
			if ( count( $allowed ) !== count( array_unique( $requested ) ) ) {
				throw new InvalidArgumentException( 'A requested content type is unavailable for editorial context.' );
			}
		}

		if ( array() === $allowed ) {
			throw new InvalidArgumentException( 'No content type is available for editorial context.' );
		}

		return $allowed;
	}

	/**
	 * Serializes one policy-approved content type.
	 *
	 * @param ContentTypeDefinition $definition Content type.
	 * @return array<string, mixed>
	 */
	private static function serialize_post_type( ContentTypeDefinition $definition ): array {
		return array(
			'name'         => $definition->name,
			'label'        => $definition->label,
			'public'       => $definition->is_public,
			'show_in_rest' => $definition->show_in_rest,
			'built_in'     => $definition->built_in,
		);
	}

	/**
	 * Extracts provider-neutral Local data from an already readable post target.
	 *
	 * @param int|null $post_id  Readable representative post.
	 * @param array    $warnings Warning sink.
	 * @return array<string, mixed>
	 * @phpstan-param list<string> $warnings
	 */
	private function local_businesses( ?int $post_id, array &$warnings ): array {
		$provider = $this->providers->active();
		$status   = $provider->status()->to_array();
		if ( null === $post_id ) {
			return array(
				'state'    => 'unavailable',
				'source'   => 'wp-content-bridge.editorial-context',
				'reason'   => 'No readable published content is available as a public Schema target.',
				'provider' => $status,
				'items'    => array(),
			);
		}

		try {
			$document = $provider->get( SeoTarget::for_post( $post_id ) );
			$field    = $document->resolved['local_businesses'] ?? null;
			$items    = null !== $field && is_array( $field->value ) ? array_values( $field->value ) : array();

			return array(
				'state'    => null !== $field ? $field->state->value : 'unsupported',
				'source'   => null !== $field ? $field->source : 'wp-content-bridge.editorial-context',
				'reason'   => null !== $field ? $field->reason : 'The active SEO provider does not expose a normalized Local profile.',
				'provider' => $status,
				'items'    => $items,
			);
		} catch ( Throwable ) {
			$warnings[] = 'Normalized public Local data could not be read from the active SEO provider.';

			return array(
				'state'    => 'unavailable',
				'source'   => 'wp-content-bridge.editorial-context',
				'reason'   => 'Normalized public Local data is temporarily unavailable.',
				'provider' => $status,
				'items'    => array(),
			);
		}
	}
}

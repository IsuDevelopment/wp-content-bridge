<?php
/**
 * WordPress content read adapter.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Infrastructure\WordPress;

use IsuDev\WPContentBridge\Application\Content\ContentRepository;
use IsuDev\WPContentBridge\Domain\Content\ContentDetail;
use IsuDev\WPContentBridge\Domain\Content\ContentQuery;
use IsuDev\WPContentBridge\Domain\Content\ContentSearchResult;
use IsuDev\WPContentBridge\Domain\Content\ContentSummary;
use IsuDev\WPContentBridge\Domain\Mutation\VersionToken;
use WP_Post;
use WP_Query;

/**
 * Reads content through public WordPress APIs and native capability checks.
 */
final class WordPressContentRepository implements ContentRepository {

	private const CANDIDATE_SCAN_LIMIT = 1000;

	/**
	 * Searches readable content.
	 *
	 * @param ContentQuery $query Normalized criteria.
	 * @return ContentSearchResult
	 */
	public function search( ContentQuery $query ): ContentSearchResult {
		$arguments                   = $this->query_arguments( $query );
		$arguments['fields']         = 'ids';
		$arguments['paged']          = 1;
		$arguments['posts_per_page'] = self::CANDIDATE_SCAN_LIMIT + 1;
		$arguments['no_found_rows']  = true;
		$arguments['cache_results']  = false;
		unset( $arguments['perm'] );

		$wp_query      = new WP_Query( $arguments );
		$candidate_ids = array();
		foreach ( $wp_query->posts ?? array() as $candidate_id ) {
			if ( is_int( $candidate_id ) ) {
				$candidate_ids[] = $candidate_id;
			}
		}

		$total_is_exact = count( $candidate_ids ) <= self::CANDIDATE_SCAN_LIMIT;
		$candidate_ids  = array_slice( $candidate_ids, 0, self::CANDIDATE_SCAN_LIMIT );
		$readable_ids   = array_values(
			array_filter(
				$candidate_ids,
				static fn ( int $post_id ): bool => current_user_can( 'read_post', $post_id )
			)
		);
		$total_items    = count( $readable_ids );
		$total_pages    = (int) ceil( $total_items / $query->per_page );
		$offset         = ( $query->page - 1 ) * $query->per_page;
		$page_ids       = array_slice( $readable_ids, $offset, $query->per_page );
		$items          = array();

		foreach ( $page_ids as $post_id ) {
			$post = get_post( $post_id );
			if ( ! $post instanceof WP_Post || ! current_user_can( 'read_post', $post->ID ) ) {
				continue;
			}

			$items[] = $this->summary( $post );
		}

		return new ContentSearchResult(
			$items,
			$query->page,
			$query->per_page,
			$total_items,
			$total_pages,
			$total_is_exact,
			! $total_is_exact || ( $offset + count( $items ) ) < $total_items,
			self::CANDIDATE_SCAN_LIMIT,
		);
	}

	/**
	 * Resolves the type for a content ID.
	 *
	 * @param int $post_id Object ID.
	 * @return string|null
	 */
	public function post_type( int $post_id ): ?string {
		$post = get_post( $post_id );

		return $post instanceof WP_Post ? $post->post_type : null;
	}

	/**
	 * Checks native read permission.
	 *
	 * @param int $post_id Object ID.
	 * @return bool
	 */
	public function can_read( int $post_id ): bool {
		return current_user_can( 'read_post', $post_id );
	}

	/**
	 * Reads one selected content representation.
	 *
	 * @param int   $post_id         Object ID.
	 * @param array $representations Requested forms.
	 * @param array $relationships   Requested relationships.
	 * @return ContentDetail|null
	 * @phpstan-param list<string> $representations
	 * @phpstan-param list<string> $relationships
	 */
	public function get( int $post_id, array $representations, array $relationships ): ?ContentDetail {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return null;
		}

		$selected_representations = array();
		$rendered_content         = null;
		foreach ( $representations as $representation ) {
			if ( in_array( $representation, array( 'rendered', 'plain_text' ), true ) && null === $rendered_content ) {
				$rendered_content = $this->rendered_content( $post );
			}
			$selected_representations[ $representation ] = match ( $representation ) {
				'raw'        => $post->post_content,
				'rendered'   => $rendered_content ?? '',
				'plain_text' => $this->plain_text_content( $rendered_content ?? '' ),
				default      => '',
			};
		}

		$selected_relationships = array();
		foreach ( $relationships as $relationship ) {
			$selected_relationships[ $relationship ] = match ( $relationship ) {
				'author'         => $this->author( $post ),
				'taxonomies'     => $this->taxonomies( $post ),
				'featured_media' => $this->featured_media( $post ),
				'revision'       => $this->revision( $post ),
				default          => null,
			};
		}

		$version_token = VersionToken::for_content(
			$post->post_modified_gmt,
			$post->post_title,
			$post->post_content,
			$post->post_status
		);

		return new ContentDetail(
			$this->summary( $post ),
			$selected_representations,
			$selected_relationships,
			hash( 'sha256', $post->ID . '|' . $post->post_modified_gmt . '|' . $post->post_content ),
			$version_token,
		);
	}

	/**
	 * Maps domain criteria to WP_Query arguments.
	 *
	 * @param ContentQuery $query Normalized criteria.
	 * @return array<string, mixed>
	 */
	private function query_arguments( ContentQuery $query ): array {
		$order_by = match ( $query->order_by ) {
			'date'      => 'date',
			'modified'  => 'modified',
			'title'     => 'title',
			'id'        => 'ID',
			'relevance' => '' === $query->query ? 'date' : 'relevance',
			default     => 'date',
		};

		$arguments = array(
			'post_type'           => $query->post_types,
			'post_status'         => $query->statuses,
			's'                   => $query->query,
			'author__in'          => $query->author_ids,
			'paged'               => $query->page,
			'posts_per_page'      => $query->per_page,
			'orderby'             => $order_by,
			'order'               => strtoupper( $query->order ),
			'ignore_sticky_posts' => true,
			'no_found_rows'       => true,
		);

		$date_query = array();
		if ( null !== $query->published_after || null !== $query->published_before ) {
			$date_query[] = array_filter(
				array(
					'column'    => 'post_date_gmt',
					'after'     => $query->published_after,
					'before'    => $query->published_before,
					'inclusive' => true,
				),
				static fn ( mixed $value ): bool => null !== $value
			);
		}
		if ( null !== $query->modified_after || null !== $query->modified_before ) {
			$date_query[] = array_filter(
				array(
					'column'    => 'post_modified_gmt',
					'after'     => $query->modified_after,
					'before'    => $query->modified_before,
					'inclusive' => true,
				),
				static fn ( mixed $value ): bool => null !== $value
			);
		}
		if ( array() !== $date_query ) {
			$arguments['date_query'] = $date_query;
		}

		if ( array() !== $query->taxonomy_filters ) {
			$tax_query = array( 'relation' => 'AND' );
			foreach ( $query->taxonomy_filters as $filter ) {
				$tax_query[] = array(
					'taxonomy'         => $filter->taxonomy,
					'field'            => 'term_id',
					'terms'            => $filter->term_ids,
					'operator'         => 'IN',
					'include_children' => false,
				);
			}
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- bounded, indexed core taxonomy query built from validated filters.
			$arguments['tax_query'] = $tax_query;
		}

		return $arguments;
	}

	/**
	 * Creates a compact summary.
	 *
	 * @param WP_Post $post Content object.
	 * @return ContentSummary
	 */
	private function summary( WP_Post $post ): ContentSummary {
		$url          = get_permalink( $post );
		$modified_at  = get_post_modified_time( DATE_ATOM, true, $post );
		$published_at = get_post_time( DATE_ATOM, true, $post );
		$featured     = $this->featured_media( $post );

		return new ContentSummary(
			$post->ID,
			$post->post_type,
			$post->post_status,
			get_the_title( $post ),
			$post->post_name,
			$url,
			$this->normalize_text( get_the_excerpt( $post ) ),
			(int) $post->post_author,
			'0000-00-00 00:00:00' === $post->post_date_gmt ? null : self::date_string( $published_at ),
			self::date_string( $modified_at ),
			null !== $featured ? $featured['id'] : null,
			null !== $featured ? $featured['url'] : null,
		);
	}

	/**
	 * Applies the normal WordPress content pipeline.
	 *
	 * @param WP_Post $post Content object.
	 * @return string
	 */
	private function rendered_content( WP_Post $post ): string {
		/** This is the normal WordPress content rendering pipeline. */
		$rendered = apply_filters( 'the_content', $post->post_content );

		return is_string( $rendered ) ? $rendered : '';
	}

	/**
	 * Produces normalized plain text.
	 *
	 * @param string $rendered_content Rendered content.
	 * @return string
	 */
	private function plain_text_content( string $rendered_content ): string {
		return $this->normalize_text( wp_strip_all_tags( $rendered_content, true ) );
	}

	/**
	 * Collapses whitespace in a text field.
	 *
	 * @param string $content Input text.
	 * @return string
	 */
	private function normalize_text( string $content ): string {
		$normalized = preg_replace( '/\s+/u', ' ', $content );

		return trim( is_string( $normalized ) ? $normalized : $content );
	}

	/**
	 * Returns a safe author projection.
	 *
	 * @param WP_Post $post Content object.
	 * @return array{id: int, display_name: string}|null
	 */
	private function author( WP_Post $post ): ?array {
		$author = get_userdata( (int) $post->post_author );

		return false === $author
			? null
			: array(
				'id'           => $author->ID,
				'display_name' => $author->display_name,
			);
	}

	/**
	 * Returns bounded taxonomy relationships.
	 *
	 * @param WP_Post $post Content object.
	 * @return array<string, list<array<string, mixed>>>
	 */
	private function taxonomies( WP_Post $post ): array {
		$result = array();
		foreach ( array_slice( get_object_taxonomies( $post->post_type, 'names' ), 0, 20 ) as $taxonomy ) {
			$terms = wp_get_object_terms( $post->ID, $taxonomy );
			if ( is_wp_error( $terms ) ) {
				continue;
			}

			$result[ $taxonomy ] = array_map(
				static fn ( object $term ): array => array(
					'id'   => (int) $term->term_id,
					'name' => (string) $term->name,
					'slug' => (string) $term->slug,
				),
				array_slice( $terms, 0, 100 )
			);
		}

		return $result;
	}

	/**
	 * Returns a safe featured-media projection.
	 *
	 * @param WP_Post $post Content object.
	 * @return array{id: int, url: string, alt_text: string}|null
	 */
	private function featured_media( WP_Post $post ): ?array {
		$attachment_id = get_post_thumbnail_id( $post );
		if ( false === $attachment_id || $attachment_id < 1 || ! current_user_can( 'read_post', $attachment_id ) ) {
			return null;
		}

		$url = wp_get_attachment_url( $attachment_id );
		if ( ! is_string( $url ) || '' === $url ) {
			return null;
		}
		$alt = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );

		return array(
			'id'       => $attachment_id,
			'url'      => $url,
			'alt_text' => is_string( $alt ) ? $alt : '',
		);
	}

	/**
	 * Returns authoritative version information.
	 *
	 * @param WP_Post $post Content object.
	 * @return array<string, mixed>
	 */
	private function revision( WP_Post $post ): array {
		$revision_id = wp_is_post_revision( $post );
		$modified_at = get_post_modified_time( DATE_ATOM, true, $post );

		return array(
			'id'          => false === $revision_id ? $post->ID : $revision_id,
			'modified_at' => self::date_string( $modified_at ),
		);
	}

	/**
	 * Normalizes WordPress date helper output.
	 *
	 * @param int|string|false $value Date helper output.
	 * @return string
	 */
	private static function date_string( int|string|false $value ): string {
		return is_string( $value ) ? $value : '';
	}
}

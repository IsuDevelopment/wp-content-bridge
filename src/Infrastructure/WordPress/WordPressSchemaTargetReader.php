<?php
/**
 * WordPress-backed schema-target identity reader.
 *
 * @package IsuDev\WPContentBridge
 */

declare( strict_types=1 );

namespace IsuDev\WPContentBridge\Infrastructure\WordPress;

use IsuDev\WPContentBridge\Application\Mutation\SchemaTargetReader;
use IsuDev\WPContentBridge\Domain\Mutation\SchemaTarget;
use WP_Post;

/**
 * Reads identity from the already-cached post row and one thumbnail lookup.
 */
final class WordPressSchemaTargetReader implements SchemaTargetReader {

	/**
	 * Reads the identity projection, or null when the post is absent.
	 *
	 * @param int $post_id Post ID.
	 * @return SchemaTarget|null
	 */
	public function read( int $post_id ): ?SchemaTarget {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return null;
		}

		$url      = get_permalink( $post );
		$featured = FeaturedMediaProjection::for_post( $post );

		return new SchemaTarget(
			get_the_title( $post ),
			$post->post_name,
			is_string( $url ) && '' !== $url ? $url : null,
			$post->post_status,
			'0000-00-00 00:00:00' === $post->post_date_gmt ? null : self::date_string( get_post_time( DATE_ATOM, true, $post ) ),
			self::date_string( get_post_modified_time( DATE_ATOM, true, $post ) ),
			null !== $featured ? $featured['id'] : null,
			null !== $featured ? $featured['url'] : null,
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

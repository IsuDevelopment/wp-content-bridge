<?php
/**
 * WordPress-backed slug writer.
 *
 * @package IsuDev\WPContentBridge
 */

declare( strict_types=1 );

namespace IsuDev\WPContentBridge\Infrastructure\WordPress;

use IsuDev\WPContentBridge\Application\Mutation\MutationWriteFailed;
use IsuDev\WPContentBridge\Application\Mutation\PermalinkRepository;
use IsuDev\WPContentBridge\Application\Mutation\PermalinkUnavailable;
use IsuDev\WPContentBridge\Domain\Mutation\PermalinkUpdate;
use WP_Post;

/**
 * The only place `post_name` is written, and the write is always read back.
 */
final class WordPressPermalinkRepository implements PermalinkRepository {

	/**
	 * Current slug and permalink, or null when the post is absent.
	 *
	 * @param int $post_id Post ID.
	 * @return array{slug: string, url: string}|null
	 */
	public function current( int $post_id ): ?array {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return null;
		}

		return $this->projection( $post );
	}

	/**
	 * Applies the slug and confirms WordPress stored exactly it.
	 *
	 * @param PermalinkUpdate $update Validated update.
	 * @param string          $slug   Normalized slug to store.
	 * @return array{slug: string, url: string}
	 * @throws MutationWriteFailed When WordPress rejects the write or the post vanishes.
	 * @throws PermalinkUnavailable When the requested slug is already taken.
	 */
	public function apply( PermalinkUpdate $update, string $slug ): array {
		$post = get_post( $update->post_id );
		if ( ! $post instanceof WP_Post ) {
			throw new MutationWriteFailed( 'The target post could not be read.' );
		}

		/*
		 * Asked before writing, because wp_update_post() would uniquify a
		 * collision to "slug-2" and report success. Answering "unavailable" is
		 * the honest result; storing a URL the caller never requested is not.
		 * Passing the post's own ID excludes itself, so re-submitting the
		 * current slug is not reported as a collision.
		 */
		$unique = wp_unique_post_slug(
			$slug,
			$update->post_id,
			$post->post_status,
			$post->post_type,
			(int) $post->post_parent
		);
		if ( $unique !== $slug ) {
			throw new PermalinkUnavailable( 'That slug is already in use for this content type.' );
		}

		$result = wp_update_post(
			array(
				'ID'        => $update->post_id,
				'post_name' => $slug,
			),
			true
		);
		if ( is_wp_error( $result ) || 0 === $result ) {
			throw new MutationWriteFailed( 'WordPress rejected the permalink write.' );
		}

		clean_post_cache( $update->post_id );
		$updated = get_post( $update->post_id );
		if ( ! $updated instanceof WP_Post || $updated->post_name !== $slug ) {
			throw new MutationWriteFailed( 'The permalink was not stored as requested.' );
		}

		return $this->projection( $updated );
	}

	/**
	 * Projects one post's slug and permalink.
	 *
	 * @param WP_Post $post Content object.
	 * @return array{slug: string, url: string}
	 */
	private function projection( WP_Post $post ): array {
		$url = get_permalink( $post );

		return array(
			'slug' => $post->post_name,
			'url'  => is_string( $url ) ? $url : '',
		);
	}
}

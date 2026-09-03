<?php
/**
 * Permalink (slug) write port.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Application\Mutation;

use IsuDev\WPContentBridge\Domain\Mutation\PermalinkUpdate;

/**
 * Reads and writes one post's slug, and reports the URLs on both sides.
 */
interface PermalinkRepository {

	/**
	 * Current slug and permalink, or null when the post is absent.
	 *
	 * @param int $post_id Post ID.
	 * @return array{slug: string, url: string}|null
	 */
	public function current( int $post_id ): ?array;

	/**
	 * Applies the slug and confirms WordPress stored exactly it.
	 *
	 * WordPress silently uniquifies a colliding slug, so an implementation must
	 * read back and refuse a value it did not request rather than report success
	 * for a URL the caller never asked for.
	 *
	 * @param PermalinkUpdate $update Validated update.
	 * @param string          $slug   Normalized slug to store.
	 * @return array{slug: string, url: string}
	 * @throws MutationWriteFailed When WordPress rejects the write.
	 * @throws PermalinkUnavailable When the requested slug is already taken.
	 */
	public function apply( PermalinkUpdate $update, string $slug ): array;
}

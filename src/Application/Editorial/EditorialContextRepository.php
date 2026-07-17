<?php
/**
 * Editorial-context WordPress metadata port.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Application\Editorial;

/**
 * Supplies bounded taxonomy vocabulary and public author labels.
 */
interface EditorialContextRepository {

	/**
	 * Lists eligible taxonomy definitions and optional bounded terms.
	 *
	 * @param array $post_types      Effective content types.
	 * @param array $requested       Optional taxonomy filter.
	 * @param bool  $include_terms   Whether term vocabulary is needed.
	 * @param int   $terms_per_taxonomy Maximum terms per taxonomy.
	 * @return list<array<string, mixed>>
	 * @phpstan-param non-empty-list<string> $post_types
	 * @phpstan-param list<string> $requested
	 */
	public function taxonomies( array $post_types, array $requested, bool $include_terms, int $terms_per_taxonomy ): array;

	/**
	 * Resolves display labels only for authors already observed in readable content.
	 *
	 * @param array $author_ids Observed author IDs.
	 * @return list<array{id: int, display_name: string}>
	 * @phpstan-param list<int> $author_ids
	 */
	public function authors( array $author_ids ): array;
}

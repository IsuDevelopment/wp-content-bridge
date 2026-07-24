<?php
/**
 * Port for writing the SEO write allowlist.
 *
 * @package IsuDev\WPContentBridge
 */

declare( strict_types=1 );

namespace IsuDev\WPContentBridge\Application\Mutation;

/**
 * Writes the allowlisted SEO fields for one post and returns the re-read
 * effective SEO document. The only implementation calls an SEO plugin.
 */
interface SeoWriter {

	/**
	 * Whether a compatible SEO write surface is currently available.
	 *
	 * @return bool
	 */
	public function is_available(): bool;

	/**
	 * Writes the given allowlisted fields and re-reads effective SEO.
	 *
	 * @param int   $post_id Target post ID.
	 * @param array $fields  Present allowlisted field name to value.
	 * @phpstan-param array<string, string|int|bool|list<string>> $fields
	 * @return array<string, mixed> Re-read normalized SEO document.
	 * @throws SeoFieldUnsupported When no compatible writer is available.
	 * @throws MutationWriteFailed When the underlying write fails.
	 */
	public function write( int $post_id, array $fields ): array;
}

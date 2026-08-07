<?php
/**
 * Port for reading and previewing the SEO write allowlist without writing.
 *
 * @package IsuDev\WPContentBridge
 */

declare( strict_types=1 );

namespace IsuDev\WPContentBridge\Application\Mutation;

/**
 * Read-only companion to SeoWriter. Never writes metadata.
 */
interface SeoPreviewProvider {

	/**
	 * Whether a compatible SEO write surface is currently available.
	 *
	 * @return bool
	 */
	public function is_available(): bool;

	/**
	 * Reads the current full resolved SEO document (already public).
	 *
	 * @param int $post_id Target post ID.
	 * @return array<string, mixed>
	 */
	public function current( int $post_id ): array;

	/**
	 * Normalizes the given allowlisted fields exactly as a write would,
	 * without writing metadata. Returns only the requested fields.
	 *
	 * @param int   $post_id Target post ID.
	 * @param array $fields  Present allowlisted field name to value.
	 * @phpstan-param array<string, string|int|bool|list<string>> $fields
	 * @return array<string, mixed>
	 * @throws SeoFieldUnsupported When no compatible provider is available.
	 * @throws SeoImageUnavailable When a requested social image attachment is invalid.
	 */
	public function preview( int $post_id, array $fields ): array;
}

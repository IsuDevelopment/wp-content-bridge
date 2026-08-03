<?php
/**
 * Port for writing a structured Service entity.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Application\Mutation;

/**
 * Provider-neutral write and re-read contract for Service schema metadata.
 */
interface ServiceSchemaWriter {

	/**
	 * Whether a compatible provider is currently loaded.
	 */
	public function is_available(): bool;

	/**
	 * Whether the provider supports Service entities on this post type.
	 *
	 * @param string $post_type WordPress post type.
	 */
	public function supports_post_type( string $post_type ): bool;

	/**
	 * Writes fixed Service fields and returns their sanitized effective values.
	 *
	 * @param int                  $post_id Target post ID.
	 * @param array<string, mixed> $fields  Provider-neutral field allowlist.
	 * @return array<string, mixed>
	 * @throws ServiceSchemaUnavailable When the provider is unavailable or incompatible.
	 * @throws MutationWriteFailed When WordPress rejects a metadata write.
	 */
	public function write( int $post_id, array $fields ): array;
}

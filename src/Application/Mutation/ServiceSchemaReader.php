<?php
/**
 * Port for reading and previewing a structured Service entity.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Application\Mutation;

/**
 * Provider-neutral read contract for Service schema metadata.
 */
interface ServiceSchemaReader {

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
	 * Reads the provider-sanitized effective configuration.
	 *
	 * @param int $post_id Target post ID.
	 * @return array<string, mixed>
	 * @throws ServiceSchemaUnavailable When the provider is unavailable or incompatible.
	 */
	public function read( int $post_id ): array;

	/**
	 * Returns the provider-sanitized prospective configuration without writing.
	 *
	 * @param int                  $post_id Target post ID.
	 * @param array<string, mixed> $fields  Provider-neutral field allowlist.
	 * @return array<string, mixed>
	 * @throws ServiceSchemaUnavailable When the provider is unavailable or incompatible.
	 */
	public function preview( int $post_id, array $fields ): array;
}

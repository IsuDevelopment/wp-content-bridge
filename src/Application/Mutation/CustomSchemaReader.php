<?php
/**
 * Port for reading and previewing Custom Schema configuration.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Application\Mutation;

/**
 * Provider-neutral read contract for bounded Custom Schema JSON.
 */
interface CustomSchemaReader {

	/**
	 * Whether a compatible provider is currently loaded.
	 */
	public function is_available(): bool;

	/**
	 * Reads the provider-sanitized effective configuration.
	 *
	 * @param int $post_id Target post ID.
	 * @return array<string, mixed>
	 * @throws CustomSchemaUnavailable When the provider cannot handle the target.
	 */
	public function read( int $post_id ): array;

	/**
	 * Returns the validated prospective configuration without writing.
	 *
	 * @param int                        $post_id Target post ID.
	 * @param array<string, bool|string> $fields Provider-neutral field allowlist.
	 * @return array<string, mixed>
	 * @throws CustomSchemaUnavailable When the provider cannot handle the target.
	 */
	public function preview( int $post_id, array $fields ): array;
}

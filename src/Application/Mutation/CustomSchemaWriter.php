<?php
/**
 * Port for writing Custom Schema configuration.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Application\Mutation;

/**
 * Provider-neutral write and re-read contract for Custom Schema JSON.
 */
interface CustomSchemaWriter {

	/**
	 * Whether a compatible provider is currently loaded.
	 */
	public function is_available(): bool;

	/**
	 * Writes fixed Custom Schema fields and returns effective values.
	 *
	 * @param int                        $post_id Target post ID.
	 * @param array<string, bool|string> $fields Provider-neutral field allowlist.
	 * @return array<string, mixed>
	 * @throws CustomSchemaInvalid When enabled JSON fails validation.
	 * @throws CustomSchemaUnavailable When the provider cannot handle the target.
	 * @throws MutationWriteFailed When the provider rejects or cannot verify the write.
	 */
	public function write( int $post_id, array $fields ): array;
}

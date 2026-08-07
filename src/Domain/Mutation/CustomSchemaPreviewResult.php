<?php
/**
 * Result of previewing one Custom Schema update.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Domain\Mutation;

/**
 * Carries current and prospective configurations without a mutation.
 */
final readonly class CustomSchemaPreviewResult {

	/**
	 * Creates the result.
	 *
	 * @param int                  $post_id              Target post ID.
	 * @param string               $post_type            Target post type.
	 * @param VersionToken         $version              Current optimistic-concurrency token.
	 * @param array                $changed_fields       Fields included in the preview request.
	 * @param array<string, mixed> $current_custom_schema Current provider configuration.
	 * @param array<string, mixed> $preview_custom_schema Prospective provider configuration.
	 * @phpstan-param list<string> $changed_fields
	 */
	public function __construct(
		public int $post_id,
		public string $post_type,
		public VersionToken $version,
		public array $changed_fields,
		public array $current_custom_schema,
		public array $preview_custom_schema,
	) {}

	/**
	 * Returns the public wire document.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'schema_version'        => '1.0',
			'dry_run'               => true,
			'writes_performed'      => false,
			'post_id'               => $this->post_id,
			'post_type'             => $this->post_type,
			'version_token'         => $this->version->to_string(),
			'changed_fields'        => $this->changed_fields,
			'current_custom_schema' => $this->current_custom_schema,
			'preview_custom_schema' => $this->preview_custom_schema,
			'provenance'            => array(
				'source'    => 'wordpress-custom-schema',
				'untrusted' => true,
			),
		);
	}
}

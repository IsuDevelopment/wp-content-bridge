<?php
/**
 * Result of previewing one Service schema update.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Domain\Mutation;

/**
 * Carries current and prospective configurations without a mutation.
 */
final readonly class ServiceSchemaPreviewResult {

	/**
	 * Creates the result.
	 *
	 * @param int                  $post_id               Target post ID.
	 * @param string               $post_type             Target post type.
	 * @param VersionToken         $version               Current optimistic-concurrency token.
	 * @param array                $changed_fields         Fields included in the preview request.
	 * @param array<string, mixed> $current_service_schema Current provider configuration.
	 * @param array<string, mixed> $preview_service_schema Prospective provider configuration.
	 * @phpstan-param list<string> $changed_fields
	 */
	public function __construct(
		public int $post_id,
		public string $post_type,
		public VersionToken $version,
		public array $changed_fields,
		public array $current_service_schema,
		public array $preview_service_schema,
	) {}

	/**
	 * Returns the public wire document.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'schema_version'         => '1.0',
			'dry_run'                => true,
			'post_id'                => $this->post_id,
			'post_type'              => $this->post_type,
			'version_token'          => $this->version->to_string(),
			'changed_fields'         => $this->changed_fields,
			'current_service_schema' => $this->current_service_schema,
			'preview_service_schema' => $this->preview_service_schema,
			'provenance'             => array(
				'source'    => 'wordpress-service-schema',
				'untrusted' => true,
			),
		);
	}
}

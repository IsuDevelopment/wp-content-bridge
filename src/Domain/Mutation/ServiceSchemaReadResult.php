<?php
/**
 * Result of reading one Service schema configuration.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Domain\Mutation;

/**
 * Carries target identity, concurrency state, and effective configuration.
 */
final readonly class ServiceSchemaReadResult {

	/**
	 * Creates the result.
	 *
	 * @param int                  $post_id        Target post ID.
	 * @param string               $post_type      Target post type.
	 * @param VersionToken         $version         Current optimistic-concurrency token.
	 * @param array<string, mixed> $service_schema Provider-sanitized configuration.
	 */
	public function __construct(
		public int $post_id,
		public string $post_type,
		public VersionToken $version,
		public array $service_schema,
	) {}

	/**
	 * Returns the public wire document.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'schema_version' => '1.0',
			'post_id'        => $this->post_id,
			'post_type'      => $this->post_type,
			'version_token'  => $this->version->to_string(),
			'service_schema' => $this->service_schema,
			'provenance'     => array(
				'source'    => 'wordpress-service-schema',
				'untrusted' => true,
			),
		);
	}
}

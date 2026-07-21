<?php
/**
 * Outcome of a successful content mutation.
 *
 * @package IsuDev\WPContentBridge
 */

declare( strict_types=1 );

namespace IsuDev\WPContentBridge\Domain\Mutation;

/**
 * Immutable result returned by write use cases to the adapter.
 */
final readonly class MutationResult {

	/**
	 * Creates a mutation result.
	 *
	 * @param int                $post_id        The post ID.
	 * @param string             $post_type      The post type.
	 * @param string             $status         The post status.
	 * @param VersionToken       $version        The version token.
	 * @param array<int, string> $changed_fields Field names that changed (never values).
	 * @param bool               $created        Whether the post was created.
	 */
	public function __construct(
		public int $post_id,
		public string $post_type,
		public string $status,
		public VersionToken $version,
		public array $changed_fields,
		public bool $created,
	) {}

	/**
	 * Wire representation.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'schema_version' => '1.0',
			'post_id'        => $this->post_id,
			'post_type'      => $this->post_type,
			'status'         => $this->status,
			'version_token'  => $this->version->to_string(),
			'changed_fields' => array_values( $this->changed_fields ),
			'created'        => $this->created,
			'provenance'     => array(
				'source'    => 'wordpress',
				'untrusted' => true,
			),
		);
	}
}

<?php
/**
 * Result of previewing one block-subtree update.
 *
 * @package IsuDev\WPContentBridge
 */

declare( strict_types=1 );

namespace IsuDev\WPContentBridge\Domain\Mutation;

/**
 * Carries the whole current and prospective `post_content` without a
 * mutation. Unlike ContentPreviewResult, this is scoped to the single field a
 * block-level edit can ever change.
 */
final readonly class BlockUpdatePreviewResult {

	/**
	 * Creates the result.
	 *
	 * @param int          $post_id         Target post ID.
	 * @param string       $post_type       Target post type.
	 * @param VersionToken $version         Current optimistic-concurrency token.
	 * @param string       $current_content Current whole post_content.
	 * @param string       $preview_content Prospective whole post_content after the splice/serialize round trip.
	 */
	public function __construct(
		public int $post_id,
		public string $post_type,
		public VersionToken $version,
		public string $current_content,
		public string $preview_content,
	) {}

	/**
	 * Returns the public wire document.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'schema_version'   => '1.0',
			'writes_performed' => false,
			'post_id'          => $this->post_id,
			'post_type'        => $this->post_type,
			'version_token'    => $this->version->to_string(),
			'changed_fields'   => array( 'content' ),
			'current_content'  => $this->current_content,
			'preview_content'  => $this->preview_content,
			'provenance'       => array(
				'source'    => 'wordpress',
				'untrusted' => true,
			),
		);
	}
}

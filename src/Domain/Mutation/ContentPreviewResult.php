<?php
/**
 * Result of previewing one content update.
 *
 * @package IsuDev\WPContentBridge
 */

declare( strict_types=1 );

namespace IsuDev\WPContentBridge\Domain\Mutation;

/**
 * Carries current and prospective content field values without a mutation.
 */
final readonly class ContentPreviewResult {

	/**
	 * Creates the result.
	 *
	 * @param int          $post_id            Target post ID.
	 * @param string       $post_type          Target post type.
	 * @param VersionToken $version            Current optimistic-concurrency token.
	 * @param array        $changed_fields     Fields included in the preview request.
	 * @param array        $current_content    Current title/block_markup/excerpt.
	 * @param array        $preview_content    Prospective title/block_markup/excerpt.
	 * @param array        $preview_taxonomies Prospective taxonomy assignments, empty when untouched.
	 * @param array        $warnings           Bounded machine-readable warnings.
	 * @phpstan-param list<string> $changed_fields
	 * @phpstan-param array{title: string, block_markup: string, excerpt: string} $current_content
	 * @phpstan-param array{title: string, block_markup: string, excerpt: string} $preview_content
	 * @phpstan-param list<array{taxonomy: string, term_ids: list<int>}> $preview_taxonomies
	 * @phpstan-param list<array{code: string, field: string, message: string}> $warnings
	 */
	public function __construct(
		public int $post_id,
		public string $post_type,
		public VersionToken $version,
		public array $changed_fields,
		public array $current_content,
		public array $preview_content,
		public array $preview_taxonomies,
		public array $warnings,
	) {}

	/**
	 * Returns the public wire document.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'schema_version'     => '1.0',
			'writes_performed'   => false,
			'post_id'            => $this->post_id,
			'post_type'          => $this->post_type,
			'version_token'      => $this->version->to_string(),
			'changed_fields'     => $this->changed_fields,
			'current_content'    => $this->current_content,
			'preview_content'    => $this->preview_content,
			'preview_taxonomies' => $this->preview_taxonomies,
			'warnings'           => $this->warnings,
			'provenance'         => array(
				'source'    => 'wordpress',
				'untrusted' => true,
			),
		);
	}
}

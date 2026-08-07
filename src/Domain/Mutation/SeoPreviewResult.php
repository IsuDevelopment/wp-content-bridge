<?php
/**
 * Result of previewing one SEO update.
 *
 * @package IsuDev\WPContentBridge
 */

declare( strict_types=1 );

namespace IsuDev\WPContentBridge\Domain\Mutation;

/**
 * Carries the current resolved SEO document and prospective configured field
 * values without a mutation.
 */
final readonly class SeoPreviewResult {

	/**
	 * Creates the result.
	 *
	 * @param int          $post_id        Target post ID.
	 * @param string       $post_type      Target post type.
	 * @param VersionToken $version        Current optimistic-concurrency token.
	 * @param array        $changed_fields Fields included in the preview request.
	 * @param array        $current_seo    Current full resolved SEO document.
	 * @param array        $preview_seo    Prospective configured field values only.
	 * @param array        $warnings       Bounded machine-readable warnings.
	 * @phpstan-param list<string> $changed_fields
	 * @phpstan-param array<string, mixed> $current_seo
	 * @phpstan-param array<string, mixed> $preview_seo
	 * @phpstan-param list<array{code: string, field: string, message: string}> $warnings
	 */
	public function __construct(
		public int $post_id,
		public string $post_type,
		public VersionToken $version,
		public array $changed_fields,
		public array $current_seo,
		public array $preview_seo,
		public array $warnings,
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
			'changed_fields'   => $this->changed_fields,
			'current_seo'      => $this->current_seo,
			'preview_seo'      => $this->preview_seo,
			'warnings'         => $this->warnings,
			'provenance'       => array(
				'source'    => 'wordpress-seo',
				'untrusted' => true,
			),
		);
	}
}

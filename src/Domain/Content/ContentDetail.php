<?php
/**
 * Detailed content result.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Domain\Content;

use IsuDev\WPContentBridge\Domain\Mutation\VersionToken;

/**
 * A selected, bounded representation of one WordPress content object.
 */
final readonly class ContentDetail {

	/**
	 * Creates a detailed content result.
	 *
	 * @param ContentSummary    $summary           Compact content data.
	 * @param array             $representations   Selected content forms.
	 * @param array             $relationships     Selected relationships.
	 * @param string|null       $concurrency_token Version token.
	 * @param VersionToken|null $version_token     Optimistic-concurrency token for update-content.
	 * @phpstan-param array<string, string> $representations
	 * @phpstan-param array<string, mixed> $relationships
	 */
	public function __construct(
		public ContentSummary $summary,
		public array $representations,
		public array $relationships,
		public ?string $concurrency_token,
		public ?VersionToken $version_token = null,
	) {
	}

	/**
	 * Serializes the result.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'schema_version'    => '1.0',
			'content'           => $this->summary->to_array(),
			'representations'   => $this->representations,
			'relationships'     => $this->relationships,
			'concurrency_token' => $this->concurrency_token,
			'version_token'     => $this->version_token?->to_string(),
			'payload'           => array(
				'representation_bytes'       => $this->representation_bytes(),
				'total_representation_bytes' => $this->total_representation_bytes(),
			),
			'provenance'        => array(
				'source'    => 'wordpress',
				'untrusted' => true,
			),
			'warnings'          => array(),
		);
	}

	/**
	 * Returns byte size for every selected representation.
	 *
	 * @return array<string, int>
	 */
	public function representation_bytes(): array {
		return array_map( 'strlen', $this->representations );
	}

	/**
	 * Returns the combined selected-representation size.
	 *
	 * @return int
	 */
	public function total_representation_bytes(): int {
		return array_sum( $this->representation_bytes() );
	}
}

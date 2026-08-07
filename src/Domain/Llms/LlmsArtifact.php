<?php
/**
 * Generated llms.txt snapshot.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Domain\Llms;

/**
 * One immutable, generated llms.txt document plus the metadata a caller needs
 * without re-reading the whole body: a content hash for cheap comparison and
 * `ETag` derivation, byte and link counts, and any bound-truncation warnings
 * {@see LlmsDocumentBuilder} recorded while producing it.
 */
final readonly class LlmsArtifact {

	/**
	 * Creates a snapshot.
	 *
	 * @param string $content      Generated Markdown document.
	 * @param string $content_hash Lowercase hex SHA-256 digest of `content`.
	 * @param string $generated_at Generation time, `Y-m-d\TH:i:s\Z` (UTC).
	 * @param int    $byte_count   Byte length of `content`.
	 * @param int    $link_count   Number of Markdown links `content` contains.
	 * @param array  $warnings     Bounded, human-readable truncation warnings.
	 * @phpstan-param list<string> $warnings
	 */
	public function __construct(
		public string $content,
		public string $content_hash,
		public string $generated_at,
		public int $byte_count,
		public int $link_count,
		public array $warnings,
	) {
	}

	/**
	 * Serializes the public wire document.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'content'      => $this->content,
			'content_hash' => $this->content_hash,
			'generated_at' => $this->generated_at,
			'byte_count'   => $this->byte_count,
			'link_count'   => $this->link_count,
			'warnings'     => $this->warnings,
		);
	}

	/**
	 * Serializes an artifact summary: every field except the document body
	 * itself. Abilities that report on the current or a prospective snapshot
	 * do not necessarily need to return the whole document, so this omits
	 * `content` while keeping `content_hash` — the same hash the virtual
	 * endpoint's `ETag` will be derived from — for correlation.
	 *
	 * @return array<string, mixed>
	 */
	public function to_summary_array(): array {
		return array(
			'content_hash' => $this->content_hash,
			'generated_at' => $this->generated_at,
			'byte_count'   => $this->byte_count,
			'link_count'   => $this->link_count,
			'warnings'     => $this->warnings,
		);
	}
}

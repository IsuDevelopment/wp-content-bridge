<?php
/**
 * Paginated block-pattern result.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Domain\Pattern;

/**
 * Immutable object envelope for pattern discovery.
 */
final readonly class PatternSearchResult {

	/**
	 * Creates one bounded result page.
	 *
	 * @param array $items                Pattern items.
	 * @param int   $page                 Current page.
	 * @param int   $per_page             Page size.
	 * @param int   $total_items          Matching inspected items.
	 * @param int   $total_pages          Matching inspected pages.
	 * @param bool  $total_is_exact       Whether every candidate was inspected.
	 * @param bool  $has_more             Whether further results may exist.
	 * @param int   $candidate_scan_limit Candidate scan bound.
	 * @param int   $content_limit_bytes  Combined response content bound.
	 * @phpstan-param list<BlockPatternItem> $items
	 */
	public function __construct(
		public array $items,
		public int $page,
		public int $per_page,
		public int $total_items,
		public int $total_pages,
		public bool $total_is_exact,
		public bool $has_more,
		public int $candidate_scan_limit,
		public int $content_limit_bytes,
	) {
	}

	/**
	 * Serializes the stable output envelope.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'schema_version' => '1.0',
			'items'          => array_map(
				static fn ( BlockPatternItem $item ): array => $item->to_array(),
				$this->items
			),
			'pagination'     => array(
				'page'                 => $this->page,
				'per_page'             => $this->per_page,
				'total_items'          => $this->total_items,
				'total_pages'          => $this->total_pages,
				'total_is_exact'       => $this->total_is_exact,
				'has_more'             => $this->has_more,
				'candidate_scan_limit' => $this->candidate_scan_limit,
			),
			'limits'         => array(
				'content_response_bytes' => $this->content_limit_bytes,
			),
			'provenance'     => array(
				'source'    => 'wordpress',
				'untrusted' => true,
			),
		);
	}
}

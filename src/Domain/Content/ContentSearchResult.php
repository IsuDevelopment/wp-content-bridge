<?php
/**
 * Paginated content search result.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Domain\Content;

/**
 * Immutable result page.
 */
final readonly class ContentSearchResult {

	/**
	 * Creates a result page.
	 *
	 * @param array $items       Result items.
	 * @param int   $page        Current page.
	 * @param int   $per_page    Page size.
	 * @param int   $total_items Total result count.
	 * @param int   $total_pages Total page count.
	 * @param bool  $total_is_exact Whether totals cover every candidate.
	 * @param bool  $has_more       Whether more readable results may exist.
	 * @param int   $candidate_scan_limit Maximum candidates inspected.
	 * @phpstan-param list<ContentSummary> $items
	 */
	public function __construct(
		public array $items,
		public int $page,
		public int $per_page,
		public int $total_items,
		public int $total_pages,
		public bool $total_is_exact = true,
		public bool $has_more = false,
		public int $candidate_scan_limit = 1000,
	) {
	}

	/**
	 * Serializes the page.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'schema_version' => '1.0',
			'items'          => array_map(
				static fn ( ContentSummary $item ): array => $item->to_array(),
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
			'provenance'     => array(
				'source'    => 'wordpress',
				'untrusted' => true,
			),
		);
	}
}

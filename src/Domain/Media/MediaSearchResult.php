<?php
/**
 * Paginated media result.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Domain\Media;

/**
 * Immutable media result envelope.
 */
final readonly class MediaSearchResult {

	/**
	 * Creates a media result page.
	 *
	 * @param array $items                Media items.
	 * @param int   $page                 Current page.
	 * @param int   $per_page             Page size.
	 * @param int   $total_items          Authorized item count.
	 * @param int   $total_pages          Page count.
	 * @param bool  $total_is_exact       Whether all candidates were inspected.
	 * @param bool  $has_more             Whether more results may exist.
	 * @param int   $candidate_scan_limit Candidate bound.
	 * @phpstan-param list<MediaItem> $items
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
	) {
	}

	/**
	 * Serializes the object envelope.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'schema_version' => '1.0',
			'items'          => array_map(
				static fn ( MediaItem $item ): array => $item->to_array(),
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

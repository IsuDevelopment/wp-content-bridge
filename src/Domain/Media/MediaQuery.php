<?php
/**
 * Media search criteria.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Domain\Media;

use InvalidArgumentException;

/**
 * Immutable, bounded media search criteria.
 */
final readonly class MediaQuery {

	/**
	 * Creates normalized search criteria.
	 *
	 * @param int|null    $id       Exact attachment ID.
	 * @param string|null $url      Exact attachment URL.
	 * @param string|null $filename Exact attachment filename.
	 * @param string      $query    WordPress text query.
	 * @param int         $page     One-based page.
	 * @param int         $per_page Page size.
	 */
	public function __construct(
		public ?int $id,
		public ?string $url,
		public ?string $filename,
		public string $query,
		public int $page,
		public int $per_page,
	) {
	}

	/**
	 * Builds validated criteria from adapter input.
	 *
	 * @param array<string, mixed> $input Untrusted input.
	 * @return self
	 * @throws InvalidArgumentException When input is ambiguous or out of bounds.
	 */
	public static function from_input( array $input ): self {
		$id       = $input['id'] ?? null;
		$url      = $input['url'] ?? null;
		$filename = $input['filename'] ?? null;
		$query    = $input['query'] ?? '';
		$page     = $input['page'] ?? 1;
		$per_page = $input['per_page'] ?? 20;

		if ( null !== $id && ( ! is_int( $id ) || 1 > $id ) ) {
			throw new InvalidArgumentException( 'id must be a positive integer.' );
		}
		if ( null !== $url && ( ! is_string( $url ) || 2048 < strlen( $url ) || false === filter_var( $url, FILTER_VALIDATE_URL ) ) ) {
			throw new InvalidArgumentException( 'url must be a valid absolute URL.' );
		}
		if ( null !== $filename && ( ! is_string( $filename ) || '' === trim( $filename ) || 255 < strlen( $filename ) || basename( $filename ) !== $filename || str_contains( $filename, '\\' ) ) ) {
			throw new InvalidArgumentException( 'filename must be one basename without a path.' );
		}
		if ( ! is_string( $query ) || 500 < strlen( $query ) ) {
			throw new InvalidArgumentException( 'query must be a string of at most 500 bytes.' );
		}
		if ( ! is_int( $page ) || 1 > $page || ! is_int( $per_page ) || 1 > $per_page || 100 < $per_page ) {
			throw new InvalidArgumentException( 'Media pagination is invalid.' );
		}

		$selectors = array_filter(
			array(
				'id'       => $id,
				'url'      => $url,
				'filename' => $filename,
				'query'    => '' !== trim( $query ) ? $query : null,
			),
			static fn ( mixed $value ): bool => null !== $value
		);
		if ( 1 < count( $selectors ) ) {
			throw new InvalidArgumentException( 'Use at most one of id, url, filename, or query.' );
		}

		return new self(
			$id,
			null !== $url ? trim( $url ) : null,
			null !== $filename ? trim( $filename ) : null,
			trim( $query ),
			$page,
			$per_page,
		);
	}
}

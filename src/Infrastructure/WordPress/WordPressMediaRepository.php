<?php
/**
 * WordPress media read adapter.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Infrastructure\WordPress;

use InvalidArgumentException;
use IsuDev\WPContentBridge\Application\Media\MediaRepository;
use IsuDev\WPContentBridge\Domain\Media\MediaItem;
use IsuDev\WPContentBridge\Domain\Media\MediaQuery;
use IsuDev\WPContentBridge\Domain\Media\MediaSearchResult;
use WP_Post;
use WP_Query;

/**
 * Reads bounded attachment projections through public WordPress APIs.
 */
final class WordPressMediaRepository implements MediaRepository {

	private const CANDIDATE_SCAN_LIMIT = 1000;

	/**
	 * Searches attachments and applies native authorization before pagination.
	 *
	 * @param MediaQuery $query Search criteria.
	 * @return MediaSearchResult
	 */
	public function search( MediaQuery $query ): MediaSearchResult {
		$candidates     = $this->candidate_ids( $query );
		$total_is_exact = count( $candidates ) <= self::CANDIDATE_SCAN_LIMIT;
		$candidates     = array_slice( $candidates, 0, self::CANDIDATE_SCAN_LIMIT );
		$readable       = array();

		foreach ( $candidates as $attachment_id ) {
			if ( null !== $query->filename && $query->filename !== $this->filename( $attachment_id ) ) {
				continue;
			}
			if ( ! $this->can_read( $attachment_id ) ) {
				continue;
			}

			$item = $this->get( $attachment_id );
			if ( null !== $item ) {
				$readable[] = $item;
			}
		}

		$total_items = count( $readable );
		$total_pages = (int) ceil( $total_items / $query->per_page );
		$offset      = ( $query->page - 1 ) * $query->per_page;
		$items       = array_slice( $readable, $offset, $query->per_page );

		return new MediaSearchResult(
			$items,
			$query->page,
			$query->per_page,
			$total_items,
			$total_pages,
			$total_is_exact,
			! $total_is_exact || ( $offset + count( $items ) ) < $total_items,
			self::CANDIDATE_SCAN_LIMIT,
		);
	}

	/**
	 * Checks attachment identity and native object permission.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	public function can_read( int $attachment_id ): bool {
		$post = get_post( $attachment_id );

		return $post instanceof WP_Post
			&& 'attachment' === $post->post_type
			&& current_user_can( 'read_post', $attachment_id );
	}

	/**
	 * Reads one explicit attachment projection.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return MediaItem|null
	 */
	public function get( int $attachment_id ): ?MediaItem {
		if ( ! $this->can_read( $attachment_id ) ) {
			return null;
		}

		$post = get_post( $attachment_id );
		if ( ! $post instanceof WP_Post || 'attachment' !== $post->post_type ) {
			return null;
		}

		$url = wp_get_attachment_url( $attachment_id );
		if ( ! is_string( $url ) || '' === $url ) {
			return null;
		}

		$alt = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );

		return new MediaItem(
			$attachment_id,
			get_the_title( $post ),
			$this->filename( $attachment_id ),
			$url,
			is_string( $alt ) ? $alt : '',
			$post->post_excerpt,
			$post->post_content,
			'' !== $post->post_mime_type ? $post->post_mime_type : 'application/octet-stream',
		);
	}

	/**
	 * Returns bounded raw candidate IDs for one selector.
	 *
	 * @param MediaQuery $query Search criteria.
	 * @return list<int>
	 */
	private function candidate_ids( MediaQuery $query ): array {
		if ( null !== $query->id ) {
			return array( $query->id );
		}
		if ( null !== $query->url ) {
			$this->assert_same_site_url( $query->url );
			$attachment_id = attachment_url_to_postid( $query->url );
			$actual_url    = 0 < $attachment_id ? wp_get_attachment_url( $attachment_id ) : false;

			return is_string( $actual_url ) && esc_url_raw( $actual_url ) === esc_url_raw( $query->url )
				? array( $attachment_id )
				: array();
		}

		$arguments = array(
			'post_type'              => 'attachment',
			'post_status'            => array( 'inherit', 'private' ),
			'fields'                 => 'ids',
			'posts_per_page'         => self::CANDIDATE_SCAN_LIMIT + 1,
			'paged'                  => 1,
			'orderby'                => 'date',
			'order'                  => 'DESC',
			'no_found_rows'          => true,
			'cache_results'          => false,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		);
		if ( '' !== $query->query ) {
			$arguments['s'] = $query->query;
		}

		$wp_query = new WP_Query( $arguments );
		$result   = array();
		foreach ( $wp_query->posts ?? array() as $candidate_id ) {
			if ( is_int( $candidate_id ) ) {
				$result[] = $candidate_id;
			}
		}

		return $result;
	}

	/**
	 * Returns a basename without exposing the attachment filesystem path.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string
	 */
	private function filename( int $attachment_id ): string {
		$file = get_attached_file( $attachment_id );
		if ( is_string( $file ) && '' !== $file ) {
			return wp_basename( $file );
		}

		$url  = wp_get_attachment_url( $attachment_id );
		$path = is_string( $url ) ? wp_parse_url( $url, PHP_URL_PATH ) : null;

		return is_string( $path ) ? wp_basename( $path ) : 'attachment-' . $attachment_id;
	}

	/**
	 * Rejects external or ambiguous attachment URLs before WordPress lookup.
	 *
	 * @param string $url Requested URL.
	 * @return void
	 * @throws InvalidArgumentException When the URL is external or ambiguous.
	 */
	private function assert_same_site_url( string $url ): void {
		$target = wp_parse_url( $url );
		$site   = wp_parse_url( home_url( '/' ) );
		if ( ! is_array( $target ) || ! is_array( $site ) ) {
			throw new InvalidArgumentException( 'url is invalid.' );
		}

		$target_scheme = $target['scheme'] ?? null;
		$target_host   = $target['host'] ?? null;
		$target_path   = $target['path'] ?? '';
		$site_scheme   = $site['scheme'] ?? null;
		$site_host     = $site['host'] ?? null;
		if ( ! is_string( $target_scheme ) || ! is_string( $target_host ) || ! is_string( $target_path ) || ! is_string( $site_scheme ) || ! is_string( $site_host ) ) {
			throw new InvalidArgumentException( 'url is invalid.' );
		}

		$scheme      = strtolower( $target_scheme );
		$host        = strtolower( $target_host );
		$site_scheme = strtolower( $site_scheme );
		$site_host   = strtolower( $site_host );
		if (
			! in_array( $scheme, array( 'http', 'https' ), true )
			|| ! hash_equals( $site_scheme, $scheme )
			|| ! hash_equals( $site_host, $host )
			|| self::effective_port( $target ) !== self::effective_port( $site )
			|| isset( $target['user'] )
			|| isset( $target['pass'] )
			|| isset( $target['fragment'] )
			|| str_contains( $url, '\\' )
			|| str_contains( rawurldecode( $target_path ), '..' )
		) {
			throw new InvalidArgumentException( 'url must identify media on this site.' );
		}
	}

	/**
	 * Returns an explicit or scheme-default URL port.
	 *
	 * @param array<array-key, mixed> $parts Parsed URL.
	 * @return int
	 */
	private static function effective_port( array $parts ): int {
		if ( isset( $parts['port'] ) && is_int( $parts['port'] ) ) {
			return $parts['port'];
		}

		$scheme = $parts['scheme'] ?? null;

		return is_string( $scheme ) && 'https' === strtolower( $scheme ) ? 443 : 80;
	}
}

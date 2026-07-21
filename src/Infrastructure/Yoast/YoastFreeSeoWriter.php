<?php
/**
 * Yoast Free SEO write adapter.
 *
 * @package IsuDev\WPContentBridge
 */

declare( strict_types=1 );

namespace IsuDev\WPContentBridge\Infrastructure\Yoast;

use IsuDev\WPContentBridge\Application\Mutation\SeoFieldUnsupported;
use IsuDev\WPContentBridge\Application\Mutation\SeoWriter;
use IsuDev\WPContentBridge\Application\Seo\SeoProvider;
use IsuDev\WPContentBridge\Domain\Seo\SeoTarget;

/**
 * Writes the Yoast Free 28.x core-field allowlist directly through the same
 * versioned post-meta keys YoastSeoProvider already reads, then re-reads
 * effective SEO through that same provider. Premium/Local fields are never
 * writable here; they are excluded from the allowlist upstream in SeoUpdate.
 */
final readonly class YoastFreeSeoWriter implements SeoWriter {

	private const COMPATIBLE_MAJOR = '28.';

	/**
	 * Simple string fields mapped 1:1 to a single documented Yoast meta key.
	 *
	 * @var array<string, string>
	 */
	private const TEXT_META = array(
		'seo_title'           => '_yoast_wpseo_title',
		'meta_description'    => '_yoast_wpseo_metadesc',
		'focus_keyphrase'     => '_yoast_wpseo_focuskw',
		'canonical'           => '_yoast_wpseo_canonical',
		'og_title'            => '_yoast_wpseo_opengraph-title',
		'og_description'      => '_yoast_wpseo_opengraph-description',
		'twitter_title'       => '_yoast_wpseo_twitter-title',
		'twitter_description' => '_yoast_wpseo_twitter-description',
	);

	/**
	 * Creates the writer.
	 *
	 * @param SeoProvider $reader Read-side provider used for the mandatory post-write re-read.
	 */
	public function __construct(
		private SeoProvider $reader,
	) {
	}

	/**
	 * Whether a version-compatible Yoast Free install is active.
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		if ( ! function_exists( 'YoastSEO' ) || ! defined( 'WPSEO_VERSION' ) ) {
			return false;
		}
		$version = constant( 'WPSEO_VERSION' );

		return is_string( $version ) && str_starts_with( $version, self::COMPATIBLE_MAJOR );
	}

	/**
	 * Writes the allowlisted fields and returns the re-read effective SEO document.
	 *
	 * @param int   $post_id Target post ID.
	 * @param array $fields  Present allowlisted field name to value.
	 * @phpstan-param array<string, string|bool> $fields
	 * @return array<string, mixed>
	 * @throws SeoFieldUnsupported When no compatible writer is available.
	 */
	public function write( int $post_id, array $fields ): array {
		if ( ! $this->is_available() ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- field names are stored as structured data on the exception, not rendered as its message text.
			throw new SeoFieldUnsupported( array_keys( $fields ) );
		}

		foreach ( self::TEXT_META as $field => $meta_key ) {
			if ( array_key_exists( $field, $fields ) && is_string( $fields[ $field ] ) ) {
				update_post_meta( $post_id, $meta_key, $fields[ $field ] );
			}
		}

		if ( array_key_exists( 'robots_index', $fields ) && is_bool( $fields['robots_index'] ) ) {
			update_post_meta( $post_id, '_yoast_wpseo_meta-robots-noindex', $fields['robots_index'] ? '2' : '1' );
		}
		if ( array_key_exists( 'robots_follow', $fields ) && is_bool( $fields['robots_follow'] ) ) {
			update_post_meta( $post_id, '_yoast_wpseo_meta-robots-nofollow', $fields['robots_follow'] ? '0' : '1' );
		}

		$document = $this->reader->get( SeoTarget::for_post( $post_id ) );

		return $document->to_array();
	}
}

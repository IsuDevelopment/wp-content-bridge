<?php
/**
 * Yoast SEO write adapter.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Infrastructure\Yoast;

use IsuDev\WPContentBridge\Application\Mutation\MutationWriteFailed;
use IsuDev\WPContentBridge\Application\Mutation\SeoFieldUnsupported;
use IsuDev\WPContentBridge\Application\Mutation\SeoImageRepository;
use IsuDev\WPContentBridge\Application\Mutation\SeoImageUnavailable;
use IsuDev\WPContentBridge\Application\Mutation\SeoWriter;
use IsuDev\WPContentBridge\Application\Seo\SeoProvider;
use IsuDev\WPContentBridge\Domain\Seo\SeoTarget;

/**
 * Writes the version-tested Yoast 28.x Free and Premium editor allowlist.
 */
final readonly class YoastSeoWriter implements SeoWriter {

	private const COMPATIBLE_MAJOR = '28.';
	private const PREMIUM_FIELDS   = array( 'keyphrase_synonyms', 'related_keyphrases' );
	private const PREMIUM_LIMIT    = 20;
	private const SYNONYM_BYTES    = 4000;
	private const ADVANCED_ROBOTS  = array(
		'robots_noimageindex' => 'noimageindex',
		'robots_noarchive'    => 'noarchive',
		'robots_nosnippet'    => 'nosnippet',
	);
	private const SOCIAL_IMAGES    = array(
		'og_image_id'      => 'opengraph-image',
		'twitter_image_id' => 'twitter-image',
	);

	/**
	 * Simple string fields mapped 1:1 to a single versioned Yoast meta key.
	 *
	 * @var array<string, string>
	 */
	private const TEXT_META = array(
		'seo_title'           => 'title',
		'meta_description'    => 'metadesc',
		'focus_keyphrase'     => 'focuskw',
		'canonical'           => 'canonical',
		'og_title'            => 'opengraph-title',
		'og_description'      => 'opengraph-description',
		'twitter_title'       => 'twitter-title',
		'twitter_description' => 'twitter-description',
	);

	/**
	 * Creates the writer.
	 *
	 * @param SeoProvider        $reader Read-side provider used for the mandatory post-write re-read.
	 * @param SeoImageRepository $images Authorized WordPress image attachment resolver.
	 */
	public function __construct(
		private SeoProvider $reader,
		private SeoImageRepository $images,
	) {}

	/**
	 * Whether a version-compatible Yoast Free install is active.
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		if ( ! function_exists( 'YoastSEO' ) || ! class_exists( 'WPSEO_Meta' ) || ! defined( 'WPSEO_VERSION' ) ) {
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
	 * @phpstan-param array<string, string|int|bool|list<string>> $fields
	 * @return array<string, mixed>
	 * @throws SeoFieldUnsupported When the required compatible provider is unavailable.
	 */
	public function write( int $post_id, array $fields ): array {
		if ( ! $this->is_available() ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- structured field names, not rendered output.
			throw new SeoFieldUnsupported( array_keys( $fields ) );
		}

		$requested_premium = array_values( array_intersect( self::PREMIUM_FIELDS, array_keys( $fields ) ) );
		if ( array() !== $requested_premium && ! $this->is_premium_available() ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- structured field names, not rendered output.
			throw new SeoFieldUnsupported( $requested_premium );
		}

		// Resolve every requested attachment before the first write so an invalid
		// image cannot leave an otherwise valid multi-field request half-applied.
		$social_images = $this->resolve_social_images( $fields );

		foreach ( self::TEXT_META as $field => $yoast_key ) {
			if ( array_key_exists( $field, $fields ) && is_string( $fields[ $field ] ) ) {
				$value = 'canonical' === $field
					? esc_url_raw( $fields[ $field ], array( 'http', 'https' ) )
					: sanitize_text_field( $fields[ $field ] );
				\WPSEO_Meta::set_value( $yoast_key, $value, $post_id );
			}
		}

		if ( array_key_exists( 'robots_index', $fields ) && is_bool( $fields['robots_index'] ) ) {
			\WPSEO_Meta::set_value( 'meta-robots-noindex', $fields['robots_index'] ? '2' : '1', $post_id );
		}
		if ( array_key_exists( 'robots_follow', $fields ) && is_bool( $fields['robots_follow'] ) ) {
			\WPSEO_Meta::set_value( 'meta-robots-nofollow', $fields['robots_follow'] ? '0' : '1', $post_id );
		}

		$this->write_advanced_robots( $post_id, $fields );
		$this->write_social_images( $post_id, $social_images );

		$this->write_premium_keyphrases( $post_id, $fields );

		$document = $this->reader->get( SeoTarget::for_post( $post_id ) );

		return $document->to_array();
	}

	/**
	 * Resolves requested image IDs to trusted attachment URLs before any write.
	 *
	 * @param array $fields Validated public fields.
	 * @phpstan-param array<string, string|int|bool|list<string>> $fields
	 * @return array<string, array{id: int, url: string}>
	 * @throws SeoImageUnavailable When an attachment is absent, unreadable, or not an image.
	 */
	private function resolve_social_images( array $fields ): array {
		$resolved = array();
		foreach ( self::SOCIAL_IMAGES as $field => $yoast_key ) {
			if ( ! array_key_exists( $field, $fields ) || ! is_int( $fields[ $field ] ) ) {
				continue;
			}

			$attachment_id = $fields[ $field ];
			if ( 0 === $attachment_id ) {
				$resolved[ $yoast_key ] = array(
					'id'  => 0,
					'url' => '',
				);
				continue;
			}

			$url = $this->images->image_url( $attachment_id );
			if ( null === $url ) {
				throw new SeoImageUnavailable( 'SEO social image is unavailable.' );
			}
			$resolved[ $yoast_key ] = array(
				'id'  => $attachment_id,
				'url' => $url,
			);
		}

		return $resolved;
	}

	/**
	 * Merges explicitly requested advanced directives with the current Yoast value.
	 *
	 * @param int   $post_id Target post ID.
	 * @param array $fields  Validated public fields.
	 * @phpstan-param array<string, string|int|bool|list<string>> $fields
	 * @return void
	 */
	private function write_advanced_robots( int $post_id, array $fields ): void {
		$requested = array_intersect( array_keys( self::ADVANCED_ROBOTS ), array_keys( $fields ) );
		if ( array() === $requested ) {
			return;
		}

		$current = get_post_meta( $post_id, '_yoast_wpseo_meta-robots-adv', true );
		$current = is_string( $current ) ? array_map( 'trim', explode( ',', $current ) ) : array();
		$enabled = array_fill_keys( array_intersect( array_values( self::ADVANCED_ROBOTS ), $current ), true );

		foreach ( self::ADVANCED_ROBOTS as $field => $directive ) {
			if ( ! array_key_exists( $field, $fields ) || ! is_bool( $fields[ $field ] ) ) {
				continue;
			}
			if ( $fields[ $field ] ) {
				$enabled[ $directive ] = true;
			} else {
				unset( $enabled[ $directive ] );
			}
		}

		$ordered = array_values( array_filter( array_values( self::ADVANCED_ROBOTS ), static fn ( string $directive ): bool => isset( $enabled[ $directive ] ) ) );
		\WPSEO_Meta::set_value( 'meta-robots-adv', implode( ',', $ordered ), $post_id );
	}

	/**
	 * Writes each resolved social image as the paired Yoast URL and attachment ID.
	 *
	 * @param int   $post_id Target post ID.
	 * @param array $images  Resolved Yoast key to attachment identity.
	 * @phpstan-param array<string, array{id: int, url: string}> $images
	 * @return void
	 */
	private function write_social_images( int $post_id, array $images ): void {
		foreach ( $images as $yoast_key => $image ) {
			\WPSEO_Meta::set_value( $yoast_key, esc_url_raw( $image['url'], array( 'http', 'https' ) ), $post_id );
			\WPSEO_Meta::set_value( $yoast_key . '-id', 0 === $image['id'] ? '' : (string) $image['id'], $post_id );
		}
	}

	/**
	 * Whether the matching Premium 28.x storage contract is active.
	 *
	 * @return bool
	 */
	private function is_premium_available(): bool {
		if ( ! defined( 'WPSEO_PREMIUM_VERSION' ) ) {
			return false;
		}
		$version = constant( 'WPSEO_PREMIUM_VERSION' );

		return is_string( $version ) && str_starts_with( $version, self::COMPATIBLE_MAJOR );
	}

	/**
	 * Writes normalized Premium keyphrase fields while preserving matching scores
	 * and related-keyphrase synonyms maintained by the Yoast editor.
	 *
	 * @param int   $post_id Target post ID.
	 * @param array $fields  Validated fields.
	 * @phpstan-param array<string, string|int|bool|list<string>> $fields
	 * @return void
	 * @throws MutationWriteFailed When JSON encoding fails.
	 */
	private function write_premium_keyphrases( int $post_id, array $fields ): void {
		$updates_synonyms = array_key_exists( 'keyphrase_synonyms', $fields );
		$updates_related  = array_key_exists( 'related_keyphrases', $fields );
		if ( ! $updates_synonyms && ! $updates_related ) {
			return;
		}

		$current_related  = self::decoded_related( get_post_meta( $post_id, '_yoast_wpseo_focuskeywords', true ) );
		$current_synonyms = self::decoded_synonym_strings( get_post_meta( $post_id, '_yoast_wpseo_keywordsynonyms', true ) );
		$primary_synonyms = $current_synonyms[0] ?? '';
		if ( $updates_synonyms && is_array( $fields['keyphrase_synonyms'] ) ) {
			$primary_synonyms = implode( ', ', array_map( 'sanitize_text_field', $fields['keyphrase_synonyms'] ) );
		}

		if ( ! $updates_related ) {
			$current_synonyms[0] = $primary_synonyms;
			\WPSEO_Meta::set_value( 'keywordsynonyms', self::encode_json( $current_synonyms ), $post_id );
			return;
		}

		$existing = array();
		foreach ( $current_related as $index => $item ) {
			$keyword              = $item['keyword'];
			$existing[ $keyword ] = array(
				'score'    => $item['score'],
				'synonyms' => $current_synonyms[ $index + 1 ] ?? '',
			);
		}

		$related          = array();
		$related_synonyms = array( $primary_synonyms );
		if ( is_array( $fields['related_keyphrases'] ) ) {
			foreach ( $fields['related_keyphrases'] as $keyword ) {
				$keyword            = sanitize_text_field( $keyword );
				$related[]          = array(
					'keyword' => $keyword,
					'score'   => $existing[ $keyword ]['score'] ?? 0,
				);
				$related_synonyms[] = $existing[ $keyword ]['synonyms'] ?? '';
			}
		}

		\WPSEO_Meta::set_value( 'focuskeywords', self::encode_json( $related ), $post_id );
		\WPSEO_Meta::set_value( 'keywordsynonyms', self::encode_json( $related_synonyms ), $post_id );
	}

	/**
	 * Decodes the narrow Yoast Premium related-keyphrase storage shape.
	 *
	 * @param mixed $raw Stored JSON.
	 * @return list<array{keyword: string, score: int}>
	 */
	private static function decoded_related( mixed $raw ): array {
		$decoded = is_string( $raw ) ? json_decode( $raw, true ) : null;
		if ( ! is_array( $decoded ) ) {
			return array();
		}

		$items = array();
		foreach ( array_slice( $decoded, 0, self::PREMIUM_LIMIT ) as $item ) {
			if ( ! is_array( $item ) || ! is_string( $item['keyword'] ?? null ) ) {
				continue;
			}
			$keyword = sanitize_text_field( $item['keyword'] );
			if ( '' === $keyword || 191 < mb_strlen( $keyword ) ) {
				continue;
			}
			$score   = isset( $item['score'] ) && is_numeric( $item['score'] ) ? (int) $item['score'] : 0;
			$items[] = array(
				'keyword' => $keyword,
				'score'   => max( 0, min( 100, $score ) ),
			);
		}

		return $items;
	}

	/**
	 * Decodes Yoast's positional synonym-string array.
	 *
	 * @param mixed $raw Stored JSON.
	 * @return list<string>
	 */
	private static function decoded_synonym_strings( mixed $raw ): array {
		$decoded = is_string( $raw ) ? json_decode( $raw, true ) : null;
		if ( ! is_array( $decoded ) ) {
			return array();
		}

		$strings = array();
		foreach ( array_slice( $decoded, 0, self::PREMIUM_LIMIT + 1 ) as $item ) {
			if ( ! is_string( $item ) ) {
				$strings[] = '';
				continue;
			}
			$item      = sanitize_text_field( $item );
			$strings[] = function_exists( 'mb_substr' ) ? mb_substr( $item, 0, self::SYNONYM_BYTES ) : substr( $item, 0, self::SYNONYM_BYTES );
		}

		return $strings;
	}

	/**
	 * Encodes one validated Premium value using WordPress JSON behavior.
	 *
	 * @param array $value JSON-compatible value.
	 * @phpstan-param list<string>|list<array{keyword: string, score: int}> $value
	 * @return string
	 * @throws MutationWriteFailed When JSON encoding fails.
	 */
	private static function encode_json( array $value ): string {
		$encoded = wp_json_encode( $value );
		if ( ! is_string( $encoded ) ) {
			throw new MutationWriteFailed( 'Yoast Premium keyphrase data could not be encoded.' );
		}

		return $encoded;
	}
}

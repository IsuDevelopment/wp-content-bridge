<?php
/**
 * Yoast SEO read adapter.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Infrastructure\Yoast;

use IsuDev\WPContentBridge\Application\Seo\RenderedSchemaReader;
use IsuDev\WPContentBridge\Application\Seo\SeoProvider;
use IsuDev\WPContentBridge\Domain\Seo\SeoCompleteness;
use IsuDev\WPContentBridge\Domain\Seo\SeoDocument;
use IsuDev\WPContentBridge\Domain\Seo\SeoField;
use IsuDev\WPContentBridge\Domain\Seo\SeoProviderStatus;
use IsuDev\WPContentBridge\Domain\Seo\SeoTarget;
use IsuDev\WPContentBridge\Domain\Seo\SeoValueState;
use Throwable;

/**
 * Reads documented Yoast Surfaces output and a version-tested configured allowlist.
 */
final readonly class YoastSeoProvider implements SeoProvider {

	private const CONFIGURED_META_COMPATIBLE_MAJOR = '28.';
	private const PREMIUM_COMPATIBLE_MAJOR         = '28.';
	private const LOCAL_COMPATIBLE_MAJOR           = '15.';

	/**
	 * Versioned configured-value allowlist for Yoast 28.x.
	 *
	 * @var array<string, string>
	 */
	private const TEXT_META = array(
		'title'       => '_yoast_wpseo_title',
		'description' => '_yoast_wpseo_metadesc',
		'canonical'   => '_yoast_wpseo_canonical',
	);

	/**
	 * Public Open Graph image keys; anything else (notably the filesystem `path`) is dropped.
	 *
	 * @var list<string>
	 */
	private const OPEN_GRAPH_IMAGE_ALLOWED_KEYS = array( 'url', 'width', 'height', 'type', 'alt' );

	/**
	 * Creates the provider.
	 *
	 * @param RenderedSchemaReader|null $rendered_schema Optional rendered-schema reader used to
	 *                                                   capture Local multiple-location branch data
	 *                                                   that the resolved meta surface omits.
	 */
	public function __construct(
		private ?RenderedSchemaReader $rendered_schema = null,
	) {
	}

	/**
	 * Whether Yoast's documented surface is available.
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return function_exists( 'YoastSEO' ) && defined( 'WPSEO_VERSION' );
	}

	/**
	 * Returns provider identity without exposing configuration.
	 *
	 * @return SeoProviderStatus
	 */
	public function status(): SeoProviderStatus {
		$version         = $this->defined_version( 'WPSEO_VERSION' );
		$modules         = array();
		$module_versions = array();
		$capabilities    = array( 'post', 'resolved', 'schema', 'url' );
		if ( null !== $version && str_starts_with( $version, self::CONFIGURED_META_COMPATIBLE_MAJOR ) ) {
			$capabilities[] = 'configured';
		}

		$premium_version = $this->defined_version( 'WPSEO_PREMIUM_VERSION' );
		if ( null !== $premium_version ) {
			$modules[]                  = 'premium';
			$module_versions['premium'] = $premium_version;
			if ( str_starts_with( $premium_version, self::PREMIUM_COMPATIBLE_MAJOR ) ) {
				$capabilities[] = 'additional_keyphrases';
			}
		}

		$local_version = $this->defined_version( 'WPSEO_LOCAL_VERSION' );
		if ( null !== $local_version ) {
			$modules[]                = 'local';
			$module_versions['local'] = $local_version;
			if ( str_starts_with( $local_version, self::LOCAL_COMPATIBLE_MAJOR ) ) {
				$capabilities[] = 'local_schema';
				$capabilities[] = 'local_profile';
			}
		}

		return new SeoProviderStatus( 'yoast', $version, $this->is_available(), $modules, $capabilities, $module_versions );
	}

	/**
	 * Reads normalized SEO through the documented Surfaces API.
	 *
	 * @param SeoTarget $target Validated and authorized target.
	 * @return SeoDocument
	 */
	public function get( SeoTarget $target ): SeoDocument {
		$status     = $this->status();
		$warnings   = array();
		$configured = array();
		if ( null !== $target->post_id && in_array( 'configured', $status->capabilities, true ) ) {
			$configured = $this->configured_for_post( $target->post_id );
		} elseif ( null !== $target->post_id ) {
			$warnings[] = 'Configured Yoast values are disabled for this unverified Yoast major version.';
		} else {
			$warnings[] = 'Configured editor values are unavailable for URL-only targets.';
		}

		$meta = $this->meta_for_target( $target );
		if ( null === $meta ) {
			$warnings[] = 'Yoast resolved SEO data is unavailable for this target. Save the object or run Yoast SEO data optimization.';

			return new SeoDocument(
				$configured,
				array(),
				array(),
				array(),
				$status,
				array() === $configured ? SeoCompleteness::UNAVAILABLE : SeoCompleteness::PARTIAL,
				$warnings
			);
		}

		$warnings[] = 'Yoast analysis score abilities return recent-post aggregates without stable post IDs, so they cannot be mapped safely to this target.';

		$resolved = $this->resolved_fields( $meta );
		$schema   = $this->schema_graph( $meta );
		if ( in_array( 'local_profile', $status->capabilities, true ) ) {
			$local_graph  = $schema;
			$local_source = 'yoast.schema.local';
			$rendered_url = $this->rendered_target_url( $target );
			if ( null !== $this->rendered_schema && null !== $rendered_url ) {
				$rendered_graph = $this->rendered_schema->graph_for_url( $rendered_url );
				if ( array() !== $rendered_graph ) {
					$local_graph  = $rendered_graph;
					$local_source = 'yoast.schema.local.rendered';
				} else {
					$warnings[] = 'Rendered Local schema was unavailable; local businesses use the resolved surface, which omits multiple-location branch relationships.';
				}
			}
			$local_profiles               = ( new LocalSchemaProjector() )->project( $local_graph );
			$resolved['local_businesses'] = new SeoField(
				array() === $local_profiles ? null : $local_profiles,
				array() === $local_profiles ? SeoValueState::UNAVAILABLE : SeoValueState::GENERATED,
				$local_source,
				array() === $local_profiles ? 'Local SEO is active, but this target emits no public Place or LocalBusiness entity.' : null
			);
		}

		return new SeoDocument(
			$configured,
			$resolved,
			array(),
			$schema,
			$status,
			SeoCompleteness::PARTIAL,
			$warnings
		);
	}

	/**
	 * Resolves the public URL whose rendered schema should be captured.
	 *
	 * @param SeoTarget $target Authorized SEO target.
	 * @return string|null
	 */
	private function rendered_target_url( SeoTarget $target ): ?string {
		if ( null !== $target->url && '' !== $target->url ) {
			return $target->url;
		}
		if ( null === $target->post_id || ! function_exists( 'get_permalink' ) ) {
			return null;
		}
		$permalink = get_permalink( $target->post_id );

		return is_string( $permalink ) && '' !== $permalink ? $permalink : null;
	}

	/**
	 * Resolves Yoast's public Meta value for a post or URL.
	 *
	 * @param SeoTarget $target SEO target.
	 * @return object|null
	 */
	private function meta_for_target( SeoTarget $target ): ?object {
		if ( ! $this->is_available() ) {
			return null;
		}

		try {
			$main    = \YoastSEO();
			$surface = $main->__get( 'meta' );
			$method  = null !== $target->post_id ? 'for_post' : 'for_url';
			$value   = null !== $target->post_id ? $target->post_id : $target->url;
			if ( ! method_exists( $surface, $method ) ) {
				return null;
			}
			$surface_callback = array( $surface, $method );
			if ( ! is_callable( $surface_callback ) ) {
				return null;
			}
			$meta = $surface_callback( $value );

			return is_object( $meta ) ? $meta : null;
		} catch ( Throwable ) {
			return null;
		}
	}

	/**
	 * Builds resolved public fields from documented Meta properties.
	 *
	 * @param object $meta Yoast Meta value.
	 * @return array<string, SeoField>
	 */
	private function resolved_fields( object $meta ): array {
		$open_graph = $this->property_map(
			$meta,
			array( 'type', 'title', 'description', 'url', 'site_name', 'locale', 'images', 'article_publisher', 'article_author', 'article_published_time', 'article_modified_time' ),
			'open_graph_'
		);
		if ( array_key_exists( 'images', $open_graph ) ) {
			$open_graph['images'] = $this->sanitize_open_graph_images( $open_graph['images'] );
		}

		$twitter = $this->property_map( $meta, array( 'card', 'title', 'description', 'image', 'creator', 'site' ), 'twitter_' );
		if ( is_array( $twitter['image'] ?? null ) ) {
			$twitter['image'] = $this->is_single_open_graph_image( $twitter['image'] )
				? $this->sanitize_open_graph_image( $twitter['image'] )
				: $this->sanitize_open_graph_images( $twitter['image'] );
		}

		$resolved = array(
			'title'       => $this->resolved_field( $meta, 'title' ),
			'description' => $this->resolved_field( $meta, 'description' ),
			'canonical'   => $this->resolved_field( $meta, 'canonical' ),
			'robots'      => $this->resolved_field( $meta, 'robots' ),
			'open_graph'  => new SeoField( $open_graph, SeoValueState::GENERATED, 'yoast.surfaces' ),
			'twitter'     => new SeoField( $twitter, SeoValueState::GENERATED, 'yoast.surfaces' ),
		);

		return $resolved;
	}

	/**
	 * Creates one resolved property field.
	 *
	 * @param object $meta     Yoast Meta value.
	 * @param string $property Documented property.
	 * @return SeoField
	 */
	private function resolved_field( object $meta, string $property ): SeoField {
		return new SeoField( $this->surface_value( $meta, $property ), SeoValueState::GENERATED, 'yoast.surfaces' );
	}

	/**
	 * Sanitizes Open Graph image data to the public allowlist.
	 *
	 * Yoast's documented `open_graph_images` is normally a URL-keyed map of image arrays
	 * (each carrying a filesystem `path` key alongside the public `url`/`width`/`height`
	 * keys), but this also defensively accepts a plain list of image arrays or a single
	 * image array. Only the public allowlisted keys may leave this provider.
	 *
	 * @param mixed $images Raw Open Graph images value (URL-keyed map, list, single array, or absent).
	 * @return list<array<string, mixed>>
	 */
	private function sanitize_open_graph_images( mixed $images ): array {
		if ( ! is_array( $images ) ) {
			return array();
		}
		if ( $this->is_single_open_graph_image( $images ) ) {
			return array( $this->sanitize_open_graph_image( $images ) );
		}

		// A list of images, or Yoast's real URL-keyed map of images: sanitize each value,
		// falling back to the map key as the url when a mapped value omits its own url.
		$sanitized = array();
		foreach ( $images as $key => $image ) {
			if ( is_array( $image ) ) {
				$sanitized[] = $this->sanitize_open_graph_image( $image, is_string( $key ) ? $key : null );
			}
		}

		return $sanitized;
	}

	/**
	 * Determines whether an array represents one Open Graph image (has an allowlisted key
	 * directly on it) rather than a list/map of several images.
	 *
	 * @param array $value Candidate array.
	 * @return bool
	 * @phpstan-param array<array-key, mixed> $value
	 */
	private function is_single_open_graph_image( array $value ): bool {
		foreach ( self::OPEN_GRAPH_IMAGE_ALLOWED_KEYS as $key ) {
			if ( array_key_exists( $key, $value ) && ( is_scalar( $value[ $key ] ) || null === $value[ $key ] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Sanitizes one Open Graph image entry to the public allowlist, dropping `path` and
	 * any other non-allowlisted key.
	 *
	 * @param array       $image        Raw Open Graph image entry.
	 * @param string|null $fallback_url URL to use when the entry itself omits one (e.g. the
	 *                                  entry's map key in a URL-keyed map).
	 * @return array<string, mixed>
	 * @phpstan-param array<array-key, mixed> $image
	 */
	private function sanitize_open_graph_image( array $image, ?string $fallback_url = null ): array {
		$sanitized = array();
		foreach ( self::OPEN_GRAPH_IMAGE_ALLOWED_KEYS as $key ) {
			if ( array_key_exists( $key, $image ) ) {
				$sanitized[ $key ] = $image[ $key ];
			}
		}
		if ( ! array_key_exists( 'url', $sanitized ) && null !== $fallback_url ) {
			$sanitized['url'] = $fallback_url;
		}

		return $sanitized;
	}

	/**
	 * Extracts the provider-native public Schema graph without flattening nodes.
	 *
	 * @param object $meta Yoast Meta value.
	 * @return list<array<string, mixed>>
	 */
	private function schema_graph( object $meta ): array {
		$schema = $this->surface_value( $meta, 'schema' );
		if ( ! is_array( $schema ) || ! isset( $schema['@graph'] ) || ! is_array( $schema['@graph'] ) ) {
			return array();
		}

		$nodes = array();
		foreach ( $schema['@graph'] as $node ) {
			if ( is_array( $node ) ) {
				$normalized_node = array();
				foreach ( $node as $key => $value ) {
					if ( is_string( $key ) ) {
						$normalized_node[ $key ] = $value;
					}
				}
				$nodes[] = $normalized_node;
			}
		}

		return $nodes;
	}

	/**
	 * Reads the versioned configured meta allowlist for one authorized post.
	 *
	 * @param int $post_id Authorized post ID.
	 * @return array<string, SeoField>
	 */
	private function configured_for_post( int $post_id ): array {
		$configured = array();
		foreach ( self::TEXT_META as $field => $meta_key ) {
			$configured[ $field ] = $this->configured_meta_field( $post_id, $meta_key );
		}

		$focus      = $this->configured_meta_field( $post_id, '_yoast_wpseo_focuskw' );
		$primary    = is_string( $focus->value ) ? $focus->value : '';
		$additional = '';
		$synonyms   = '';
		if ( defined( 'WPSEO_PREMIUM_VERSION' ) ) {
			$raw_additional = get_post_meta( $post_id, '_yoast_wpseo_focuskeywords', true );
			$additional     = is_string( $raw_additional ) ? $raw_additional : '';
			$raw_synonyms   = get_post_meta( $post_id, '_yoast_wpseo_keywordsynonyms', true );
			$synonyms       = is_string( $raw_synonyms ) ? $raw_synonyms : '';
		}
		$keyphrases                       = ( new PremiumKeyphraseNormalizer() )->normalize( $primary, $additional, $synonyms );
		$has_explicit_keyphrases          = SeoValueState::EXPLICIT === $focus->state
			|| metadata_exists( 'post', $post_id, '_yoast_wpseo_focuskeywords' );
		$configured['focus_keyphrases']   = new SeoField(
			$keyphrases['phrases'],
			$has_explicit_keyphrases ? SeoValueState::EXPLICIT : SeoValueState::INHERITED,
			defined( 'WPSEO_PREMIUM_VERSION' ) ? 'yoast.post_meta.v28-premium' : $focus->source,
			$has_explicit_keyphrases ? null : $focus->reason
		);
		$configured['keyphrase_details']  = defined( 'WPSEO_PREMIUM_VERSION' )
			? new SeoField(
				$keyphrases['details'],
				$has_explicit_keyphrases ? SeoValueState::EXPLICIT : SeoValueState::INHERITED,
				'yoast.post_meta.v28-premium',
				$has_explicit_keyphrases ? null : 'No explicit Premium keyphrase value is stored.'
			)
			: new SeoField( null, SeoValueState::UNSUPPORTED, 'yoast.premium', 'Yoast Premium is not active.' );
		$configured['keyphrase_synonyms'] = defined( 'WPSEO_PREMIUM_VERSION' )
			? new SeoField(
				$keyphrases['keyphrase_synonyms'],
				metadata_exists( 'post', $post_id, '_yoast_wpseo_keywordsynonyms' ) ? SeoValueState::EXPLICIT : SeoValueState::INHERITED,
				'yoast.post_meta.v28-premium',
				metadata_exists( 'post', $post_id, '_yoast_wpseo_keywordsynonyms' ) ? null : 'No explicit Premium synonym value is stored.'
			)
			: new SeoField( null, SeoValueState::UNSUPPORTED, 'yoast.premium', 'Yoast Premium is not active.' );
		$configured['related_keyphrases'] = defined( 'WPSEO_PREMIUM_VERSION' )
			? new SeoField(
				$keyphrases['related_keyphrases'],
				metadata_exists( 'post', $post_id, '_yoast_wpseo_focuskeywords' ) ? SeoValueState::EXPLICIT : SeoValueState::INHERITED,
				'yoast.post_meta.v28-premium',
				metadata_exists( 'post', $post_id, '_yoast_wpseo_focuskeywords' ) ? null : 'No explicit Premium related-keyphrase value is stored.'
			)
			: new SeoField( null, SeoValueState::UNSUPPORTED, 'yoast.premium', 'Yoast Premium is not active.' );
		$configured['robots']             = $this->configured_group_field(
			$post_id,
			array(
				'noindex'  => '_yoast_wpseo_meta-robots-noindex',
				'nofollow' => '_yoast_wpseo_meta-robots-nofollow',
			)
		);
		$configured['social']             = $this->configured_group_field(
			$post_id,
			array(
				'open_graph_title'       => '_yoast_wpseo_opengraph-title',
				'open_graph_description' => '_yoast_wpseo_opengraph-description',
				'open_graph_image'       => '_yoast_wpseo_opengraph-image',
				'twitter_title'          => '_yoast_wpseo_twitter-title',
				'twitter_description'    => '_yoast_wpseo_twitter-description',
				'twitter_image'          => '_yoast_wpseo_twitter-image',
			)
		);
		$configured['schema_types']       = $this->configured_group_field(
			$post_id,
			array(
				'page'    => '_yoast_wpseo_schema_page_type',
				'article' => '_yoast_wpseo_schema_article_type',
			)
		);
		$cornerstone                      = $this->configured_meta_field( $post_id, '_yoast_wpseo_is_cornerstone' );
		$configured['cornerstone']        = new SeoField(
			SeoValueState::EXPLICIT === $cornerstone->state ? '1' === $cornerstone->value : null,
			$cornerstone->state,
			$cornerstone->source,
			$cornerstone->reason
		);

		return $configured;
	}

	/**
	 * Reads one allowlisted configured field and distinguishes inheritance.
	 *
	 * @param int    $post_id  Authorized post ID.
	 * @param string $meta_key Allowlisted Yoast key.
	 * @return SeoField
	 */
	private function configured_meta_field( int $post_id, string $meta_key ): SeoField {
		if ( ! metadata_exists( 'post', $post_id, $meta_key ) ) {
			return new SeoField( null, SeoValueState::INHERITED, 'yoast.inheritance', 'No explicit editor value is stored.' );
		}
		$value = get_post_meta( $post_id, $meta_key, true );

		return new SeoField( is_scalar( $value ) ? $value : null, SeoValueState::EXPLICIT, 'yoast.post_meta.v28' );
	}

	/**
	 * Reads a group of allowlisted keys and reports inheritance when all are absent.
	 *
	 * @param int   $post_id Authorized post ID.
	 * @param array $keys    Public field to allowlisted meta key.
	 * @return SeoField
	 * @phpstan-param array<string, string> $keys
	 */
	private function configured_group_field( int $post_id, array $keys ): SeoField {
		$values       = array();
		$has_explicit = false;
		foreach ( $keys as $field => $meta_key ) {
			$has_explicit     = metadata_exists( 'post', $post_id, $meta_key ) || $has_explicit;
			$values[ $field ] = get_post_meta( $post_id, $meta_key, true );
		}
		if ( ! $has_explicit ) {
			return new SeoField( null, SeoValueState::INHERITED, 'yoast.inheritance', 'No explicit editor value is stored.' );
		}

		return new SeoField( $values, SeoValueState::EXPLICIT, 'yoast.post_meta.v28' );
	}

	/**
	 * Maps a documented family of public surface properties.
	 *
	 * @param object $meta       Yoast Meta value.
	 * @param array  $properties Property suffixes.
	 * @param string $prefix     Property prefix.
	 * @return array<string, mixed>
	 * @phpstan-param list<string> $properties
	 */
	private function property_map( object $meta, array $properties, string $prefix ): array {
		$output = array();
		foreach ( $properties as $property ) {
			$output[ $property ] = $this->surface_value( $meta, $prefix . $property );
		}

		return $output;
	}

	/**
	 * Safely reads one documented magic property and normalizes nested values.
	 *
	 * @param object $meta     Yoast Meta value.
	 * @param string $property Documented property name.
	 * @return string|int|float|bool|array|null
	 * @phpstan-return string|int|float|bool|array<array-key, mixed>|null
	 */
	private function surface_value( object $meta, string $property ): string|int|float|bool|array|null {
		if ( ! method_exists( $meta, '__get' ) ) {
			return null;
		}
		try {
			$callback = array( $meta, '__get' );
			$value    = $callback( $property );
		} catch ( Throwable ) {
			return null;
		}

		return $this->normalize_value( $value );
	}

	/**
	 * Keeps public surface values JSON-compatible and bounded in depth.
	 *
	 * @param mixed $value Public surface value.
	 * @param int   $depth Current depth.
	 * @return string|int|float|bool|array|null
	 * @phpstan-return string|int|float|bool|array<array-key, mixed>|null
	 */
	private function normalize_value( mixed $value, int $depth = 0 ): string|int|float|bool|array|null {
		if ( $depth > 10 ) {
			return null;
		}
		if ( is_string( $value ) && '' === trim( $value ) ) {
			return null;
		}
		if ( is_scalar( $value ) || null === $value ) {
			return $value;
		}
		if ( ! is_array( $value ) ) {
			return null;
		}

		$normalized = array();
		foreach ( array_slice( $value, 0, 5000, true ) as $key => $item ) {
			$normalized[ $key ] = $this->normalize_value( $item, $depth + 1 );
		}

		return $normalized;
	}

	/**
	 * Reads one public plugin version constant without exposing other constants.
	 *
	 * @param string $name Allowlisted version constant name.
	 * @return string|null
	 */
	private function defined_version( string $name ): ?string {
		if ( ! in_array( $name, array( 'WPSEO_VERSION', 'WPSEO_PREMIUM_VERSION', 'WPSEO_LOCAL_VERSION' ), true ) || ! defined( $name ) ) {
			return null;
		}
		$value = constant( $name );

		return is_string( $value ) && '' !== trim( $value ) ? $value : null;
	}
}

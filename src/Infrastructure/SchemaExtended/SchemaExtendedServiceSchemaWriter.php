<?php
/**
 * IsuDev Schema Extended Service write adapter.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Infrastructure\SchemaExtended;

use IsuDev\SchemaExtended\Service\Meta_Fields;
use IsuDev\WPContentBridge\Application\Mutation\MutationWriteFailed;
use IsuDev\WPContentBridge\Application\Mutation\ServiceSchemaReader;
use IsuDev\WPContentBridge\Application\Mutation\ServiceSchemaUnavailable;
use IsuDev\WPContentBridge\Application\Mutation\ServiceSchemaWriter;

/**
 * Maps the provider-neutral Service document to the standalone plugin's fixed
 * public metadata API. No arbitrary meta key or Schema.org fragment is accepted.
 */
final class SchemaExtendedServiceSchemaWriter implements ServiceSchemaReader, ServiceSchemaWriter {

	private const REQUIRED_METHODS = array(
		'get_supported_post_types',
		'is_enabled',
		'get_string',
		'get_list',
		'get_areas',
		'sanitize_areas',
		'get_offers',
		'sanitize_offers',
	);

	/**
	 * Whether the standalone Schema Extended plugin and its compatible public API
	 * were loaded through WordPress's plugin bootstrap.
	 */
	public function is_available(): bool {
		if ( ! defined( 'ISUDEV_SCHEMA_EXTENDED_VERSION' ) || ! class_exists( Meta_Fields::class, false ) ) {
			return false;
		}

		foreach ( self::REQUIRED_METHODS as $method ) {
			if ( ! method_exists( Meta_Fields::class, $method ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Whether Schema Extended enables Service metadata for this post type.
	 *
	 * @param string $post_type WordPress post type.
	 */
	public function supports_post_type( string $post_type ): bool {
		return $this->is_available() && in_array( $post_type, Meta_Fields::get_supported_post_types(), true );
	}

	/**
	 * Writes the fixed Service field set and returns its effective configuration.
	 *
	 * @param int                  $post_id Target post ID.
	 * @param array<string, mixed> $fields  Validated provider-neutral fields.
	 * @return array<string, mixed>
	 * @throws ServiceSchemaUnavailable When the optional plugin is unavailable.
	 * @throws MutationWriteFailed When a metadata write fails.
	 */
	public function write( int $post_id, array $fields ): array {
		if ( ! $this->is_available() ) {
			throw new ServiceSchemaUnavailable( 'The Service schema provider is unavailable.' );
		}

		$normalized = $this->normalize_fields( $fields );
		$snapshots  = array();
		$written    = array();

		foreach ( $normalized as $field => $value ) {
			$key               = self::meta_key( $field );
			$storage_value     = self::storage_value( $field, $value );
			$snapshots[ $key ] = array(
				'existed' => metadata_exists( 'post', $post_id, $key ),
				'value'   => get_post_meta( $post_id, $key, true ),
			);

			if ( $snapshots[ $key ]['value'] === $storage_value ) {
				continue;
			}

			$result = update_post_meta( $post_id, $key, $storage_value );
			if ( false === $result ) {
				$this->rollback( $post_id, $written, $snapshots );
				throw new MutationWriteFailed( 'WordPress rejected a Service schema metadata write.' );
			}
			$written[] = $key;
		}

		return $this->read( $post_id );
	}

	/**
	 * Returns a sanitized prospective configuration without metadata writes.
	 *
	 * @param int                  $post_id Target post ID.
	 * @param array<string, mixed> $fields  Validated provider-neutral fields.
	 * @return array<string, mixed>
	 * @throws ServiceSchemaUnavailable When the optional plugin is unavailable.
	 */
	public function preview( int $post_id, array $fields ): array {
		return array_replace( $this->read( $post_id ), $this->normalize_fields( $fields ) );
	}

	/**
	 * Normalizes the domain document through the provider's public sanitizers.
	 *
	 * @param array<string, mixed> $fields Provider-neutral Service fields.
	 * @return array<string, mixed>
	 * @throws ServiceSchemaUnavailable When a field escapes the fixed allowlist.
	 */
	private function normalize_fields( array $fields ): array {
		$normalized = array();
		foreach ( $fields as $field => $value ) {
			switch ( $field ) {
				case 'enabled':
					$normalized[ $field ] = true === $value;
					break;
				case 'name':
				case 'service_type':
				case 'catalog_name':
					$normalized[ $field ] = sanitize_text_field( is_string( $value ) ? $value : '' );
					break;
				case 'description':
					$normalized[ $field ] = sanitize_textarea_field( is_string( $value ) ? $value : '' );
					break;
				case 'areas':
					$normalized[ $field ] = Meta_Fields::sanitize_areas( $value );
					break;
				case 'brands':
					$brands = array();
					if ( is_array( $value ) ) {
						foreach ( $value as $brand ) {
							$brand = trim( sanitize_text_field( is_string( $brand ) ? $brand : '' ) );
							if ( '' !== $brand && ! in_array( $brand, $brands, true ) ) {
								$brands[] = $brand;
							}
						}
					}
					$normalized[ $field ] = $brands;
					break;
				case 'offers':
					$normalized[ $field ] = Meta_Fields::sanitize_offers( $value );
					break;
				default:
					throw new ServiceSchemaUnavailable( 'The Service schema field is unsupported.' );
			}
		}

		return $normalized;
	}

	/**
	 * Converts one normalized public value to the provider's storage shape.
	 *
	 * @param string $field Public field name.
	 * @param mixed  $value Provider-normalized public value.
	 * @return mixed
	 */
	private static function storage_value( string $field, mixed $value ): mixed {
		if ( 'brands' !== $field ) {
			return $value;
		}

		$brands = array();
		if ( is_array( $value ) ) {
			foreach ( $value as $brand ) {
				if ( is_string( $brand ) ) {
					$brands[] = $brand;
				}
			}
		}

		return implode( "\n", $brands );
	}

	/**
	 * Maps a public field to one fixed Schema Extended metadata constant.
	 *
	 * @param string $field Public field name.
	 * @throws ServiceSchemaUnavailable When the field is unknown.
	 */
	private static function meta_key( string $field ): string {
		return match ( $field ) {
			'enabled'      => Meta_Fields::ENABLED,
			'name'         => Meta_Fields::NAME,
			'service_type' => Meta_Fields::SERVICE_TYPE,
			'description'  => Meta_Fields::DESCRIPTION,
			'areas'        => Meta_Fields::AREAS,
			'brands'       => Meta_Fields::BRANDS,
			'catalog_name' => Meta_Fields::CATALOG_NAME,
			'offers'       => Meta_Fields::OFFERS,
			default        => throw new ServiceSchemaUnavailable( 'The Service schema field is unsupported.' ),
		};
	}

	/**
	 * Best-effort rollback for keys already changed in this request.
	 *
	 * @param int                                               $post_id   Target post ID.
	 * @param array                                             $written   Written keys.
	 * @param array<string, array{existed: bool, value: mixed}> $snapshots Pre-write values.
	 * @phpstan-param list<string> $written
	 */
	private function rollback( int $post_id, array $written, array $snapshots ): void {
		foreach ( array_reverse( $written ) as $key ) {
			$snapshot = $snapshots[ $key ];
			if ( $snapshot['existed'] ) {
				update_post_meta( $post_id, $key, $snapshot['value'] );
			} else {
				delete_post_meta( $post_id, $key );
			}
		}
	}

	/**
	 * Re-reads the provider-sanitized effective configuration.
	 *
	 * @param int $post_id Target post ID.
	 * @return array<string, mixed>
	 * @throws ServiceSchemaUnavailable When the optional plugin is unavailable.
	 */
	public function read( int $post_id ): array {
		if ( ! $this->is_available() ) {
			throw new ServiceSchemaUnavailable( 'The Service schema provider is unavailable.' );
		}

		$version = constant( 'ISUDEV_SCHEMA_EXTENDED_VERSION' );

		return array(
			'schema_version' => '1.0',
			'enabled'        => Meta_Fields::is_enabled( $post_id ),
			'name'           => Meta_Fields::get_string( $post_id, Meta_Fields::NAME ),
			'service_type'   => Meta_Fields::get_string( $post_id, Meta_Fields::SERVICE_TYPE ),
			'description'    => Meta_Fields::get_string( $post_id, Meta_Fields::DESCRIPTION ),
			'areas'          => Meta_Fields::get_areas( $post_id ),
			'brands'         => Meta_Fields::get_list( $post_id, Meta_Fields::BRANDS ),
			'catalog_name'   => Meta_Fields::get_string( $post_id, Meta_Fields::CATALOG_NAME ),
			'offers'         => Meta_Fields::get_offers( $post_id ),
			'provider'       => array(
				'name'    => 'isudev-schema-extended',
				'version' => is_string( $version ) ? $version : '',
			),
		);
	}
}

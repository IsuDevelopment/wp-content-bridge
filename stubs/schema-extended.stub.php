<?php
/**
 * Static-analysis surface for the optional IsuDev Schema Extended plugin.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\SchemaExtended\Service;

final class Meta_Fields {
	public const ENABLED = '';
	public const NAME = '';
	public const SERVICE_TYPE = '';
	public const DESCRIPTION = '';
	public const AREAS = '';
	public const BRANDS = '';
	public const CATALOG_NAME = '';
	public const OFFERS = '';

	/** @return list<string> */
	public static function get_supported_post_types(): array {}
	public static function is_enabled( int $post_id ): bool {}
	public static function get_string( int $post_id, string $meta_key ): string {}
	/** @return list<string> */
	public static function get_list( int $post_id, string $meta_key ): array {}
	/** @return list<array{type: string, name: string}> */
	public static function get_areas( int $post_id ): array {}
	/** @return list<array{type: string, name: string}> */
	public static function sanitize_areas( mixed $value ): array {}
	/** @return list<array{name: string, description: string}> */
	public static function get_offers( int $post_id ): array {}
	/** @return list<array{name: string, description: string}> */
	public static function sanitize_offers( mixed $value ): array {}
}

namespace IsuDev\SchemaExtended\Custom;

use WP_Error;

final class Integration_API {
	public const CONTRACT_VERSION = '1.0';

	/** @return array<string, mixed>|WP_Error */
	public static function get_configuration( int $post_id ): array|WP_Error {}
	/** @return array<string, mixed> */
	public static function validate_source( string $source ): array {}
	/** @return array<string, mixed>|WP_Error */
	public static function update_configuration( int $post_id, bool $enabled, string $source ): array|WP_Error {}
}

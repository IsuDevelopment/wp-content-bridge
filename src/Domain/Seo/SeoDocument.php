<?php
/**
 * Provider-neutral SEO document.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Domain\Seo;

use InvalidArgumentException;
use JsonException;

/**
 * A bounded normalized projection, never a raw provider data dump.
 */
final readonly class SeoDocument {

	public const SCHEMA_VERSION   = '1.1';
	public const MAX_SCHEMA_NODES = 200;
	public const MAX_SCHEMA_BYTES = 1024 * 1024;
	public const MAX_WARNINGS     = 50;

	private const CONFIGURED_FIELDS = array(
		'title',
		'description',
		'focus_keyphrases',
		'keyphrase_details',
		'canonical',
		'robots',
		'social',
		'schema_types',
		'cornerstone',
	);

	private const RESOLVED_FIELDS = array(
		'title',
		'description',
		'canonical',
		'robots',
		'open_graph',
		'twitter',
		'other_public_meta',
		'local_businesses',
	);

	private const ANALYSIS_FIELDS = array(
		'seo',
		'readability',
		'inclusive_language',
	);

	/**
	 * Creates a normalized document.
	 *
	 * @param array             $configured  Configured editor values.
	 * @param array             $resolved    Effective public values.
	 * @param array             $analysis    Provider analysis values.
	 * @param array             $schema_graph Provider-native public Schema nodes.
	 * @param SeoProviderStatus $provider    Provider identity.
	 * @param SeoCompleteness   $completeness Completeness state.
	 * @param array             $warnings    Safe compatibility warnings.
	 * @phpstan-param array<string, SeoField> $configured
	 * @phpstan-param array<string, SeoField> $resolved
	 * @phpstan-param array<string, SeoField> $analysis
	 * @phpstan-param list<array<string, mixed>> $schema_graph
	 * @phpstan-param list<string> $warnings
	 * @throws InvalidArgumentException When normalized output violates the public bounds.
	 */
	public function __construct(
		public array $configured,
		public array $resolved,
		public array $analysis,
		public array $schema_graph,
		public SeoProviderStatus $provider,
		public SeoCompleteness $completeness,
		public array $warnings,
	) {
		self::assert_fields( $configured, self::CONFIGURED_FIELDS );
		self::assert_fields( $resolved, self::RESOLVED_FIELDS );
		self::assert_fields( $analysis, self::ANALYSIS_FIELDS );
		self::assert_schema_graph( $schema_graph );
		self::assert_warnings( $warnings );
	}

	/**
	 * Serializes the public SEO contract.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'schema_version' => self::SCHEMA_VERSION,
			'configured'     => self::serialize_fields( $this->configured ),
			'resolved'       => self::serialize_fields( $this->resolved ),
			'analysis'       => self::serialize_fields( $this->analysis ),
			'schema_graph'   => $this->schema_graph,
			'provenance'     => array(
				'provider'                     => $this->provider->to_array(),
				'normalization_schema_version' => self::SCHEMA_VERSION,
				'completeness'                 => $this->completeness->value,
			),
			'warnings'       => $this->warnings,
		);
	}

	/**
	 * Validates section allowlists and value types.
	 *
	 * @param array $fields  Section values.
	 * @param array $allowed Allowed field names.
	 * @return void
	 * @phpstan-param array<string, SeoField> $fields
	 * @phpstan-param list<string> $allowed
	 * @throws InvalidArgumentException When a field is outside the section allowlist.
	 */
	private static function assert_fields( array $fields, array $allowed ): void {
		foreach ( array_keys( $fields ) as $name ) {
			if ( ! in_array( $name, $allowed, true ) ) {
				throw new InvalidArgumentException( 'Unknown normalized SEO field.' );
			}
		}
	}

	/**
	 * Validates graph bounds and JSON compatibility.
	 *
	 * @param array $schema_graph Schema graph nodes.
	 * @return void
	 * @phpstan-param list<array<string, mixed>> $schema_graph
	 * @throws InvalidArgumentException When the graph is invalid or exceeds bounds.
	 */
	private static function assert_schema_graph( array $schema_graph ): void {
		if ( count( $schema_graph ) > self::MAX_SCHEMA_NODES ) {
			throw new InvalidArgumentException( 'SEO Schema graph exceeds the node limit.' );
		}
		try {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- domain code cannot depend on WordPress functions.
			$encoded = json_encode( $schema_graph, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES );
		} catch ( JsonException ) {
			throw new InvalidArgumentException( 'SEO Schema graph is not JSON-compatible.' );
		}
		if ( strlen( $encoded ) > self::MAX_SCHEMA_BYTES ) {
			throw new InvalidArgumentException( 'SEO Schema graph exceeds the byte limit.' );
		}
	}

	/**
	 * Validates bounded safe warning messages.
	 *
	 * @param array $warnings Warning list.
	 * @return void
	 * @phpstan-param list<string> $warnings
	 * @throws InvalidArgumentException When warnings exceed safe bounds.
	 */
	private static function assert_warnings( array $warnings ): void {
		if ( count( $warnings ) > self::MAX_WARNINGS ) {
			throw new InvalidArgumentException( 'SEO warning count exceeds the limit.' );
		}
		foreach ( $warnings as $warning ) {
			if ( '' === trim( $warning ) || strlen( $warning ) > 500 ) {
				throw new InvalidArgumentException( 'SEO warning is invalid.' );
			}
		}
	}

	/**
	 * Serializes a normalized field section.
	 *
	 * @param array $fields Section values.
	 * @return array<string, array<string, mixed>>
	 * @phpstan-param array<string, SeoField> $fields
	 */
	private static function serialize_fields( array $fields ): array {
		return array_map(
			static fn ( SeoField $field ): array => $field->to_array(),
			$fields
		);
	}
}

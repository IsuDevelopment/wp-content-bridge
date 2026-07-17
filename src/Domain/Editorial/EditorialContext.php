<?php
/**
 * Editorial-context result.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Domain\Editorial;

use InvalidArgumentException;

/**
 * Bounded provider-neutral context envelope.
 */
final readonly class EditorialContext {

	public const SCHEMA_VERSION = '1.0';
	public const MAX_WARNINGS   = 20;

	/**
	 * Creates one editorial context response.
	 *
	 * @param array $sections Requested section names.
	 * @param array $context  Selected section payloads.
	 * @param array $bounds   Effective public limits.
	 * @param array $warnings Safe warnings.
	 * @phpstan-param non-empty-list<string> $sections
	 * @phpstan-param array<string, mixed> $context
	 * @phpstan-param array<string, int> $bounds
	 * @phpstan-param list<string> $warnings
	 * @throws InvalidArgumentException When the public envelope exceeds its bounds.
	 */
	public function __construct(
		public array $sections,
		public array $context,
		public array $bounds,
		public array $warnings = array(),
	) {
		if ( array() !== array_diff( array_keys( $context ), EditorialContextQuery::SECTIONS ) ) {
			throw new InvalidArgumentException( 'Editorial context contains an unknown section.' );
		}
		if ( count( $warnings ) > self::MAX_WARNINGS ) {
			throw new InvalidArgumentException( 'Editorial warning count exceeds the limit.' );
		}
		foreach ( $warnings as $warning ) {
			if ( '' === trim( $warning ) || strlen( $warning ) > 500 ) {
				throw new InvalidArgumentException( 'Editorial warning is invalid.' );
			}
		}
	}

	/**
	 * Serializes the public result.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'schema_version' => self::SCHEMA_VERSION,
			'sections'       => $this->sections,
			'context'        => $this->context,
			'bounds'         => $this->bounds,
			'provenance'     => array(
				'source'    => 'wordpress_and_normalized_seo',
				'untrusted' => true,
			),
			'warnings'       => $this->warnings,
		);
	}
}

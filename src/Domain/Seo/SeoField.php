<?php
/**
 * One normalized SEO field.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Domain\Seo;

use InvalidArgumentException;

/**
 * Couples a JSON-compatible value with its origin and safe source label.
 */
final readonly class SeoField {

	/**
	 * Creates a normalized field.
	 *
	 * @param string|int|float|bool|array|null $value  Normalized JSON-compatible value.
	 * @param SeoValueState                    $state  Value origin/state.
	 * @param string                           $source Safe source identifier.
	 * @param string|null                      $reason Safe unavailability explanation.
	 * @phpstan-param string|int|float|bool|array<array-key, mixed>|null $value
	 * @throws InvalidArgumentException When the field metadata or value is unsafe.
	 */
	public function __construct(
		public string|int|float|bool|array|null $value,
		public SeoValueState $state,
		public string $source,
		public ?string $reason = null,
	) {
		if ( 1 !== preg_match( '/^[a-z0-9_.-]{1,100}$/', $source ) ) {
			throw new InvalidArgumentException( 'SEO field source is invalid.' );
		}
		if ( null !== $reason && ( '' === trim( $reason ) || strlen( $reason ) > 500 ) ) {
			throw new InvalidArgumentException( 'SEO field reason is invalid.' );
		}
		self::assert_json_value( $value );
	}

	/**
	 * Serializes the normalized field.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'value'  => $this->value,
			'state'  => $this->state->value,
			'source' => $this->source,
			'reason' => $this->reason,
		);
	}

	/**
	 * Rejects objects/resources hidden inside nested arrays.
	 *
	 * @param mixed $value Candidate JSON value.
	 * @param int   $depth Current nesting depth.
	 * @return void
	 * @throws InvalidArgumentException When the value cannot be safely normalized.
	 */
	private static function assert_json_value( mixed $value, int $depth = 0 ): void {
		if ( $depth > 10 ) {
			throw new InvalidArgumentException( 'SEO field value is nested too deeply.' );
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $item ) {
				self::assert_json_value( $item, $depth + 1 );
			}
			return;
		}
		if ( null !== $value && ! is_scalar( $value ) ) {
			throw new InvalidArgumentException( 'SEO field value must be JSON-compatible.' );
		}
	}
}

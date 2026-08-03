<?php
/**
 * Custom Schema validation failure.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Application\Mutation;

use InvalidArgumentException;

/**
 * Carries safe provider diagnostics for a rejected enabled configuration.
 */
final class CustomSchemaInvalid extends InvalidArgumentException {

	/**
	 * Creates the failure.
	 *
	 * @param string               $message    Human-readable failure.
	 * @param array<string, mixed> $validation Bounded validation diagnostics.
	 */
	public function __construct( string $message, private readonly array $validation ) {
		parent::__construct( $message );
	}

	/**
	 * Returns the stable public error code.
	 */
	public function error_code(): string {
		return 'wpcb_invalid_custom_schema';
	}

	/**
	 * Returns bounded safe diagnostics.
	 *
	 * @return array<string, mixed>
	 */
	public function validation(): array {
		return $this->validation;
	}
}

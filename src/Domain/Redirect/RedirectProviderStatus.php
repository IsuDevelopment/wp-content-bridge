<?php
/**
 * Redirect provider identity and capability status.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Domain\Redirect;

use InvalidArgumentException;

/**
 * Safe provider metadata suitable for diagnostics and every redirect result
 * (ADR 0026 s4: "expose the selected provider and version in diagnostics and
 * every redirect result").
 */
final readonly class RedirectProviderStatus {

	/**
	 * Creates provider status.
	 *
	 * @param string      $provider     Stable provider slug.
	 * @param string|null $version     Public plugin/provider version.
	 * @param bool        $detected     Whether a real provider is active and compatible.
	 * @param array       $capabilities Supported normalized capabilities.
	 * @phpstan-param list<string> $capabilities
	 * @throws InvalidArgumentException When provider metadata is invalid.
	 */
	public function __construct(
		public string $provider,
		public ?string $version,
		public bool $detected,
		public array $capabilities,
	) {
		if ( 1 !== preg_match( '/^[a-z0-9_-]{1,64}$/', $provider ) ) {
			throw new InvalidArgumentException( 'Redirect provider slug is invalid.' );
		}
		if ( null !== $version && ( '' === trim( $version ) || strlen( $version ) > 64 ) ) {
			throw new InvalidArgumentException( 'Redirect provider version is invalid.' );
		}
		if ( count( $capabilities ) > 20 ) {
			throw new InvalidArgumentException( 'Too many redirect provider capabilities.' );
		}
		foreach ( $capabilities as $capability ) {
			if ( 1 !== preg_match( '/^[a-z0-9_-]{1,64}$/', $capability ) ) {
				throw new InvalidArgumentException( 'Redirect provider capability token is invalid.' );
			}
		}
	}

	/**
	 * Serializes safe provider status.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		$capabilities = array_values( array_unique( $this->capabilities ) );
		sort( $capabilities );

		return array(
			'provider'     => $this->provider,
			'version'      => $this->version,
			'detected'     => $this->detected,
			'capabilities' => $capabilities,
		);
	}
}

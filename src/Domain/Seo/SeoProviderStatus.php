<?php
/**
 * SEO provider identity and capability status.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Domain\Seo;

use InvalidArgumentException;

/**
 * Safe provider metadata suitable for diagnostics and provenance.
 */
final readonly class SeoProviderStatus {

	/**
	 * Creates provider status.
	 *
	 * @param string      $provider     Stable provider slug.
	 * @param string|null $version      Public plugin/provider version.
	 * @param bool        $detected     Whether a real provider is active.
	 * @param array       $modules      Detected public modules.
	 * @param array       $capabilities Supported normalized capabilities.
	 * @param array       $module_versions Safe public module versions keyed by slug.
	 * @phpstan-param list<string> $modules
	 * @phpstan-param list<string> $capabilities
	 * @phpstan-param array<string, string> $module_versions
	 * @throws InvalidArgumentException When provider metadata is invalid.
	 */
	public function __construct(
		public string $provider,
		public ?string $version,
		public bool $detected,
		public array $modules,
		public array $capabilities,
		public array $module_versions = array(),
	) {
		if ( 1 !== preg_match( '/^[a-z0-9_-]{1,64}$/', $provider ) ) {
			throw new InvalidArgumentException( 'SEO provider slug is invalid.' );
		}
		if ( null !== $version && ( '' === trim( $version ) || strlen( $version ) > 64 ) ) {
			throw new InvalidArgumentException( 'SEO provider version is invalid.' );
		}
		self::assert_tokens( $modules );
		self::assert_tokens( $capabilities );
		self::assert_module_versions( $module_versions );
	}

	/**
	 * Serializes safe provider status.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		$modules      = array_values( array_unique( $this->modules ) );
		$capabilities = array_values( array_unique( $this->capabilities ) );
		sort( $modules );
		sort( $capabilities );
		$module_versions = $this->module_versions;
		ksort( $module_versions );

		return array(
			'provider'        => $this->provider,
			'version'         => $this->version,
			'detected'        => $this->detected,
			'modules'         => $modules,
			'module_versions' => $module_versions,
			'capabilities'    => $capabilities,
		);
	}

	/**
	 * Validates one provider token list.
	 *
	 * @param array $tokens Token list.
	 * @return void
	 * @phpstan-param list<string> $tokens
	 * @throws InvalidArgumentException When a token is invalid or the list is too large.
	 */
	private static function assert_tokens( array $tokens ): void {
		if ( count( $tokens ) > 50 ) {
			throw new InvalidArgumentException( 'Too many SEO provider metadata values.' );
		}
		foreach ( $tokens as $token ) {
			if ( 1 !== preg_match( '/^[a-z0-9_-]{1,64}$/', $token ) ) {
				throw new InvalidArgumentException( 'SEO provider metadata token is invalid.' );
			}
		}
	}

	/**
	 * Validates safe public module versions.
	 *
	 * @param array $versions Module versions keyed by safe slug.
	 * @return void
	 * @phpstan-param array<string, string> $versions
	 * @throws InvalidArgumentException When a module version entry is invalid.
	 */
	private static function assert_module_versions( array $versions ): void {
		if ( count( $versions ) > 50 ) {
			throw new InvalidArgumentException( 'Too many SEO module versions.' );
		}
		foreach ( $versions as $module => $version ) {
			if ( 1 !== preg_match( '/^[a-z0-9_-]{1,64}$/', $module )
				|| '' === trim( $version )
				|| strlen( $version ) > 64
			) {
				throw new InvalidArgumentException( 'SEO module version is invalid.' );
			}
		}
	}
}

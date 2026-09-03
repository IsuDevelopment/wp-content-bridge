<?php
/**
 * Error-statistics provider identity and capability status.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Domain\Statistics;

use InvalidArgumentException;

/**
 * Safe provider metadata for every statistics result and for diagnostics.
 *
 * Deliberately a separate type from `RedirectProviderStatus` even though the
 * two currently carry the same fields, because ADR 0030 s1's whole point is
 * that these are two ports: statistics availability does not follow redirect
 * availability, and one shared status object would invite exactly the
 * "Redirection is detected, so statistics work" inference the ADR rejects.
 */
final readonly class ErrorStatisticsProviderStatus {

	/**
	 * Creates provider status.
	 *
	 * @param string      $provider     Stable provider slug.
	 * @param string|null $version      Public plugin/provider version.
	 * @param bool        $detected     Whether a real provider is active and its schema vouched for.
	 * @param array       $collects     Statistic kinds this provider can answer.
	 * @phpstan-param list<string> $collects
	 * @throws InvalidArgumentException When provider metadata is invalid.
	 */
	public function __construct(
		public string $provider,
		public ?string $version,
		public bool $detected,
		public array $collects,
	) {
		if ( 1 !== preg_match( '/^[a-z0-9_-]{1,64}$/', $provider ) ) {
			throw new InvalidArgumentException( 'Statistics provider slug is invalid.' );
		}
		if ( null !== $version && ( '' === trim( $version ) || strlen( $version ) > 64 ) ) {
			throw new InvalidArgumentException( 'Statistics provider version is invalid.' );
		}
		if ( count( $collects ) > 20 ) {
			throw new InvalidArgumentException( 'Too many statistics kinds.' );
		}
		foreach ( $collects as $kind ) {
			if ( 1 !== preg_match( '/^[a-z0-9_-]{1,64}$/', $kind ) ) {
				throw new InvalidArgumentException( 'Statistics kind token is invalid.' );
			}
		}
	}

	/**
	 * Serializes safe provider status.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		$collects = array_values( array_unique( $this->collects ) );
		sort( $collects );

		return array(
			'provider' => $this->provider,
			'version'  => $this->version,
			'detected' => $this->detected,
			'collects' => $collects,
		);
	}
}

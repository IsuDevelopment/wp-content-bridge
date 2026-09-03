<?php
/**
 * Error-statistics provider registry.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Application\Statistics;

use IsuDev\WPContentBridge\Domain\Statistics\ErrorStatisticsProviderStatus;

/**
 * Holds the configured statistics providers with a null fallback.
 *
 * Its own registry, not a view over the redirect registry (ADR 0030 s1). The
 * two lists can legitimately differ - Yoast Premium serves redirects and
 * collects no statistics at all - and a registry shared with redirects would
 * make the second fact unrepresentable.
 *
 * Like the redirect registry there is deliberately no implicit
 * active-provider accessor. Reads span every available provider and each
 * answer is labelled with the provider that gave it, because a first-available
 * pick would silently report one backend's switched-off log as the site's
 * answer while another backend was recording.
 */
final readonly class ErrorStatisticsProviderRegistry {

	/**
	 * Creates the registry.
	 *
	 * @param array                       $providers Ordered provider adapters.
	 * @param NullErrorStatisticsProvider $fallback  Required null object.
	 * @phpstan-param list<ErrorStatisticsProvider> $providers
	 */
	public function __construct(
		private array $providers,
		private NullErrorStatisticsProvider $fallback,
	) {
	}

	/**
	 * Returns every available provider, in registry order.
	 *
	 * @return array Available providers.
	 * @phpstan-return list<ErrorStatisticsProvider>
	 */
	public function available(): array {
		$available = array();

		foreach ( $this->providers as $provider ) {
			if ( $provider->is_available() ) {
				$available[] = $provider;
			}
		}

		return $available;
	}

	/**
	 * Returns the providers a read should ask: every available one, or the
	 * null object when none is available.
	 *
	 * The fallback is returned as a *provider*, not as an empty list, so the
	 * caller receives a result carrying `unavailable` instead of having to
	 * invent one - and so an empty list can never be serialized as "no 404s".
	 *
	 * @return array Providers to ask.
	 * @phpstan-return non-empty-list<ErrorStatisticsProvider>
	 */
	public function to_ask(): array {
		$available = $this->available();

		return array() === $available ? array( $this->fallback ) : $available;
	}

	/**
	 * Returns the status of every configured provider plus the fallback when
	 * none is available, for diagnostics.
	 *
	 * @return array Provider statuses, in registry order.
	 * @phpstan-return list<ErrorStatisticsProviderStatus>
	 */
	public function statuses(): array {
		$statuses = array();

		foreach ( $this->providers as $provider ) {
			$statuses[] = $provider->status();
		}

		if ( array() === $this->available() ) {
			$statuses[] = $this->fallback->status();
		}

		return $statuses;
	}
}

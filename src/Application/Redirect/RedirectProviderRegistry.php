<?php
/**
 * Redirect provider registry.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Application\Redirect;

use IsuDev\WPContentBridge\Domain\Redirect\RedirectProviderStatus;

/**
 * Holds the configured redirect providers and always has a null fallback.
 *
 * There is deliberately no "active provider" accessor (ADR 0026 s4, amended
 * 2026-09-01). Sites commonly run Redirection and Yoast Premium at once, and
 * when they do both engines serve redirects at runtime, so an implicit
 * first-available selection would silently pick a backend whose rule may not
 * be the one that fires. Writes name their provider through `select()`;
 * reads and the safety guard use `available()` and span all of them.
 */
final readonly class RedirectProviderRegistry {

	/**
	 * Creates the registry.
	 *
	 * Provider order is a stable reporting order only — it is not a
	 * preference, and it never selects a write target. Never dual-write and
	 * never an automatic fallback write to the other provider after an error
	 * (roadmap Slice 5, ADR 0026).
	 *
	 * @param array                $providers Ordered provider adapters.
	 * @param NullRedirectProvider $fallback  Required null object.
	 * @phpstan-param list<RedirectProvider> $providers
	 */
	public function __construct(
		private array $providers,
		private NullRedirectProvider $fallback,
	) {
	}

	/**
	 * Returns every available provider, in registry order.
	 *
	 * Empty when none is available; callers that need an explicit refusal use
	 * `select()` or the null fallback rather than treating emptiness as "no
	 * redirects exist".
	 *
	 * @return array Available providers.
	 * @phpstan-return list<RedirectProvider>
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
	 * Returns the available provider a caller named.
	 *
	 * Never falls back to another provider: a write addressed to a provider
	 * that is not available is refused, because writing it elsewhere would
	 * put the rule in a backend the caller did not choose.
	 *
	 * @param string $slug Provider slug as reported by `status()`.
	 * @return RedirectProvider
	 * @throws RedirectProviderUnavailable When no available provider has that slug.
	 */
	public function select( string $slug ): RedirectProvider {
		foreach ( $this->available() as $provider ) {
			if ( $provider->status()->provider === $slug ) {
				return $provider;
			}
		}

		throw new RedirectProviderUnavailable(
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the slug is caller input echoed into a message, never rendered output.
			sprintf( 'Redirect provider "%s" is not available on this site.', $slug )
		);
	}

	/**
	 * Returns a cross-provider lookup over the available providers.
	 *
	 * @return RedirectRuleLookup
	 */
	public function lookup(): RedirectRuleLookup {
		return new RedirectRuleLookup( $this->available() );
	}

	/**
	 * Returns the status of every configured provider plus the fallback,
	 * for diagnostics and for the provider list a caller chooses from.
	 *
	 * @return array Provider statuses, in registry order.
	 * @phpstan-return list<RedirectProviderStatus>
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

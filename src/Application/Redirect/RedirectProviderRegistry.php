<?php
/**
 * Redirect provider registry.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Application\Redirect;

/**
 * Selects the first available configured provider and always has a null
 * fallback. Runtime preference is Yoast SEO Premium first, Redirection
 * second (ADR 0026 s1) — the composition root supplies that order; this
 * class only ever follows it.
 */
final readonly class RedirectProviderRegistry {

	/**
	 * Creates the registry.
	 *
	 * Provider order is explicit priority, never dual-write and never an
	 * automatic fallback write to the other provider after an error
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
	 * Returns the active provider or the null object.
	 *
	 * @return RedirectProvider
	 */
	public function active(): RedirectProvider {
		foreach ( $this->providers as $provider ) {
			if ( $provider->is_available() ) {
				return $provider;
			}
		}

		return $this->fallback;
	}
}

<?php
/**
 * SEO provider registry.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Application\Seo;

/**
 * Selects the first available configured provider and always has a null fallback.
 */
final readonly class SeoProviderRegistry {

	/**
	 * Creates the registry.
	 *
	 * Provider order is explicit priority. Only one provider owns a normalized
	 * document in the initial contract; silent merging would obscure provenance.
	 *
	 * @param array           $providers Ordered provider adapters.
	 * @param NullSeoProvider $fallback  Required null object.
	 * @phpstan-param list<SeoProvider> $providers
	 */
	public function __construct(
		private array $providers,
		private NullSeoProvider $fallback,
	) {
	}

	/**
	 * Returns the active provider or the null object.
	 *
	 * @return SeoProvider
	 */
	public function active(): SeoProvider {
		foreach ( $this->providers as $provider ) {
			if ( $provider->is_available() ) {
				return $provider;
			}
		}

		return $this->fallback;
	}
}

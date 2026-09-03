<?php
/**
 * Cross-provider redirect rule lookup.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Application\Redirect;

use IsuDev\WPContentBridge\Domain\Redirect\RedirectRule;
use IsuDev\WPContentBridge\Domain\Redirect\RedirectSourcePath;

/**
 * Answers "who already claims this source path?" across every available
 * provider (ADR 0026 s4/s5, amended 2026-09-01).
 *
 * A site running Redirection and Yoast Premium at the same time has two live
 * redirect engines, and whichever hooks first wins. Asking only the provider
 * a write is addressed to would report no collision while the other plugin's
 * rule is the one that actually fires, so collision and chain resolution both
 * ask all of them.
 */
final readonly class RedirectRuleLookup {

	/**
	 * Creates the lookup.
	 *
	 * @param array $providers Available providers, in registry order.
	 * @phpstan-param list<RedirectProvider> $providers
	 */
	public function __construct( private array $providers ) {
	}

	/**
	 * Returns every provider's enabled rule for an exact source path.
	 *
	 * A provider that becomes unavailable mid-request throws rather than
	 * contributing silence: an absent answer here reads as "nobody claims
	 * this path", which is the one wrong conclusion (ADR 0026 s4).
	 *
	 * @param RedirectSourcePath $source Exact source path.
	 * @return array Matching rules, each carrying its own provider.
	 * @phpstan-return list<RedirectRule>
	 * @throws RedirectProviderUnavailable When a provider cannot answer.
	 */
	public function find_all( RedirectSourcePath $source ): array {
		$found = array();

		foreach ( $this->providers as $provider ) {
			$rule = $provider->search( $source );
			if ( null !== $rule ) {
				$found[] = $rule;
			}
		}

		return $found;
	}

	/**
	 * Returns the first provider's rule for a source path, or null when no
	 * provider claims it.
	 *
	 * @param RedirectSourcePath $source Exact source path.
	 * @return RedirectRule|null
	 * @throws RedirectProviderUnavailable When a provider cannot answer.
	 */
	public function first( RedirectSourcePath $source ): ?RedirectRule {
		return $this->find_all( $source )[0] ?? null;
	}

	/**
	 * Returns the provider slugs claiming a source path.
	 *
	 * The slug comes from the provider that answered, not from the returned
	 * rule's own `provider` field. An adapter that forgets to stamp its
	 * identity onto the rules it returns would otherwise make a collision
	 * report name the wrong backend — and on a two-plugin site that report is
	 * the operator's only clue which plugin holds the conflicting rule.
	 *
	 * @param RedirectSourcePath $source Exact source path.
	 * @return array Provider slugs, in registry order.
	 * @phpstan-return list<string>
	 * @throws RedirectProviderUnavailable When a provider cannot answer.
	 */
	public function claimants( RedirectSourcePath $source ): array {
		$slugs = array();

		foreach ( $this->providers as $provider ) {
			if ( null !== $provider->search( $source ) ) {
				$slugs[] = $provider->status()->provider;
			}
		}

		return $slugs;
	}
}

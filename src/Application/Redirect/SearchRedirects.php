<?php
/**
 * Read one source path across every redirect provider.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Application\Redirect;

use IsuDev\WPContentBridge\Domain\Redirect\RedirectSourcePath;

/**
 * Answers "who, if anyone, redirects this path?" across all available
 * providers (ADR 0026 s4, amended).
 *
 * The answer is per provider, not merged. A site can run Redirection and Yoast
 * Premium at once, both engines then serve redirects, and whichever hooks first
 * wins — so "there is a rule" is not actionable while "Yoast holds it and
 * Redirection does not" is.
 */
final readonly class SearchRedirects {

	public const ABILITY = 'wp-content-bridge/search-redirects';

	/**
	 * Creates the use case.
	 *
	 * @param RedirectProviderRegistry $registry Provider registry.
	 */
	public function __construct( private RedirectProviderRegistry $registry ) {
	}

	/**
	 * Reads one source path.
	 *
	 * @param array<string, mixed> $input Ability input with a `source` path.
	 * @return array<string, mixed>
	 * @throws \InvalidArgumentException When the source path is not a bounded site-relative path.
	 */
	public function execute( array $input ): array {
		$raw    = $input['source'] ?? '';
		$source = new RedirectSourcePath( is_string( $raw ) ? $raw : '' );

		$claims = array();
		foreach ( $this->registry->available() as $provider ) {
			$claims[] = self::ask( $provider, $source );
		}

		$holders = array();
		foreach ( $claims as $claim ) {
			if ( $claim->holds_path() ) {
				$holders[] = $claim->provider->provider;
			}
		}

		return array(
			'source'               => $source->value(),
			'claims'               => array_map(
				static fn ( RedirectProviderClaim $claim ): array => $claim->to_array(),
				$claims
			),
			'held_by'              => $holders,
			// Two engines holding the same path is a routing hazard the caller
			// cannot see from either plugin's own screen.
			'held_by_multiple'     => count( $holders ) > 1,
			'configured_providers' => array_map(
				static fn ( $status ): array => $status->to_array(),
				$this->registry->statuses()
			),
		);
	}

	/**
	 * Asks one provider, converting its refusals into reported states rather
	 * than letting one provider's unreadable rule fail the whole read.
	 *
	 * @param RedirectProvider   $provider Provider to ask.
	 * @param RedirectSourcePath $source   Source path.
	 * @return RedirectProviderClaim
	 */
	private static function ask( RedirectProvider $provider, RedirectSourcePath $source ): RedirectProviderClaim {
		$status = $provider->status();

		try {
			$rule = $provider->search( $source );
		} catch ( RedirectRuleNotRepresentable $error ) {
			return new RedirectProviderClaim( $status, RedirectProviderClaim::NOT_REPRESENTABLE, null, $error->getMessage() );
		} catch ( RedirectProviderUnavailable | RedirectProviderForbidden $error ) {
			return new RedirectProviderClaim( $status, RedirectProviderClaim::UNAVAILABLE, null, $error->getMessage() );
		}

		return null === $rule
			? new RedirectProviderClaim( $status, RedirectProviderClaim::FREE )
			: new RedirectProviderClaim( $status, RedirectProviderClaim::CLAIMED, $rule );
	}
}

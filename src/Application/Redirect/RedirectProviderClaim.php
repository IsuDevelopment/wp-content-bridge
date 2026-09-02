<?php
/**
 * One provider's answer about a source path.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Application\Redirect;

use IsuDev\WPContentBridge\Domain\Redirect\RedirectProviderStatus;
use IsuDev\WPContentBridge\Domain\Redirect\RedirectRule;

/**
 * What one provider said when asked whether it claims a source path.
 *
 * A read reports this per provider instead of a single merged answer, because
 * on a site running two redirect plugins the useful fact is *which* engine
 * holds the rule — and because one provider holding a rule this plugin cannot
 * express must not blank out the other provider's perfectly readable answer.
 */
final readonly class RedirectProviderClaim {

	/**
	 * A rule exists and was read.
	 */
	public const CLAIMED = 'claimed';

	/**
	 * The provider answered, and holds nothing for this path.
	 */
	public const FREE = 'free';

	/**
	 * A rule exists but falls outside the provider-neutral contract. This is
	 * never reported as `free`: the path is taken.
	 */
	public const NOT_REPRESENTABLE = 'not_representable';

	/**
	 * The provider could not answer. Also never `free`.
	 */
	public const UNAVAILABLE = 'unavailable';

	/**
	 * Creates a provider claim.
	 *
	 * @param RedirectProviderStatus $provider Provider identity and version.
	 * @param string                 $state    One of the state constants.
	 * @param RedirectRule|null      $rule     The rule, when the state is `claimed`.
	 * @param string|null            $reason   Why the state is not `claimed`/`free`.
	 */
	public function __construct(
		public RedirectProviderStatus $provider,
		public string $state,
		public ?RedirectRule $rule = null,
		public ?string $reason = null,
	) {
	}

	/**
	 * Whether this provider holds the path in any form the caller must respect.
	 *
	 * @return bool
	 */
	public function holds_path(): bool {
		return self::CLAIMED === $this->state || self::NOT_REPRESENTABLE === $this->state;
	}

	/**
	 * Serializes the claim for an Ability result.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'provider' => $this->provider->to_array(),
			'state'    => $this->state,
			'rule'     => $this->rule?->to_array(),
			'reason'   => $this->reason,
		);
	}
}

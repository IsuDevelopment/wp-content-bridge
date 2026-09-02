<?php
/**
 * Recording redirect provider test double.
 *
 * @package IsuDev\WPContentBridge\Tests
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Tests\Support;

use IsuDev\WPContentBridge\Application\Redirect\RedirectProvider;
use IsuDev\WPContentBridge\Application\Redirect\RedirectProviderUnavailable;
use IsuDev\WPContentBridge\Domain\Redirect\RedirectProviderStatus;
use IsuDev\WPContentBridge\Domain\Redirect\RedirectRule;
use IsuDev\WPContentBridge\Domain\Redirect\RedirectSourcePath;

/**
 * A provider fake over a fixed rule map that records what it was asked to
 * create, so a test can prove a write reached exactly one backend.
 */
final class RecordingRedirectProvider implements RedirectProvider {

	/**
	 * Rules this provider was asked to create, in order.
	 *
	 * @var list<RedirectRule>
	 */
	public array $created = array();

	/**
	 * Creates the fake.
	 *
	 * @param string                      $slug      Provider slug.
	 * @param array<string, RedirectRule> $existing  Existing rules keyed by source path.
	 * @param bool                        $available Availability flag.
	 */
	public function __construct(
		private string $slug,
		private array $existing = array(),
		private bool $available = true,
	) {
	}

	/**
	 * Returns configured availability.
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return $this->available;
	}

	/**
	 * Returns fake status.
	 *
	 * @return RedirectProviderStatus
	 */
	public function status(): RedirectProviderStatus {
		return new RedirectProviderStatus( $this->slug, '1.0', $this->available, array( 'search', 'create' ) );
	}

	/**
	 * Looks up an existing rule by exact source path.
	 *
	 * @param RedirectSourcePath $source Exact source path.
	 * @return RedirectRule|null
	 * @throws RedirectProviderUnavailable When this fake is unavailable.
	 */
	public function search( RedirectSourcePath $source ): ?RedirectRule {
		if ( ! $this->available ) {
			throw new RedirectProviderUnavailable( 'unavailable fake' );
		}

		return $this->existing[ $source->value() ] ?? null;
	}

	/**
	 * Records the create and returns the rule with an assigned identity.
	 *
	 * @param RedirectRule $candidate Candidate rule.
	 * @return RedirectRule
	 * @throws RedirectProviderUnavailable When this fake is unavailable.
	 */
	public function create( RedirectRule $candidate ): RedirectRule {
		if ( ! $this->available ) {
			throw new RedirectProviderUnavailable( 'unavailable fake' );
		}

		$this->created[] = $candidate;

		return new RedirectRule(
			$this->slug . ':1',
			$candidate->source,
			$candidate->status,
			$candidate->target,
			true,
			$this->status()
		);
	}
}

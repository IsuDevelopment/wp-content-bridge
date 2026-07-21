<?php
/**
 * Block-pattern read policy.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Application\Pattern;

/**
 * Combines the feature flag with native editor access.
 */
final readonly class PatternAccessManager {

	/**
	 * Creates the policy snapshot.
	 *
	 * @param bool               $reads_enabled Whether pattern reads are enabled.
	 * @param BlockPatternAccess $native_access Native editor authorization.
	 */
	public function __construct(
		public bool $reads_enabled,
		private BlockPatternAccess $native_access,
	) {
	}

	/**
	 * Returns whether both policy gates allow pattern reads.
	 *
	 * @return bool
	 */
	public function can_read(): bool {
		return $this->reads_enabled && $this->native_access->can_read();
	}

	/**
	 * Enforces both gates before catalog access.
	 *
	 * @return void
	 * @throws PatternUnavailable When pattern reads are unavailable.
	 */
	public function require_read(): void {
		if ( ! $this->can_read() ) {
			throw new PatternUnavailable( 'Block patterns are unavailable.' );
		}
	}
}

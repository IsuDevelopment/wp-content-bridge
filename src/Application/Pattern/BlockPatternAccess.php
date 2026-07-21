<?php
/**
 * Native block-pattern authorization port.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Application\Pattern;

/**
 * Resolves whether the current principal has WordPress editor-level access.
 */
interface BlockPatternAccess {

	/**
	 * Returns the current native access decision.
	 *
	 * @return bool
	 */
	public function can_read(): bool;
}

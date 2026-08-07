<?php
/**
 * Block-identity assertion failure.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Application\Mutation;

use RuntimeException;

/**
 * Thrown when the block found at the submitted path does not match
 * `expected_block_name`. A matching version token proves the document did
 * not change; it does not prove the path points at the block the caller
 * believes it does, so this is asserted separately and fails closed.
 */
final class BlockMismatch extends RuntimeException {

	/**
	 * Returns the stable adapter error code.
	 *
	 * @return string
	 */
	public function error_code(): string {
		return 'wpcb_block_mismatch';
	}
}

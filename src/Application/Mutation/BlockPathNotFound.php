<?php
/**
 * Out-of-range block-path failure.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Application\Mutation;

use RuntimeException;

/**
 * Thrown when no block exists at the submitted path. Non-enumerating, like
 * every other target failure.
 */
final class BlockPathNotFound extends RuntimeException {

	/**
	 * Returns the stable adapter error code.
	 *
	 * @return string
	 */
	public function error_code(): string {
		return 'wpcb_block_path_not_found';
	}
}

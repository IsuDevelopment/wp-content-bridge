<?php
/**
 * Invalid block markup failure.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Application\Mutation;

use RuntimeException;

/**
 * Thrown when submitted block markup fails validation.
 */
final class InvalidBlockMarkup extends RuntimeException {

	/**
	 * Bounded human-readable reasons (no raw markup).
	 *
	 * @var array<int, string>
	 */
	private array $reasons;

	/**
	 * Creates the failure.
	 *
	 * @param array<int, string> $reasons Bounded reasons.
	 */
	public function __construct( array $reasons ) {
		$this->reasons = $reasons;
		parent::__construct( 'Submitted block markup is invalid.' );
	}

	/**
	 * Returns the stable adapter error code.
	 *
	 * @return string
	 */
	public function error_code(): string {
		return 'wpcb_invalid_blocks';
	}

	/**
	 * Returns bounded reasons.
	 *
	 * @return array<int, string>
	 */
	public function reasons(): array {
		return $this->reasons;
	}
}

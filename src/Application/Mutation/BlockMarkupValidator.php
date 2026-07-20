<?php
/**
 * Block markup validation port.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Application\Mutation;

/**
 * Validates raw Gutenberg block markup.
 */
interface BlockMarkupValidator {

	/**
	 * Validates block markup.
	 *
	 * @param string $markup Raw Gutenberg block markup (may be empty).
	 * @return list<string> Bounded failure reasons; empty means valid.
	 */
	public function validate( string $markup ): array;
}

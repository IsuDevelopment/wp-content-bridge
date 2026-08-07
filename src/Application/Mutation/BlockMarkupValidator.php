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

	/**
	 * Round-trips block markup through parsing and re-serialization only,
	 * without applying content filters that could mutate stored source.
	 *
	 * @param string $markup Raw Gutenberg block markup (may be empty).
	 * @return string The markup that would actually be stored.
	 */
	public function normalize( string $markup ): string;
}

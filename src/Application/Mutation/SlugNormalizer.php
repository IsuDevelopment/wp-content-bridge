<?php
/**
 * Slug normalization port.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Application\Mutation;

/**
 * Turns a caller's requested slug into the one WordPress would store.
 *
 * This is a port rather than a domain helper because slug normalization is
 * WordPress's own behaviour (`sanitize_title()`, its filters, and whatever a
 * site has hooked onto them). Reimplementing it in the domain would drift from
 * what the database actually receives, and the whole point of normalizing
 * before writing is to compare against exactly that.
 */
interface SlugNormalizer {

	/**
	 * Returns the normalized slug, or null when nothing usable remains.
	 *
	 * @param string $requested Caller's raw slug.
	 * @return string|null
	 */
	public function normalize( string $requested ): ?string;
}

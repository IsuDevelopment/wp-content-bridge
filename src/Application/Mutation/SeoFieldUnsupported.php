<?php
/**
 * Unsupported SEO field failure.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Application\Mutation;

use RuntimeException;

/**
 * Thrown when an SEO write requests a field outside the writable allowlist.
 */
final class SeoFieldUnsupported extends RuntimeException {

	/**
	 * Offending field names.
	 *
	 * @var array<int, string>
	 */
	private array $fields;

	/**
	 * Creates the failure.
	 *
	 * @param array<int, string> $fields Offending field names.
	 */
	public function __construct( array $fields ) {
		$this->fields = $fields;
		parent::__construct( 'One or more requested SEO fields are not writable.' );
	}

	/**
	 * Returns the stable adapter error code.
	 *
	 * @return string
	 */
	public function error_code(): string {
		return 'wpcb_seo_field_unsupported';
	}

	/**
	 * Returns offending field names.
	 *
	 * @return array<int, string>
	 */
	public function fields(): array {
		return $this->fields;
	}
}

<?php
/**
 * Media access policy.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Application\Media;

/**
 * Holds the transport-neutral media feature decision for one request.
 */
final readonly class MediaAccessManager {

	/**
	 * Creates the policy snapshot.
	 *
	 * @param bool $reads_enabled Whether media reads are enabled.
	 */
	public function __construct( public bool $reads_enabled ) {
	}

	/**
	 * Enforces the master policy without revealing media existence.
	 *
	 * @return void
	 * @throws MediaUnavailable When media reads are disabled.
	 */
	public function require_read(): void {
		if ( ! $this->reads_enabled ) {
			throw new MediaUnavailable( 'Media is unavailable.' );
		}
	}
}

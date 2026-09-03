<?php
/**
 * Media-upload failure.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Application\Media;

use RuntimeException;

/**
 * The fetch, the type check, or the attachment write refused the request.
 *
 * One exception type covers all three on purpose. A caller learning *which*
 * stage rejected a URL learns about the site's network position - whether a
 * host resolved, whether it answered, what it answered with - which is the
 * reconnaissance value an SSRF attempt is after.
 */
final class MediaUploadFailed extends RuntimeException {

	/**
	 * Returns the stable public error code.
	 */
	public function error_code(): string {
		return 'wpcb_media_upload_failed';
	}
}

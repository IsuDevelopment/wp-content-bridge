<?php
/**
 * Media-upload port.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Application\Media;

use IsuDev\WPContentBridge\Domain\Media\MediaItem;
use IsuDev\WPContentBridge\Domain\Media\MediaUploadRequest;

/**
 * Imports one remote image into the media library.
 */
interface MediaUploader {

	/**
	 * Fetches, validates, and stores one image, returning the new attachment.
	 *
	 * Implementations must validate the URL against the host allowlist, decide
	 * the file type from the downloaded bytes rather than any caller-supplied
	 * hint, enforce the byte ceiling, refuse anything outside the raster-image
	 * allowlist, and delete the temporary file on every path.
	 *
	 * @param MediaUploadRequest $request Validated upload request.
	 * @return MediaItem
	 * @throws MediaUploadFailed When the URL is refused, the fetch fails, the bytes are not an allowed image, or the attachment cannot be stored.
	 */
	public function upload( MediaUploadRequest $request ): MediaItem;
}

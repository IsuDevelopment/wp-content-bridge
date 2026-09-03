<?php
/**
 * WordPress-backed remote image importer.
 *
 * @package IsuDev\WPContentBridge
 */

declare( strict_types=1 );

namespace IsuDev\WPContentBridge\Infrastructure\WordPress;

use IsuDev\WPContentBridge\Application\Media\MediaRepository;
use IsuDev\WPContentBridge\Application\Media\MediaUploadFailed;
use IsuDev\WPContentBridge\Application\Media\MediaUploader;
use IsuDev\WPContentBridge\Domain\Media\MediaItem;
use IsuDev\WPContentBridge\Domain\Media\MediaUploadRequest;
use WP_Error;

/**
 * Fetches one remote image through core's own URL allowlist and stores it.
 *
 * See ADR 0031. The security-relevant properties, in the order they apply:
 * the URL passes `wp_http_validate_url()` (via `wp_safe_remote_get()`), which
 * is re-applied to every redirect target by `WP_Http::validate_redirects()`;
 * the byte ceiling is enforced against the actual response, not the declared
 * `Content-Length`; and the stored MIME type comes from the downloaded bytes,
 * never from the URL, its extension, or the response headers.
 */
final class WordPressMediaUploader implements MediaUploader {

	private const MAX_BYTES = 12582912;
	private const TIMEOUT   = 15;
	private const REDIRECTS = 3;

	/**
	 * Raster images only. Deliberately excludes SVG: it is an XML document that
	 * can carry script and would be served from the site's own origin.
	 *
	 * @var array<string, string>
	 */
	private const ALLOWED_MIMES = array(
		'jpg|jpeg|jpe' => 'image/jpeg',
		'png'          => 'image/png',
		'gif'          => 'image/gif',
		'webp'         => 'image/webp',
		'avif'         => 'image/avif',
	);

	/**
	 * Creates the uploader.
	 *
	 * @param MediaRepository $reader Read port used to project the stored attachment.
	 */
	public function __construct( private MediaRepository $reader ) {}

	/**
	 * Fetches, validates, and stores one image.
	 *
	 * @param MediaUploadRequest $request Validated upload request.
	 * @return MediaItem
	 * @throws MediaUploadFailed When any stage refuses the request.
	 */
	public function upload( MediaUploadRequest $request ): MediaItem {
		$this->require_admin_includes();

		$body = $this->fetch( $request->source_url );
		$path = $this->to_temporary_file( $body );

		try {
			$type          = $this->sniff_allowed_type( $path, $request->source_url );
			$attachment_id = $this->store( $path, $type, $request );
		} finally {
			if ( file_exists( $path ) ) {
				wp_delete_file( $path );
			}
		}

		$item = $this->reader->get( $attachment_id );
		if ( null === $item ) {
			throw new MediaUploadFailed( 'The stored attachment could not be read back.' );
		}

		return $item;
	}

	/**
	 * Performs the bounded, allowlisted fetch.
	 *
	 * @param string $url Caller-supplied source URL.
	 * @return string
	 * @throws MediaUploadFailed When the URL is refused or the response is unusable.
	 */
	private function fetch( string $url ): string {
		/*
		 * wp_safe_remote_get() is what carries the SSRF defence: it sets
		 * reject_unsafe_urls, so wp_http_validate_url() screens the host - and
		 * core re-applies it to every redirect target. Writing our own IP
		 * filter here would be a worse copy of a maintained one.
		 */
		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'     => self::TIMEOUT,
				'redirection' => self::REDIRECTS,
				'headers'     => array( 'Accept' => 'image/*' ),
			)
		);
		if ( $response instanceof WP_Error ) {
			throw new MediaUploadFailed( 'The source URL could not be fetched.' );
		}
		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			throw new MediaUploadFailed( 'The source URL did not return a usable response.' );
		}

		$declared = (int) wp_remote_retrieve_header( $response, 'content-length' );
		if ( 0 < $declared && self::MAX_BYTES < $declared ) {
			throw new MediaUploadFailed( 'The source image exceeds the accepted size.' );
		}

		$body = (string) wp_remote_retrieve_body( $response );

		/*
		 * Checked again against the real body: Content-Length is a claim, and
		 * an absent or understated header must not buy a larger file.
		 */
		if ( '' === $body || self::MAX_BYTES < strlen( $body ) ) {
			throw new MediaUploadFailed( 'The source image is empty or exceeds the accepted size.' );
		}

		return $body;
	}

	/**
	 * Writes the response body to a temporary file.
	 *
	 * @param string $body Fetched bytes.
	 * @return string
	 * @throws MediaUploadFailed When no temporary file can be written.
	 */
	private function to_temporary_file( string $body ): string {
		$path = wp_tempnam();
		if ( ! is_string( $path ) || '' === $path ) {
			throw new MediaUploadFailed( 'No temporary file could be created for the upload.' );
		}

		global $wp_filesystem;
		WP_Filesystem();
		if ( ! $wp_filesystem instanceof \WP_Filesystem_Base || ! $wp_filesystem->put_contents( $path, $body ) ) {
			wp_delete_file( $path );
			throw new MediaUploadFailed( 'The upload could not be written for validation.' );
		}

		return $path;
	}

	/**
	 * Decides the stored type from the file's own bytes.
	 *
	 * @param string $path Temporary file path.
	 * @param string $url  Source URL, used only for a filename hint.
	 * @return array{ext: string, type: string, name: string}
	 * @throws MediaUploadFailed When the bytes are not an allowed raster image.
	 */
	private function sniff_allowed_type( string $path, string $url ): array {
		$hint = $this->filename_hint( $url );

		/*
		 * The caller's extension is only a hint. For image types core calls
		 * wp_get_image_mime(), which reads the magic bytes, and returns the
		 * corrected extension in `proper_filename`. Passing our own allowlist
		 * as $mimes means anything outside it resolves to a false type and is
		 * refused below.
		 */
		$checked = wp_check_filetype_and_ext( $path, $hint, self::ALLOWED_MIMES );

		$type = is_string( $checked['type'] ?? null ) ? $checked['type'] : '';
		$ext  = is_string( $checked['ext'] ?? null ) ? $checked['ext'] : '';
		if ( '' === $type || '' === $ext || ! in_array( $type, array_values( self::ALLOWED_MIMES ), true ) ) {
			throw new MediaUploadFailed( 'The source is not an accepted image type.' );
		}

		$proper = $checked['proper_filename'] ?? false;
		$name   = is_string( $proper ) && '' !== $proper ? $proper : $hint;

		/*
		 * Belt and braces: an allowed MIME whose bytes do not parse as an image
		 * is still refused. wp_getimagesize() is core's hardened wrapper.
		 */
		if ( false === wp_getimagesize( $path ) ) {
			throw new MediaUploadFailed( 'The source is not a readable image.' );
		}

		return array(
			'ext'  => $ext,
			'type' => $type,
			'name' => $this->ensure_extension( sanitize_file_name( $name ), $ext ),
		);
	}

	/**
	 * Stores the validated file as an attachment and generates its sizes.
	 *
	 * @param string                                         $path    Temporary file path.
	 * @param array{ext: string, type: string, name: string} $type Sniffed type data.
	 * @param MediaUploadRequest                             $request Validated request.
	 * @return int
	 * @throws MediaUploadFailed When WordPress refuses the sideload or the insert.
	 */
	private function store( string $path, array $type, MediaUploadRequest $request ): int {
		$file = array(
			'name'     => $type['name'],
			'type'     => $type['type'],
			'tmp_name' => $path,
			'error'    => 0,
			'size'     => (int) filesize( $path ),
		);

		$sideloaded = wp_handle_sideload(
			$file,
			array(
				'test_form' => false,
				'mimes'     => self::ALLOWED_MIMES,
			)
		);
		if ( isset( $sideloaded['error'] ) ) {
			throw new MediaUploadFailed( 'WordPress refused the uploaded image.' );
		}

		$stored_file = is_string( $sideloaded['file'] ?? null ) ? $sideloaded['file'] : '';
		$stored_url  = is_string( $sideloaded['url'] ?? null ) ? $sideloaded['url'] : '';
		if ( '' === $stored_file || '' === $stored_url ) {
			throw new MediaUploadFailed( 'The uploaded image was not stored.' );
		}

		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => $type['type'],
				'post_title'     => $this->attachment_title( $request, $type['name'] ),
				'post_content'   => '',
				'post_excerpt'   => (string) ( $request->caption ?? '' ),
				'post_status'    => 'inherit',
				'guid'           => $stored_url,
			),
			$stored_file,
			0,
			true
		);
		if ( $attachment_id instanceof WP_Error || 1 > (int) $attachment_id ) {
			wp_delete_file( $stored_file );
			throw new MediaUploadFailed( 'The attachment record could not be created.' );
		}
		$attachment_id = (int) $attachment_id;

		if ( null !== $request->alt_text ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', $request->alt_text );
		}

		$metadata = wp_generate_attachment_metadata( $attachment_id, $stored_file );
		if ( is_array( $metadata ) ) {
			wp_update_attachment_metadata( $attachment_id, $metadata );
		}

		return $attachment_id;
	}

	/**
	 * Returns the attachment title: the caller's, else the stored filename.
	 *
	 * @param MediaUploadRequest $request  Validated request.
	 * @param string             $filename Sanitized filename.
	 * @return string
	 */
	private function attachment_title( MediaUploadRequest $request, string $filename ): string {
		if ( null !== $request->title && '' !== trim( $request->title ) ) {
			return $request->title;
		}

		$base = pathinfo( $filename, PATHINFO_FILENAME );

		return '' === $base ? 'image' : $base;
	}

	/**
	 * Derives a safe filename hint from the source URL.
	 *
	 * @param string $url Source URL.
	 * @return string
	 */
	private function filename_hint( string $url ): string {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$base = sanitize_file_name( basename( $path ) );

		return '' === $base || '.' === $base ? 'image' : $base;
	}

	/**
	 * Ensures the filename carries the extension the bytes actually imply.
	 *
	 * @param string $name Sanitized filename.
	 * @param string $ext  Extension from the sniffed type.
	 * @return string
	 */
	private function ensure_extension( string $name, string $ext ): string {
		$current = strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) );
		if ( strtolower( $ext ) === $current ) {
			return $name;
		}

		$base = (string) pathinfo( $name, PATHINFO_FILENAME );

		return ( '' === $base ? 'image' : $base ) . '.' . $ext;
	}

	/**
	 * Loads the wp-admin includes the sideload helpers live in.
	 *
	 * Unguarded, like `Installer`'s `upgrade.php` require: these three ship
	 * with every WordPress install, and a missing one is a broken core, not a
	 * condition this adapter should paper over.
	 *
	 * @return void
	 */
	private function require_admin_includes(): void {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
	}
}

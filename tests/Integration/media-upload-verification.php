<?php
/**
 * Runtime verification for remote image import (ADR 0031).
 *
 * Run: wp eval 'require "<abs path>/tests/Integration/media-upload-verification.php";'
 *
 * @package IsuDev\WPContentBridgeTests
 */

declare(strict_types=1);

use IsuDev\WPContentBridge\Application\Media\CreateMedia;
use IsuDev\WPContentBridge\Application\Media\MediaAccessManager;
use IsuDev\WPContentBridge\Application\Media\MediaUploadFailed;
use IsuDev\WPContentBridge\Infrastructure\WordPress\Installer;
use IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressMediaRepository;
use IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressMediaUploader;
use IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressTransientIdempotencyStore;

// phpcs:disable Squiz.Commenting.FunctionCommentThrowTag.Missing -- assertion helpers intentionally fail the runtime harness fast.
// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON is emitted to CLI only.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception messages are CLI diagnostics.
// phpcs:disable WordPress.WP.AlternativeFunctions -- a verifier writes fixture files directly and reports to STDERR.
// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- the two constants are inlined 1x1 image fixtures, so the verifier needs no binary files in the repository and no network.

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Must run inside WordPress via wp eval.\n" );
	exit( 1 );
}

Installer::activate();

require_once __DIR__ . '/support/featured-image-discarding-audit-log.php';

/**
 * Exercises the URL allowlist, byte-level type checks, and replay safety.
 */
final class WPCB_Media_Upload_Verification {

	private const MISSING_OPTION = '__wpcb_missing__';

	/** A 1x1 transparent PNG. */
	private const PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAAC0lEQVR42mNgAAIAAAUAAen63NgAAAAASUVORK5CYII=';

	/** A 1x1 GIF. */
	private const GIF_BASE64 = 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

	/**
	 * Collected failures.
	 *
	 * @var list<string>
	 */
	private array $failures = array();

	/**
	 * Saved options restored on exit.
	 *
	 * @var array<string, mixed>
	 */
	private array $saved_options = array();

	/**
	 * Absolute paths of fixture files to delete.
	 *
	 * @var list<string>
	 */
	private array $fixture_files = array();

	/**
	 * Attachment IDs created during the run.
	 *
	 * @var list<int>
	 */
	private array $attachment_ids = array();

	/**
	 * Current user before the run.
	 *
	 * @var int
	 */
	private int $original_user_id = 0;

	/**
	 * Runs the verification and prints a machine-readable result.
	 *
	 * @return void
	 */
	public function run(): void {
		$this->original_user_id = get_current_user_id();

		try {
			$this->set_up();
			$this->verify_private_and_metadata_urls_are_refused();
			$this->verify_non_http_schemes_are_refused();
			$this->verify_svg_is_refused();
			$this->verify_text_disguised_as_an_image_is_refused();
			$this->verify_image_imports_and_is_readable();
			$this->verify_extension_follows_the_bytes();
			$this->verify_replay_returns_the_same_attachment();
			$this->verify_oversize_is_refused();
			$this->verify_disabled_media_refuses();
		} catch ( Throwable $error ) {
			$this->failures[] = $error->getMessage();
		} finally {
			$this->tear_down();
		}

		echo wp_json_encode(
			array(
				'status'   => array() === $this->failures ? 'PASS' : 'FAIL',
				'failures' => $this->failures,
			)
		) . "\n";

		exit( array() === $this->failures ? 0 : 1 );
	}

	/**
	 * Enables the required flags and prepares fixture files.
	 *
	 * @return void
	 */
	private function set_up(): void {
		$administrators = get_users(
			array(
				'role'   => 'administrator',
				'number' => 1,
				'fields' => 'ID',
			)
		);
		$this->assert_true( array() !== $administrators, 'No administrator is available for the verification.' );
		wp_set_current_user( (int) $administrators[0] );

		foreach (
			array(
				Installer::WRITES_ENABLED_OPTION,
				Installer::MEDIA_READS_ENABLED_OPTION,
				Installer::MEDIA_UPLOADS_ENABLED_OPTION,
			) as $option
		) {
			$this->saved_options[ $option ] = get_option( $option, self::MISSING_OPTION );
		}

		update_option( Installer::WRITES_ENABLED_OPTION, true, false );
		update_option( Installer::MEDIA_READS_ENABLED_OPTION, true, false );
		update_option( Installer::MEDIA_UPLOADS_ENABLED_OPTION, true, false );
	}

	/**
	 * Proves the SSRF allowlist refuses private, loopback, and metadata hosts.
	 *
	 * These are literal addresses, so the assertion needs no network at all: a
	 * refusal here must happen before any socket is opened. `169.254.169.254`
	 * is the cloud metadata endpoint and is the reason this check exists.
	 *
	 * @return void
	 */
	private function verify_private_and_metadata_urls_are_refused(): void {
		foreach (
			array(
				'http://127.0.0.1/hero.jpg',
				'http://localhost/hero.jpg',
				'http://169.254.169.254/latest/meta-data/iam/security-credentials/',
				'http://10.0.0.5/hero.jpg',
				'http://192.168.1.10/hero.jpg',
				'http://172.16.0.9/hero.jpg',
				'http://[::1]/hero.jpg',
				'http://user:pass@example.com/hero.jpg',
				'http://example.com:22/hero.jpg',
			) as $url
		) {
			$this->assert_refused( $url, 'A non-public or malformed host was accepted: ' . $url );
		}
	}

	/**
	 * Proves non-HTTP schemes are refused, including local file access.
	 *
	 * @return void
	 */
	private function verify_non_http_schemes_are_refused(): void {
		foreach (
			array(
				'file:///etc/passwd',
				'ftp://example.com/hero.jpg',
				'gopher://example.com/hero.jpg',
				'data:image/png;base64,' . self::PNG_BASE64,
			) as $url
		) {
			$this->assert_refused( $url, 'A non-HTTP scheme was accepted: ' . $url );
		}
	}

	/**
	 * Proves SVG is refused even when served from the site's own uploads.
	 *
	 * An SVG is an XML document that can carry script, served from the site's
	 * origin. It must not be importable regardless of its extension.
	 *
	 * @return void
	 */
	private function verify_svg_is_refused(): void {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" width="1" height="1"><script>alert(1)</script></svg>';

		$this->assert_refused(
			$this->publish_fixture( 'wpcb-fixture-payload.svg', $svg ),
			'An SVG was accepted into the media library.'
		);
		$this->assert_refused(
			$this->publish_fixture( 'wpcb-fixture-disguised-svg.png', $svg ),
			'An SVG renamed to .png was accepted into the media library.'
		);
	}

	/**
	 * Proves the type comes from the bytes, not the extension.
	 *
	 * @return void
	 */
	private function verify_text_disguised_as_an_image_is_refused(): void {
		$this->assert_refused(
			$this->publish_fixture( 'wpcb-fixture-not-an-image.jpg', "<?php echo 'hello'; ?>\n" ),
			'A PHP text file named .jpg was accepted as an image.'
		);
	}

	/**
	 * Proves a real image imports, is stored with the right type, and reads back.
	 *
	 * @return void
	 */
	private function verify_image_imports_and_is_readable(): void {
		$url    = $this->publish_fixture( 'wpcb-fixture-hero.png', (string) base64_decode( self::PNG_BASE64, true ) );
		$result = $this->use_case()->execute(
			array(
				'source_url'      => $url,
				'idempotency_key' => 'wpcb-verify-import-1',
				'title'           => 'WPCB verification hero',
				'alt_text'        => 'Verification hero image',
			),
			get_current_user_id()
		);

		$this->attachment_ids[] = $result->media->id;

		$this->assert_true( $result->created, 'The first import did not report itself as created.' );
		$this->assert_true( 'image/png' === $result->media->mime_type, 'The stored MIME type is not image/png.' );
		$this->assert_true( 'WPCB verification hero' === $result->media->title, 'The caller title was not stored.' );
		$this->assert_true( 'Verification hero image' === $result->media->alt_text, 'The alternative text was not stored.' );
		$this->assert_true( 'attachment' === get_post_type( $result->media->id ), 'The import did not create an attachment.' );
		$this->assert_true(
			0 === wp_get_post_parent_id( $result->media->id ),
			'The import attached the image to a post; it must only create it.'
		);

		$metadata = wp_get_attachment_metadata( $result->media->id );
		$this->assert_true( is_array( $metadata ) && isset( $metadata['width'] ), 'Attachment metadata was not generated.' );
	}

	/**
	 * Proves a mislabelled but valid image is stored under the real extension.
	 *
	 * @return void
	 */
	private function verify_extension_follows_the_bytes(): void {
		$url    = $this->publish_fixture( 'wpcb-fixture-actually-gif.jpg', (string) base64_decode( self::GIF_BASE64, true ) );
		$result = $this->use_case()->execute(
			array(
				'source_url'      => $url,
				'idempotency_key' => 'wpcb-verify-import-2',
			),
			get_current_user_id()
		);

		$this->attachment_ids[] = $result->media->id;

		$this->assert_true(
			'image/gif' === $result->media->mime_type,
			'A GIF served as .jpg was stored as ' . $result->media->mime_type . ' instead of image/gif.'
		);
		$this->assert_true(
			str_ends_with( strtolower( $result->media->filename ), '.gif' ),
			'The stored filename kept the wrong extension: ' . $result->media->filename
		);
	}

	/**
	 * Proves the same key returns the first attachment and imports nothing new.
	 *
	 * @return void
	 */
	private function verify_replay_returns_the_same_attachment(): void {
		$url  = $this->publish_fixture( 'wpcb-fixture-replay.png', (string) base64_decode( self::PNG_BASE64, true ) );
		$key  = 'wpcb-verify-replay-1';
		$use  = $this->use_case();
		$args = array(
			'source_url'      => $url,
			'idempotency_key' => $key,
		);

		$first                  = $use->execute( $args, get_current_user_id() );
		$this->attachment_ids[] = $first->media->id;
		$before                 = $this->attachment_count();

		$second = $use->execute( $args, get_current_user_id() );

		$this->assert_true( $first->created, 'The first call did not report created.' );
		$this->assert_true( ! $second->created, 'The replay reported itself as a new import.' );
		$this->assert_true( $first->media->id === $second->media->id, 'The replay returned a different attachment.' );
		$this->assert_true( $before === $this->attachment_count(), 'The replay created a second attachment.' );
	}

	/**
	 * Proves the byte ceiling is enforced against the real response body.
	 *
	 * @return void
	 */
	private function verify_oversize_is_refused(): void {
		$oversize = str_repeat( 'a', 12582913 );

		$this->assert_refused(
			$this->publish_fixture( 'wpcb-fixture-oversize.png', $oversize ),
			'A file over the byte ceiling was accepted.'
		);
	}

	/**
	 * Proves the media feature gate refuses the import.
	 *
	 * @return void
	 */
	private function verify_disabled_media_refuses(): void {
		update_option( Installer::MEDIA_READS_ENABLED_OPTION, false, false );

		try {
			$this->use_case()->execute(
				array(
					'source_url'      => $this->publish_fixture( 'wpcb-fixture-gated.png', (string) base64_decode( self::PNG_BASE64, true ) ),
					'idempotency_key' => 'wpcb-verify-gated-1',
				),
				get_current_user_id()
			);
			$this->failures[] = 'An import succeeded while media reads were disabled.';
		} catch ( Throwable $expected ) {
			unset( $expected );
		} finally {
			update_option( Installer::MEDIA_READS_ENABLED_OPTION, true, false );
		}
	}

	/**
	 * Asserts one URL is refused and creates no attachment.
	 *
	 * @param string $url     Candidate source URL.
	 * @param string $message Failure message.
	 * @return void
	 */
	private function assert_refused( string $url, string $message ): void {
		$before = $this->attachment_count();

		try {
			$result                 = $this->use_case()->execute(
				array(
					'source_url'      => $url,
					'idempotency_key' => 'wpcb-verify-refuse-' . md5( $url ),
				),
				get_current_user_id()
			);
			$this->attachment_ids[] = $result->media->id;
			$this->failures[]       = $message;
		} catch ( MediaUploadFailed $expected ) {
			unset( $expected );
		} catch ( InvalidArgumentException $expected ) {
			unset( $expected );
		}

		$this->assert_true(
			$before === $this->attachment_count(),
			'A refused import still created an attachment: ' . $url
		);
	}

	/**
	 * Writes a fixture into the uploads directory and returns its public URL.
	 *
	 * The site's own host is used deliberately: it is reachable offline, and
	 * core exempts the site's own host from the IP checks, so this exercises
	 * the byte-level checks without depending on the public internet.
	 *
	 * @param string $filename Fixture file name.
	 * @param string $contents Fixture bytes.
	 * @return string
	 */
	private function publish_fixture( string $filename, string $contents ): string {
		$uploads = wp_get_upload_dir();
		$this->assert_true( empty( $uploads['error'] ), 'The uploads directory is unavailable.' );

		$path = trailingslashit( $uploads['basedir'] ) . $filename;
		$this->assert_true( false !== file_put_contents( $path, $contents ), 'Could not write the fixture ' . $filename . '.' );
		$this->fixture_files[] = $path;

		return trailingslashit( $uploads['baseurl'] ) . $filename;
	}

	/**
	 * Returns the current attachment count.
	 *
	 * @return int
	 */
	private function attachment_count(): int {
		$counts = (array) wp_count_posts( 'attachment' );

		return (int) ( $counts['inherit'] ?? 0 );
	}

	/**
	 * Builds the use case from real adapters.
	 *
	 * @return CreateMedia
	 */
	private function use_case(): CreateMedia {
		return new CreateMedia(
			new MediaAccessManager( (bool) get_option( Installer::MEDIA_READS_ENABLED_OPTION ) ),
			new WordPressMediaUploader( new WordPressMediaRepository() ),
			new WordPressMediaRepository(),
			new WordPressTransientIdempotencyStore(),
			new WPCB_Featured_Image_Discarding_Audit_Log()
		);
	}

	/**
	 * Deletes every created attachment and fixture, and restores options.
	 *
	 * @return void
	 */
	private function tear_down(): void {
		foreach ( array_unique( $this->attachment_ids ) as $attachment_id ) {
			wp_delete_attachment( $attachment_id, true );
		}

		foreach ( $this->fixture_files as $path ) {
			if ( file_exists( $path ) ) {
				wp_delete_file( $path );
			}
		}

		foreach ( $this->saved_options as $option => $value ) {
			if ( self::MISSING_OPTION === $value ) {
				delete_option( $option );
				continue;
			}
			update_option( $option, $value, false );
		}

		wp_set_current_user( $this->original_user_id );
	}

	/**
	 * Fails the run when the condition does not hold.
	 *
	 * @param bool   $condition Condition to assert.
	 * @param string $message   Failure message.
	 * @return void
	 */
	private function assert_true( bool $condition, string $message ): void {
		if ( ! $condition ) {
			throw new RuntimeException( $message );
		}
	}
}

( new WPCB_Media_Upload_Verification() )->run();

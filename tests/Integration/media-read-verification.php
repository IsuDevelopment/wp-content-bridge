<?php
/**
 * Runtime verification for the Media P0 read surface.
 *
 * Run: wp eval 'require "<abs path>/tests/Integration/media-read-verification.php";'
 *
 * @package IsuDev\WPContentBridgeTests
 */

declare(strict_types=1);

use IsuDev\WPContentBridge\Adapter\Abilities\MediaAbilities;
use IsuDev\WPContentBridge\Application\Media\GetMediaById;
use IsuDev\WPContentBridge\Application\Media\MediaAccessManager;
use IsuDev\WPContentBridge\Application\Media\SearchMedia;
use IsuDev\WPContentBridge\Infrastructure\WordPress\Installer;
use IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressContentRepository;
use IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressAttachmentMetadataRepository;
use IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressMediaRepository;

// phpcs:disable Squiz.Commenting.FunctionCommentThrowTag.Missing -- verifier fails fast with bounded CLI diagnostics.
// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI output, not HTML.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- bounded CLI diagnostics, not HTML.
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI diagnostic output.

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Must run inside WordPress via wp eval.\n" );
	exit( 1 );
}

/**
 * Exercises media registration, identity lookup, normalized fields, and cleanup.
 */
final class WPCB_Media_Read_Verification {

	/**
	 * Disposable attachment ID.
	 *
	 * @var int
	 */
	private int $attachment_id = 0;

	/**
	 * Disposable page ID.
	 *
	 * @var int
	 */
	private int $page_id = 0;

	/**
	 * Prior media-read option.
	 *
	 * @var bool
	 */
	private bool $original_enabled = false;

	/**
	 * Runs the verifier.
	 *
	 * @return void
	 */
	public function run(): void {
		$this->original_enabled = (bool) get_option( Installer::MEDIA_READS_ENABLED_OPTION, false );
		Installer::activate();
		update_option( Installer::MEDIA_READS_ENABLED_OPTION, true, false );

		try {
			wp_set_current_user( $this->administrator_id() );
			$this->create_fixture();

			$repository = new WordPressMediaRepository();
			$access     = new MediaAccessManager( true );
			$projection = new MediaAbilities(
				new SearchMedia( $access, $repository ),
				new GetMediaById( $access, $repository, new WordPressAttachmentMetadataRepository() )
			);

			if ( null === wp_get_ability( 'wp-content-bridge/get-media' ) ) {
				$projection->register_abilities();
			}

			$search = wp_get_ability( 'wp-content-bridge/get-media' );
			$get    = wp_get_ability( 'wp-content-bridge/get-media-by-id' );
			$this->assert_true( null !== $search && null !== $get, 'Media abilities were not registered.' );
			$this->assert_true( true === $search->check_permissions( array() ), 'Administrator lacks media permission.' );

			$by_id        = $this->execute_array( $search, array( 'id' => $this->attachment_id ) );
			$expected_url = wp_get_attachment_url( $this->attachment_id );
			$this->assert_true( is_string( $expected_url ), 'Fixture attachment URL is unavailable.' );
			$by_url      = $this->execute_array( $search, array( 'url' => $expected_url ) );
			$by_filename = $this->execute_array( $search, array( 'filename' => wp_basename( get_attached_file( $this->attachment_id ) ) ) );
			$detail      = $this->execute_array( $get, array( 'id' => $this->attachment_id ) );

			foreach ( array( $by_id, $by_url, $by_filename ) as $result ) {
				$this->assert_true( isset( $result['items'][0] ) && is_array( $result['items'][0] ), 'Search did not return an object envelope.' );
				$this->assert_media_item( $result['items'][0] );
			}
			$this->assert_true( isset( $detail['item'] ) && is_array( $detail['item'] ), 'Detail did not return an object envelope.' );
			$this->assert_media_item( $detail['item'] );

			$content = ( new WordPressContentRepository() )->get( $this->page_id, array(), array() );
			$this->assert_true( null !== $content, 'Fixture page could not be read.' );
			$summary = $content->to_array()['content'];
			$this->assert_true( $this->attachment_id === $summary['featured_image_id'], 'Featured-image ID was not returned.' );
			$this->assert_true( $expected_url === $summary['featured_image_url'], 'Featured-image URL was not returned with its ID.' );

			wp_set_current_user( 0 );
			$this->assert_true( false === $search->check_permissions( array() ), 'Anonymous media access was granted.' );

			echo "PASS: media reads (object envelope, ID/URL/filename lookup, normalized fields, featured-image identity, anonymous denial)\n";
		} finally {
			$this->cleanup();
		}
	}

	/**
	 * Creates a disposable PNG attachment and featured-image page.
	 *
	 * @return void
	 */
	private function create_fixture(): void {
		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- fixed transparent 1x1 PNG test fixture, never executable code or input.
		$bytes = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true );
		if ( ! is_string( $bytes ) ) {
			throw new RuntimeException( 'Could not decode fixture image.' );
		}
		$upload = wp_upload_bits( 'wpcb-media-fixture-' . wp_generate_password( 8, false, false ) . '.png', null, $bytes );
		if ( ! empty( $upload['error'] ) || ! is_string( $upload['file'] ) ) {
			throw new RuntimeException( 'Could not write fixture image.' );
		}

		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => 'image/png',
				'post_title'     => 'WPCB media fixture',
				'post_excerpt'   => 'Fixture caption',
				'post_content'   => 'Fixture description',
				'post_status'    => 'inherit',
			),
			$upload['file'],
			0,
			true
		);
		if ( is_wp_error( $attachment_id ) ) {
			throw new RuntimeException( 'Could not create fixture attachment.' );
		}
		$this->attachment_id = (int) $attachment_id;
		update_post_meta( $this->attachment_id, '_wp_attachment_image_alt', 'Fixture alt' );
		wp_update_attachment_metadata( $this->attachment_id, wp_generate_attachment_metadata( $this->attachment_id, $upload['file'] ) );

		$page_id = wp_insert_post(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'WPCB media identity fixture',
			),
			true
		);
		if ( is_wp_error( $page_id ) ) {
			throw new RuntimeException( 'Could not create fixture page.' );
		}
		$this->page_id = (int) $page_id;
		if ( ! set_post_thumbnail( $this->page_id, $this->attachment_id ) ) {
			throw new RuntimeException( 'Could not assign fixture featured image.' );
		}
	}

	/**
	 * Verifies the complete normalized field allowlist.
	 *
	 * @param array<string, mixed> $item Media item.
	 * @return void
	 */
	private function assert_media_item( array $item ): void {
		$expected = array( 'id', 'title', 'filename', 'url', 'alt_text', 'caption', 'description', 'mime_type' );
		sort( $expected );
		$actual = array_keys( $item );
		sort( $actual );
		$this->assert_true( $expected === $actual, 'Normalized media fields drifted.' );
		$this->assert_true( $this->attachment_id === $item['id'], 'Media ID drifted.' );
		$this->assert_true( 'Fixture alt' === $item['alt_text'], 'Media ALT drifted.' );
		$this->assert_true( 'Fixture caption' === $item['caption'], 'Media caption drifted.' );
		$this->assert_true( 'Fixture description' === $item['description'], 'Media description drifted.' );
		$this->assert_true( 'image/png' === $item['mime_type'], 'Media MIME drifted.' );
	}

	/**
	 * Executes an ability and requires an array result.
	 *
	 * @param object                    $ability Ability object.
	 * @param array<string, mixed>|null $input   Ability input.
	 * @return array<string, mixed>
	 */
	private function execute_array( object $ability, ?array $input = null ): array {
		$result = $ability->execute( $input );
		if ( is_wp_error( $result ) || ! is_array( $result ) ) {
			throw new RuntimeException( 'Ability execution failed.' );
		}

		return $result;
	}

	/**
	 * Resolves one administrator user ID.
	 *
	 * @return int
	 */
	private function administrator_id(): int {
		$ids = get_users(
			array(
				'role'   => 'administrator',
				'number' => 1,
				'fields' => 'ids',
			)
		);
		if ( ! isset( $ids[0] ) ) {
			throw new RuntimeException( 'No administrator is available.' );
		}

		return (int) $ids[0];
	}

	/**
	 * Cleans only disposable fixtures and restores the feature option.
	 *
	 * @return void
	 */
	private function cleanup(): void {
		wp_set_current_user( $this->administrator_id() );
		if ( 0 < $this->page_id ) {
			wp_delete_post( $this->page_id, true );
		}
		if ( 0 < $this->attachment_id ) {
			wp_delete_attachment( $this->attachment_id, true );
		}
		update_option( Installer::MEDIA_READS_ENABLED_OPTION, $this->original_enabled, false );
	}

	/**
	 * Fails with one bounded message.
	 *
	 * @param bool   $condition Condition.
	 * @param string $message   Failure message.
	 * @return void
	 */
	private function assert_true( bool $condition, string $message ): void {
		if ( ! $condition ) {
			throw new RuntimeException( $message );
		}
	}
}

( new WPCB_Media_Read_Verification() )->run();

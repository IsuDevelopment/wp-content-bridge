<?php
/**
 * Runtime verification for featured-image writes.
 *
 * Run: wp eval 'require "<abs path>/tests/Integration/featured-image-verification.php";'
 *
 * @package IsuDev\WPContentBridgeTests
 */

declare(strict_types=1);

use IsuDev\WPContentBridge\Application\ContentAccess\ContentAccessManager;
use IsuDev\WPContentBridge\Application\Media\MediaAccessManager;
use IsuDev\WPContentBridge\Application\Media\UpdateFeaturedImage;
use IsuDev\WPContentBridge\Application\Mutation\MutationConflict;
use IsuDev\WPContentBridge\Application\Mutation\MutationForbidden;
use IsuDev\WPContentBridge\Infrastructure\WordPress\Installer;
use IsuDev\WPContentBridge\Infrastructure\WordPress\PostVersionTokenFactory;
use IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressContentAccessSettingsRepository;
use IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressContentMutationRepository;
use IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressContentTypeCatalog;
use IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressFeaturedImageRepository;
use IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressMediaRepository;

// phpcs:disable Squiz.Commenting.FunctionCommentThrowTag.Missing -- assertion helpers intentionally fail the runtime harness fast.
// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON is emitted to CLI only.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception messages are CLI diagnostics.
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI diagnostic output, not a filesystem write.

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Must run inside WordPress via wp eval.\n" );
	exit( 1 );
}

Installer::activate();

require_once __DIR__ . '/support/featured-image-discarding-audit-log.php';

/**
 * Exercises assignment, removal, concurrency, and the assignability gate.
 */
final class WPCB_Featured_Image_Verification {

	private const MISSING_OPTION = '__wpcb_missing__';

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
	 * Fixture post ID.
	 *
	 * @var int
	 */
	private int $post_id = 0;

	/**
	 * Fixture image attachment ID.
	 *
	 * @var int
	 */
	private int $image_id = 0;

	/**
	 * Fixture second image attachment ID.
	 *
	 * @var int
	 */
	private int $other_image_id = 0;

	/**
	 * Fixture non-image attachment ID.
	 *
	 * @var int
	 */
	private int $document_id = 0;

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
			$this->verify_assignment_round_trip();
			$this->verify_replacement();
			$this->verify_removal_is_idempotent();
			$this->verify_non_image_is_refused();
			$this->verify_absent_attachment_is_refused();
			$this->verify_stale_token_is_refused();
			$this->verify_policy_off_refuses();
			$this->verify_token_moves_on_a_featured_image_write();
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
	 * Creates fixtures and enables exactly the required policy.
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
				Installer::MEDIA_WRITES_ENABLED_OPTION,
				WordPressContentAccessSettingsRepository::OPTION_NAME,
			) as $option
		) {
			$this->saved_options[ $option ] = get_option( $option, self::MISSING_OPTION );
		}

		update_option( Installer::WRITES_ENABLED_OPTION, true, false );
		update_option( Installer::MEDIA_READS_ENABLED_OPTION, true, false );
		update_option( Installer::MEDIA_WRITES_ENABLED_OPTION, true, false );
		update_option(
			WordPressContentAccessSettingsRepository::OPTION_NAME,
			array(
				'post' => array(
					'get_content'           => true,
					'update_featured_image' => true,
				),
			)
		);

		$post_id = wp_insert_post(
			array(
				'post_type'    => 'post',
				'post_status'  => 'publish',
				'post_title'   => 'WPCB featured image fixture',
				'post_content' => 'Fixture for the featured-image runtime verifier.',
			),
			true
		);
		$this->assert_true( ! is_wp_error( $post_id ), 'Could not create the fixture post.' );
		$this->post_id = (int) $post_id;

		$this->image_id       = $this->create_attachment( 'wpcb-fixture-hero.jpg', 'image/jpeg' );
		$this->other_image_id = $this->create_attachment( 'wpcb-fixture-alt.jpg', 'image/jpeg' );
		$this->document_id    = $this->create_attachment( 'wpcb-fixture-doc.pdf', 'application/pdf' );
	}

	/**
	 * Creates one attachment fixture without touching the filesystem.
	 *
	 * @param string $filename  Attachment file name.
	 * @param string $mime_type Attachment MIME type.
	 * @return int
	 */
	private function create_attachment( string $filename, string $mime_type ): int {
		$attachment_id = wp_insert_post(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_title'     => $filename,
				'post_mime_type' => $mime_type,
				'guid'           => 'https://example.test/' . $filename,
			),
			true
		);
		$this->assert_true( ! is_wp_error( $attachment_id ), 'Could not create the attachment fixture ' . $filename . '.' );

		/*
		 * `wp_attachment_is_image()` reads `_wp_attached_file` when the MIME
		 * type is not decisive, so the fixture sets it. No file is created:
		 * nothing in this path reads the bytes.
		 */
		update_post_meta( (int) $attachment_id, '_wp_attached_file', $filename );

		return (int) $attachment_id;
	}

	/**
	 * Proves an assignment persists and comes back as the effective attachment.
	 *
	 * @return void
	 */
	private function verify_assignment_round_trip(): void {
		$result = $this->use_case()->execute(
			array(
				'post_id'       => $this->post_id,
				'version_token' => $this->current_token(),
				'attachment_id' => $this->image_id,
			),
			get_current_user_id()
		);

		$this->assert_true(
			$this->image_id === $result->featured_image?->id,
			'The write did not return the assigned attachment as effective.'
		);
		$this->assert_true(
			get_post_thumbnail_id( $this->post_id ) === $this->image_id,
			'The assignment did not persist to storage.'
		);
		$this->assert_true(
			array( 'featured_image' ) === $result->mutation->changed_fields,
			'The mutation reported unexpected changed fields.'
		);
	}

	/**
	 * Proves a second assignment replaces the first rather than adding to it.
	 *
	 * @return void
	 */
	private function verify_replacement(): void {
		$this->use_case()->execute(
			array(
				'post_id'       => $this->post_id,
				'version_token' => $this->current_token(),
				'attachment_id' => $this->other_image_id,
			),
			get_current_user_id()
		);

		$this->assert_true(
			get_post_thumbnail_id( $this->post_id ) === $this->other_image_id,
			'The replacement assignment did not take effect.'
		);
	}

	/**
	 * Proves removal works and that a repeated removal is not an error.
	 *
	 * @return void
	 */
	private function verify_removal_is_idempotent(): void {
		$result = $this->use_case()->execute(
			array(
				'post_id'       => $this->post_id,
				'version_token' => $this->current_token(),
				'attachment_id' => null,
			),
			get_current_user_id()
		);

		$this->assert_true( null === $result->featured_image, 'Removal still reported an effective attachment.' );
		$this->assert_true( ! has_post_thumbnail( $this->post_id ), 'The featured image was not removed from storage.' );

		/*
		 * `delete_post_thumbnail()` returns false when nothing is assigned,
		 * which is indistinguishable from a genuine failure. The adapter
		 * asserts the post-condition instead, so a second removal must succeed.
		 */
		$repeated = $this->use_case()->execute(
			array(
				'post_id'       => $this->post_id,
				'version_token' => $this->current_token(),
				'attachment_id' => null,
			),
			get_current_user_id()
		);
		$this->assert_true( null === $repeated->featured_image, 'A repeated removal did not stay removed.' );
	}

	/**
	 * Proves a non-image attachment is refused.
	 *
	 * WordPress accepts any attachment ID as a thumbnail, so without this gate
	 * a PDF would be assigned and rendered in a public image slot.
	 *
	 * @return void
	 */
	private function verify_non_image_is_refused(): void {
		$this->assert_refused(
			$this->document_id,
			'A non-image attachment was accepted as a featured image.'
		);
	}

	/**
	 * Proves an absent attachment ID is refused with the same error as a
	 * non-image, so the response cannot be used to probe which IDs exist.
	 *
	 * @return void
	 */
	private function verify_absent_attachment_is_refused(): void {
		$absent = $this->document_id + 100000;
		$this->assert_true(
			! ( get_post( $absent ) instanceof WP_Post ),
			'The chosen absent attachment ID unexpectedly exists.'
		);
		$this->assert_refused( $absent, 'An absent attachment ID was accepted.' );
	}

	/**
	 * Asserts one attachment ID is refused and nothing is written.
	 *
	 * @param int    $attachment_id Attachment ID to attempt.
	 * @param string $message       Failure message.
	 * @return void
	 */
	private function assert_refused( int $attachment_id, string $message ): void {
		$before = get_post_thumbnail_id( $this->post_id );

		try {
			$this->use_case()->execute(
				array(
					'post_id'       => $this->post_id,
					'version_token' => $this->current_token(),
					'attachment_id' => $attachment_id,
				),
				get_current_user_id()
			);
			$this->failures[] = $message;
		} catch ( MutationForbidden $expected ) {
			unset( $expected );
		}

		$this->assert_true(
			get_post_thumbnail_id( $this->post_id ) === $before,
			'A refused write still changed the stored featured image.'
		);
	}

	/**
	 * Proves a stale token is refused before anything is written.
	 *
	 * @return void
	 */
	private function verify_stale_token_is_refused(): void {
		$before = get_post_thumbnail_id( $this->post_id );

		try {
			$this->use_case()->execute(
				array(
					'post_id'       => $this->post_id,
					'version_token' => str_repeat( 'a', 16 ) . ':2020-01-01 00:00:00',
					'attachment_id' => $this->image_id,
				),
				get_current_user_id()
			);
			$this->failures[] = 'A stale version token was accepted.';
		} catch ( MutationConflict $expected ) {
			unset( $expected );
		}

		$this->assert_true(
			get_post_thumbnail_id( $this->post_id ) === $before,
			'A conflicting write still changed the stored featured image.'
		);
	}

	/**
	 * Proves the per-type policy is enforced independently of read access.
	 *
	 * @return void
	 */
	private function verify_policy_off_refuses(): void {
		update_option(
			WordPressContentAccessSettingsRepository::OPTION_NAME,
			array( 'post' => array( 'get_content' => true ) )
		);

		try {
			$this->use_case()->execute(
				array(
					'post_id'       => $this->post_id,
					'version_token' => $this->current_token(),
					'attachment_id' => $this->image_id,
				),
				get_current_user_id()
			);
			$this->failures[] = 'A type without the featured-image policy was still written.';
		} catch ( MutationForbidden $expected ) {
			unset( $expected );
		} finally {
			update_option(
				WordPressContentAccessSettingsRepository::OPTION_NAME,
				array(
					'post' => array(
						'get_content'           => true,
						'update_featured_image' => true,
					),
				)
			);
		}
	}

	/**
	 * Proves the write moves the version token, so a chained caller must use
	 * the returned one.
	 *
	 * A featured image is postmeta, and the post row is untouched. Before the
	 * token covered postmeta, two agents could both write here and the second
	 * would silently overwrite the first with no conflict.
	 *
	 * @return void
	 */
	private function verify_token_moves_on_a_featured_image_write(): void {
		$before = $this->current_token();
		$result = $this->use_case()->execute(
			array(
				'post_id'       => $this->post_id,
				'version_token' => $before,
				'attachment_id' => $this->image_id,
			),
			get_current_user_id()
		);

		$after = $result->mutation->version->to_string();
		$this->assert_true( $before !== $after, 'The version token did not move after a featured-image write.' );
		$this->assert_true( $after === $this->current_token(), 'The returned token is not the post\'s current token.' );

		try {
			$this->use_case()->execute(
				array(
					'post_id'       => $this->post_id,
					'version_token' => $before,
					'attachment_id' => $this->other_image_id,
				),
				get_current_user_id()
			);
			$this->failures[] = 'The pre-write token was still accepted after the write.';
		} catch ( MutationConflict $expected ) {
			unset( $expected );
		}
	}

	/**
	 * Returns the fixture post's current version token.
	 *
	 * @return string
	 */
	private function current_token(): string {
		$post = get_post( $this->post_id );
		$this->assert_true( $post instanceof WP_Post, 'The fixture post disappeared.' );

		return PostVersionTokenFactory::for_post( $post )->to_string();
	}

	/**
	 * Builds the use case from real adapters.
	 *
	 * @return UpdateFeaturedImage
	 */
	private function use_case(): UpdateFeaturedImage {
		return new UpdateFeaturedImage(
			new ContentAccessManager(
				new WordPressContentAccessSettingsRepository(),
				new WordPressContentTypeCatalog()
			),
			new MediaAccessManager( (bool) get_option( Installer::MEDIA_READS_ENABLED_OPTION ) ),
			new WordPressContentMutationRepository(),
			new WordPressFeaturedImageRepository(),
			new WordPressMediaRepository(),
			new WPCB_Featured_Image_Discarding_Audit_Log()
		);
	}

	/**
	 * Removes fixtures and restores every touched option.
	 *
	 * @return void
	 */
	private function tear_down(): void {
		foreach ( array( $this->post_id, $this->image_id, $this->other_image_id, $this->document_id ) as $id ) {
			if ( 0 !== $id ) {
				wp_delete_post( $id, true );
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
	 * Records a failure when the condition does not hold.
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

( new WPCB_Featured_Image_Verification() )->run();

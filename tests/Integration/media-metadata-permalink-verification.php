<?php
/**
 * Runtime verification for attachment-metadata edits and permalink changes.
 *
 * Run: wp eval 'require "<abs path>/tests/Integration/media-metadata-permalink-verification.php";'
 *
 * @package IsuDev\WPContentBridgeTests
 */

declare(strict_types=1);

use IsuDev\WPContentBridge\Application\ContentAccess\ContentAccessManager;
use IsuDev\WPContentBridge\Application\Media\GetMediaById;
use IsuDev\WPContentBridge\Application\Media\MediaAccessManager;
use IsuDev\WPContentBridge\Application\Media\UpdateMedia;
use IsuDev\WPContentBridge\Application\Mutation\MutationConflict;
use IsuDev\WPContentBridge\Application\Mutation\MutationForbidden;
use IsuDev\WPContentBridge\Application\Mutation\PermalinkUnavailable;
use IsuDev\WPContentBridge\Application\Mutation\UpdatePermalink;
use IsuDev\WPContentBridge\Infrastructure\WordPress\Installer;
use IsuDev\WPContentBridge\Infrastructure\WordPress\PostVersionTokenFactory;
use IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressAttachmentMetadataRepository;
use IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressContentAccessSettingsRepository;
use IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressContentMutationRepository;
use IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressContentTypeCatalog;
use IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressMediaRepository;
use IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressPermalinkRepository;
use IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressSlugNormalizer;

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
 * Exercises both remaining write surfaces against real WordPress.
 */
final class WPCB_Media_Metadata_Permalink_Verification {

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
	 * Second fixture post, used to occupy a slug.
	 *
	 * @var int
	 */
	private int $rival_post_id = 0;

	/**
	 * Fixture attachment ID.
	 *
	 * @var int
	 */
	private int $attachment_id = 0;

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
			$this->verify_read_issues_a_usable_token();
			$this->verify_metadata_round_trip();
			$this->verify_partial_edit_leaves_other_fields_alone();
			$this->verify_metadata_stale_token_is_refused();
			$this->verify_permalink_round_trip_reports_both_urls();
			$this->verify_taken_slug_is_refused_not_uniquified();
			$this->verify_unusable_slug_is_refused();
			$this->verify_permalink_policy_off_refuses();
			$this->verify_permalink_stale_token_is_refused();
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
		$this->allow_permalinks( true );

		$this->post_id       = $this->create_post( 'WPCB permalink fixture', 'wpcb-permalink-fixture' );
		$this->rival_post_id = $this->create_post( 'WPCB permalink rival', 'wpcb-permalink-rival' );

		$attachment_id = wp_insert_post(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_title'     => 'WPCB metadata fixture',
				'post_excerpt'   => 'original caption',
				'post_content'   => 'original description',
				'post_mime_type' => 'image/jpeg',
				'guid'           => 'https://example.test/wpcb-metadata-fixture.jpg',
			),
			true
		);
		$this->assert_true( ! is_wp_error( $attachment_id ), 'Could not create the attachment fixture.' );
		$this->attachment_id = (int) $attachment_id;
		update_post_meta( $this->attachment_id, '_wp_attached_file', 'wpcb-metadata-fixture.jpg' );
		update_post_meta( $this->attachment_id, '_wp_attachment_image_alt', 'original alt' );
	}

	/**
	 * Creates one published fixture post.
	 *
	 * @param string $title Post title.
	 * @param string $slug  Post slug.
	 * @return int
	 */
	private function create_post( string $title, string $slug ): int {
		$post_id = wp_insert_post(
			array(
				'post_type'    => 'post',
				'post_status'  => 'publish',
				'post_title'   => $title,
				'post_name'    => $slug,
				'post_content' => 'Fixture.',
			),
			true
		);
		$this->assert_true( ! is_wp_error( $post_id ), 'Could not create the fixture post ' . $slug . '.' );

		return (int) $post_id;
	}

	/**
	 * Sets the per-type policy, optionally granting permalink writes.
	 *
	 * @param bool $permalinks Whether to permit permalink writes.
	 * @return void
	 */
	private function allow_permalinks( bool $permalinks ): void {
		update_option(
			WordPressContentAccessSettingsRepository::OPTION_NAME,
			array(
				'post' => array(
					'get_content'      => true,
					'update_permalink' => $permalinks,
				),
			)
		);
	}

	/**
	 * Proves the media read hands out a token the write accepts.
	 *
	 * Without this the write contract would be unreachable: no other read
	 * issues a current token for an attachment.
	 *
	 * @return void
	 */
	private function verify_read_issues_a_usable_token(): void {
		$read = ( new GetMediaById(
			new MediaAccessManager( true ),
			new WordPressMediaRepository(),
			new WordPressAttachmentMetadataRepository()
		) )->execute( $this->attachment_id );

		$this->assert_true( $read->media->id === $this->attachment_id, 'The read returned the wrong attachment.' );
		$this->assert_true( '' !== $read->version->to_string(), 'The read issued no version token.' );

		$result = $this->media_use_case()->execute(
			array(
				'attachment_id' => $this->attachment_id,
				'version_token' => $read->version->to_string(),
				'alt_text'      => 'token accepted',
			),
			get_current_user_id()
		);

		$this->assert_true( 'token accepted' === $result->media->alt_text, 'The token from the read was not accepted by the write.' );
	}

	/**
	 * Proves all four fields persist and come back re-read.
	 *
	 * @return void
	 */
	private function verify_metadata_round_trip(): void {
		$result = $this->media_use_case()->execute(
			array(
				'attachment_id' => $this->attachment_id,
				'version_token' => $this->attachment_token(),
				'title'         => 'Edited title',
				'alt_text'      => 'Edited alt',
				'caption'       => 'Edited caption',
				'description'   => 'Edited description',
			),
			get_current_user_id()
		);

		$this->assert_true( 'Edited title' === $result->media->title, 'The title was not stored.' );
		$this->assert_true( 'Edited alt' === $result->media->alt_text, 'The alternative text was not stored.' );
		$this->assert_true( 'Edited caption' === $result->media->caption, 'The caption was not stored.' );
		$this->assert_true( 'Edited description' === $result->media->description, 'The description was not stored.' );
		$this->assert_true(
			array( 'title', 'alt_text', 'caption', 'description' ) === $result->changed_fields,
			'The result reported unexpected changed fields.'
		);
		$this->assert_true(
			'Edited alt' === get_post_meta( $this->attachment_id, '_wp_attachment_image_alt', true ),
			'The alternative text did not reach postmeta.'
		);
		$this->assert_true(
			$result->version->to_string() === $this->attachment_token(),
			'The returned token is not the attachment current token.'
		);
	}

	/**
	 * Proves an edit naming one field leaves the other three untouched.
	 *
	 * @return void
	 */
	private function verify_partial_edit_leaves_other_fields_alone(): void {
		$result = $this->media_use_case()->execute(
			array(
				'attachment_id' => $this->attachment_id,
				'version_token' => $this->attachment_token(),
				'caption'       => 'Only the caption changed',
			),
			get_current_user_id()
		);

		$this->assert_true( array( 'caption' ) === $result->changed_fields, 'A partial edit reported the wrong fields.' );
		$this->assert_true( 'Only the caption changed' === $result->media->caption, 'The caption was not stored.' );
		$this->assert_true( 'Edited title' === $result->media->title, 'A partial edit overwrote the title.' );
		$this->assert_true( 'Edited alt' === $result->media->alt_text, 'A partial edit overwrote the alternative text.' );
		$this->assert_true( 'Edited description' === $result->media->description, 'A partial edit overwrote the description.' );
	}

	/**
	 * Proves a stale attachment token is refused with nothing written.
	 *
	 * @return void
	 */
	private function verify_metadata_stale_token_is_refused(): void {
		$before = get_post_meta( $this->attachment_id, '_wp_attachment_image_alt', true );

		try {
			$this->media_use_case()->execute(
				array(
					'attachment_id' => $this->attachment_id,
					'version_token' => str_repeat( 'b', 16 ) . ':2020-01-01 00:00:00',
					'alt_text'      => 'should not persist',
				),
				get_current_user_id()
			);
			$this->failures[] = 'A stale attachment token was accepted.';
		} catch ( MutationConflict $expected ) {
			unset( $expected );
		}

		$this->assert_true(
			get_post_meta( $this->attachment_id, '_wp_attachment_image_alt', true ) === $before,
			'A conflicting attachment write still changed storage.'
		);
	}

	/**
	 * Proves a slug change persists and reports both URLs.
	 *
	 * @return void
	 */
	private function verify_permalink_round_trip_reports_both_urls(): void {
		$result = $this->permalink_use_case()->execute(
			array(
				'post_id'       => $this->post_id,
				'version_token' => $this->post_token( $this->post_id ),
				'slug'          => 'WPCB Renamed Fixture!',
			),
			get_current_user_id()
		);

		$document = $result->to_array();
		$this->assert_true( 'wpcb-renamed-fixture' === $result->after['slug'], 'The slug was not normalized and stored.' );
		$this->assert_true(
			'wpcb-permalink-fixture' === $result->before['slug'],
			'The result did not report the previous slug.'
		);
		$this->assert_true(
			str_contains( $document['permalink']['previous_url'], 'wpcb-permalink-fixture' ),
			'The result did not report a usable previous URL to redirect from.'
		);
		$this->assert_true(
			str_contains( $document['permalink']['url'], 'wpcb-renamed-fixture' ),
			'The result did not report the new URL.'
		);
		$this->assert_true(
			'wpcb-renamed-fixture' === get_post_field( 'post_name', $this->post_id ),
			'The slug did not persist to storage.'
		);
		$this->assert_true( array( 'slug' ) === $result->mutation->changed_fields, 'The mutation reported unexpected fields.' );
	}

	/**
	 * Proves a taken slug is refused rather than silently uniquified.
	 *
	 * WordPress would store `wpcb-permalink-rival-2` and report success, which
	 * hands the caller a URL it never asked for.
	 *
	 * @return void
	 */
	private function verify_taken_slug_is_refused_not_uniquified(): void {
		$before = get_post_field( 'post_name', $this->post_id );

		try {
			$this->permalink_use_case()->execute(
				array(
					'post_id'       => $this->post_id,
					'version_token' => $this->post_token( $this->post_id ),
					'slug'          => 'wpcb-permalink-rival',
				),
				get_current_user_id()
			);
			$this->failures[] = 'A slug already in use was accepted.';
		} catch ( PermalinkUnavailable $expected ) {
			unset( $expected );
		}

		$this->assert_true(
			get_post_field( 'post_name', $this->post_id ) === $before,
			'A refused slug change still altered storage.'
		);
		$this->assert_true(
			'wpcb-permalink-rival' === get_post_field( 'post_name', $this->rival_post_id ),
			'The rival post slug was disturbed.'
		);
	}

	/**
	 * Proves a slug that normalizes to nothing is refused.
	 *
	 * An empty `post_name` makes WordPress regenerate one from the title, so
	 * accepting this would hand back an unrequested URL with no error.
	 *
	 * @return void
	 */
	private function verify_unusable_slug_is_refused(): void {
		$before = get_post_field( 'post_name', $this->post_id );

		foreach ( array( '!!!', '---', '###' ) as $candidate ) {
			try {
				$this->permalink_use_case()->execute(
					array(
						'post_id'       => $this->post_id,
						'version_token' => $this->post_token( $this->post_id ),
						'slug'          => $candidate,
					),
					get_current_user_id()
				);
				$this->failures[] = 'A slug normalizing to nothing was accepted: ' . $candidate;
			} catch ( InvalidArgumentException $expected ) {
				unset( $expected );
			}
		}

		$this->assert_true(
			get_post_field( 'post_name', $this->post_id ) === $before,
			'A refused slug change still altered storage.'
		);
	}

	/**
	 * Proves the per-type policy gates permalink writes independently of reads.
	 *
	 * @return void
	 */
	private function verify_permalink_policy_off_refuses(): void {
		$this->allow_permalinks( false );
		$before = get_post_field( 'post_name', $this->post_id );

		try {
			$this->permalink_use_case()->execute(
				array(
					'post_id'       => $this->post_id,
					'version_token' => $this->post_token( $this->post_id ),
					'slug'          => 'policy-should-block-this',
				),
				get_current_user_id()
			);
			$this->failures[] = 'A permalink write succeeded with the policy off.';
		} catch ( MutationForbidden $expected ) {
			unset( $expected );
		} finally {
			$this->allow_permalinks( true );
		}

		$this->assert_true(
			get_post_field( 'post_name', $this->post_id ) === $before,
			'A policy-refused slug change still altered storage.'
		);
	}

	/**
	 * Proves a stale post token is refused, and that a successful slug change
	 * moves the token so a chained caller must use the returned one.
	 *
	 * @return void
	 */
	private function verify_permalink_stale_token_is_refused(): void {
		$token  = $this->post_token( $this->post_id );
		$result = $this->permalink_use_case()->execute(
			array(
				'post_id'       => $this->post_id,
				'version_token' => $token,
				'slug'          => 'wpcb-token-moved',
			),
			get_current_user_id()
		);

		$after = $result->mutation->version->to_string();
		$this->assert_true( $token !== $after, 'A slug change did not move the version token.' );

		try {
			$this->permalink_use_case()->execute(
				array(
					'post_id'       => $this->post_id,
					'version_token' => $token,
					'slug'          => 'wpcb-should-not-apply',
				),
				get_current_user_id()
			);
			$this->failures[] = 'The pre-write token was still accepted after the write.';
		} catch ( MutationConflict $expected ) {
			unset( $expected );
		}

		$this->assert_true(
			'wpcb-token-moved' === get_post_field( 'post_name', $this->post_id ),
			'The refused second write altered the slug.'
		);
	}

	/**
	 * Returns the attachment current version token.
	 *
	 * @return string
	 */
	private function attachment_token(): string {
		$version = ( new WordPressAttachmentMetadataRepository() )->current_version( $this->attachment_id );
		$this->assert_true( null !== $version, 'The attachment fixture has no version token.' );

		return (string) $version?->to_string();
	}

	/**
	 * Returns one post current version token.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	private function post_token( int $post_id ): string {
		$post = get_post( $post_id );
		$this->assert_true( $post instanceof WP_Post, 'The fixture post disappeared.' );

		return PostVersionTokenFactory::for_post( $post )->to_string();
	}

	/**
	 * Builds the attachment-metadata use case from real adapters.
	 *
	 * @return UpdateMedia
	 */
	private function media_use_case(): UpdateMedia {
		return new UpdateMedia(
			new MediaAccessManager( (bool) get_option( Installer::MEDIA_READS_ENABLED_OPTION ) ),
			new WordPressAttachmentMetadataRepository(),
			new WordPressMediaRepository(),
			new WPCB_Featured_Image_Discarding_Audit_Log()
		);
	}

	/**
	 * Builds the permalink use case from real adapters.
	 *
	 * @return UpdatePermalink
	 */
	private function permalink_use_case(): UpdatePermalink {
		return new UpdatePermalink(
			new ContentAccessManager(
				new WordPressContentAccessSettingsRepository(),
				new WordPressContentTypeCatalog()
			),
			new WordPressContentMutationRepository(),
			new WordPressPermalinkRepository(),
			new WordPressSlugNormalizer(),
			new WPCB_Featured_Image_Discarding_Audit_Log()
		);
	}

	/**
	 * Removes fixtures and restores every touched option.
	 *
	 * @return void
	 */
	private function tear_down(): void {
		foreach ( array( $this->post_id, $this->rival_post_id, $this->attachment_id ) as $id ) {
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

( new WPCB_Media_Metadata_Permalink_Verification() )->run();

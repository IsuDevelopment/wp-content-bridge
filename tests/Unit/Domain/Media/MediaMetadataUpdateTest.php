<?php
/**
 * Attachment-metadata input validation.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Tests\Unit\Domain\Media;

use InvalidArgumentException;
use IsuDev\WPContentBridge\Domain\Media\MediaMetadataUpdate;
use PHPUnit\Framework\TestCase;

/**
 * Verifies field bounds and that an empty update cannot report success.
 */
final class MediaMetadataUpdateTest extends TestCase {

	private const TOKEN = 'abcdef0123456789:2026-07-20 12:30:00';

	/**
	 * Only the fields actually present are carried, in a stable order.
	 */
	public function test_carries_only_present_fields(): void {
		$update = MediaMetadataUpdate::from_input(
			array(
				'attachment_id' => 7,
				'version_token' => self::TOKEN,
				'alt_text'      => 'A hero image',
			)
		);

		self::assertSame( array( 'alt_text' ), $update->changed_fields() );
		self::assertSame( array( 'alt_text' => 'A hero image' ), $update->fields );
	}

	/**
	 * An update naming no field is refused.
	 *
	 * Otherwise it would consume a token, pass every check, write nothing, and
	 * report success - which a caller reads as "the edit was applied".
	 */
	public function test_refuses_an_update_with_no_fields(): void {
		$this->expectException( InvalidArgumentException::class );
		MediaMetadataUpdate::from_input(
			array(
				'attachment_id' => 7,
				'version_token' => self::TOKEN,
			)
		);
	}

	/**
	 * An empty string is a real value: it clears the field.
	 */
	public function test_an_empty_string_is_a_clearing_value(): void {
		$update = MediaMetadataUpdate::from_input(
			array(
				'attachment_id' => 7,
				'version_token' => self::TOKEN,
				'alt_text'      => '',
			)
		);

		self::assertSame( array( 'alt_text' => '' ), $update->fields );
	}

	/**
	 * Null is refused, because clearing already has one spelling.
	 */
	public function test_refuses_null(): void {
		$this->expectException( InvalidArgumentException::class );
		MediaMetadataUpdate::from_input(
			array(
				'attachment_id' => 7,
				'version_token' => self::TOKEN,
				'caption'       => null,
			)
		);
	}

	/**
	 * The title has a tighter bound than the long text fields.
	 */
	public function test_title_and_text_bounds_differ(): void {
		$long = str_repeat( 'a', 501 );

		$accepted = MediaMetadataUpdate::from_input(
			array(
				'attachment_id' => 7,
				'version_token' => self::TOKEN,
				'caption'       => $long,
			)
		);
		self::assertSame( array( 'caption' ), $accepted->changed_fields() );

		$this->expectException( InvalidArgumentException::class );
		MediaMetadataUpdate::from_input(
			array(
				'attachment_id' => 7,
				'version_token' => self::TOKEN,
				'title'         => $long,
			)
		);
	}

	/**
	 * An unsupported field is rejected rather than ignored.
	 */
	public function test_rejects_unsupported_fields(): void {
		$this->expectException( InvalidArgumentException::class );
		MediaMetadataUpdate::from_input(
			array(
				'attachment_id' => 7,
				'version_token' => self::TOKEN,
				'mime_type'     => 'image/svg+xml',
			)
		);
	}
}

<?php
/**
 * Permalink input validation.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Tests\Unit\Domain\Mutation;

use InvalidArgumentException;
use IsuDev\WPContentBridge\Domain\Mutation\PermalinkUpdate;
use PHPUnit\Framework\TestCase;

/**
 * Verifies that a slug is normalized and that an unusable one is refused.
 */
final class PermalinkUpdateTest extends TestCase {

	private const TOKEN = 'abcdef0123456789:2026-07-20 12:30:00';

	/**
	 * The raw slug is carried through untouched.
	 *
	 * Normalization belongs to the application layer via `SlugNormalizer`,
	 * because it is WordPress's `sanitize_title()` behaviour and the domain
	 * layer does not call WordPress.
	 */
	public function test_carries_the_requested_slug_untouched(): void {
		$update = PermalinkUpdate::from_input( $this->input( '  Hello World!  ' ) );

		self::assertSame( 'Hello World!', $update->requested_slug );
	}

	/**
	 * A blank slug is refused here, before any normalization is attempted.
	 */
	public function test_refuses_a_blank_slug(): void {
		$refusals = 0;
		foreach ( array( '', '   ' ) as $candidate ) {
			try {
				PermalinkUpdate::from_input( $this->input( $candidate ) );
				self::fail( 'A blank slug must be refused: ' . $candidate );
			} catch ( InvalidArgumentException $expected ) {
				unset( $expected );
				++$refusals;
			}
		}

		self::assertSame( 2, $refusals );
	}

	/**
	 * The audit field list names the field, never the slug.
	 */
	public function test_changed_fields_never_carry_the_slug(): void {
		$update = PermalinkUpdate::from_input( $this->input( 'secret-campaign-page' ) );

		self::assertSame( array( 'slug' ), $update->changed_fields() );
		self::assertStringNotContainsString( 'secret', implode( '|', $update->changed_fields() ) );
	}

	/**
	 * An unexpected field is rejected rather than ignored.
	 */
	public function test_rejects_unexpected_fields(): void {
		$input             = $this->input( 'fine' );
		$input['redirect'] = true;

		$this->expectException( InvalidArgumentException::class );
		PermalinkUpdate::from_input( $input );
	}

	/**
	 * An over-long slug is refused before normalization.
	 */
	public function test_refuses_an_over_long_slug(): void {
		$this->expectException( InvalidArgumentException::class );
		PermalinkUpdate::from_input( $this->input( str_repeat( 'a', 201 ) ) );
	}

	/**
	 * Builds valid input around one slug.
	 *
	 * @param string $slug Candidate slug.
	 * @return array<string, mixed>
	 */
	private function input( string $slug ): array {
		return array(
			'post_id'       => 42,
			'version_token' => self::TOKEN,
			'slug'          => $slug,
		);
	}
}

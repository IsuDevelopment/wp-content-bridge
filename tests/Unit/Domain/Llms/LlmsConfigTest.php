<?php
/**
 * Unit tests for the llms.txt configuration DTO.
 *
 * @package IsuDev\WPContentBridge\Tests
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Tests\Unit\Domain\Llms;

use InvalidArgumentException;
use IsuDev\WPContentBridge\Domain\Llms\LlmsConfig;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the fixed field allowlist, same-site URL enforcement, structural
 * bounds, and the `to_array()`/`from_input()` round trip.
 */
final class LlmsConfigTest extends TestCase {

	/**
	 * Site origin passed to `from_input()` as the explicit, non-input parameter.
	 *
	 * @var string
	 */
	private const SITE_URL = 'https://example.com';

	/**
	 * A minimal valid wire document, reused and overridden per test.
	 *
	 * @return array<string, mixed>
	 */
	private function valid_input(): array {
		return array(
			'site_title'            => 'Example Site',
			'site_summary'          => 'A site about examples.',
			'introduction'          => 'Welcome to the site.',
			'enabled_post_types'    => array( 'post', 'page' ),
			'sections'              => array(
				array(
					'key'   => 'post',
					'label' => 'Blog Posts',
				),
				array(
					'key'   => 'page',
					'label' => 'Pages',
				),
			),
			'group_by_section'      => true,
			'show_excerpts'         => true,
			'excerpt_length'        => 160,
			'max_items_per_section' => 50,
			'curated_links'         => array(
				array(
					'title'   => 'About',
					'url'     => 'https://example.com/about/',
					'section' => 'page',
				),
			),
		);
	}

	/**
	 * A complete, valid document is accepted and round-trips through `to_array()`.
	 */
	public function test_builds_complete_configuration(): void {
		$config = LlmsConfig::from_input( $this->valid_input(), self::SITE_URL );

		self::assertSame( 'https://example.com', $config->site_url );
		self::assertSame( 'Example Site', $config->site_title );
		self::assertTrue( $config->group_by_section );
		self::assertCount( 2, $config->sections );
		self::assertCount( 1, $config->curated_links );

		$rebuilt = LlmsConfig::from_input( $config->to_array(), self::SITE_URL );
		self::assertEquals( $config, $rebuilt );
	}

	/**
	 * An absent introduction and an absent curated-link list both default to
	 * their empty representation, and round-trip the same way.
	 */
	public function test_optional_fields_default_and_round_trip(): void {
		$input = $this->valid_input();
		unset( $input['introduction'], $input['curated_links'] );

		$config = LlmsConfig::from_input( $input, self::SITE_URL );

		self::assertNull( $config->introduction );
		self::assertSame( array(), $config->curated_links );
		self::assertEquals( $config, LlmsConfig::from_input( $config->to_array(), self::SITE_URL ) );
	}

	/**
	 * Unknown keys cannot become arbitrary configuration.
	 */
	public function test_rejects_unknown_field(): void {
		$input                  = $this->valid_input();
		$input['arbitrary_key'] = 'value';

		$this->expectException( InvalidArgumentException::class );

		LlmsConfig::from_input( $input, self::SITE_URL );
	}

	/**
	 * A `site_url` key in the input array is ignored rather than honored:
	 * `site_url` is a fact about the site, taken only from the explicit
	 * parameter, never from caller-controlled input.
	 */
	public function test_ignores_site_url_key_in_input(): void {
		$input             = $this->valid_input();
		$input['site_url'] = 'https://attacker.example';

		$config = LlmsConfig::from_input( $input, self::SITE_URL );

		self::assertSame( self::SITE_URL, $config->site_url );
	}

	/**
	 * A curated link whose origin differs from the supplied `site_url`
	 * parameter is rejected outright, even when the input array's own
	 * (ignored) `site_url` key claims that foreign origin as home. A caller
	 * cannot launder a cross-origin link into the document by supplying a
	 * `site_url` that matches the link: only the caller-independent
	 * parameter decides same-site-ness.
	 */
	public function test_rejects_curated_link_foreign_to_the_supplied_site_url(): void {
		$input                              = $this->valid_input();
		$input['site_url']                  = 'https://evil.example';
		$input['curated_links'][0]['title'] = 'Zaufana oferta';
		$input['curated_links'][0]['url']   = 'https://evil.example/phish/';

		$this->expectException( InvalidArgumentException::class );

		LlmsConfig::from_input( $input, self::SITE_URL );
	}

	/**
	 * A curated link on another origin is rejected outright rather than dropped:
	 * this is administrator configuration, not public content.
	 */
	public function test_rejects_cross_origin_curated_link(): void {
		$input                            = $this->valid_input();
		$input['curated_links'][0]['url'] = 'https://evil.example/';

		$this->expectException( InvalidArgumentException::class );

		LlmsConfig::from_input( $input, self::SITE_URL );
	}

	/**
	 * A curated link URL that could break out of Markdown link syntax is rejected.
	 */
	public function test_rejects_curated_link_url_with_unsafe_characters(): void {
		$input                            = $this->valid_input();
		$input['curated_links'][0]['url'] = 'https://example.com/a)(b';

		$this->expectException( InvalidArgumentException::class );

		LlmsConfig::from_input( $input, self::SITE_URL );
	}

	/**
	 * A curated link section must reference an actually configured section key.
	 */
	public function test_rejects_curated_link_with_unknown_section(): void {
		$input                                = $this->valid_input();
		$input['curated_links'][0]['section'] = 'unknown-section';

		$this->expectException( InvalidArgumentException::class );

		LlmsConfig::from_input( $input, self::SITE_URL );
	}

	/**
	 * Section keys must be unique.
	 */
	public function test_rejects_duplicate_section_keys(): void {
		$input               = $this->valid_input();
		$input['sections'][] = array(
			'key'   => 'post',
			'label' => 'Duplicate',
		);

		$this->expectException( InvalidArgumentException::class );

		LlmsConfig::from_input( $input, self::SITE_URL );
	}

	/**
	 * More than the 20-section limit is rejected outright; this is
	 * configuration, so the generator's truncate-and-warn behaviour does not
	 * apply here.
	 */
	public function test_rejects_more_than_twenty_sections(): void {
		$input             = $this->valid_input();
		$input['sections'] = array();
		for ( $i = 0; $i < 21; $i++ ) {
			$input['sections'][] = array(
				'key'   => "section-{$i}",
				'label' => "Section {$i}",
			);
		}
		$input['enabled_post_types'] = array( 'post' );

		$this->expectException( InvalidArgumentException::class );

		LlmsConfig::from_input( $input, self::SITE_URL );
	}

	/**
	 * The excerpt_length field must fall within the 1-200 bound.
	 */
	public function test_rejects_excerpt_length_over_two_hundred(): void {
		$input                   = $this->valid_input();
		$input['excerpt_length'] = 201;

		$this->expectException( InvalidArgumentException::class );

		LlmsConfig::from_input( $input, self::SITE_URL );
	}

	/**
	 * The max_items_per_section field must fall within the 1-100 bound.
	 */
	public function test_rejects_max_items_per_section_over_one_hundred(): void {
		$input                          = $this->valid_input();
		$input['max_items_per_section'] = 101;

		$this->expectException( InvalidArgumentException::class );

		LlmsConfig::from_input( $input, self::SITE_URL );
	}

	/**
	 * A site title containing a raw newline is collapsed to a single line
	 * rather than accepted verbatim, so it can never start a spurious heading.
	 */
	public function test_collapses_newlines_in_site_title(): void {
		$input               = $this->valid_input();
		$input['site_title'] = "Example\nSite";

		$config = LlmsConfig::from_input( $input, self::SITE_URL );

		self::assertSame( 'Example Site', $config->site_title );
	}

	/**
	 * Invalid UTF-8 in a text field is rejected.
	 */
	public function test_rejects_invalid_utf8_site_title(): void {
		$input               = $this->valid_input();
		$input['site_title'] = "Example \xB1\x31 Site";

		$this->expectException( InvalidArgumentException::class );

		LlmsConfig::from_input( $input, self::SITE_URL );
	}

	/**
	 * `is_same_site_absolute_url()` accepts only an exact scheme/host/port match.
	 */
	public function test_is_same_site_absolute_url_requires_exact_origin_match(): void {
		self::assertTrue( LlmsConfig::is_same_site_absolute_url( 'https://example.com/post/', 'https://example.com' ) );
		self::assertFalse( LlmsConfig::is_same_site_absolute_url( 'https://other.example/post/', 'https://example.com' ) );
		self::assertFalse( LlmsConfig::is_same_site_absolute_url( 'http://example.com/post/', 'https://example.com' ) );
		self::assertFalse( LlmsConfig::is_same_site_absolute_url( 'https://example.com:8443/post/', 'https://example.com' ) );
	}

	/**
	 * URLs carrying embedded credentials are never treated as same-site, even
	 * on the correct host.
	 */
	public function test_is_same_site_absolute_url_rejects_credentials(): void {
		self::assertFalse( LlmsConfig::is_same_site_absolute_url( 'https://user:pass@example.com/post/', 'https://example.com' ) );
	}
}

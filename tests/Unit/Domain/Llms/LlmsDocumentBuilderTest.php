<?php
/**
 * Unit tests for the pure llms.txt document generator.
 *
 * @package IsuDev\WPContentBridge\Tests
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Tests\Unit\Domain\Llms;

use DateTimeImmutable;
use DateTimeZone;
use IsuDev\WPContentBridge\Domain\Llms\LlmsConfig;
use IsuDev\WPContentBridge\Domain\Llms\LlmsDocumentBuilder;
use IsuDev\WPContentBridge\Domain\Llms\LlmsSourceEntry;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the exact output shape, each bound's truncation-plus-warning
 * behaviour, the UTF-8/control-character rules, and the Markdown-injection
 * defense over untrusted entry text.
 */
final class LlmsDocumentBuilderTest extends TestCase {

	/**
	 * Builds a minimal, directly-constructed configuration. Bypassing
	 * `LlmsConfig::from_input()` lets individual tests exercise the builder's
	 * own defensive bounds independently of configuration-time validation.
	 *
	 * @param array<string, mixed> $overrides Fields to override.
	 * @return LlmsConfig
	 */
	private function config( array $overrides = array() ): LlmsConfig {
		$fields = array_merge(
			array(
				'site_url'              => 'https://example.com',
				'site_title'            => 'Example Site',
				'site_summary'          => 'A site about examples.',
				'introduction'          => null,
				'enabled_post_types'    => array( 'post' ),
				'sections'              => array(
					array(
						'key'   => 'post',
						'label' => 'Blog Posts',
					),
				),
				'group_by_section'      => true,
				'show_excerpts'         => true,
				'excerpt_length'        => 160,
				'max_items_per_section' => 50,
				'curated_links'         => array(),
			),
			$overrides
		);

		return new LlmsConfig(
			$fields['site_url'],
			$fields['site_title'],
			$fields['site_summary'],
			$fields['introduction'],
			$fields['enabled_post_types'],
			$fields['sections'],
			$fields['group_by_section'],
			$fields['show_excerpts'],
			$fields['excerpt_length'],
			$fields['max_items_per_section'],
			$fields['curated_links']
		);
	}

	/**
	 * The generated document matches the llms.txt proposal's shape exactly:
	 * a `#` title, a `>` summary, an optional introduction, and `##` sections
	 * of `- [title](url): excerpt` lines.
	 */
	public function test_builds_expected_document_shape(): void {
		$config  = $this->config( array( 'introduction' => 'Optional introduction paragraph.' ) );
		$entries = array(
			new LlmsSourceEntry( 'Post title', 'https://example.com/post/', 'excerpt text', 'post' ),
			new LlmsSourceEntry( 'Another title', 'https://example.com/other/', null, 'post' ),
		);

		$artifact = ( new LlmsDocumentBuilder() )->build( $config, $entries, new DateTimeImmutable( '2026-08-07T00:00:00+00:00' ) );

		$expected = '# Example Site' . "\n\n"
			. '> A site about examples.' . "\n\n"
			. 'Optional introduction paragraph.' . "\n\n"
			. '## Blog Posts' . "\n\n"
			. '- [Post title](https://example.com/post/): excerpt text' . "\n"
			. '- [Another title](https://example.com/other/)' . "\n";

		self::assertSame( $expected, $artifact->content );
		self::assertSame( array(), $artifact->warnings );
		self::assertSame( 2, $artifact->link_count );
		self::assertSame( strlen( $expected ), $artifact->byte_count );
		self::assertSame( hash( 'sha256', $expected ), $artifact->content_hash );
		self::assertSame( '2026-08-07T00:00:00Z', $artifact->generated_at );
	}

	/**
	 * With no introduction and no entries, only the title and summary block
	 * are emitted; empty sections are skipped rather than rendered with no items.
	 */
	public function test_omits_introduction_and_empty_sections(): void {
		$artifact = ( new LlmsDocumentBuilder() )->build( $this->config(), array() );

		self::assertSame( "# Example Site\n\n> A site about examples.\n", $artifact->content );
		self::assertSame( 0, $artifact->link_count );
		self::assertSame( array(), $artifact->warnings );
	}

	/**
	 * `group_by_section = false` ignores each entry's own section and collapses
	 * everything into the first configured section.
	 */
	public function test_group_by_section_false_collapses_into_one_section(): void {
		$config  = $this->config(
			array(
				'group_by_section' => false,
				'sections'         => array(
					array(
						'key'   => 'post',
						'label' => 'Everything',
					),
					array(
						'key'   => 'page',
						'label' => 'Pages',
					),
				),
			)
		);
		$entries = array(
			new LlmsSourceEntry( 'From post', 'https://example.com/1/', null, 'post' ),
			new LlmsSourceEntry( 'From page', 'https://example.com/2/', null, 'page' ),
		);

		$artifact = ( new LlmsDocumentBuilder() )->build( $config, $entries );

		self::assertSame( 1, substr_count( $artifact->content, '## ' ) );
		self::assertStringContainsString( '## Everything', $artifact->content );
		self::assertStringNotContainsString( '## Pages', $artifact->content );
		self::assertSame( 2, $artifact->link_count );
	}

	/**
	 * A post titled to break out of Markdown link syntax must never produce a
	 * working link to another origin: the required security case. Only the
	 * closing bracket and the backslash itself are escaped — a parser skips
	 * the escaped `\]` as literal text, finds the real, unescaped `]` right
	 * before the genuine destination, and resolves the link to the real post,
	 * never to the other origin embedded in the title.
	 */
	public function test_neutralizes_markdown_link_breakout_in_title(): void {
		$entries = array(
			new LlmsSourceEntry( '](https://evil.example/)', 'https://example.com/real-post/', null, 'post' ),
		);

		$artifact = ( new LlmsDocumentBuilder() )->build( $this->config(), $entries );

		self::assertStringContainsString(
			'- [\\](https://evil.example/)](https://example.com/real-post/)',
			$artifact->content
		);
		self::assertSame( 1, $artifact->link_count );
	}

	/**
	 * Brackets in an excerpt are neutralized the same way; an asterisk is not,
	 * since it is cosmetic in a renderer and not a security boundary.
	 */
	public function test_neutralizes_bracket_breakout_in_excerpt(): void {
		$entries = array(
			new LlmsSourceEntry( 'Title', 'https://example.com/post/', '*bold* and [link](https://evil.example/)', 'post' ),
		);

		$artifact = ( new LlmsDocumentBuilder() )->build( $this->config(), $entries );

		self::assertStringContainsString( '*bold* and \\[link\\](https://evil.example/)', $artifact->content );
	}

	/**
	 * An ordinary title containing parentheses, an asterisk, and an underscore
	 * passes through unescaped. None of those characters are load-bearing for
	 * the link-breakout defense, and the artifact is served as `text/plain`
	 * (ADR 0023) and read by its consumer — predominantly a language model —
	 * as raw text, not rendered Markdown, so escaping them would only
	 * disfigure the document for the reader. This is the regression test for
	 * widening the escape set again without weighing that cost.
	 */
	public function test_ordinary_punctuation_passes_through_unescaped(): void {
		$entries = array(
			new LlmsSourceEntry( 'Alarmy (Wroclaw) *featured* and _new_', 'https://example.com/post/', null, 'post' ),
		);

		$artifact = ( new LlmsDocumentBuilder() )->build( $this->config(), $entries );

		self::assertStringContainsString(
			'- [Alarmy (Wroclaw) *featured* and _new_](https://example.com/post/)',
			$artifact->content
		);
	}

	/**
	 * Invalid UTF-8 in a title is dropped, not passed through, and recorded once.
	 */
	public function test_drops_entry_with_invalid_utf8_title(): void {
		$entries = array(
			new LlmsSourceEntry( "Bad \xB1\x31 title", 'https://example.com/post/', null, 'post' ),
		);

		$artifact = ( new LlmsDocumentBuilder() )->build( $this->config(), $entries );

		self::assertSame( 0, $artifact->link_count );
		self::assertSame(
			array( '1 entries were dropped because their title was invalid.' ),
			$artifact->warnings
		);
	}

	/**
	 * A C0 control character in a title is stripped rather than dropping the
	 * whole entry, since the title is still otherwise usable.
	 */
	public function test_strips_control_characters_from_title(): void {
		$entries = array(
			new LlmsSourceEntry( "Title\x07with\x07bell", 'https://example.com/post/', null, 'post' ),
		);

		$artifact = ( new LlmsDocumentBuilder() )->build( $this->config(), $entries );

		self::assertStringContainsString( '[Title with bell]', $artifact->content );
		self::assertSame( array(), $artifact->warnings );
	}

	/**
	 * A cross-origin URL is dropped rather than trusted, even though entries
	 * are already-authorized: defense in depth against a compromised selector.
	 */
	public function test_drops_entry_with_cross_origin_url(): void {
		$entries = array(
			new LlmsSourceEntry( 'Elsewhere', 'https://evil.example/post/', null, 'post' ),
		);

		$artifact = ( new LlmsDocumentBuilder() )->build( $this->config(), $entries );

		self::assertSame( 0, $artifact->link_count );
		self::assertSame(
			array( '1 entries were dropped because their URL was not a canonical same-site absolute URL.' ),
			$artifact->warnings
		);
	}

	/**
	 * An entry whose section is not configured is dropped rather than
	 * fabricating a new section.
	 */
	public function test_drops_entry_with_unconfigured_section(): void {
		$entries = array(
			new LlmsSourceEntry( 'Orphan', 'https://example.com/post/', null, 'unknown-section' ),
		);

		$artifact = ( new LlmsDocumentBuilder() )->build( $this->config(), $entries );

		self::assertSame( 0, $artifact->link_count );
		self::assertSame(
			array( '1 entries were dropped because their section was not configured.' ),
			$artifact->warnings
		);
	}

	/**
	 * `show_excerpts = false` omits every excerpt regardless of what the
	 * entry carries.
	 */
	public function test_show_excerpts_false_omits_all_excerpts(): void {
		$config  = $this->config( array( 'show_excerpts' => false ) );
		$entries = array(
			new LlmsSourceEntry( 'Title', 'https://example.com/post/', 'has an excerpt', 'post' ),
		);

		$artifact = ( new LlmsDocumentBuilder() )->build( $config, $entries );

		self::assertStringContainsString( '- [Title](https://example.com/post/)' . "\n", $artifact->content );
		self::assertStringNotContainsString( 'has an excerpt', $artifact->content );
	}

	/**
	 * Bound: sections beyond the 20-section limit are truncated deterministically
	 * and recorded, even though `LlmsConfig::from_input()` would normally have
	 * rejected this many sections outright.
	 */
	public function test_truncates_sections_over_the_limit(): void {
		$sections = array();
		for ( $i = 0; $i < 25; $i++ ) {
			$sections[] = array(
				'key'   => "section-{$i}",
				'label' => "Section {$i}",
			);
		}
		$config = $this->config( array( 'sections' => $sections ) );

		$artifact = ( new LlmsDocumentBuilder() )->build( $config, array() );

		self::assertSame(
			array( 'Sections were truncated to the 20-section limit.' ),
			$artifact->warnings
		);
	}

	/**
	 * Bound: a section with more entries than the effective per-section cap
	 * is truncated deterministically and recorded.
	 */
	public function test_truncates_items_per_section_over_the_limit(): void {
		$config  = $this->config( array( 'max_items_per_section' => 2 ) );
		$entries = array(
			new LlmsSourceEntry( 'One', 'https://example.com/1/', null, 'post' ),
			new LlmsSourceEntry( 'Two', 'https://example.com/2/', null, 'post' ),
			new LlmsSourceEntry( 'Three', 'https://example.com/3/', null, 'post' ),
		);

		$artifact = ( new LlmsDocumentBuilder() )->build( $config, $entries );

		self::assertSame( 2, $artifact->link_count );
		self::assertStringNotContainsString( 'Three', $artifact->content );
		self::assertSame(
			array( 'One or more sections were truncated to the 2-item limit.' ),
			$artifact->warnings
		);
	}

	/**
	 * Bound: an excerpt longer than the effective character cap is truncated
	 * deterministically and recorded.
	 */
	public function test_truncates_excerpt_over_the_limit(): void {
		$config  = $this->config( array( 'excerpt_length' => 10 ) );
		$entries = array(
			new LlmsSourceEntry( 'Title', 'https://example.com/post/', 'This excerpt is much longer than ten characters.', 'post' ),
		);

		$artifact = ( new LlmsDocumentBuilder() )->build( $config, $entries );

		self::assertStringContainsString( ': This excer' . "\n", $artifact->content );
		self::assertSame(
			array( 'One or more excerpts were truncated to the 10-character limit.' ),
			$artifact->warnings
		);
	}

	/**
	 * Bound: curated links are exempt from the per-section item cap, but not
	 * from the whole-document link cap. Content alone can never exceed 2000
	 * links once the 20-section and 100-item bounds are both enforced
	 * (20 * 100 = 2000), so this bound is reachable only through curated links
	 * stacked on top — exercised here via a directly constructed configuration
	 * bypassing `LlmsConfig::from_input()`'s own 200-link curated-link cap.
	 */
	public function test_truncates_links_over_the_whole_document_limit(): void {
		$curated_links = array();
		for ( $i = 0; $i < 2001; $i++ ) {
			$curated_links[] = array(
				'title'   => "Link {$i}",
				'url'     => "https://example.com/link-{$i}/",
				'section' => 'post',
			);
		}
		$config = $this->config( array( 'curated_links' => $curated_links ) );

		$artifact = ( new LlmsDocumentBuilder() )->build( $config, array() );

		self::assertSame( 2000, $artifact->link_count );
		self::assertSame(
			array( 'Links were truncated to the 2000-link limit.' ),
			$artifact->warnings
		);
	}

	/**
	 * Bound: a document whose content exceeds the 1 MiB ceiling is truncated
	 * on a full line boundary rather than mid-line, and the byte and link
	 * counts reported reflect what actually survived truncation.
	 */
	public function test_truncates_document_over_the_byte_limit(): void {
		$entries = array(
			new LlmsSourceEntry( 'Fits', 'https://example.com/fits/', null, 'post' ),
			new LlmsSourceEntry( str_repeat( 'x', 1100000 ), 'https://example.com/huge/', null, 'post' ),
		);

		$artifact = ( new LlmsDocumentBuilder() )->build( $this->config(), $entries );

		self::assertLessThanOrEqual( LlmsDocumentBuilder::MAX_DOCUMENT_BYTES, $artifact->byte_count );
		self::assertSame( strlen( $artifact->content ), $artifact->byte_count );
		self::assertStringEndsWith( "\n", $artifact->content );
		self::assertStringNotContainsString( 'xxxx', $artifact->content );
		self::assertStringContainsString( '[Fits](https://example.com/fits/)', $artifact->content );
		self::assertSame( 1, $artifact->link_count );
		self::assertSame(
			array( 'Document content was truncated to the 1048576-byte limit.' ),
			$artifact->warnings
		);
	}

	/**
	 * The generation clock defaults to "now" (UTC) when the caller withholds it.
	 */
	public function test_defaults_generated_at_to_now(): void {
		$before = new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );

		$artifact = ( new LlmsDocumentBuilder() )->build( $this->config(), array() );

		$after    = new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
		$reported = DateTimeImmutable::createFromFormat( 'Y-m-d\TH:i:s\Z', $artifact->generated_at, new DateTimeZone( 'UTC' ) );

		self::assertNotFalse( $reported );
		self::assertGreaterThanOrEqual( $before->getTimestamp(), $reported->getTimestamp() );
		self::assertLessThanOrEqual( $after->getTimestamp(), $reported->getTimestamp() );
	}
}

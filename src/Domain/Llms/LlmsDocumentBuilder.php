<?php
/**
 * Pure llms.txt document generator.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Domain\Llms;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Renders an {@see LlmsConfig} plus an ordered list of already-selected,
 * already-authorized {@see LlmsSourceEntry} items into an {@see LlmsArtifact}.
 *
 * Pure: no WordPress function, no I/O, no clock access unless the caller
 * withholds `$generated_at`. Selection and authorization are the selector
 * port's job (a later slice); this class never decides what is eligible, but
 * it still treats every entry's `title`, `url`, and `excerpt` as untrusted
 * public content, since a post author is not the administrator who
 * configured publication. It never fails: a bound violation truncates
 * deterministically and records a warning instead of throwing.
 *
 * Curated links from `LlmsConfig::$curated_links` are administrator
 * configuration, already validated by `LlmsConfig::from_input()`, but are
 * still run through the same sanitization as entries for defense in depth
 * against a directly constructed, unvalidated configuration. They are
 * deliberately exempt from the per-section item bound — that bound paginates
 * algorithmically selected content, not a short, deliberately curated list —
 * but they are not exempt from the whole-document link bound.
 */
final class LlmsDocumentBuilder {

	/**
	 * Whole-document byte ceiling (1 MiB).
	 *
	 * @var int
	 */
	public const MAX_DOCUMENT_BYTES = 1048576;

	/**
	 * Whole-document Markdown link ceiling.
	 *
	 * @var int
	 */
	public const MAX_LINKS = 2000;

	private const WARNING_SECTIONS_TRUNCATED = 'Sections were truncated to the %d-section limit.';
	private const WARNING_ITEMS_TRUNCATED    = 'One or more sections were truncated to the %d-item limit.';
	private const WARNING_EXCERPTS_TRUNCATED = 'One or more excerpts were truncated to the %d-character limit.';
	private const WARNING_LINKS_TRUNCATED    = 'Links were truncated to the %d-link limit.';
	private const WARNING_DOCUMENT_TRUNCATED = 'Document content was truncated to the %d-byte limit.';
	private const WARNING_DROPPED_TITLE      = '%d entries were dropped because their title was invalid.';
	private const WARNING_DROPPED_URL        = '%d entries were dropped because their URL was not a canonical same-site absolute URL.';
	private const WARNING_DROPPED_SECTION    = '%d entries were dropped because their section was not configured.';

	/**
	 * Generates the document.
	 *
	 * @param LlmsConfig             $config       Effective configuration.
	 * @param array                  $entries      Already-selected, already-authorized entries.
	 * @param DateTimeImmutable|null $generated_at Generation time; defaults to now (UTC).
	 * @return LlmsArtifact
	 * @phpstan-param list<LlmsSourceEntry> $entries
	 */
	public function build( LlmsConfig $config, array $entries, ?DateTimeImmutable $generated_at = null ): LlmsArtifact {
		$warnings = array();

		$sections = self::bound_sections( $config->sections, $warnings );
		$keys     = array_column( $sections, 'key' );

		$buckets = self::empty_buckets( $keys );
		self::bucket_entries( $config, $entries, $keys, $buckets, $warnings );

		$item_cap = min( $config->max_items_per_section, LlmsConfig::MAX_ITEMS_PER_SECTION );
		self::bound_items_per_section( $buckets, $item_cap, $warnings );

		self::bucket_curated_links( $config, $keys, $buckets );

		$excerpt_cap         = min( $config->excerpt_length, LlmsConfig::MAX_EXCERPT_LENGTH );
		[$body, $link_count] = self::render_sections( $sections, $buckets, $excerpt_cap, $warnings );

		$content = self::assemble( $config, $body );

		[$content, $document_truncated] = self::bound_document_bytes( $content );
		if ( $document_truncated ) {
			$warnings[] = sprintf( self::WARNING_DOCUMENT_TRUNCATED, self::MAX_DOCUMENT_BYTES );
			$link_count = preg_match_all( '/^- \[/m', $content );
			$link_count = false === $link_count ? 0 : $link_count;
		}

		return new LlmsArtifact(
			$content,
			hash( 'sha256', $content ),
			( $generated_at ?? new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) )->format( 'Y-m-d\TH:i:s\Z' ),
			strlen( $content ),
			$link_count,
			$warnings
		);
	}

	/**
	 * Bounds the configured section list, truncating deterministically.
	 *
	 * @param array $sections Configured sections, in order.
	 * @param array $warnings Warning accumulator, appended in place.
	 * @return array
	 * @phpstan-param list<array{key: string, label: string}> $sections
	 * @phpstan-param list<string> $warnings
	 * @phpstan-return list<array{key: string, label: string}>
	 */
	private static function bound_sections( array $sections, array &$warnings ): array {
		if ( count( $sections ) <= LlmsConfig::MAX_SECTIONS ) {
			return $sections;
		}

		$warnings[] = sprintf( self::WARNING_SECTIONS_TRUNCATED, LlmsConfig::MAX_SECTIONS );

		return array_slice( $sections, 0, LlmsConfig::MAX_SECTIONS );
	}

	/**
	 * Builds one empty, ordered bucket per configured section key.
	 *
	 * @param array $keys Configured section keys, in order.
	 * @return array
	 * @phpstan-param list<string> $keys
	 * @phpstan-return array<string, list<array{title: string, url: string, excerpt: string|null}>>
	 */
	private static function empty_buckets( array $keys ): array {
		$buckets = array();
		foreach ( $keys as $key ) {
			$buckets[ $key ] = array();
		}

		return $buckets;
	}

	/**
	 * Sanitizes and routes content entries into their target section bucket.
	 *
	 * @param LlmsConfig $config   Effective configuration.
	 * @param array      $entries  Candidate entries.
	 * @param array      $keys     Configured section keys, in order.
	 * @param array      $buckets  Ordered buckets, appended in place.
	 * @param array      $warnings Warning accumulator, appended in place.
	 * @return void
	 * @phpstan-param list<LlmsSourceEntry> $entries
	 * @phpstan-param list<string> $keys
	 * @phpstan-param array<string, list<array{title: string, url: string, excerpt: string|null}>> $buckets
	 * @phpstan-param list<string> $warnings
	 */
	private static function bucket_entries( LlmsConfig $config, array $entries, array $keys, array &$buckets, array &$warnings ): void {
		$dropped_title   = 0;
		$dropped_url     = 0;
		$dropped_section = 0;

		foreach ( $entries as $entry ) {
			$title = self::sanitize_inline( $entry->title );
			if ( null === $title ) {
				++$dropped_title;
				continue;
			}

			if ( ! LlmsConfig::is_same_site_absolute_url( $entry->url, $config->site_url ) ) {
				++$dropped_url;
				continue;
			}

			$key = $config->group_by_section ? $entry->section : ( $keys[0] ?? null );
			if ( null === $key || ! in_array( $key, $keys, true ) ) {
				++$dropped_section;
				continue;
			}

			$excerpt = $config->show_excerpts ? self::sanitize_inline( $entry->excerpt ) : null;

			$buckets[ $key ][] = array(
				'title'   => $title,
				'url'     => $entry->url,
				'excerpt' => $excerpt,
			);
		}

		if ( 0 < $dropped_title ) {
			$warnings[] = sprintf( self::WARNING_DROPPED_TITLE, $dropped_title );
		}
		if ( 0 < $dropped_url ) {
			$warnings[] = sprintf( self::WARNING_DROPPED_URL, $dropped_url );
		}
		if ( 0 < $dropped_section ) {
			$warnings[] = sprintf( self::WARNING_DROPPED_SECTION, $dropped_section );
		}
	}

	/**
	 * Bounds each section's content items, truncating deterministically.
	 *
	 * @param array $buckets  Ordered buckets, truncated in place.
	 * @param int   $item_cap Effective per-section item cap.
	 * @param array $warnings Warning accumulator, appended in place.
	 * @return void
	 * @phpstan-param array<string, list<array{title: string, url: string, excerpt: string|null}>> $buckets
	 * @phpstan-param list<string> $warnings
	 */
	private static function bound_items_per_section( array &$buckets, int $item_cap, array &$warnings ): void {
		$truncated = false;
		foreach ( $buckets as $key => $items ) {
			if ( count( $items ) > $item_cap ) {
				$buckets[ $key ] = array_slice( $items, 0, $item_cap );
				$truncated       = true;
			}
		}

		if ( $truncated ) {
			$warnings[] = sprintf( self::WARNING_ITEMS_TRUNCATED, $item_cap );
		}
	}

	/**
	 * Sanitizes and appends curated links, exempt from the per-section item bound.
	 *
	 * @param LlmsConfig $config  Effective configuration.
	 * @param array      $keys    Configured section keys, in order.
	 * @param array      $buckets Ordered buckets, appended in place.
	 * @return void
	 * @phpstan-param list<string> $keys
	 * @phpstan-param array<string, list<array{title: string, url: string, excerpt: string|null}>> $buckets
	 */
	private static function bucket_curated_links( LlmsConfig $config, array $keys, array &$buckets ): void {
		foreach ( $config->curated_links as $link ) {
			$title = self::sanitize_inline( $link['title'] );
			if ( null === $title || ! LlmsConfig::is_same_site_absolute_url( $link['url'], $config->site_url ) ) {
				continue;
			}

			$key = $config->group_by_section ? $link['section'] : ( $keys[0] ?? null );
			if ( null === $key || ! in_array( $key, $keys, true ) ) {
				continue;
			}

			$buckets[ $key ][] = array(
				'title'   => $title,
				'url'     => $link['url'],
				'excerpt' => null,
			);
		}
	}

	/**
	 * Renders ordered, non-empty sections into Markdown blocks and counts links,
	 * bounding the whole-document link total.
	 *
	 * @param array $sections    Bounded, ordered section key/label pairs.
	 * @param array $buckets     Bounded, ordered buckets.
	 * @param int   $excerpt_cap Effective excerpt character cap.
	 * @param array $warnings    Warning accumulator, appended in place.
	 * @return array{0: list<string>, 1: int}
	 * @phpstan-param list<array{key: string, label: string}> $sections
	 * @phpstan-param array<string, list<array{title: string, url: string, excerpt: string|null}>> $buckets
	 * @phpstan-param list<string> $warnings
	 */
	private static function render_sections( array $sections, array $buckets, int $excerpt_cap, array &$warnings ): array {
		$blocks            = array();
		$link_count        = 0;
		$links_truncated   = false;
		$excerpt_truncated = false;

		foreach ( $sections as $section ) {
			$items = $buckets[ $section['key'] ] ?? array();
			if ( array() === $items ) {
				continue;
			}

			$lines = array();
			foreach ( $items as $item ) {
				if ( $link_count >= self::MAX_LINKS ) {
					$links_truncated = true;
					break;
				}

				$excerpt = $item['excerpt'];
				if ( null !== $excerpt && mb_strlen( $excerpt ) > $excerpt_cap ) {
					$excerpt           = mb_substr( $excerpt, 0, $excerpt_cap, 'UTF-8' );
					$excerpt_truncated = true;
				}

				$lines[] = null === $excerpt
					? "- [{$item['title']}]({$item['url']})"
					: "- [{$item['title']}]({$item['url']}): {$excerpt}";
				++$link_count;
			}

			if ( array() !== $lines ) {
				$blocks[] = "## {$section['label']}\n\n" . implode( "\n", $lines );
			}

			if ( $links_truncated ) {
				break;
			}
		}

		if ( $links_truncated ) {
			$warnings[] = sprintf( self::WARNING_LINKS_TRUNCATED, self::MAX_LINKS );
		}
		if ( $excerpt_truncated ) {
			$warnings[] = sprintf( self::WARNING_EXCERPTS_TRUNCATED, $excerpt_cap );
		}

		return array( $blocks, $link_count );
	}

	/**
	 * Assembles the document header and section blocks.
	 *
	 * @param LlmsConfig $config Effective configuration.
	 * @param array      $blocks Rendered section blocks, in order.
	 * @return string
	 * @phpstan-param list<string> $blocks
	 */
	private static function assemble( LlmsConfig $config, array $blocks ): string {
		$document = array(
			"# {$config->site_title}",
			"> {$config->site_summary}",
		);

		if ( null !== $config->introduction ) {
			$document[] = $config->introduction;
		}

		array_push( $document, ...$blocks );

		return implode( "\n\n", $document ) . "\n";
	}

	/**
	 * Bounds the whole document by byte length, truncating on the last full
	 * line at or before the limit so the result never ends mid-line.
	 *
	 * @param string $content Assembled document.
	 * @return array{0: string, 1: bool}
	 */
	private static function bound_document_bytes( string $content ): array {
		if ( strlen( $content ) <= self::MAX_DOCUMENT_BYTES ) {
			return array( $content, false );
		}

		$truncated    = substr( $content, 0, self::MAX_DOCUMENT_BYTES );
		$last_newline = strrpos( $truncated, "\n" );
		if ( false !== $last_newline ) {
			$truncated = substr( $truncated, 0, $last_newline );
		}

		return array( rtrim( $truncated, "\n" ) . "\n", true );
	}

	/**
	 * Sanitizes untrusted public text for safe inline Markdown emission:
	 * requires valid UTF-8, strips C0 control characters (including newlines,
	 * since this text must never break out of its single list-item line),
	 * collapses whitespace, and backslash-escapes only `\`, `[`, and `]`.
	 *
	 * The artifact is served as `text/plain` (ADR 0023), not rendered
	 * Markdown, so escaping is limited to what is load-bearing for the
	 * security property: an entry must never close its link early and point
	 * a reader at another origin. That requires escaping the closing bracket
	 * — and the backslash itself, so an attacker cannot escape the escape —
	 * but nothing else. Parentheses only matter inside a URL, which is
	 * validated and dropped separately; emphasis markers (`*`, `_`) and angle
	 * brackets are cosmetic in a renderer and irrelevant in plain text, and
	 * escaping them would visibly disfigure ordinary titles for the reader.
	 *
	 * @param string|null $value Raw text.
	 * @return string|null Null when the input is not a string, not valid UTF-8, or empty after sanitizing.
	 */
	private static function sanitize_inline( ?string $value ): ?string {
		if ( null === $value || ! mb_check_encoding( $value, 'UTF-8' ) ) {
			return null;
		}

		$value = preg_replace( '/[\x00-\x1F\x7F]/u', ' ', $value );
		$value = preg_replace( '/\s+/u', ' ', (string) $value );
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return null;
		}

		$value = str_replace( '\\', '\\\\', $value );

		return preg_replace( '/([\[\]])/u', '\\\\$1', $value );
	}
}

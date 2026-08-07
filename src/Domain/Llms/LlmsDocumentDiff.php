<?php
/**
 * Pure section-level llms.txt document diff.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Domain\Llms;

/**
 * Compares two documents produced by {@see LlmsDocumentBuilder} at the
 * rendered section-block level, so preview-update-llms-txt can show an
 * administrator what would change without diffing the whole document
 * byte-for-byte.
 *
 * Sections are identified by their rendered `## ` label, not by configured
 * section key, because that is what a reader of the generated document
 * actually sees. `LlmsConfig::from_input()` does not require labels to be
 * unique, so two differently-keyed sections that happen to share a label
 * collapse into one entry here; that is a documented simplification of this
 * diff, not a leak, since the underlying documents remain the source of truth.
 */
final class LlmsDocumentDiff {

	/**
	 * Diffs two rendered documents at the section-block level.
	 *
	 * @param string $current_content     Currently stored document content, or an empty string when none exists.
	 * @param string $prospective_content Prospective document content.
	 * @return array{added_sections: list<string>, removed_sections: list<string>, changed_sections: list<string>}
	 */
	public static function diff( string $current_content, string $prospective_content ): array {
		$current     = self::sections( $current_content );
		$prospective = self::sections( $prospective_content );

		$added   = array_values( array_diff( array_keys( $prospective ), array_keys( $current ) ) );
		$removed = array_values( array_diff( array_keys( $current ), array_keys( $prospective ) ) );

		$changed = array();
		foreach ( array_intersect( array_keys( $current ), array_keys( $prospective ) ) as $label ) {
			if ( $current[ $label ] !== $prospective[ $label ] ) {
				$changed[] = $label;
			}
		}

		sort( $added );
		sort( $removed );
		sort( $changed );

		return array(
			'added_sections'   => $added,
			'removed_sections' => $removed,
			'changed_sections' => $changed,
		);
	}

	/**
	 * Splits a rendered document into its `## `-labeled section blocks,
	 * discarding the title, summary, and optional introduction that precede
	 * them. Relies on {@see LlmsDocumentBuilder::assemble()} always joining
	 * top-level document pieces with a blank line, so every section block is
	 * preceded by `\n\n## `.
	 *
	 * @param string $content Rendered document.
	 * @return array<string, string>
	 */
	private static function sections( string $content ): array {
		$parts = preg_split( '/\n\n(?=## )/', $content );
		if ( false === $parts ) {
			return array();
		}

		$sections = array();
		foreach ( $parts as $part ) {
			if ( ! str_starts_with( $part, '## ' ) ) {
				continue;
			}

			$newline = strpos( $part, "\n" );
			$label   = false === $newline ? substr( $part, 3 ) : substr( $part, 3, $newline - 3 );

			$sections[ $label ] = $part;
		}

		return $sections;
	}
}

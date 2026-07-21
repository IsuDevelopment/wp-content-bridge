<?php
/**
 * Yoast Premium keyphrase normalization.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Infrastructure\Yoast;

/**
 * Parses only the version-tested Premium keyphrase JSON shape into bounded data.
 */
final class PremiumKeyphraseNormalizer {

	private const MAX_KEYPHRASES = 20;
	private const MAX_LENGTH     = 200;
	private const MAX_SYNONYMS   = 20;

	/**
	 * Normalizes primary and additional keyphrases.
	 *
	 * @param string $primary         Primary focus keyphrase.
	 * @param string $additional_json Premium additional-keyphrase JSON.
	 * @param string $synonyms_json   Premium positional synonym JSON.
	 * @return array<string, array>
	 * @phpstan-return array{phrases: list<string>, details: list<array{keyphrase: string, role: string, score: int|null}>, keyphrase_synonyms: list<string>, related_keyphrases: list<array{keyphrase: string, synonyms: list<string>, score: int|null}>}
	 */
	public function normalize( string $primary, string $additional_json, string $synonyms_json = '' ): array {
		$phrases  = array();
		$details  = array();
		$related  = array();
		$synonyms = $this->decode_synonyms( $synonyms_json );
		$primary  = $this->normalize_phrase( $primary );
		if ( null !== $primary ) {
			$phrases[] = $primary;
			$details[] = array(
				'keyphrase' => $primary,
				'role'      => 'primary',
				'score'     => null,
			);
		}

		$decoded = json_decode( $additional_json, true );
		if ( ! is_array( $decoded ) ) {
			return array(
				'phrases'            => $phrases,
				'details'            => $details,
				'keyphrase_synonyms' => $synonyms[0] ?? array(),
				'related_keyphrases' => $related,
			);
		}

		foreach ( array_slice( $decoded, 0, self::MAX_KEYPHRASES ) as $index => $item ) {
			if ( ! is_array( $item ) || ! isset( $item['keyword'] ) || ! is_string( $item['keyword'] ) ) {
				continue;
			}
			$keyphrase = $this->normalize_phrase( $item['keyword'] );
			if ( null === $keyphrase || in_array( $keyphrase, $phrases, true ) ) {
				continue;
			}
			$score     = isset( $item['score'] ) && is_numeric( $item['score'] ) ? (int) $item['score'] : null;
			$score     = null !== $score && $score >= 0 && $score <= 100 ? $score : null;
			$phrases[] = $keyphrase;
			$details[] = array(
				'keyphrase' => $keyphrase,
				'role'      => 'additional',
				'score'     => $score,
			);
			$related[] = array(
				'keyphrase' => $keyphrase,
				'synonyms'  => $synonyms[ $index + 1 ] ?? array(),
				'score'     => $score,
			);
		}

		return array(
			'phrases'            => $phrases,
			'details'            => $details,
			'keyphrase_synonyms' => $synonyms[0] ?? array(),
			'related_keyphrases' => $related,
		);
	}

	/**
	 * Decodes Yoast's positional array of comma-delimited synonym strings.
	 *
	 * @param string $synonyms_json Stored JSON.
	 * @return list<list<string>>
	 */
	private function decode_synonyms( string $synonyms_json ): array {
		$decoded = json_decode( $synonyms_json, true );
		if ( ! is_array( $decoded ) ) {
			return array();
		}

		$groups = array();
		foreach ( array_slice( $decoded, 0, self::MAX_KEYPHRASES + 1 ) as $group ) {
			if ( ! is_string( $group ) ) {
				$groups[] = array();
				continue;
			}

			$items = array();
			foreach ( array_slice( explode( ',', $group ), 0, self::MAX_SYNONYMS ) as $synonym ) {
				$synonym = $this->normalize_phrase( $synonym );
				if ( null !== $synonym && ! in_array( $synonym, $items, true ) ) {
					$items[] = $synonym;
				}
			}
			$groups[] = $items;
		}

		return $groups;
	}

	/**
	 * Bounds and normalizes one phrase.
	 *
	 * @param string $phrase Candidate phrase.
	 * @return string|null
	 */
	private function normalize_phrase( string $phrase ): ?string {
		$phrase = trim( $phrase );
		if ( '' === $phrase ) {
			return null;
		}

		return function_exists( 'mb_substr' ) ? mb_substr( $phrase, 0, self::MAX_LENGTH ) : substr( $phrase, 0, self::MAX_LENGTH );
	}
}

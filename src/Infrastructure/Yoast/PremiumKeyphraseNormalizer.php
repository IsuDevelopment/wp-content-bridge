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

	/**
	 * Normalizes primary and additional keyphrases.
	 *
	 * @param string $primary         Primary focus keyphrase.
	 * @param string $additional_json Premium additional-keyphrase JSON.
	 * @return array<string, array>
	 * @phpstan-return array{phrases: list<string>, details: list<array{keyphrase: string, role: string, score: int|null}>}
	 */
	public function normalize( string $primary, string $additional_json ): array {
		$phrases = array();
		$details = array();
		$primary = $this->normalize_phrase( $primary );
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
				'phrases' => $phrases,
				'details' => $details,
			);
		}

		foreach ( array_slice( $decoded, 0, self::MAX_KEYPHRASES ) as $item ) {
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
		}

		return array(
			'phrases' => $phrases,
			'details' => $details,
		);
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

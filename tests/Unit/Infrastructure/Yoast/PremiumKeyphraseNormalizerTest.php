<?php
/**
 * Premium keyphrase normalization tests.
 *
 * @package IsuDev\WPContentBridge\Tests
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Tests\Unit\Infrastructure\Yoast;

use IsuDev\WPContentBridge\Infrastructure\Yoast\PremiumKeyphraseNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * Locks the narrow Yoast Premium 28.x JSON projection.
 */
final class PremiumKeyphraseNormalizerTest extends TestCase {

	/**
	 * Primary and valid additional keyphrases retain roles and bounded scores.
	 */
	public function test_normalizes_primary_and_additional_keyphrases(): void {
		$result = ( new PremiumKeyphraseNormalizer() )->normalize(
			'Primary phrase',
			'[{"keyword":"Additional one","score":87},{"keyword":"Additional two","score":101}]'
		);

		self::assertSame( array( 'Primary phrase', 'Additional one', 'Additional two' ), $result['phrases'] );
		self::assertSame(
			array(
				array(
					'keyphrase' => 'Primary phrase',
					'role'      => 'primary',
					'score'     => null,
				),
				array(
					'keyphrase' => 'Additional one',
					'role'      => 'additional',
					'score'     => 87,
				),
				array(
					'keyphrase' => 'Additional two',
					'role'      => 'additional',
					'score'     => null,
				),
			),
			$result['details']
		);
	}

	/**
	 * Malformed, duplicate, and unknown structures cannot escape the projection.
	 */
	public function test_rejects_malformed_and_duplicate_items(): void {
		$result = ( new PremiumKeyphraseNormalizer() )->normalize(
			'Same',
			'[{"keyword":"Same","score":50},{"unsafe":"secret"},"bad"]'
		);

		self::assertSame( array( 'Same' ), $result['phrases'] );
		self::assertCount( 1, $result['details'] );
	}
}

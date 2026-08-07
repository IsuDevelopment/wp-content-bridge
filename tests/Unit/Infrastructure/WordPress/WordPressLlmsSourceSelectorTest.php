<?php
/**
 * Unit tests for the llms.txt `noindex` interpretation logic.
 *
 * @package IsuDev\WPContentBridge\Tests
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Tests\Unit\Infrastructure\WordPress;

use IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressLlmsSourceSelector;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * `WordPressLlmsSourceSelector` cannot be constructed or exercised end-to-end
 * here: `select()` calls `WP_Query`, `get_post_type_object()`, and `get_post()`
 * directly, and this repository's unit suite never loads a WordPress runtime
 * (see the `WordPress*Repository` adapters, all left to the runtime
 * verifiers). What is genuinely application-level and provider-neutral is the
 * private static `signals_noindex()` helper: the leak-prevention decision of
 * whether a normalized `SeoDocument::$resolved['robots']` value — whatever
 * shape the active provider normalizes it to — means "excluded from
 * llms.txt". That logic touches no WordPress function and is exercised here
 * against synthetic shapes standing in for different providers' output,
 * exactly as {@see \IsuDev\WPContentBridge\Tests\Unit\Adapter\Abilities\ContentAbilitiesMcpAdapterDetectionTest}
 * reflects into a pure private method of an otherwise WordPress-bound class.
 */
final class WordPressLlmsSourceSelectorTest extends TestCase {

	/**
	 * Invokes the private static `signals_noindex()` helper.
	 *
	 * @param mixed $value Candidate resolved `robots` field value.
	 * @return bool
	 */
	private function signals_noindex( mixed $value ): bool {
		$method = new ReflectionMethod( WordPressLlmsSourceSelector::class, 'signals_noindex' );
		$method->setAccessible( true );

		return true === $method->invoke( null, $value );
	}

	/**
	 * A plain string directive containing `noindex` is recognized.
	 */
	public function test_recognizes_plain_string_noindex(): void {
		self::assertTrue( $this->signals_noindex( 'noindex, follow' ) );
	}

	/**
	 * A plain string of only positive directives is not recognized as `noindex`.
	 */
	public function test_does_not_recognize_index_string(): void {
		self::assertFalse( $this->signals_noindex( 'index, follow' ) );
	}

	/**
	 * A keyed directive map — the shape Yoast's documented Meta surface uses,
	 * `['index' => 'noindex', 'follow' => 'follow']` — is recognized via its value.
	 */
	public function test_recognizes_keyed_directive_map(): void {
		self::assertTrue(
			$this->signals_noindex(
				array(
					'index'  => 'noindex',
					'follow' => 'follow',
				)
			)
		);
	}

	/**
	 * The same keyed shape with an `index` directive of `index` is not `noindex`.
	 */
	public function test_keyed_directive_map_without_noindex_is_not_recognized(): void {
		self::assertFalse(
			$this->signals_noindex(
				array(
					'index'  => 'index',
					'follow' => 'follow',
				)
			)
		);
	}

	/**
	 * A flat list of directive strings containing `noindex` is recognized.
	 */
	public function test_recognizes_noindex_in_flat_directive_list(): void {
		self::assertTrue( $this->signals_noindex( array( 'noindex', 'nofollow' ) ) );
	}

	/**
	 * A boolean flag keyed `noindex` is recognized regardless of its own value,
	 * since a provider that emits the key at all is signalling the directive.
	 */
	public function test_recognizes_noindex_boolean_flag_key(): void {
		self::assertTrue( $this->signals_noindex( array( 'noindex' => true ) ) );
	}

	/**
	 * A `noindex` signal nested inside another array level is still found.
	 */
	public function test_recognizes_nested_noindex(): void {
		self::assertTrue(
			$this->signals_noindex(
				array(
					'advanced' => array( 'noindex' ),
				)
			)
		);
	}

	/**
	 * An absent, null, or otherwise non-string/array value never signals `noindex`;
	 * a post must never be excluded on a value the provider did not resolve.
	 */
	public function test_absent_or_unsupported_value_does_not_signal_noindex(): void {
		self::assertFalse( $this->signals_noindex( null ) );
		self::assertFalse( $this->signals_noindex( true ) );
		self::assertFalse( $this->signals_noindex( array() ) );
	}
}

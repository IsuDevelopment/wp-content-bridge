<?php
/**
 * Ability registration metadata contract tests.
 *
 * @package IsuDev\WPContentBridge\Tests
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Tests\Unit\Adapter\Abilities;

use IsuDev\WPContentBridge\Adapter\Abilities\AbilityMeta;
use PHPUnit\Framework\TestCase;

/**
 * Locks the shape every ability registers with.
 *
 * The per-ability values are asserted where they belong — against the live
 * registry in `tests/Integration/abilities-runtime-verification.php`. What is
 * worth pinning here is the shape itself, because it is now produced in one
 * place for all 31 abilities: a mistake here is a mistake everywhere at once,
 * which is the price of the deduplication.
 */
final class AbilityMetaTest extends TestCase {

	/**
	 * Every ability declares all three exposure flags explicitly.
	 *
	 * `public` is stated rather than left to core's
	 * `show_in_rest ?? public ?? false` fallback, and `mcp.public` is a
	 * different flag with a different consumer — the MCP Adapter — not a
	 * duplicate of it.
	 */
	public function test_every_shape_declares_all_three_exposure_flags(): void {
		foreach ( $this->shapes() as $label => $meta ) {
			self::assertTrue( $meta['public'], $label . ' omits the 7.1 public flag.' );
			self::assertTrue( $meta['show_in_rest'], $label . ' omits show_in_rest.' );
			self::assertTrue( $meta['mcp']['public'], $label . ' omits mcp.public.' );
		}
	}

	/**
	 * Exposure metadata carries nothing beyond the declared keys.
	 */
	public function test_shape_is_closed(): void {
		foreach ( $this->shapes() as $label => $meta ) {
			self::assertSame(
				array( 'annotations', 'public', 'show_in_rest', 'mcp' ),
				array_keys( $meta ),
				$label . ' registers unexpected metadata keys.'
			);
			self::assertSame(
				array( 'readonly', 'destructive', 'idempotent' ),
				array_keys( $meta['annotations'] ),
				$label . ' does not declare exactly the three safety annotations.'
			);
		}
	}

	/**
	 * A read is read-only, non-destructive and idempotent.
	 */
	public function test_read_annotations(): void {
		self::assertSame(
			array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			),
			AbilityMeta::read()['annotations']
		);
	}

	/**
	 * A preview is a read.
	 *
	 * The two helpers are kept distinct for intent, not for behaviour: a preview
	 * computes a write's result and stores nothing. If this ever diverges, it is
	 * a contract change and not a refactor.
	 */
	public function test_preview_is_exactly_a_read(): void {
		self::assertSame( AbilityMeta::read(), AbilityMeta::preview() );
	}

	/**
	 * A write names both annotations at the call site.
	 *
	 * This is the footgun the factory replaced: two same-named per-class
	 * helpers took a single boolean, one meaning `destructive` and the other
	 * `idempotent`, so a call site could not be read without opening the helper.
	 */
	public function test_write_annotations_are_positional_and_explicit(): void {
		self::assertSame(
			array(
				'readonly'    => false,
				'destructive' => true,
				'idempotent'  => false,
			),
			AbilityMeta::write( true, false )['annotations']
		);
		self::assertSame(
			array(
				'readonly'    => false,
				'destructive' => false,
				'idempotent'  => true,
			),
			AbilityMeta::write( false, true )['annotations']
		);
	}

	/**
	 * A write is never annotated read-only, whatever its arguments.
	 */
	public function test_a_write_is_never_readonly(): void {
		foreach ( array( array( true, true ), array( true, false ), array( false, true ), array( false, false ) ) as $case ) {
			self::assertFalse( AbilityMeta::write( $case[0], $case[1] )['annotations']['readonly'] );
		}
	}

	/**
	 * The metadata shapes this plugin can produce.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function shapes(): array {
		return array(
			'read'                => AbilityMeta::read(),
			'preview'             => AbilityMeta::preview(),
			'write (destructive)' => AbilityMeta::write( true, false ),
			'write (idempotent)'  => AbilityMeta::write( false, true ),
			'write (neither)'     => AbilityMeta::write( false, false ),
		);
	}
}

<?php
/**
 * Registration metadata shared by every ability this plugin registers.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Adapter\Abilities;

/**
 * Builds the `meta` array for `wp_register_ability()`.
 *
 * Every ability previously repeated the same three-key shape, through thirteen
 * private per-class helpers plus eight inline literals. The shape was in fact
 * consistent, so this is a deduplication rather than a repair — with two
 * exceptions worth naming, because both were live footguns:
 *
 * - `LlmsAbilities::write_meta( bool $idempotent )` and
 *   `MutationAbilities::write_meta( bool $destructive )` shared a name and took
 *   different arguments. A single boolean at a call site said nothing about
 *   which one it meant. `write()` here names both annotations explicitly.
 * - Three helper names (`read_meta`, `preview_meta`, and `read_meta` used for
 *   previews) produced one identical array. `preview()` now says what a preview
 *   *is* — a read — instead of leaving it to the reader to compare bodies.
 *
 * ## Exposure flags
 *
 * Three separate things, deliberately all stated rather than inferred:
 *
 * - `show_in_rest` — WordPress core's REST exposure. Kept **explicit** even
 *   though 7.1 would fall back to `public`, because `CLOSED_PROFILE` in
 *   `tests/Integration/abilities-runtime-verification.php` asserts exactly which
 *   abilities REST lists. An explicit value is what makes a future intentional
 *   divergence — public elsewhere, absent from REST — a one-line reviewable
 *   change instead of an emergent consequence of a fallback.
 * - `public` — WordPress 7.1's unified exposure flag, resolved by core as
 *   `show_in_rest ?? public ?? false`. Declared so channels that read only
 *   `public` (AI Client function declarations, future adapters) see these
 *   abilities at all.
 * - `mcp.public` — read by the MCP Adapter. **Not** core's `public` and not a
 *   duplicate of it: different key, different consumer, different nesting. Do
 *   not "consolidate" the two.
 *
 * None of the three authorizes anything. Exposure decides what a client can
 * discover; `permission_callback` plus the capability and policy gates decide
 * what it may execute (ADR 0025).
 */
final class AbilityMeta {

	/**
	 * Metadata for a read ability.
	 *
	 * @return array<string, mixed>
	 */
	public static function read(): array {
		return self::build( true, false, true );
	}

	/**
	 * Metadata for a preview ability.
	 *
	 * A preview computes the result of a write and stores nothing, so it is a
	 * read and is annotated as one. The separate name records the intent: these
	 * abilities must stay side-effect-free, and a change here that made them
	 * anything else would be a contract change.
	 *
	 * @return array<string, mixed>
	 */
	public static function preview(): array {
		return self::read();
	}

	/**
	 * Metadata for a write ability.
	 *
	 * @param bool $destructive Whether the operation can lose content the client did not supply.
	 * @param bool $idempotent  Whether replaying the same input has no additional effect.
	 * @return array<string, mixed>
	 */
	public static function write( bool $destructive, bool $idempotent ): array {
		return self::build( false, $destructive, $idempotent );
	}

	/**
	 * Assembles the metadata array.
	 *
	 * @param bool $reads_only  Whether the ability only reads.
	 * @param bool $destructive Whether the ability can lose content.
	 * @param bool $idempotent  Whether replaying has no additional effect.
	 * @return array<string, mixed>
	 */
	private static function build( bool $reads_only, bool $destructive, bool $idempotent ): array {
		return array(
			'annotations'  => array(
				'readonly'    => $reads_only,
				'destructive' => $destructive,
				'idempotent'  => $idempotent,
			),
			'public'       => true,
			'show_in_rest' => true,
			'mcp'          => array( 'public' => true ),
		);
	}
}

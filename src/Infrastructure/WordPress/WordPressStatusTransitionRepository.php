<?php
/**
 * WordPress option-backed status transition repository.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Infrastructure\WordPress;

use IsuDev\WPContentBridge\Application\Status\StatusTransitionSettingsRepository;

/**
 * Loads and persists the status transition matrix in one non-autoloaded
 * WordPress option, `wpcb_status_transitions`.
 *
 * ADR 0024 requires "absent" (never configured) to stay distinguishable
 * from "configured to nothing" (explicitly saved as an empty matrix), and
 * both mean deny-all in effect. This class represents the difference the
 * same way {@see \IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressLlmsArtifactStore}
 * represents an absent snapshot: `get_option()` is called with `false` as
 * its default, a value `update_option()` never stores for this option (it
 * always stores an array, even an empty one). If the raw stored value is
 * still exactly `false`, the option row itself does not exist and nobody
 * has ever saved this matrix; any other array value, including `[]`, means
 * an administrator explicitly saved it. {@see self::is_configured()} is the
 * only place that reads the raw value for this purpose — every other
 * method treats both cases as equivalent, because the resulting
 * authorization is identical either way.
 *
 * Deliberately **not** seeded by `Installer::activate()` or
 * `maybe_upgrade()`, unlike this plugin's boolean feature flags. Seeding it
 * with an empty array on activation would make every fresh install
 * indistinguishable from an administrator who visited the settings screen
 * and explicitly saved nothing, defeating the distinction this class
 * exists to preserve.
 */
final class WordPressStatusTransitionRepository implements StatusTransitionSettingsRepository {

	public const OPTION_NAME = 'wpcb_status_transitions';

	/**
	 * Loads and shape-checks the stored matrix.
	 *
	 * @return array<string, mixed>
	 */
	public function load(): array {
		$value = get_option( self::OPTION_NAME, false );

		if ( ! is_array( $value ) ) {
			return array();
		}

		return $this->normalize_rows( $value );
	}

	/**
	 * Reports whether the option row exists at all. See the class docblock
	 * for why this differs from an empty {@see self::load()} result.
	 *
	 * @return bool
	 */
	public function is_configured(): bool {
		return false !== get_option( self::OPTION_NAME, false );
	}

	/**
	 * Replaces the stored matrix with a single, non-autoloaded write.
	 *
	 * @param array<string, mixed> $rows Rows to persist.
	 * @return void
	 */
	public function save( array $rows ): void {
		update_option( self::OPTION_NAME, $rows, false );
	}

	/**
	 * Removes non-string keys from the stored option value.
	 *
	 * @param array<int|string, mixed> $value Raw stored option value.
	 * @return array<string, mixed>
	 */
	private function normalize_rows( array $value ): array {
		$normalized = array();

		foreach ( $value as $post_type => $pairs ) {
			if ( is_string( $post_type ) ) {
				$normalized[ $post_type ] = $pairs;
			}
		}

		return $normalized;
	}
}

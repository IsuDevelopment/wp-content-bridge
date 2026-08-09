<?php
/**
 * Status transition settings port.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Application\Status;

/**
 * Loads and persists the raw stored status transition matrix.
 *
 * Deliberately exposes presence separately from content: {@see self::load()}
 * alone cannot tell "never configured" apart from "configured to nothing",
 * and the settings screen must tell them apart. See
 * {@see \IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressStatusTransitionRepository}
 * for how the underlying option represents that difference.
 */
interface StatusTransitionSettingsRepository {

	/**
	 * Loads the raw stored rows, shape-checked but not otherwise validated.
	 *
	 * Returns an empty array both when the option is absent and when it is
	 * stored as an empty array; use {@see self::is_configured()} to tell
	 * those two cases apart.
	 *
	 * @return array<string, mixed>
	 */
	public function load(): array;

	/**
	 * Reports whether the option has ever been saved, independent of its
	 * content.
	 *
	 * @return bool
	 */
	public function is_configured(): bool;

	/**
	 * Replaces the stored rows.
	 *
	 * @param array<string, mixed> $rows Rows to persist, matching {@see \IsuDev\WPContentBridge\Domain\Status\StatusTransitionConfig::to_array()}.
	 * @return void
	 */
	public function save( array $rows ): void;
}

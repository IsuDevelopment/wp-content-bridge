<?php
/**
 * Content access settings port.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Application\ContentAccess;

/**
 * Loads the persisted content access matrix.
 */
interface ContentAccessSettingsRepository {

	/**
	 * Loads raw stored rows.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function load(): array;
}

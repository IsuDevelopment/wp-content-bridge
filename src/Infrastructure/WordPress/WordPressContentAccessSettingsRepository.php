<?php
/**
 * WordPress option-backed settings repository.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Infrastructure\WordPress;

use IsuDev\WPContentBridge\Application\ContentAccess\ContentAccessSettingsRepository;

/**
 * Loads the matrix from one non-autoloaded WordPress option.
 */
final class WordPressContentAccessSettingsRepository implements ContentAccessSettingsRepository {

	public const OPTION_NAME = 'wpcb_content_type_access';

	/**
	 * Loads and shape-checks the stored matrix.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function load(): array {
		$value = get_option( self::OPTION_NAME, array() );

		if ( ! is_array( $value ) ) {
			return array();
		}

		$settings = array();

		foreach ( $value as $post_type => $operations ) {
			if ( is_string( $post_type ) && is_array( $operations ) ) {
				$settings[ $post_type ] = $this->normalize_row( $operations );
			}
		}

		return $settings;
	}

	/**
	 * Removes non-string keys from a stored operation row.
	 *
	 * @param array<mixed> $operations Stored operation row.
	 * @return array<string, mixed>
	 */
	private function normalize_row( array $operations ): array {
		$normalized = array();

		foreach ( $operations as $operation => $value ) {
			if ( is_string( $operation ) ) {
				$normalized[ $operation ] = $value;
			}
		}

		return $normalized;
	}
}

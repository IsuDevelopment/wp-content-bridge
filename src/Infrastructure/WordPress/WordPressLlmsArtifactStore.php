<?php
/**
 * WordPress llms.txt configuration and snapshot storage adapter.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Infrastructure\WordPress;

use InvalidArgumentException;
use IsuDev\WPContentBridge\Application\Llms\LlmsArtifactStore;
use IsuDev\WPContentBridge\Domain\Llms\LlmsArtifact;
use IsuDev\WPContentBridge\Domain\Llms\LlmsConfig;

/**
 * Persists llms.txt configuration and its generated snapshot in two
 * non-autoloaded WordPress options.
 *
 * Each write is a single `update_option()` call built from an
 * already-validated, fully-constructed domain object; nothing is written in
 * stages, so a failed write leaves the previously stored option value
 * completely intact. A stored value that fails to shape-check on read is
 * treated as absent rather than thrown, matching this codebase's other
 * option-backed adapters.
 */
final class WordPressLlmsArtifactStore implements LlmsArtifactStore {

	public const CONFIG_OPTION   = 'wpcb_llms_config';
	public const ARTIFACT_OPTION = 'wpcb_llms_artifact';

	/**
	 * Reads the stored configuration, if one has been saved.
	 *
	 * @return LlmsConfig|null
	 */
	public function config(): ?LlmsConfig {
		$value = get_option( self::CONFIG_OPTION, false );
		if ( ! is_array( $value ) ) {
			return null;
		}

		$input = array();
		foreach ( $value as $key => $item ) {
			if ( is_string( $key ) ) {
				$input[ $key ] = $item;
			}
		}

		try {
			return LlmsConfig::from_input( $input );
		} catch ( InvalidArgumentException ) {
			return null;
		}
	}

	/**
	 * Atomically replaces the stored configuration.
	 *
	 * @param LlmsConfig $config Configuration to persist.
	 * @return void
	 */
	public function replace_config( LlmsConfig $config ): void {
		update_option( self::CONFIG_OPTION, $config->to_array(), false );
	}

	/**
	 * Reads the stored snapshot, if one has been generated.
	 *
	 * @return LlmsArtifact|null
	 */
	public function artifact(): ?LlmsArtifact {
		$value = get_option( self::ARTIFACT_OPTION, false );
		if ( ! is_array( $value ) ) {
			return null;
		}

		return $this->to_artifact( $value );
	}

	/**
	 * Atomically replaces the stored snapshot.
	 *
	 * @param LlmsArtifact $artifact Snapshot to persist.
	 * @return void
	 */
	public function replace_artifact( LlmsArtifact $artifact ): void {
		update_option( self::ARTIFACT_OPTION, $artifact->to_array(), false );
	}

	/**
	 * Shape-checks a stored artifact array before trusting it.
	 *
	 * @param array $value Raw stored option value.
	 * @return LlmsArtifact|null
	 * @phpstan-param array<int|string, mixed> $value
	 */
	private function to_artifact( array $value ): ?LlmsArtifact {
		if (
			! isset( $value['content'], $value['content_hash'], $value['generated_at'], $value['byte_count'], $value['link_count'], $value['warnings'] )
			|| ! is_string( $value['content'] )
			|| ! is_string( $value['content_hash'] )
			|| ! is_string( $value['generated_at'] )
			|| ! is_int( $value['byte_count'] )
			|| ! is_int( $value['link_count'] )
			|| ! is_array( $value['warnings'] )
		) {
			return null;
		}

		$warnings = array();
		foreach ( $value['warnings'] as $warning ) {
			if ( ! is_string( $warning ) ) {
				return null;
			}
			$warnings[] = $warning;
		}

		return new LlmsArtifact(
			$value['content'],
			$value['content_hash'],
			$value['generated_at'],
			$value['byte_count'],
			$value['link_count'],
			$warnings
		);
	}
}

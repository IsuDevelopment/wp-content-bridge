<?php
/**
 * Status transition configuration application service.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Application\Status;

use InvalidArgumentException;
use IsuDev\WPContentBridge\Domain\Status\StatusTransition;
use IsuDev\WPContentBridge\Domain\Status\StatusTransitionConfig;
use IsuDev\WPContentBridge\Domain\Status\StatusTransitionGraph;

/**
 * Loads the effective transition graph and normalizes settings-screen
 * submissions for it.
 *
 * This mirrors {@see \IsuDev\WPContentBridge\Application\ContentAccess\ContentAccessManager::normalize_submitted()}:
 * rows for post types the current request does not consider eligible are
 * preserved unchanged rather than dropped, because a post type can stop
 * existing after it was configured and this class must not be the thing
 * that erases that configuration (see the graph's own docblock).
 */
final readonly class StatusTransitionManager {

	/**
	 * Creates the service.
	 *
	 * @param StatusTransitionSettingsRepository $repository Settings storage port.
	 */
	public function __construct(
		private StatusTransitionSettingsRepository $repository,
	) {
	}

	/**
	 * Reports whether the option has ever been saved, independent of its
	 * content. See {@see StatusTransitionSettingsRepository::is_configured()}.
	 *
	 * @return bool
	 */
	public function is_configured(): bool {
		return $this->repository->is_configured();
	}

	/**
	 * Returns the effective configuration.
	 *
	 * A stored value that fails to validate is treated as absent (deny-all),
	 * matching {@see \IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressLlmsArtifactStore}'s
	 * convention for other option-backed adapters in this codebase.
	 *
	 * @return StatusTransitionConfig
	 */
	public function config(): StatusTransitionConfig {
		try {
			return StatusTransitionConfig::from_input( $this->repository->load() );
		} catch ( InvalidArgumentException ) {
			return StatusTransitionConfig::empty();
		}
	}

	/**
	 * Normalizes a settings-screen submission into the stored wire shape,
	 * without persisting it.
	 *
	 * The submitted shape is nested checkboxes,
	 * `submitted[post_type][from][to] = '1'`, one per
	 * {@see StatusTransition::all_possible()} entry; anything else at a given
	 * cell is treated as unchecked. Because that enumeration is fixed at 20
	 * entries, a row built from it can never exceed
	 * {@see StatusTransitionGraph::MAX_PAIRS_PER_POST_TYPE} on its own — only
	 * an oversized `$eligible_post_types` list could exceed
	 * {@see StatusTransitionGraph::MAX_POST_TYPES}, which is exactly the case
	 * the final validation guards.
	 *
	 * If the merged result still fails validation (for example, that
	 * post-type-count bound), the whole submission is rejected and the
	 * previously stored rows are returned unchanged — never a guessed
	 * truncation, matching {@see StatusTransitionConfig}'s own rule.
	 *
	 * @param mixed $submitted           Submitted option value.
	 * @param array $eligible_post_types Post types the matrix rendered rows for.
	 * @phpstan-param list<string> $eligible_post_types
	 * @return array<string, mixed>
	 */
	public function normalize_submitted( mixed $submitted, array $eligible_post_types ): array {
		$normalized = $this->merge_rows( is_array( $submitted ) ? $submitted : array(), $eligible_post_types );

		try {
			return StatusTransitionConfig::from_input( $normalized )->to_array();
		} catch ( InvalidArgumentException ) {
			return $this->repository->load();
		}
	}

	/**
	 * Builds and persists the editorial preset from ADR 0024 for the given
	 * post types.
	 *
	 * This is only ever invoked by a settings-screen action the administrator
	 * chooses to press; nothing in activation, upgrade, or any other path
	 * calls it. Rows for post types outside `$eligible_post_types` are
	 * preserved unchanged, matching {@see self::normalize_submitted()}.
	 *
	 * @param array $eligible_post_types Post types to apply the preset to.
	 * @phpstan-param list<string> $eligible_post_types
	 * @return array<string, mixed>
	 */
	public function apply_editorial_preset( array $eligible_post_types ): array {
		$preset = StatusTransitionGraph::editorial_preset_pairs();

		$submitted = array();
		foreach ( $eligible_post_types as $post_type ) {
			$submitted[ $post_type ] = array();
			foreach ( $preset as $transition ) {
				$submitted[ $post_type ][ $transition->from->value ][ $transition->to->value ] = '1';
			}
		}

		$normalized = $this->normalize_submitted( $submitted, $eligible_post_types );
		$this->repository->save( $normalized );

		return $normalized;
	}

	/**
	 * Merges preserved rows with freshly extracted submitted rows.
	 *
	 * @param array $submitted_rows      Raw submitted option value, already known to be an array.
	 * @param array $eligible_post_types Post types the matrix rendered rows for.
	 * @phpstan-param array<array-key, mixed> $submitted_rows
	 * @phpstan-param list<string> $eligible_post_types
	 * @return array<string, mixed>
	 */
	private function merge_rows( array $submitted_rows, array $eligible_post_types ): array {
		$normalized = array();

		foreach ( $this->repository->load() as $post_type => $pairs ) {
			if ( ! in_array( $post_type, $eligible_post_types, true ) ) {
				$normalized[ $post_type ] = $pairs;
			}
		}

		foreach ( $eligible_post_types as $post_type ) {
			$row                      = isset( $submitted_rows[ $post_type ] ) && is_array( $submitted_rows[ $post_type ] ) ? $submitted_rows[ $post_type ] : array();
			$normalized[ $post_type ] = $this->extract_pairs( $row );
		}

		ksort( $normalized );

		return $normalized;
	}

	/**
	 * Extracts checked `(from, to)` pairs from one submitted post-type row.
	 *
	 * @param array $row Submitted row, `row[from][to]` checkbox values.
	 * @phpstan-param array<array-key, mixed> $row
	 * @return array
	 * @phpstan-return list<array{from: string, to: string}>
	 */
	private function extract_pairs( array $row ): array {
		$pairs = array();

		foreach ( StatusTransition::all_possible() as $candidate ) {
			$targets = $row[ $candidate->from->value ] ?? null;
			if ( ! is_array( $targets ) ) {
				continue;
			}

			if ( $this->is_checked( $targets[ $candidate->to->value ] ?? null ) ) {
				$pairs[] = array(
					'from' => $candidate->from->value,
					'to'   => $candidate->to->value,
				);
			}
		}

		return $pairs;
	}

	/**
	 * Accepts only the values emitted by a WordPress checkbox, matching
	 * {@see \IsuDev\WPContentBridge\Adapter\Admin\ContentAccessSettingsPage::sanitize_checkbox()}.
	 *
	 * @param mixed $value Raw submitted cell value.
	 * @return bool
	 */
	private function is_checked( mixed $value ): bool {
		return true === $value || 1 === $value || '1' === $value || 'yes' === $value || 'on' === $value;
	}
}

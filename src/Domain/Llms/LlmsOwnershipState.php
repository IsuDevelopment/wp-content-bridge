<?php
/**
 * Detected llms.txt ownership state.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Domain\Llms;

/**
 * Immutable, read-only ownership report for `/llms.txt`.
 *
 * This never resolves a conflict; it only describes one. `owner` and
 * `conflict` are convenience summaries derived from the three booleans, so a
 * caller who wants raw signals can read those directly and a caller who
 * wants a decision can read `owner`/`conflict`/`administrator_action`
 * instead. Every field is safe to surface to an Ability response: nothing
 * here is or contains a filesystem path, and `administrator_action` is
 * prose, never a path.
 */
final readonly class LlmsOwnershipState {

	/**
	 * Creates a state.
	 *
	 * @param LlmsOwnershipOwner         $owner                      Best-effort detected claimant.
	 * @param bool                       $physical_artifact_exists   Whether a physical `llms.txt` exists at the web root.
	 * @param bool                       $legacy_full_artifact_exists Whether a physical `llms-full.txt` exists at the web root.
	 * @param bool                       $legacy_docs_directory_exists Whether a physical `llms-docs/` directory exists at the web root.
	 * @param bool                       $yoast_llms_txt_enabled     Whether Yoast SEO's `llms.txt` feature is enabled.
	 * @param bool                       $bridge_publication_enabled Whether the bridge's own publication flag is enabled.
	 * @param bool                       $bridge_route_routable      Whether pretty permalinks allow the virtual route to match.
	 * @param LlmsPublicVerification     $public_verification        What a same-site `GET` of the public path actually observed.
	 * @param LlmsOwnershipConflict|null $conflict               Blocking conflict code, or null when publication may proceed.
	 * @param string                     $administrator_action       Safe, human-readable next step; never a filesystem path.
	 */
	public function __construct(
		public LlmsOwnershipOwner $owner,
		public bool $physical_artifact_exists,
		public bool $legacy_full_artifact_exists,
		public bool $legacy_docs_directory_exists,
		public bool $yoast_llms_txt_enabled,
		public bool $bridge_publication_enabled,
		public bool $bridge_route_routable,
		public LlmsPublicVerification $public_verification,
		public ?LlmsOwnershipConflict $conflict,
		public string $administrator_action,
	) {
	}

	/**
	 * Whether any exact legacy-generator target remains at the web root.
	 *
	 * @return bool
	 */
	public function has_legacy_artifacts(): bool {
		return $this->physical_artifact_exists
			|| $this->legacy_full_artifact_exists
			|| $this->legacy_docs_directory_exists;
	}

	/**
	 * Whether this state blocks the bridge from claiming public ownership.
	 *
	 * @return bool
	 */
	public function is_blocking(): bool {
		return null !== $this->conflict;
	}

	/**
	 * Serializes the public wire document.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'owner'                        => $this->owner->value,
			'physical_artifact_exists'     => $this->physical_artifact_exists,
			'legacy_full_artifact_exists'  => $this->legacy_full_artifact_exists,
			'legacy_docs_directory_exists' => $this->legacy_docs_directory_exists,
			'yoast_llms_txt_enabled'       => $this->yoast_llms_txt_enabled,
			'bridge_publication_enabled'   => $this->bridge_publication_enabled,
			'bridge_route_routable'        => $this->bridge_route_routable,
			'public_verification'          => $this->public_verification->value,
			'conflict'                     => $this->conflict?->value,
			'administrator_action'         => $this->administrator_action,
		);
	}
}

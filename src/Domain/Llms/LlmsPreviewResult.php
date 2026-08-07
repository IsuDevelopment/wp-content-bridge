<?php
/**
 * Result of previewing an llms.txt configuration update.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Domain\Llms;

use IsuDev\WPContentBridge\Domain\Mutation\VersionToken;

/**
 * Carries the current and prospective configuration and artifact summaries,
 * plus a section-level diff, without performing any write.
 */
final readonly class LlmsPreviewResult {

	/**
	 * Creates the result.
	 *
	 * @param VersionToken      $version              Current optimistic-concurrency token.
	 * @param LlmsConfig|null   $current_config       Currently stored configuration, if any.
	 * @param LlmsArtifact|null $current_artifact     Currently stored snapshot, if any.
	 * @param LlmsConfig        $prospective_config   Prospective configuration from the request.
	 * @param LlmsArtifact      $prospective_artifact Prospective snapshot the update would store.
	 */
	public function __construct(
		public VersionToken $version,
		public ?LlmsConfig $current_config,
		public ?LlmsArtifact $current_artifact,
		public LlmsConfig $prospective_config,
		public LlmsArtifact $prospective_artifact,
	) {
	}

	/**
	 * Returns the public wire document.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'schema_version'       => '1.0',
			'writes_performed'     => false,
			'version_token'        => $this->version->to_string(),
			'current_config'       => $this->current_config?->to_array(),
			'current_artifact'     => $this->current_artifact?->to_summary_array(),
			'prospective_config'   => $this->prospective_config->to_array(),
			'prospective_artifact' => $this->prospective_artifact->to_summary_array(),
			'diff'                 => LlmsDocumentDiff::diff(
				$this->current_artifact->content ?? '',
				$this->prospective_artifact->content
			),
			'provenance'           => array(
				'source'    => 'wordpress-llms-txt',
				'untrusted' => true,
			),
		);
	}
}

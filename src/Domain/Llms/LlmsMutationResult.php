<?php
/**
 * Result of a successful llms.txt write.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Domain\Llms;

use IsuDev\WPContentBridge\Domain\Mutation\VersionToken;

/**
 * Shared result shape for update-llms-txt and regenerate-llms-txt: the
 * resulting optimistic-concurrency token, the effective configuration and
 * artifact summary after the write, and the top-level field names that
 * changed. `changed_fields` is empty for a regeneration that left the
 * configuration untouched, and also empty for a regeneration whose rebuilt
 * content was identical to what was already stored.
 */
final readonly class LlmsMutationResult {

	/**
	 * Creates the result.
	 *
	 * @param VersionToken       $version        Resulting optimistic-concurrency token.
	 * @param LlmsConfig         $config         Effective configuration after the write.
	 * @param LlmsArtifact       $artifact       Effective snapshot after the write.
	 * @param array<int, string> $changed_fields Top-level field names that changed, never values.
	 * @param LlmsOwnershipState $ownership      Local publication state after the write.
	 */
	public function __construct(
		public VersionToken $version,
		public LlmsConfig $config,
		public LlmsArtifact $artifact,
		public array $changed_fields,
		public LlmsOwnershipState $ownership,
	) {
	}

	/**
	 * Returns the public wire document.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'schema_version' => '1.0',
			'version_token'  => $this->version->to_string(),
			'config'         => $this->config->to_array(),
			'artifact'       => $this->artifact->to_summary_array(),
			'changed_fields' => array_values( $this->changed_fields ),
			'ownership'      => $this->ownership->to_array(),
			'provenance'     => array(
				'source'    => 'wordpress-llms-txt',
				'untrusted' => true,
			),
		);
	}
}

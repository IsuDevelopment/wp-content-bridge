<?php
/**
 * Result of reading llms.txt configuration and state.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Domain\Llms;

use IsuDev\WPContentBridge\Domain\Mutation\VersionToken;

/**
 * Carries the current configuration, current artifact summary, and ownership
 * state get-llms-txt reports so an administrator can inspect them before
 * enabling publication. `config` and `artifact` are null when nothing has
 * been configured or generated yet.
 */
final readonly class LlmsReadResult {

	/**
	 * Creates the result.
	 *
	 * @param LlmsConfig|null    $config   Stored configuration, if any.
	 * @param LlmsArtifact|null  $artifact Stored snapshot, if any.
	 * @param LlmsOwnershipState $ownership Detected ownership state.
	 * @param VersionToken       $version   Current optimistic-concurrency token.
	 */
	public function __construct(
		public ?LlmsConfig $config,
		public ?LlmsArtifact $artifact,
		public LlmsOwnershipState $ownership,
		public VersionToken $version,
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
			'config'         => $this->config?->to_array(),
			'artifact'       => $this->artifact?->to_summary_array(),
			'ownership'      => $this->ownership->to_array(),
			'version_token'  => $this->version->to_string(),
			'provenance'     => array(
				'source'    => 'wordpress-llms-txt',
				'untrusted' => true,
			),
		);
	}
}

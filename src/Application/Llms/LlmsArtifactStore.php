<?php
/**
 * Llms.txt configuration and snapshot storage port.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Application\Llms;

use IsuDev\WPContentBridge\Domain\Llms\LlmsArtifact;
use IsuDev\WPContentBridge\Domain\Llms\LlmsConfig;

/**
 * Reads and atomically replaces the stored llms.txt configuration and its
 * generated snapshot.
 *
 * Replacement is atomic at this boundary: a failed write must leave the
 * previously stored state completely intact. A later slice's virtual
 * endpoint serves whatever this port last durably held, performing no
 * generation of its own, so a partial write here would be a public leak or
 * outage, not merely a lost update.
 */
interface LlmsArtifactStore {

	/**
	 * Reads the stored configuration, if one has been saved.
	 *
	 * @return LlmsConfig|null
	 */
	public function config(): ?LlmsConfig;

	/**
	 * Atomically replaces the stored configuration.
	 *
	 * @param LlmsConfig $config Configuration to persist.
	 * @return void
	 */
	public function replace_config( LlmsConfig $config ): void;

	/**
	 * Reads the stored snapshot, if one has been generated.
	 *
	 * @return LlmsArtifact|null
	 */
	public function artifact(): ?LlmsArtifact;

	/**
	 * Atomically replaces the stored snapshot.
	 *
	 * @param LlmsArtifact $artifact Snapshot to persist.
	 * @return void
	 */
	public function replace_artifact( LlmsArtifact $artifact ): void;
}

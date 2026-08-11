<?php
/**
 * Result of an explicit administrator llms.txt ownership adoption.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Domain\Llms;

/**
 * Carries only safe basenames and the post-adoption ownership state.
 */
final readonly class LlmsOwnershipAdoptionResult {

	/**
	 * Creates the result.
	 *
	 * @param array<int, string> $archived_artifacts Archived basenames, never paths.
	 * @param LlmsOwnershipState $ownership          State after archival.
	 */
	public function __construct(
		public array $archived_artifacts,
		public LlmsOwnershipState $ownership,
	) {
	}
}

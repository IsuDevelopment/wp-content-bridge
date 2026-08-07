<?php
/**
 * Llms.txt source-entry selection port.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Application\Llms;

use IsuDev\WPContentBridge\Domain\Llms\LlmsConfig;
use IsuDev\WPContentBridge\Domain\Llms\LlmsSourceEntry;

/**
 * Resolves the ordered, already-authorized entries eligible for llms.txt.
 *
 * Implementations decide eligibility — publish status, password protection,
 * configured post type, and the active SEO provider's `noindex` resolution —
 * and must query in bounded batches rather than loading a whole site into
 * memory. {@see \IsuDev\WPContentBridge\Domain\Llms\LlmsDocumentBuilder} does
 * not and must not re-derive eligibility from the entries this port returns.
 */
interface LlmsSourceSelector {

	/**
	 * Selects eligible, already-authorized entries for the given configuration.
	 *
	 * @param LlmsConfig $config Effective llms.txt configuration.
	 * @return array
	 * @phpstan-return list<LlmsSourceEntry>
	 */
	public function select( LlmsConfig $config ): array;
}

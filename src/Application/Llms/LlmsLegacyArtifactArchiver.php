<?php
/**
 * Legacy llms.txt filesystem archival port.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Application\Llms;

/**
 * Archives the closed set of legacy physical artifacts that can remain after
 * another llms.txt generator is disabled.
 *
 * Implementations never accept caller-supplied paths. The only supported
 * targets are `/llms.txt`, `/llms-full.txt`, and `/llms-docs/` at the site's
 * resolved web root.
 */
interface LlmsLegacyArtifactArchiver {

	/**
	 * Archives every known legacy artifact that currently exists.
	 *
	 * @return array<int, string> Archived basenames, never absolute paths.
	 */
	public function archive(): array;
}

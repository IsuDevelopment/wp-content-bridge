<?php
/**
 * One already-selected, already-authorized llms.txt candidate entry.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Domain\Llms;

/**
 * Transport-neutral entry crossing the {@see \IsuDev\WPContentBridge\Application\Llms\LlmsSourceSelector}
 * port into {@see LlmsDocumentBuilder}.
 *
 * Carries no eligibility or authorization state: the selector already decided
 * this entry may appear in the artifact. The builder still treats `title`,
 * `url`, and `excerpt` as untrusted public content and sanitizes them before
 * emission; it never re-derives eligibility from this object.
 */
final readonly class LlmsSourceEntry {

	/**
	 * Creates one entry.
	 *
	 * @param string      $title   Content title, untrusted public text.
	 * @param string      $url     Canonical absolute content URL, untrusted public text.
	 * @param string|null $excerpt Optional excerpt, untrusted public text.
	 * @param string      $section Section key the selector assigned this entry to.
	 */
	public function __construct(
		public string $title,
		public string $url,
		public ?string $excerpt,
		public string $section,
	) {
	}
}

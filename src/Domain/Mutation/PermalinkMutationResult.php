<?php
/**
 * Result of one permalink change.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Domain\Mutation;

/**
 * Carries the mutation envelope plus the URL on both sides of the change.
 */
final readonly class PermalinkMutationResult {

	/**
	 * Creates the result.
	 *
	 * @param MutationResult                   $mutation Standard post mutation envelope.
	 * @param array{slug: string, url: string} $before  Slug and URL before the write.
	 * @param array{slug: string, url: string} $after   Slug and URL after the write.
	 */
	public function __construct(
		public MutationResult $mutation,
		public array $before,
		public array $after,
	) {}

	/**
	 * Returns the public wire document.
	 *
	 * The previous URL is returned because a caller that changes a permalink
	 * usually needs it next - to create a redirect from it. WordPress stores a
	 * `_wp_old_slug` and resolves the old URL on its own for post-type objects
	 * with a permalink structure that includes the slug, but that fallback does
	 * not cover every rewrite, so the old URL is handed back rather than
	 * assumed to be someone else's problem.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		$document = $this->mutation->to_array();

		$document['permalink'] = array(
			'previous_slug' => $this->before['slug'],
			'previous_url'  => $this->before['url'],
			'slug'          => $this->after['slug'],
			'url'           => $this->after['url'],
		);

		return $document;
	}
}

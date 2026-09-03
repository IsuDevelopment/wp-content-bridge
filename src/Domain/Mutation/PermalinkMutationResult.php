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
	 * @param array{slug: string, url: string} $before   Slug and URL before the write.
	 * @param array{slug: string, url: string} $after    Slug and URL after the write.
	 * @param array                            $cache_channels Cache channels notified for the old and new URL.
	 * @phpstan-param list<string> $cache_channels
	 */
	public function __construct(
		public MutationResult $mutation,
		public array $before,
		public array $after,
		public array $cache_channels = array(),
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

		/*
		 * ADR 0032. The post-scoped invalidation every write inherits cannot
		 * reach a page-cache entry for the *old* URL, which is keyed by a URL
		 * no longer associated with the post - and that entry serves a stale
		 * page rather than none. What this reports is what was notified, never
		 * that a purge was confirmed: dispatching a hook proves a listener
		 * ran, not that a cached page was dropped. `delegated` says the actual
		 * purge depends on site-level glue this plugin cannot see, which is
		 * the honest answer and is actionable, where silence is neither.
		 */
		$document['permalink']['cache'] = array(
			'urls'      => array_values( array_unique( array( $this->before['url'], $this->after['url'] ) ) ),
			'notified'  => $this->cache_channels,
			'delegated' => array( 'wp-content-bridge' ) === $this->cache_channels || array() === $this->cache_channels,
		);

		return $document;
	}
}

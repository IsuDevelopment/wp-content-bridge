<?php
/**
 * WordPress llms.txt source-entry selection adapter.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Infrastructure\WordPress;

use IsuDev\WPContentBridge\Application\Llms\LlmsSourceSelector;
use IsuDev\WPContentBridge\Application\Seo\SeoProvider;
use IsuDev\WPContentBridge\Application\Seo\SeoProviderRegistry;
use IsuDev\WPContentBridge\Domain\Llms\LlmsConfig;
use IsuDev\WPContentBridge\Domain\Llms\LlmsSourceEntry;
use IsuDev\WPContentBridge\Domain\Seo\SeoTarget;
use Throwable;
use WP_Post;
use WP_Post_Type;
use WP_Query;

/**
 * Selects `publish`, non-password-protected entries of a public, non-internal,
 * configured post type, excluding anything the active SEO provider resolves
 * as `noindex`.
 *
 * This is `wp-content-bridge`'s leak-prevention boundary for llms.txt (ADR
 * 0023): every eligibility rule below is re-checked against live WordPress
 * state rather than trusted from configuration, and nothing here calls Yoast
 * or `WPSEO_*` directly — only the provider-neutral {@see SeoProviderRegistry}
 * port. This class runs only inside an authenticated Ability or a scheduled
 * job (never a front-end request) and queries in bounded batches, never `-1`,
 * so a large site is never loaded into memory at once.
 *
 * A section key is not a separate configuration concern here: each returned
 * entry is tagged with its own post type slug as its section, matching how
 * `LlmsConfig::$sections` is keyed by convention (one section per enabled post
 * type). {@see \IsuDev\WPContentBridge\Domain\Llms\LlmsDocumentBuilder} drops
 * any entry whose section does not match a configured section key, so a
 * configuration that names sections differently degrades safely rather than
 * leaking content into the wrong bucket.
 */
final class WordPressLlmsSourceSelector implements LlmsSourceSelector {

	/**
	 * Batch size for each `WP_Query` page. Never `-1`; bounded to a few hundred
	 * per ADR 0023 and ADR ambient "never load the whole site at once" rule.
	 *
	 * @var int
	 */
	private const BATCH_SIZE = 200;

	/**
	 * Hard ceiling on batches scanned per post type. Bounds worst-case cost when
	 * a section's cap cannot be satisfied (for example, a type where most posts
	 * are password-protected or resolved `noindex`) so a pathological site still
	 * finishes in bounded time instead of scanning indefinitely.
	 *
	 * @var int
	 */
	private const MAX_BATCHES_PER_POST_TYPE = 25;

	/**
	 * Warnings recorded by the most recent {@see self::select()} call.
	 *
	 * Not part of the {@see LlmsSourceSelector} port, whose return type carries
	 * only entries: this is the run-level signal a caller needs to see when the
	 * `noindex` filter did not execute at all (no active SEO provider), which is
	 * not a property of any single entry.
	 *
	 * @var list<string>
	 */
	private array $warnings = array();

	/**
	 * Creates the selector.
	 *
	 * @param SeoProviderRegistry $seo_providers Provider-neutral `noindex` lookup.
	 */
	public function __construct(
		private readonly SeoProviderRegistry $seo_providers,
	) {
	}

	/**
	 * Selects eligible, already-authorized entries for the given configuration.
	 *
	 * @param LlmsConfig $config Effective llms.txt configuration.
	 * @return array
	 * @phpstan-return list<LlmsSourceEntry>
	 */
	public function select( LlmsConfig $config ): array {
		$this->warnings = array();

		$provider = $this->seo_providers->active();
		if ( ! $provider->is_available() ) {
			$this->warnings[] = 'No SEO provider is active: the noindex filter did not run and eligible content was included unfiltered.';
		}

		$item_cap = min( $config->max_items_per_section, LlmsConfig::MAX_ITEMS_PER_SECTION );

		$entries = array();
		foreach ( $config->enabled_post_types as $post_type ) {
			if ( ! $this->is_public_content_type( $post_type ) ) {
				continue;
			}

			array_push( $entries, ...$this->collect_for_post_type( $post_type, $item_cap, $config, $provider ) );
		}

		return $entries;
	}

	/**
	 * Returns the warnings recorded by the most recent {@see self::select()} call.
	 *
	 * @return array
	 * @phpstan-return list<string>
	 */
	public function warnings(): array {
		return $this->warnings;
	}

	/**
	 * Re-checks a configured post type against live WordPress registration: a
	 * type can stop being public, or stop existing, after it was configured.
	 * Attachments and internal `wp_`-prefixed types are excluded unconditionally,
	 * even if configured.
	 *
	 * @param string $post_type Configured post type slug.
	 * @return bool
	 */
	private function is_public_content_type( string $post_type ): bool {
		if ( 'attachment' === $post_type || str_starts_with( $post_type, 'wp_' ) ) {
			return false;
		}

		$object = get_post_type_object( $post_type );

		return $object instanceof WP_Post_Type && $object->public;
	}

	/**
	 * Collects up to `$item_cap` eligible entries for one post type, querying
	 * in bounded batches and stopping as soon as the cap is satisfied.
	 *
	 * @param string      $post_type Public, configured post type.
	 * @param int         $item_cap  Effective per-section item cap.
	 * @param LlmsConfig  $config    Effective configuration.
	 * @param SeoProvider $provider  Active SEO provider, possibly the null provider.
	 * @return array
	 * @phpstan-return list<LlmsSourceEntry>
	 */
	private function collect_for_post_type( string $post_type, int $item_cap, LlmsConfig $config, SeoProvider $provider ): array {
		$entries = array();
		$count   = 0;
		$page    = 1;

		while ( $count < $item_cap && $page <= self::MAX_BATCHES_PER_POST_TYPE ) {
			$post_ids = $this->batch( $post_type, $page );
			if ( array() === $post_ids ) {
				break;
			}

			foreach ( $post_ids as $post_id ) {
				if ( $count >= $item_cap ) {
					break;
				}

				if ( $this->is_noindex( $provider, $post_id ) ) {
					continue;
				}

				$entry = $this->entry_for( $post_id, $post_type, $config );
				if ( null !== $entry ) {
					$entries[] = $entry;
					++$count;
				}
			}

			if ( count( $post_ids ) < self::BATCH_SIZE ) {
				break;
			}
			++$page;
		}

		return $entries;
	}

	/**
	 * Queries one bounded batch of candidate post IDs.
	 *
	 * `publish` status and password protection are filtered at the query level
	 * (`has_password` is a native `WP_Query` parameter); no meta or term cache is
	 * populated for fields this class does not use.
	 *
	 * @param string $post_type Public, configured post type.
	 * @param int    $page      1-indexed batch page.
	 * @return array
	 * @phpstan-return list<int>
	 */
	private function batch( string $post_type, int $page ): array {
		$query = new WP_Query(
			array(
				'post_type'              => $post_type,
				'post_status'            => 'publish',
				'has_password'           => false,
				'fields'                 => 'ids',
				'posts_per_page'         => self::BATCH_SIZE,
				'paged'                  => $page,
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'ignore_sticky_posts'    => true,
				'cache_results'          => false,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		$post_ids = array();
		foreach ( $query->posts as $post_id ) {
			if ( is_int( $post_id ) ) {
				$post_ids[] = $post_id;
			}
		}

		return $post_ids;
	}

	/**
	 * Asks the active SEO provider whether a post resolves as `noindex`.
	 *
	 * When no provider is active this always returns false: {@see self::select()}
	 * already recorded the run-level warning that the filter did not execute,
	 * and a post must never be excluded on a check that could not run. A
	 * provider failure is treated the same way — a third-party plugin error
	 * must never take down llms.txt generation, and it is safer to include a
	 * post than to silently drop public content on an unrelated exception.
	 *
	 * @param SeoProvider $provider Active SEO provider, possibly the null provider.
	 * @param int         $post_id  Candidate post ID.
	 * @return bool
	 */
	private function is_noindex( SeoProvider $provider, int $post_id ): bool {
		if ( ! $provider->is_available() ) {
			return false;
		}

		try {
			$robots = $provider->get( SeoTarget::for_post( $post_id ) )->resolved['robots'] ?? null;
		} catch ( Throwable ) {
			return false;
		}

		return null !== $robots && self::signals_noindex( $robots->value );
	}

	/**
	 * Recognizes a resolved `robots` value as `noindex`, regardless of whether a
	 * provider normalizes it as a directive list, a keyed directive map (for
	 * example `['index' => 'noindex']`), or a plain string.
	 *
	 * @param mixed $value Normalized resolved `robots` field value.
	 * @return bool
	 */
	private static function signals_noindex( mixed $value ): bool {
		if ( is_string( $value ) ) {
			return str_contains( strtolower( $value ), 'noindex' );
		}

		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				if ( is_string( $key ) && str_contains( strtolower( $key ), 'noindex' ) ) {
					return true;
				}
				if ( self::signals_noindex( $item ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Builds one entry from an already-eligible post, tagging it with its own
	 * post type slug as its section.
	 *
	 * @param int        $post_id   Eligible post ID.
	 * @param string     $post_type Post type, used as the entry's section key.
	 * @param LlmsConfig $config    Effective configuration.
	 * @return LlmsSourceEntry|null
	 */
	private function entry_for( int $post_id, string $post_type, LlmsConfig $config ): ?LlmsSourceEntry {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return null;
		}

		$url = get_permalink( $post );
		if ( '' === $url ) {
			return null;
		}

		return new LlmsSourceEntry(
			get_the_title( $post ),
			$url,
			$config->show_excerpts ? $this->excerpt( $post ) : null,
			$post_type
		);
	}

	/**
	 * Reads a post's excerpt, treating an empty result as absent.
	 *
	 * @param WP_Post $post Eligible post.
	 * @return string|null
	 */
	private function excerpt( WP_Post $post ): ?string {
		$excerpt = get_the_excerpt( $post );

		return '' !== trim( $excerpt ) ? $excerpt : null;
	}
}

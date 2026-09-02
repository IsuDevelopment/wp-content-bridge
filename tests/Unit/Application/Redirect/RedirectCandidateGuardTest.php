<?php
/**
 * Redirect candidate guard tests.
 *
 * @package IsuDev\WPContentBridge\Tests
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Tests\Unit\Application\Redirect;

use IsuDev\WPContentBridge\Application\Redirect\PublishedPermalinkLookup;
use IsuDev\WPContentBridge\Application\Redirect\RedirectCandidateGuard;
use IsuDev\WPContentBridge\Application\Redirect\RedirectProvider;
use IsuDev\WPContentBridge\Application\Redirect\RedirectRuleLookup;
use IsuDev\WPContentBridge\Application\Redirect\RedirectSourceRejected;
use IsuDev\WPContentBridge\Domain\Redirect\RedirectProviderStatus;
use IsuDev\WPContentBridge\Domain\Redirect\RedirectRule;
use IsuDev\WPContentBridge\Domain\Redirect\RedirectSourcePath;
use IsuDev\WPContentBridge\Domain\Redirect\RedirectStatusCode;
use IsuDev\WPContentBridge\Domain\Redirect\RedirectTargetUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Covers the provider-neutral invariants ADR 0026 s5 requires of every
 * candidate before any adapter's `create()` is called: reserved prefixes,
 * the live-content shadow guard, collision, and the chain/loop bound.
 */
final class RedirectCandidateGuardTest extends TestCase {

	private const SITE = 'https://example.com';

	/**
	 * A candidate with no collision, no shadow, and a terminating target
	 * passes without complaint.
	 */
	public function test_accepts_a_clean_candidate(): void {
		$this->expectNotToPerformAssertions();

		( new RedirectCandidateGuard() )->assert_creatable(
			$this->rule( '/old-page', '/new-page' ),
			$this->lookup(),
			$this->permalinks( false )
		);
	}

	/**
	 * Provides source paths under each reserved prefix.
	 *
	 * @return array<string, list<string>>
	 */
	public static function provide_reserved_prefixes(): array {
		return array(
			'rest api'      => array( '/wp-json/wp/v2/posts' ),
			'admin'         => array( '/wp-admin/edit.php' ),
			'content'       => array( '/wp-content/uploads/x.jpg' ),
			'includes'      => array( '/wp-includes/js/x.js' ),
			'feed'          => array( '/feed/rss2' ),
			'login'         => array( '/wp-login.php' ),
			'cron'          => array( '/wp-cron.php' ),
			'signup'        => array( '/wp-signup.php' ),
			'activate'      => array( '/wp-activate.php' ),
			'xmlrpc'        => array( '/xmlrpc.php' ),
			'core sitemap'  => array( '/wp-sitemap.xml' ),
			'sitemap index' => array( '/wp-sitemap-posts-post-1.xml' ),
			'robots'        => array( '/robots.txt' ),
			// This plugin's own public endpoint: shadowing it would disable a
			// feature the same plugin serves.
			'llms'          => array( '/llms.txt' ),
			'llms full'     => array( '/llms-full.txt' ),
		);
	}

	/**
	 * A redirect can never shadow a core or plugin endpoint.
	 *
	 * @param string $source Reserved source path.
	 */
	#[DataProvider( 'provide_reserved_prefixes' )]
	public function test_rejects_reserved_source_prefix( string $source ): void {
		$this->expectException( RedirectSourceRejected::class );

		( new RedirectCandidateGuard() )->assert_creatable(
			$this->rule( $source, '/new-page' ),
			$this->lookup(),
			$this->permalinks( false )
		);
	}

	/**
	 * A source that is the current canonical permalink of published content
	 * would intercept live traffic rather than recover a dead URL.
	 */
	public function test_rejects_a_source_that_shadows_published_content(): void {
		$this->expectException( RedirectSourceRejected::class );

		( new RedirectCandidateGuard() )->assert_creatable(
			$this->rule( '/old-page', '/new-page' ),
			$this->lookup(),
			$this->permalinks( true )
		);
	}

	/**
	 * An enabled rule already answering this exact source is a collision; the
	 * caller must update the existing rule instead of creating a duplicate.
	 */
	public function test_rejects_a_collision_with_an_existing_rule(): void {
		$existing = $this->rule( '/old-page', '/elsewhere' );

		$this->expectException( RedirectSourceRejected::class );

		( new RedirectCandidateGuard() )->assert_creatable(
			$this->rule( '/old-page', '/new-page' ),
			$this->lookup( array( '/old-page' => $existing ) ),
			$this->permalinks( false )
		);
	}

	/**
	 * A -> B -> A is a loop: the visitor would bounce forever between two
	 * provider rules once this candidate exists.
	 */
	public function test_rejects_a_target_that_would_loop_back_to_the_source(): void {
		$back_to_source = $this->rule( '/b', '/old-page' );

		$this->expectException( RedirectSourceRejected::class );

		( new RedirectCandidateGuard() )->assert_creatable(
			$this->rule( '/old-page', '/b' ),
			$this->lookup( array( '/b' => $back_to_source ) ),
			$this->permalinks( false )
		);
	}

	/**
	 * A chain that has not terminated within the bounded number of hops is
	 * rejected as unresolvable rather than created and left to fail at
	 * request time.
	 */
	public function test_rejects_a_chain_exceeding_the_hop_bound(): void {
		$this->expectException( RedirectSourceRejected::class );

		( new RedirectCandidateGuard() )->assert_creatable(
			$this->rule( '/a', '/b' ),
			$this->lookup(
				array(
					'/b' => $this->rule( '/b', '/c' ),
					'/c' => $this->rule( '/c', '/d' ),
					'/d' => $this->rule( '/d', '/e' ),
				)
			),
			$this->permalinks( false )
		);
	}

	/**
	 * A chain that terminates within the bound (here: two hops before the
	 * final target has no further rule) is accepted.
	 */
	public function test_accepts_a_chain_terminating_within_the_hop_bound(): void {
		$this->expectNotToPerformAssertions();

		( new RedirectCandidateGuard() )->assert_creatable(
			$this->rule( '/a', '/b' ),
			$this->lookup(
				array(
					'/b' => $this->rule( '/b', '/c' ),
				)
			),
			$this->permalinks( false )
		);
	}

	/**
	 * A Gone candidate has no target, so there is nothing to resolve a chain
	 * through; only the collision and shadow checks apply.
	 */
	public function test_accepts_a_gone_candidate_with_no_target(): void {
		$this->expectNotToPerformAssertions();

		$rule = new RedirectRule(
			null,
			new RedirectSourcePath( '/discontinued' ),
			RedirectStatusCode::GONE,
			null,
			true,
			$this->provider_status()
		);

		( new RedirectCandidateGuard() )->assert_creatable( $rule, $this->lookup(), $this->permalinks( false ) );
	}

	/**
	 * The two-plugin case ADR 0026 s5 was corrected for: the source is free in
	 * the provider the write is addressed to, but claimed by the other active
	 * plugin. Both engines serve redirects at runtime, so this is a collision
	 * and the rejection names the provider that holds it.
	 */
	public function test_rejects_a_collision_held_only_by_another_provider(): void {
		$held_elsewhere = new RedirectRuleLookup(
			array(
				$this->provider( array(), 'redirection' ),
				$this->provider( array( '/old-page' => $this->rule( '/old-page', '/elsewhere' ) ), 'yoast-premium' ),
			)
		);

		$this->expectException( RedirectSourceRejected::class );
		$this->expectExceptionMessage( 'yoast-premium' );

		( new RedirectCandidateGuard() )->assert_creatable(
			$this->rule( '/old-page', '/new-page' ),
			$held_elsewhere,
			$this->permalinks( false )
		);
	}

	/**
	 * A loop that hops between backends: the candidate sends `/old-page` to
	 * `/b` in one plugin, and the other plugin already sends `/b` back to
	 * `/old-page`. Neither plugin can see this on its own.
	 */
	public function test_rejects_a_loop_that_spans_two_providers(): void {
		$split_chain = new RedirectRuleLookup(
			array(
				$this->provider( array(), 'redirection' ),
				$this->provider( array( '/b' => $this->rule( '/b', '/old-page' ) ), 'yoast-premium' ),
			)
		);

		$this->expectException( RedirectSourceRejected::class );

		( new RedirectCandidateGuard() )->assert_creatable(
			$this->rule( '/old-page', '/b' ),
			$split_chain,
			$this->permalinks( false )
		);
	}

	/**
	 * Returns a shared provider status fixture.
	 *
	 * @param string $slug Provider slug.
	 * @return RedirectProviderStatus
	 */
	private function provider_status( string $slug = 'redirection' ): RedirectProviderStatus {
		return new RedirectProviderStatus( $slug, '5.5.2', true, array( 'create', 'search' ) );
	}

	/**
	 * Builds a permanent redirect rule fixture.
	 *
	 * @param string $source Source path.
	 * @param string $target Target path.
	 * @return RedirectRule
	 */
	private function rule( string $source, string $target ): RedirectRule {
		return new RedirectRule(
			null,
			new RedirectSourcePath( $source ),
			RedirectStatusCode::PERMANENT,
			new RedirectTargetUrl( self::SITE, $target ),
			true,
			$this->provider_status()
		);
	}

	/**
	 * Builds a single-provider cross-provider lookup, which is what the guard
	 * consumes on an ordinary one-plugin site.
	 *
	 * @param array<string, RedirectRule> $existing Existing rules keyed by source path.
	 * @return RedirectRuleLookup
	 */
	private function lookup( array $existing = array() ): RedirectRuleLookup {
		return new RedirectRuleLookup( array( $this->provider( $existing ) ) );
	}

	/**
	 * Builds an always-available provider fake backed by a fixed rule map.
	 *
	 * @param array<string, RedirectRule> $existing Existing rules keyed by source path.
	 * @param string                      $slug     Provider slug the fake reports.
	 * @return RedirectProvider
	 */
	private function provider( array $existing = array(), string $slug = 'redirection' ): RedirectProvider {
		$status = $this->provider_status( $slug );

		return new class( $existing, $status ) implements RedirectProvider {

			/**
			 * Creates the fake.
			 *
			 * @param array<string, RedirectRule> $existing Existing rules keyed by source path.
			 * @param RedirectProviderStatus      $status   Fixed provider status.
			 */
			public function __construct( private array $existing, private RedirectProviderStatus $status ) {
			}

			/**
			 * Always available.
			 *
			 * @return bool
			 */
			public function is_available(): bool {
				return true;
			}

			/**
			 * Returns the fixed status.
			 *
			 * @return RedirectProviderStatus
			 */
			public function status(): RedirectProviderStatus {
				return $this->status;
			}

			/**
			 * Looks up an existing rule by exact source path.
			 *
			 * @param RedirectSourcePath $source Exact source path.
			 * @return RedirectRule|null
			 */
			public function search( RedirectSourcePath $source ): ?RedirectRule {
				return $this->existing[ $source->value() ] ?? null;
			}

			/**
			 * Not exercised by any guard test; the guard never calls `create()`.
			 *
			 * @param RedirectRule $candidate Candidate rule.
			 * @return RedirectRule
			 */
			public function create( RedirectRule $candidate ): RedirectRule {
				return $candidate;
			}
		};
	}

	/**
	 * Builds a permalink lookup fake with a fixed answer for every path.
	 *
	 * @param bool $published Whether every lookup answers "published".
	 * @return PublishedPermalinkLookup
	 */
	private function permalinks( bool $published ): PublishedPermalinkLookup {
		return new class( $published ) implements PublishedPermalinkLookup {

			/**
			 * Creates the fake.
			 *
			 * @param bool $published Fixed answer for every lookup.
			 */
			public function __construct( private bool $published ) {
			}

			/**
			 * Returns the fixed answer regardless of the path.
			 *
			 * @param string $path Unused path.
			 * @return bool
			 */
			public function is_published_permalink( string $path ): bool {
				unset( $path );

				return $this->published;
			}
		};
	}
}

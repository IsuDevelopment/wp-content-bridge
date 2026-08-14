<?php
/**
 * Unit tests for the pure, WordPress-free logic in RedirectionProvider.
 *
 * @package IsuDev\WPContentBridge\Tests
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Tests\Unit\Infrastructure\Redirection;

use IsuDev\WPContentBridge\Domain\Redirect\RedirectProviderStatus;
use IsuDev\WPContentBridge\Domain\Redirect\RedirectRule;
use IsuDev\WPContentBridge\Domain\Redirect\RedirectSourcePath;
use IsuDev\WPContentBridge\Domain\Redirect\RedirectStatusCode;
use IsuDev\WPContentBridge\Domain\Redirect\RedirectTargetUrl;
use IsuDev\WPContentBridge\Infrastructure\Redirection\RedirectionProvider;
use PHPUnit\Framework\TestCase;

/**
 * Covers only the static, WordPress-free methods this adapter delegates to:
 * trailing-slash normalization, the scoped `redirection_capability_check`
 * filter (ADR 0026 s2), the REST payload builders, and response mapping. The
 * instance methods (`is_available`, `search`, `create`) depend on
 * `rest_do_request()`, `add_filter()`, and a live Redirection install, so
 * they are exercised by WordPress runtime verification, not `tests/Unit`,
 * which deliberately never loads WordPress.
 */
final class RedirectionProviderTest extends TestCase {

	/**
	 * A site using trailing slashes gets one appended.
	 */
	public function test_normalize_trailing_slash_appends_when_site_uses_trailing_slashes(): void {
		self::assertSame( '/old-page/', RedirectionProvider::normalize_trailing_slash( '/old-page', true ) );
	}

	/**
	 * A site not using trailing slashes gets one stripped.
	 */
	public function test_normalize_trailing_slash_strips_when_site_does_not_use_trailing_slashes(): void {
		self::assertSame( '/old-page', RedirectionProvider::normalize_trailing_slash( '/old-page/', false ) );
	}

	/**
	 * The site root is never stripped down to an empty string.
	 */
	public function test_normalize_trailing_slash_never_empties_the_root(): void {
		self::assertSame( '/', RedirectionProvider::normalize_trailing_slash( '/', false ) );
	}

	/**
	 * The scoped filter grants the WPCB capability only for the permission
	 * names this call actually needs, never widening access to unrelated
	 * Redirection features (ADR 0026 s2).
	 */
	public function test_capability_filter_grants_wpcb_capability_for_allowed_permission(): void {
		$filter = RedirectionProvider::capability_filter( 'wpcb_manage_redirects', array( 'redirection_cap_redirect_add' ) );

		self::assertSame( 'wpcb_manage_redirects', $filter( 'manage_options', 'redirection_cap_redirect_add' ) );
	}

	/**
	 * Every other permission name falls through to Redirection's own default,
	 * so this call never accidentally grants access to groups, logs, or 404s.
	 */
	public function test_capability_filter_falls_through_for_other_permissions(): void {
		$filter = RedirectionProvider::capability_filter( 'wpcb_manage_redirects', array( 'redirection_cap_redirect_add' ) );

		self::assertSame( 'manage_options', $filter( 'manage_options', 'redirection_cap_redirect_delete' ) );
	}

	/**
	 * A search request filters by one source path and asks for at least
	 * Redirection's own schema minimum of 5 results per page.
	 */
	public function test_build_search_query_matches_the_exact_source(): void {
		$query = RedirectionProvider::build_search_query( new RedirectSourcePath( '/old-page' ) );

		self::assertSame(
			array(
				'filterBy' => array( 'url' => '/old-page' ),
				'per_page' => 5,
			),
			$query
		);
	}

	/**
	 * A permanent/found rule maps to a plain URL redirect action.
	 */
	public function test_build_create_payload_for_a_permanent_rule(): void {
		$rule = new RedirectRule(
			null,
			new RedirectSourcePath( '/old-page' ),
			RedirectStatusCode::PERMANENT,
			new RedirectTargetUrl( 'https://example.com', '/new-page' ),
			true,
			new RedirectProviderStatus( 'redirection', '5.5.2', true, array( 'create' ) )
		);

		self::assertSame(
			array(
				'url'         => '/old-page',
				'match_type'  => 'url',
				'regex'       => false,
				'status'      => 'enabled',
				'group_id'    => 1,
				'action_type' => 'url',
				'action_code' => 301,
				'action_data' => array( 'url' => '/new-page' ),
			),
			RedirectionProvider::build_create_payload( $rule )
		);
	}

	/**
	 * A Gone rule maps to an error action with no redirect destination.
	 */
	public function test_build_create_payload_for_a_gone_rule(): void {
		$rule = new RedirectRule(
			null,
			new RedirectSourcePath( '/discontinued' ),
			RedirectStatusCode::GONE,
			null,
			true,
			new RedirectProviderStatus( 'redirection', '5.5.2', true, array( 'create' ) )
		);

		self::assertSame(
			array(
				'url'         => '/discontinued',
				'match_type'  => 'url',
				'regex'       => false,
				'status'      => 'enabled',
				'group_id'    => 1,
				'action_type' => 'error',
				'action_code' => 410,
			),
			RedirectionProvider::build_create_payload( $rule )
		);
	}

	/**
	 * A disabled rule is created with `status: disabled` rather than a
	 * separate follow-up call.
	 */
	public function test_build_create_payload_reflects_disabled_state(): void {
		$rule = new RedirectRule(
			null,
			new RedirectSourcePath( '/old-page' ),
			RedirectStatusCode::PERMANENT,
			new RedirectTargetUrl( 'https://example.com', '/new-page' ),
			false,
			new RedirectProviderStatus( 'redirection', '5.5.2', true, array( 'create' ) )
		);

		self::assertSame( 'disabled', RedirectionProvider::build_create_payload( $rule )['status'] );
	}

	/**
	 * A response item for a URL redirect maps back to an equivalent rule.
	 */
	public function test_map_item_to_rule_for_a_url_action(): void {
		$item = array(
			'id'          => 42,
			'url'         => '/old-page',
			'enabled'     => true,
			'action_type' => 'url',
			'action_code' => 301,
			'action_data' => array( 'url' => '/new-page' ),
		);

		$rule = RedirectionProvider::map_item_to_rule(
			$item,
			new RedirectProviderStatus( 'redirection', '5.5.2', true, array( 'search' ) ),
			'https://example.com'
		);

		self::assertSame(
			array(
				'id'       => '42',
				'source'   => '/old-page',
				'status'   => 301,
				'target'   => '/new-page',
				'enabled'  => true,
				'provider' => array(
					'provider'     => 'redirection',
					'version'      => '5.5.2',
					'detected'     => true,
					'capabilities' => array( 'search' ),
				),
			),
			$rule->to_array()
		);
	}

	/**
	 * A response item for an error action maps back to a Gone rule with no
	 * target.
	 */
	public function test_map_item_to_rule_for_an_error_action(): void {
		$item = array(
			'id'          => 7,
			'url'         => '/discontinued',
			'enabled'     => false,
			'action_type' => 'error',
			'action_code' => 410,
		);

		$rule = RedirectionProvider::map_item_to_rule(
			$item,
			new RedirectProviderStatus( 'redirection', '5.5.2', true, array( 'search' ) ),
			'https://example.com'
		);

		self::assertNull( $rule->target );
		self::assertFalse( $rule->enabled );
	}

	/**
	 * The item whose `url` exactly equals the source is returned.
	 */
	public function test_find_exact_match_returns_the_matching_item(): void {
		$items = array(
			array( 'url' => '/old-page-2' ),
			array( 'url' => '/old-page' ),
		);

		self::assertSame( array( 'url' => '/old-page' ), RedirectionProvider::find_exact_match( $items, '/old-page' ) );
	}

	/**
	 * Redirection's own `filterBy[url]` filter is a substring match, so a
	 * result set containing only superstrings of the source must not be
	 * mistaken for an exact match.
	 */
	public function test_find_exact_match_ignores_substring_matches(): void {
		$items = array(
			array( 'url' => '/old-page-extended' ),
			array( 'url' => '/prefix-old-page' ),
		);

		self::assertNull( RedirectionProvider::find_exact_match( $items, '/old-page' ) );
	}

	/**
	 * An empty result set has no match.
	 */
	public function test_find_exact_match_returns_null_for_no_items(): void {
		self::assertNull( RedirectionProvider::find_exact_match( array(), '/old-page' ) );
	}
}

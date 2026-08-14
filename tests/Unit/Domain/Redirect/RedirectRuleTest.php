<?php
/**
 * Redirect rule aggregate tests.
 *
 * @package IsuDev\WPContentBridge\Tests
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Tests\Unit\Domain\Redirect;

use InvalidArgumentException;
use IsuDev\WPContentBridge\Domain\Redirect\RedirectProviderStatus;
use IsuDev\WPContentBridge\Domain\Redirect\RedirectRule;
use IsuDev\WPContentBridge\Domain\Redirect\RedirectSourcePath;
use IsuDev\WPContentBridge\Domain\Redirect\RedirectStatusCode;
use IsuDev\WPContentBridge\Domain\Redirect\RedirectTargetUrl;
use PHPUnit\Framework\TestCase;

/**
 * A rule is provider-neutral once built: the same shape whether it came from
 * Redirection or Yoast Premium, always carrying the provider that answered.
 */
final class RedirectRuleTest extends TestCase {

	/**
	 * Shared provider status attached to every rule built in these tests.
	 *
	 * @var RedirectProviderStatus
	 */
	private RedirectProviderStatus $provider;

	/**
	 * Builds the shared provider status fixture.
	 */
	protected function setUp(): void {
		$this->provider = new RedirectProviderStatus( 'redirection', '5.5.2', true, array( 'create' ) );
	}

	/**
	 * A 301/302 rule requires a target — there is nowhere to send the visitor
	 * otherwise.
	 */
	public function test_builds_a_permanent_rule_with_a_target(): void {
		$rule = new RedirectRule(
			'42',
			new RedirectSourcePath( '/old-page' ),
			RedirectStatusCode::PERMANENT,
			new RedirectTargetUrl( 'https://example.com', '/new-page' ),
			true,
			$this->provider
		);

		self::assertSame(
			array(
				'id'       => '42',
				'source'   => '/old-page',
				'status'   => 301,
				'target'   => '/new-page',
				'enabled'  => true,
				'provider' => $this->provider->to_array(),
			),
			$rule->to_array()
		);
	}

	/**
	 * A not-yet-created rule has no provider-assigned identity.
	 */
	public function test_allows_a_null_id_before_creation(): void {
		$rule = new RedirectRule(
			null,
			new RedirectSourcePath( '/old-page' ),
			RedirectStatusCode::PERMANENT,
			new RedirectTargetUrl( 'https://example.com', '/new-page' ),
			true,
			$this->provider
		);

		self::assertNull( $rule->to_array()['id'] );
	}

	/**
	 * HTTP 410 Gone has no Location; a target on a Gone rule would be
	 * meaningless data neither provider's own UI would show the caller.
	 */
	public function test_a_gone_rule_has_no_target(): void {
		$rule = new RedirectRule(
			'42',
			new RedirectSourcePath( '/discontinued' ),
			RedirectStatusCode::GONE,
			null,
			true,
			$this->provider
		);

		self::assertNull( $rule->to_array()['target'] );
	}

	/**
	 * A permanent redirect without a destination is not a valid rule.
	 */
	public function test_rejects_a_permanent_rule_without_a_target(): void {
		$this->expectException( InvalidArgumentException::class );

		new RedirectRule(
			'42',
			new RedirectSourcePath( '/old-page' ),
			RedirectStatusCode::PERMANENT,
			null,
			true,
			$this->provider
		);
	}

	/**
	 * A Gone rule carrying a target would be silently ignored by either
	 * provider; reject it rather than accept data that means nothing.
	 */
	public function test_rejects_a_gone_rule_with_a_target(): void {
		$this->expectException( InvalidArgumentException::class );

		new RedirectRule(
			'42',
			new RedirectSourcePath( '/discontinued' ),
			RedirectStatusCode::GONE,
			new RedirectTargetUrl( 'https://example.com', '/new-page' ),
			true,
			$this->provider
		);
	}
}

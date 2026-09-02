<?php
/**
 * Redirect update tests.
 *
 * @package IsuDev\WPContentBridge\Tests
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Tests\Unit\Application\Redirect;

use InvalidArgumentException;
use IsuDev\WPContentBridge\Application\Redirect\NullRedirectProvider;
use IsuDev\WPContentBridge\Application\Redirect\RedirectCandidateGuard;
use IsuDev\WPContentBridge\Application\Redirect\RedirectProviderRegistry;
use IsuDev\WPContentBridge\Application\Redirect\RedirectProviderUnavailable;
use IsuDev\WPContentBridge\Application\Redirect\RedirectSourceRejected;
use IsuDev\WPContentBridge\Application\Redirect\UpdateRedirect;
use IsuDev\WPContentBridge\Domain\Redirect\RedirectProviderStatus;
use IsuDev\WPContentBridge\Domain\Redirect\RedirectRule;
use IsuDev\WPContentBridge\Domain\Redirect\RedirectSourcePath;
use IsuDev\WPContentBridge\Domain\Redirect\RedirectStatusCode;
use IsuDev\WPContentBridge\Domain\Redirect\RedirectTargetUrl;
use IsuDev\WPContentBridge\Tests\Support\RecordingAuditLog;
use IsuDev\WPContentBridge\Tests\Support\RecordingRedirectProvider;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Covers the narrower guard an update runs: the loop bound still applies, but
 * collision and the live-content shadow must not, or every existing rule
 * would be impossible to fix.
 */
final class UpdateRedirectTest extends TestCase {

	private const SITE = 'https://example.com';

	/**
	 * The audit sink for the case under test.
	 *
	 * @var RecordingAuditLog
	 */
	private RecordingAuditLog $audit;

	/**
	 * Resets the audit sink.
	 */
	protected function setUp(): void {
		$this->audit = new RecordingAuditLog();
	}

	/**
	 * The whole point of an update: the source is already claimed, and the
	 * change still goes through. Re-running the collision check here would
	 * make every rule that needs fixing unfixable.
	 */
	public function test_updates_a_rule_whose_source_is_already_claimed(): void {
		$provider = new RecordingRedirectProvider( 'redirection', array( '/old' => $this->rule( '/old', '/first' ) ) );

		$result = $this->update( array( $provider ), array( 'target' => '/second' ) );

		self::assertSame( '/second', $result['target'] );
		self::assertCount( 1, $provider->updated );
	}

	/**
	 * The loop bound still applies to the new target, across providers.
	 */
	public function test_refuses_a_new_target_that_loops_back_through_another_provider(): void {
		$holder  = new RecordingRedirectProvider( 'redirection', array( '/old' => $this->rule( '/old', '/first' ) ) );
		$partner = new RecordingRedirectProvider( 'yoast-premium', array( '/second' => $this->rule( '/second', '/old' ) ) );

		try {
			$this->update( array( $holder, $partner ), array( 'target' => '/second' ) );
			self::fail( 'Expected the cross-provider loop to be refused.' );
		} catch ( RedirectSourceRejected ) {
			self::assertSame( array(), $holder->updated );
		}

		self::assertSame( 'wpcb_redirect_source_rejected', $this->audit->events[0]->error_code );
	}

	/**
	 * A provider holding no such rule is reported, never silently turned into
	 * a create.
	 */
	public function test_refuses_an_update_for_a_rule_the_provider_does_not_hold(): void {
		$this->expectException( RedirectProviderUnavailable::class );

		$this->update( array( new RecordingRedirectProvider( 'redirection' ) ), array() );
	}

	/**
	 * A write addressed to an unavailable provider is refused, not moved.
	 */
	public function test_refuses_an_unavailable_named_provider(): void {
		$absent    = new RecordingRedirectProvider( 'yoast-premium', array(), false );
		$available = new RecordingRedirectProvider( 'redirection', array( '/old' => $this->rule( '/old', '/first' ) ) );

		try {
			$this->update( array( $absent, $available ), array( 'provider' => 'yoast-premium' ) );
			self::fail( 'Expected the unavailable provider to be refused.' );
		} catch ( RedirectProviderUnavailable ) {
			self::assertSame( array(), $available->updated );
		}
	}

	/**
	 * A 410 must not carry a target, on update as on create.
	 */
	public function test_refuses_a_target_on_a_gone_update(): void {
		$this->expectException( InvalidArgumentException::class );

		$this->update(
			array( new RecordingRedirectProvider( 'redirection', array( '/old' => $this->rule( '/old', '/first' ) ) ) ),
			array(
				'status' => 410,
				'target' => '/second',
			)
		);
	}

	/**
	 * The audit row names the changed fields, and not the source — an update
	 * cannot change it.
	 */
	public function test_audits_the_changed_fields_only(): void {
		$this->update(
			array( new RecordingRedirectProvider( 'redirection', array( '/old' => $this->rule( '/old', '/first' ) ) ) ),
			array( 'target' => '/second' )
		);

		$event = $this->audit->events[0];

		self::assertSame( 'success', $event->outcome );
		self::assertSame( array( 'target', 'status', 'provider' ), $event->changed_fields );
		self::assertSame( 'redirect', $event->object_type );
	}

	/**
	 * Runs the use case.
	 *
	 * @param array $providers Provider doubles.
	 * @param array $input     Ability input overrides.
	 * @phpstan-param list<RecordingRedirectProvider> $providers
	 * @phpstan-param array<string, mixed> $input
	 * @return array<string, mixed>
	 * @throws Throwable Re-thrown use-case failure.
	 */
	private function update( array $providers, array $input ): array {
		$registry = new RedirectProviderRegistry( $providers, new NullRedirectProvider() );
		$case     = new UpdateRedirect( $registry, new RedirectCandidateGuard(), $this->audit, self::SITE );

		$defaults = array(
			'provider' => 'redirection',
			'source'   => '/old',
			'target'   => '/second',
		);

		return $case->execute( array_merge( $defaults, $input ), 7 );
	}

	/**
	 * Builds a rule fixture.
	 *
	 * @param string $source Source path.
	 * @param string $target Target path.
	 * @return RedirectRule
	 */
	private function rule( string $source, string $target ): RedirectRule {
		return new RedirectRule(
			'existing',
			new RedirectSourcePath( $source ),
			RedirectStatusCode::PERMANENT,
			new RedirectTargetUrl( self::SITE, $target ),
			true,
			new RedirectProviderStatus( 'redirection', '1.0', true, array() )
		);
	}
}

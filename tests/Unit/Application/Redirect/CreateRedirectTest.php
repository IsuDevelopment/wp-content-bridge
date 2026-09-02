<?php
/**
 * Named-provider redirect write tests.
 *
 * @package IsuDev\WPContentBridge\Tests
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Tests\Unit\Application\Redirect;

use InvalidArgumentException;
use IsuDev\WPContentBridge\Application\Redirect\CreateRedirect;
use IsuDev\WPContentBridge\Application\Redirect\NullRedirectProvider;
use IsuDev\WPContentBridge\Application\Redirect\RedirectCandidateGuard;
use IsuDev\WPContentBridge\Application\Redirect\RedirectProviderRegistry;
use IsuDev\WPContentBridge\Application\Redirect\RedirectProviderUnavailable;
use IsuDev\WPContentBridge\Application\Redirect\RedirectSourceRejected;
use IsuDev\WPContentBridge\Domain\Redirect\RedirectProviderStatus;
use IsuDev\WPContentBridge\Domain\Redirect\RedirectRule;
use IsuDev\WPContentBridge\Domain\Redirect\RedirectSourcePath;
use IsuDev\WPContentBridge\Domain\Redirect\RedirectStatusCode;
use IsuDev\WPContentBridge\Domain\Redirect\RedirectTargetUrl;
use IsuDev\WPContentBridge\Tests\Support\FixedPublishedPermalinkLookup;
use IsuDev\WPContentBridge\Tests\Support\RecordingAuditLog;
use IsuDev\WPContentBridge\Tests\Support\RecordingRedirectProvider;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Covers the write half of ADR 0026 as amended: the caller names the
 * provider, exactly one backend is written, and the guard clears the
 * candidate against every available provider first.
 */
final class CreateRedirectTest extends TestCase {

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
	 * The write reaches the named provider and only that one.
	 */
	public function test_writes_only_to_the_named_provider(): void {
		$yoast       = new RecordingRedirectProvider( 'yoast-premium' );
		$redirection = new RecordingRedirectProvider( 'redirection' );

		$result = $this->create( array( $yoast, $redirection ), array( 'provider' => 'redirection' ) );

		self::assertSame( array(), $yoast->created );
		self::assertCount( 1, $redirection->created );
		self::assertSame( 'redirection:1', $result['id'] );
		self::assertSame( 'redirection', $result['provider']['provider'] );
	}

	/**
	 * The provider is never inferred. On a two-plugin site the choice decides
	 * which engine's rule actually fires, so an unnamed provider is refused.
	 */
	public function test_refuses_a_write_with_no_named_provider(): void {
		$this->expectException( InvalidArgumentException::class );

		$this->create( array( new RecordingRedirectProvider( 'redirection' ) ), array( 'provider' => '' ) );
	}

	/**
	 * The two-plugin defect: the named provider is free, but the other active
	 * plugin already holds the path. Both engines serve redirects, so this is
	 * a collision even though the write itself would have succeeded.
	 */
	public function test_refuses_a_source_held_only_by_the_other_provider(): void {
		$holder = new RecordingRedirectProvider( 'yoast-premium', array( '/old' => $this->existing( '/old', '/elsewhere' ) ) );
		$target = new RecordingRedirectProvider( 'redirection' );

		try {
			$this->create( array( $holder, $target ), array( 'provider' => 'redirection' ) );
			self::fail( 'Expected the cross-provider collision to be refused.' );
		} catch ( RedirectSourceRejected $error ) {
			self::assertStringContainsString( 'yoast-premium', $error->getMessage() );
		}

		self::assertSame( array(), $target->created, 'Nothing may be written after a collision.' );
		self::assertSame( 'wpcb_redirect_source_rejected', $this->audit->events[0]->error_code );
		self::assertSame( 'invalid', $this->audit->events[0]->outcome );
	}

	/**
	 * Naming an unavailable provider is refused, never quietly written to the
	 * available one — the rule would land in a backend nobody chose.
	 */
	public function test_refuses_an_unavailable_named_provider_without_substituting(): void {
		$absent    = new RecordingRedirectProvider( 'yoast-premium', array(), false );
		$available = new RecordingRedirectProvider( 'redirection' );

		try {
			$this->create( array( $absent, $available ), array( 'provider' => 'yoast-premium' ) );
			self::fail( 'Expected the unavailable provider to be refused.' );
		} catch ( RedirectProviderUnavailable ) {
			self::assertSame( array(), $available->created );
		}

		self::assertSame( 'wpcb_redirect_provider_unavailable', $this->audit->events[0]->error_code );
	}

	/**
	 * A Gone rule carries no target on either side.
	 */
	public function test_creates_a_gone_rule_without_a_target(): void {
		$result = $this->create(
			array( new RecordingRedirectProvider( 'redirection' ) ),
			array(
				'provider' => 'redirection',
				'status'   => 410,
				'target'   => null,
			)
		);

		self::assertSame( 410, $result['status'] );
		self::assertNull( $result['target'] );
	}

	/**
	 * A target on a Gone rule is contradictory input, not a value to ignore.
	 */
	public function test_refuses_a_target_on_a_gone_rule(): void {
		$this->expectException( InvalidArgumentException::class );

		$this->create(
			array( new RecordingRedirectProvider( 'redirection' ) ),
			array(
				'provider' => 'redirection',
				'status'   => 410,
				'target'   => '/somewhere',
			)
		);
	}

	/**
	 * Any status other than Gone needs a target.
	 */
	public function test_refuses_a_missing_target_on_a_redirecting_rule(): void {
		$this->expectException( InvalidArgumentException::class );

		$this->create(
			array( new RecordingRedirectProvider( 'redirection' ) ),
			array(
				'provider' => 'redirection',
				'target'   => null,
			)
		);
	}

	/**
	 * A status outside the neutral allowlist is refused rather than passed to
	 * a provider that would happily accept it.
	 */
	public function test_refuses_a_status_outside_the_allowlist(): void {
		$this->expectException( InvalidArgumentException::class );

		$this->create(
			array( new RecordingRedirectProvider( 'redirection' ) ),
			array(
				'provider' => 'redirection',
				'status'   => 307,
			)
		);
	}

	/**
	 * The audit row stores field names only. A source path is content the
	 * caller supplied, and the audit table records shapes, never values.
	 */
	public function test_audits_field_names_only(): void {
		$this->create( array( new RecordingRedirectProvider( 'redirection' ) ), array( 'provider' => 'redirection' ) );

		$event = $this->audit->events[0];

		self::assertSame( 'success', $event->outcome );
		self::assertSame( array( 'source', 'target', 'status', 'provider' ), $event->changed_fields );
		self::assertNotContains( '/old', $event->changed_fields );
		self::assertSame( 'redirect', $event->object_type );
		self::assertNull( $event->object_id );
	}

	/**
	 * Runs the use case with sensible defaults for anything the test did not
	 * set.
	 *
	 * @param array $providers Provider fakes, in registry order.
	 * @param array $input     Ability input overrides.
	 * @phpstan-param list<RecordingRedirectProvider> $providers
	 * @phpstan-param array<string, mixed> $input
	 * @return array<string, mixed>
	 * @throws Throwable Re-thrown use-case failure.
	 */
	private function create( array $providers, array $input ): array {
		$registry = new RedirectProviderRegistry( $providers, new NullRedirectProvider() );
		$case     = new CreateRedirect(
			$registry,
			new RedirectCandidateGuard(),
			new FixedPublishedPermalinkLookup(),
			$this->audit,
			self::SITE
		);

		$defaults = array(
			'source' => '/old',
			'target' => '/new',
		);

		return $case->execute( array_merge( $defaults, $input ), 7 );
	}

	/**
	 * Builds an existing rule fixture.
	 *
	 * @param string $source Source path.
	 * @param string $target Target path.
	 * @return RedirectRule
	 */
	private function existing( string $source, string $target ): RedirectRule {
		return new RedirectRule(
			'existing',
			new RedirectSourcePath( $source ),
			RedirectStatusCode::PERMANENT,
			new RedirectTargetUrl( self::SITE, $target ),
			true,
			new RedirectProviderStatus( 'yoast-premium', '1.0', true, array() )
		);
	}
}

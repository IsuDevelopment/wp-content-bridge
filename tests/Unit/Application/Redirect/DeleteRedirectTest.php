<?php
/**
 * Redirect removal tests.
 *
 * @package IsuDev\WPContentBridge\Tests
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Tests\Unit\Application\Redirect;

use InvalidArgumentException;
use IsuDev\WPContentBridge\Application\Redirect\DeleteRedirect;
use IsuDev\WPContentBridge\Application\Redirect\NullRedirectProvider;
use IsuDev\WPContentBridge\Application\Redirect\RedirectProviderRegistry;
use IsuDev\WPContentBridge\Application\Redirect\RedirectProviderUnavailable;
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
 * Covers removal: it touches exactly the named provider, and a rule that is
 * not there is an error rather than a quiet success.
 */
final class DeleteRedirectTest extends TestCase {

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
	 * Only the named provider is touched, even when both hold the same path.
	 * A caller cleaning up one engine must not have the other's rule removed
	 * underneath it.
	 */
	public function test_removes_only_from_the_named_provider(): void {
		$named = new RecordingRedirectProvider( 'redirection', array( '/old' => $this->rule( '/old' ) ) );
		$other = new RecordingRedirectProvider( 'yoast-premium', array( '/old' => $this->rule( '/old' ) ) );

		$result = $this->delete( array( $named, $other ), array( 'provider' => 'redirection' ) );

		self::assertTrue( $result['deleted'] );
		self::assertSame( array( '/old' ), $named->deleted );
		self::assertSame( array(), $other->deleted );
	}

	/**
	 * Removing a rule that is not there is reported. Answering success would
	 * tell a caller the path is clear when another engine may still hold it.
	 */
	public function test_refuses_to_remove_a_rule_that_is_not_there(): void {
		$this->expectException( RedirectProviderUnavailable::class );

		$this->delete( array( new RecordingRedirectProvider( 'redirection' ) ), array() );
	}

	/**
	 * The provider is never inferred, here either.
	 */
	public function test_refuses_a_removal_with_no_named_provider(): void {
		$this->expectException( InvalidArgumentException::class );

		$this->delete( array( new RecordingRedirectProvider( 'redirection' ) ), array( 'provider' => '' ) );
	}

	/**
	 * A failure is audited as such, with the stable code.
	 */
	public function test_audits_a_failed_removal(): void {
		try {
			$this->delete( array( new RecordingRedirectProvider( 'redirection' ) ), array() );
		} catch ( Throwable ) {
			$ignored = true;
		}

		self::assertSame( 'failure', $this->audit->events[0]->outcome );
		self::assertSame( 'wpcb_redirect_provider_unavailable', $this->audit->events[0]->error_code );
	}

	/**
	 * A successful removal records field names only.
	 */
	public function test_audits_field_names_only(): void {
		$this->delete(
			array( new RecordingRedirectProvider( 'redirection', array( '/old' => $this->rule( '/old' ) ) ) ),
			array()
		);

		$event = $this->audit->events[0];

		self::assertSame( 'success', $event->outcome );
		self::assertSame( array( 'source', 'provider' ), $event->changed_fields );
		self::assertNull( $event->object_id );
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
	private function delete( array $providers, array $input ): array {
		$registry = new RedirectProviderRegistry( $providers, new NullRedirectProvider() );
		$case     = new DeleteRedirect( $registry, $this->audit );

		$defaults = array(
			'provider' => 'redirection',
			'source'   => '/old',
		);

		return $case->execute( array_merge( $defaults, $input ), 7 );
	}

	/**
	 * Builds a rule fixture.
	 *
	 * @param string $source Source path.
	 * @return RedirectRule
	 */
	private function rule( string $source ): RedirectRule {
		return new RedirectRule(
			'existing',
			new RedirectSourcePath( $source ),
			RedirectStatusCode::PERMANENT,
			new RedirectTargetUrl( self::SITE, '/somewhere' ),
			true,
			new RedirectProviderStatus( 'redirection', '1.0', true, array() )
		);
	}
}

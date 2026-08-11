<?php
/**
 * Llms.txt ownership inspector tests.
 *
 * @package IsuDev\WPContentBridge\Tests
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Tests\Unit\Infrastructure\WordPress;

// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter.Found -- fetcher fakes must match the real callable's signature; several deliberately ignore the URL.

use Closure;
use IsuDev\WPContentBridge\Domain\Llms\LlmsOwnershipConflict;
use IsuDev\WPContentBridge\Domain\Llms\LlmsOwnershipOwner;
use IsuDev\WPContentBridge\Domain\Llms\LlmsOwnershipState;
use IsuDev\WPContentBridge\Domain\Llms\LlmsPublicVerification;
use IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressLlmsOwnershipInspector;
use PHPUnit\Framework\TestCase;

/**
 * Exercises every local-signal combination against fakes, the public
 * verification outcomes, and the one rule that matters most: nothing this
 * class returns may contain a filesystem path.
 */
final class WordPressLlmsOwnershipInspectorTest extends TestCase {

	/**
	 * Builds an inspector from fixed fake answers.
	 *
	 * @param bool         $physical_artifact_exists Fake physical-artifact answer.
	 * @param bool         $yoast_llms_txt_enabled   Fake Yoast flag answer.
	 * @param bool         $bridge_publication_enabled Fake bridge flag answer.
	 * @param Closure|null $fetcher Fake public-verification fetcher.
	 * @param bool         $route_routable Fake virtual-route readiness answer.
	 * @param bool         $legacy_full_exists Fake llms-full.txt answer.
	 * @param bool         $legacy_docs_exists Fake llms-docs/ answer.
	 * @return WordPressLlmsOwnershipInspector
	 * @phpstan-param (Closure(string): (array{code: int, body: string}|null))|null $fetcher
	 */
	private function inspector_with(
		bool $physical_artifact_exists,
		bool $yoast_llms_txt_enabled,
		bool $bridge_publication_enabled,
		?Closure $fetcher = null,
		bool $route_routable = true,
		bool $legacy_full_exists = false,
		bool $legacy_docs_exists = false
	): WordPressLlmsOwnershipInspector {
		return new WordPressLlmsOwnershipInspector(
			static fn (): bool => $physical_artifact_exists,
			static fn (): bool => $yoast_llms_txt_enabled,
			static fn (): bool => $bridge_publication_enabled,
			$fetcher,
			static fn (): bool => $legacy_full_exists,
			static fn (): bool => $legacy_docs_exists,
			static fn (): bool => $route_routable
		);
	}

	/**
	 * Asserts that no string reachable from the state's public wire document
	 * looks like a filesystem path: no path separator, no drive letter, and
	 * no reference to the constant that carries the real one.
	 *
	 * @param LlmsOwnershipState $state State under inspection.
	 * @return void
	 */
	private function assert_no_path_like_string( LlmsOwnershipState $state ): void {
		foreach ( $state->to_array() as $field => $value ) {
			if ( ! is_string( $value ) ) {
				continue;
			}

			self::assertDoesNotMatchRegularExpression(
				'#(?:[A-Za-z]:\\\\|/[A-Za-z0-9_.-]+/|\\\\[A-Za-z0-9_.-]+\\\\|ABSPATH)#',
				$value,
				"Field \"{$field}\" looks like it contains a filesystem path: {$value}"
			);
		}
	}

	/**
	 * Yoast's feature being enabled blocks, with owner YOAST, regardless of
	 * whether a physical file happens to exist yet.
	 */
	public function test_yoast_enabled_blocks_and_is_reported_as_owner(): void {
		$state = $this->inspector_with( false, true, true )->inspect();

		self::assertSame( LlmsOwnershipOwner::YOAST, $state->owner );
		self::assertSame( LlmsOwnershipConflict::YOAST_LLMS_TXT_ENABLED, $state->conflict );
		self::assertTrue( $state->is_blocking() );
		self::assertTrue( $state->yoast_llms_txt_enabled );
		self::assertFalse( $state->physical_artifact_exists );
		$this->assert_no_path_like_string( $state );
	}

	/**
	 * A physical artifact without Yoast's feature blocks as an unidentified
	 * third party, never resolving who put the file there.
	 */
	public function test_physical_artifact_without_yoast_blocks_as_third_party(): void {
		$state = $this->inspector_with( true, false, false )->inspect();

		self::assertSame( LlmsOwnershipOwner::THIRD_PARTY, $state->owner );
		self::assertSame( LlmsOwnershipConflict::PHYSICAL_ARTIFACT_PRESENT, $state->conflict );
		self::assertTrue( $state->is_blocking() );
		self::assertTrue( $state->physical_artifact_exists );
		self::assertFalse( $state->yoast_llms_txt_enabled );
		$this->assert_no_path_like_string( $state );
	}

	/**
	 * When Yoast is enabled and a physical file also exists, Yoast is
	 * reported as the owner and the conflict code names Yoast, not the file.
	 */
	public function test_yoast_takes_precedence_over_a_coexisting_physical_artifact(): void {
		$state = $this->inspector_with( true, true, false )->inspect();

		self::assertSame( LlmsOwnershipOwner::YOAST, $state->owner );
		self::assertSame( LlmsOwnershipConflict::YOAST_LLMS_TXT_ENABLED, $state->conflict );
		self::assertTrue( $state->physical_artifact_exists );
		$this->assert_no_path_like_string( $state );
	}

	/**
	 * No physical artifact, Yoast disabled, and the bridge's own flag on:
	 * the bridge is reported as the owner and there is no conflict.
	 */
	public function test_no_conflict_reports_bridge_as_owner_when_enabled(): void {
		$state = $this->inspector_with( false, false, true )->inspect();

		self::assertSame( LlmsOwnershipOwner::BRIDGE, $state->owner );
		self::assertNull( $state->conflict );
		self::assertFalse( $state->is_blocking() );
		self::assertTrue( $state->bridge_publication_enabled );
		$this->assert_no_path_like_string( $state );
	}

	/**
	 * No physical artifact, Yoast disabled, and the bridge's own flag off:
	 * nobody is reported as owning the path.
	 */
	public function test_no_conflict_reports_no_owner_when_bridge_disabled(): void {
		$state = $this->inspector_with( false, false, false )->inspect();

		self::assertSame( LlmsOwnershipOwner::NONE, $state->owner );
		self::assertNull( $state->conflict );
		self::assertFalse( $state->is_blocking() );
		self::assertFalse( $state->bridge_publication_enabled );
		$this->assert_no_path_like_string( $state );
	}

	/**
	 * An enabled bridge cannot claim ownership when plain permalinks prevent
	 * the virtual route from ever matching.
	 */
	public function test_plain_permalinks_report_unroutable_bridge_conflict(): void {
		$state = $this->inspector_with( false, false, true, null, false )->inspect();

		self::assertSame( LlmsOwnershipOwner::NONE, $state->owner );
		self::assertSame( LlmsOwnershipConflict::BRIDGE_ROUTE_UNROUTABLE, $state->conflict );
		self::assertFalse( $state->bridge_route_routable );
		self::assertTrue( $state->is_blocking() );
	}

	/**
	 * Legacy companion exports are reported independently without claiming
	 * that either one owns the canonical root path.
	 */
	public function test_reports_legacy_companion_artifacts(): void {
		$state = $this->inspector_with( false, false, true, null, true, true, true )->inspect();

		self::assertSame( LlmsOwnershipOwner::BRIDGE, $state->owner );
		self::assertTrue( $state->legacy_full_artifact_exists );
		self::assertTrue( $state->legacy_docs_directory_exists );
		self::assertTrue( $state->has_legacy_artifacts() );
	}

	/**
	 * `inspect()` never performs a network request, so its public
	 * verification result is always unknown even when a fetcher is supplied.
	 */
	public function test_inspect_never_calls_the_fetcher(): void {
		$called    = false;
		$inspector = $this->inspector_with(
			false,
			false,
			false,
			function ( string $url ) use ( &$called ): array {
				$called = true;

				return array(
					'code' => 200,
					'body' => 'unused',
				);
			}
		);

		$state = $inspector->inspect();

		self::assertFalse( $called, 'inspect() must never perform a network request.' );
		self::assertSame( LlmsPublicVerification::UNKNOWN, $state->public_verification );
	}

	/**
	 * A matching content hash confirms the bridge itself is being served.
	 */
	public function test_verification_confirms_bridge_when_hash_matches(): void {
		$body      = "# Example\n";
		$hash      = hash( 'sha256', $body );
		$inspector = $this->inspector_with(
			false,
			false,
			true,
			static fn ( string $url ): array => array(
				'code' => 200,
				'body' => $body,
			)
		);

		$state = $inspector->inspect_with_verification( 'https://example.com', $hash );

		self::assertSame( LlmsPublicVerification::SERVED_BY_BRIDGE, $state->public_verification );
		$this->assert_no_path_like_string( $state );
	}

	/**
	 * A `200` response with a non-matching (or absent) expected hash reports
	 * that something other than the bridge is serving the path.
	 */
	public function test_verification_reports_served_by_other_on_hash_mismatch(): void {
		$inspector = $this->inspector_with(
			false,
			false,
			true,
			static fn ( string $url ): array => array(
				'code' => 200,
				'body' => 'not the expected document',
			)
		);

		$state = $inspector->inspect_with_verification( 'https://example.com', hash( 'sha256', 'something else' ) );

		self::assertSame( LlmsPublicVerification::SERVED_BY_OTHER, $state->public_verification );
	}

	/**
	 * A `404` response reports that nothing is currently being served.
	 */
	public function test_verification_reports_not_found_on_404(): void {
		$inspector = $this->inspector_with(
			false,
			false,
			false,
			static fn ( string $url ): array => array(
				'code' => 404,
				'body' => '',
			)
		);

		$state = $inspector->inspect_with_verification( 'https://example.com', null );

		self::assertSame( LlmsPublicVerification::NOT_FOUND, $state->public_verification );
	}

	/**
	 * An unreachable site fails soft to unknown rather than throwing.
	 */
	public function test_verification_fails_soft_to_unknown_when_unreachable(): void {
		$inspector = $this->inspector_with(
			false,
			false,
			false,
			static function ( string $url ): array {
				throw new \RuntimeException( 'connection refused' );
			}
		);

		$state = $inspector->inspect_with_verification( 'https://example.com', null );

		self::assertSame( LlmsPublicVerification::UNKNOWN, $state->public_verification );
	}

	/**
	 * An empty site URL is rejected before any fetch is attempted.
	 */
	public function test_verification_reports_unknown_for_empty_site_url(): void {
		$called    = false;
		$inspector = $this->inspector_with(
			false,
			false,
			false,
			function ( string $url ) use ( &$called ): array {
				$called = true;

				return array(
					'code' => 200,
					'body' => '',
				);
			}
		);

		$state = $inspector->inspect_with_verification( '  ', null );

		self::assertFalse( $called, 'An empty site URL must never be fetched.' );
		self::assertSame( LlmsPublicVerification::UNKNOWN, $state->public_verification );
	}

	/**
	 * Outside a WordPress runtime, with no fakes supplied, every local probe
	 * fails safe to "no signal detected" rather than erroring.
	 */
	public function test_defaults_are_safe_without_a_wordpress_runtime(): void {
		$state = ( new WordPressLlmsOwnershipInspector() )->inspect();

		self::assertSame( LlmsOwnershipOwner::NONE, $state->owner );
		self::assertNull( $state->conflict );
		self::assertFalse( $state->physical_artifact_exists );
		self::assertFalse( $state->yoast_llms_txt_enabled );
		self::assertFalse( $state->bridge_publication_enabled );
		$this->assert_no_path_like_string( $state );
	}
}

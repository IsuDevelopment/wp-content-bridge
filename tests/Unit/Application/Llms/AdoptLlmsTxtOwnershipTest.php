<?php
/**
 * Llms.txt ownership-adoption use-case tests.
 *
 * @package IsuDev\WPContentBridge\Tests
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Tests\Unit\Application\Llms;

// phpcs:disable Squiz.Commenting.FunctionComment.Missing, Squiz.Commenting.VariableComment.Missing, Generic.Commenting.DocComment.MissingShort -- Anonymous test doubles keep their contracts visible through implemented interfaces.

use IsuDev\WPContentBridge\Application\Llms\AdoptLlmsTxtOwnership;
use IsuDev\WPContentBridge\Application\Llms\LlmsArtifactStore;
use IsuDev\WPContentBridge\Application\Llms\LlmsLegacyArtifactArchiver;
use IsuDev\WPContentBridge\Application\Llms\LlmsOwnershipAdoptionProblem;
use IsuDev\WPContentBridge\Application\Llms\LlmsOwnershipInspector;
use IsuDev\WPContentBridge\Application\Mutation\AuditEvent;
use IsuDev\WPContentBridge\Application\Mutation\AuditLog;
use IsuDev\WPContentBridge\Domain\Llms\LlmsArtifact;
use IsuDev\WPContentBridge\Domain\Llms\LlmsConfig;
use IsuDev\WPContentBridge\Domain\Llms\LlmsOwnershipOwner;
use IsuDev\WPContentBridge\Domain\Llms\LlmsOwnershipConflict;
use IsuDev\WPContentBridge\Domain\Llms\LlmsOwnershipState;
use IsuDev\WPContentBridge\Domain\Llms\LlmsPublicVerification;
use PHPUnit\Framework\TestCase;

/**
 * Verifies readiness gates, archival, post-write verification, and audit.
 */
final class AdoptLlmsTxtOwnershipTest extends TestCase {

	/**
	 * A ready site archives once, verifies the post-state, and records no values.
	 */
	public function test_archives_ready_legacy_artifacts_and_audits_success(): void {
		$store     = $this->store( $this->config(), $this->artifact() );
		$ownership = $this->ownership_sequence( $this->state( true ), $this->state( false ) );
		$archiver  = $this->archiver( array( 'llms.txt.backup_20260811_120000' ) );
		$audit     = $this->audit();
		$use       = new AdoptLlmsTxtOwnership( $store, $ownership, $archiver, $audit );

		$result = $use->execute( 7 );

		self::assertSame( array( 'llms.txt.backup_20260811_120000' ), $result->archived_artifacts );
		self::assertFalse( $result->ownership->physical_artifact_exists );
		self::assertSame( 1, $archiver->calls );
		self::assertSame( 'success', $audit->events[0]->outcome );
		self::assertSame( array( 'legacy_llms_artifacts' ), $audit->events[0]->changed_fields );
	}

	/**
	 * No filesystem adapter is reached before a complete snapshot exists.
	 */
	public function test_refuses_to_archive_before_snapshot_exists(): void {
		$ownership = $this->ownership_sequence( $this->state( true ) );
		$archiver  = $this->archiver( array() );
		$audit     = $this->audit();
		$use       = new AdoptLlmsTxtOwnership( $this->store( null, null ), $ownership, $archiver, $audit );

		try {
			$use->execute( 7 );
			self::fail( 'A missing snapshot must block adoption.' );
		} catch ( LlmsOwnershipAdoptionProblem $error ) {
			self::assertSame( 'snapshot_missing', $error->error_code );
		}

		self::assertSame( 0, $archiver->calls );
		self::assertSame( 'snapshot_missing', $audit->events[0]->error_code );
	}

	/**
	 * Plain permalinks block migration before the filesystem adapter is called.
	 */
	public function test_refuses_unroutable_virtual_endpoint(): void {
		$ownership = $this->ownership_sequence( $this->state( true, false ) );
		$archiver  = $this->archiver( array() );
		$use       = new AdoptLlmsTxtOwnership( $this->store( $this->config(), $this->artifact() ), $ownership, $archiver, $this->audit() );

		$this->expectException( LlmsOwnershipAdoptionProblem::class );
		try {
			$use->execute( 7 );
		} finally {
			self::assertSame( 0, $archiver->calls );
		}
	}

	/**
	 * A legacy companion still present after the adapter returns is a failed migration.
	 */
	public function test_refuses_success_when_a_legacy_companion_remains(): void {
		$ownership = $this->ownership_sequence( $this->state( true ), $this->state( false, true, true ) );
		$archiver  = $this->archiver( array( 'llms.txt.backup_20260811_120000' ) );
		$audit     = $this->audit();
		$use       = new AdoptLlmsTxtOwnership( $this->store( $this->config(), $this->artifact() ), $ownership, $archiver, $audit );

		try {
			$use->execute( 7 );
			self::fail( 'A remaining legacy companion must fail post-write verification.' );
		} catch ( LlmsOwnershipAdoptionProblem $error ) {
			self::assertSame( 'archive_verification_failed', $error->error_code );
		}

		self::assertSame( 'archive_verification_failed', $audit->events[0]->error_code );
	}

	/**
	 * Builds a fixed ownership state.
	 *
	 * @param bool $physical        Whether the blocking root file exists.
	 * @param bool $routable         Whether the virtual route can match.
	 * @param bool $legacy_companion Whether a legacy companion remains.
	 */
	private function state( bool $physical, bool $routable = true, bool $legacy_companion = false ): LlmsOwnershipState {
		return new LlmsOwnershipState(
			$physical ? LlmsOwnershipOwner::THIRD_PARTY : LlmsOwnershipOwner::BRIDGE,
			$physical,
			$legacy_companion,
			false,
			false,
			true,
			$routable,
			LlmsPublicVerification::UNKNOWN,
			$physical ? LlmsOwnershipConflict::PHYSICAL_ARTIFACT_PRESENT : null,
			$physical ? 'Archive the legacy artifact.' : 'Bridge publication is ready.'
		);
	}

	/**
	 * Builds a valid configuration fixture.
	 */
	private function config(): LlmsConfig {
		return LlmsConfig::from_input(
			array(
				'site_title'            => 'Example',
				'site_summary'          => 'Summary.',
				'enabled_post_types'    => array( 'post' ),
				'sections'              => array(
					array(
						'key'   => 'post',
						'label' => 'Posts',
					),
				),
				'group_by_section'      => true,
				'show_excerpts'         => true,
				'excerpt_length'        => 160,
				'max_items_per_section' => 10,
			),
			'https://example.test'
		);
	}

	/**
	 * Builds a stored snapshot fixture.
	 */
	private function artifact(): LlmsArtifact {
		return new LlmsArtifact( "# Example\n", hash( 'sha256', "# Example\n" ), '2026-08-11T12:00:00Z', 10, 0, array() );
	}

	/**
	 * Builds an in-memory snapshot store.
	 *
	 * @param LlmsConfig|null   $config   Fixed configuration.
	 * @param LlmsArtifact|null $artifact Fixed snapshot.
	 */
	private function store( ?LlmsConfig $config, ?LlmsArtifact $artifact ): LlmsArtifactStore {
		return new class( $config, $artifact ) implements LlmsArtifactStore {
			public function __construct( private ?LlmsConfig $config, private ?LlmsArtifact $artifact ) {}
			public function config(): ?LlmsConfig {
				return $this->config; }
			public function replace_config( LlmsConfig $config ): void {
				$this->config = $config; }
			public function artifact(): ?LlmsArtifact {
				return $this->artifact; }
			public function replace_artifact( LlmsArtifact $artifact ): void {
				$this->artifact = $artifact; }
		};
	}

	/**
	 * Builds an inspector that advances through fixed states.
	 *
	 * @param LlmsOwnershipState ...$states Ordered states.
	 */
	private function ownership_sequence( LlmsOwnershipState ...$states ): LlmsOwnershipInspector {
		return new class( $states ) implements LlmsOwnershipInspector {
			private int $index = 0;
			/** @param array<int, LlmsOwnershipState> $states */
			public function __construct( private array $states ) {}
			public function inspect(): LlmsOwnershipState {
				$state = $this->states[ min( $this->index, count( $this->states ) - 1 ) ];
				++$this->index;
				return $state;
			}
			public function inspect_with_verification( string $site_url, ?string $expected_content_hash ): LlmsOwnershipState {
				unset( $site_url, $expected_content_hash );
				return $this->inspect();
			}
		};
	}

	/**
	 * Builds a call-counting archival adapter.
	 *
	 * @param array<int, string> $result Fixed safe basenames.
	 */
	private function archiver( array $result ): object {
		return new class( $result ) implements LlmsLegacyArtifactArchiver {
			public int $calls = 0;
			/** @param array<int, string> $result */
			public function __construct( private array $result ) {}
			public function archive(): array {
				++$this->calls;
				return $this->result; }
		};
	}

	/**
	 * Builds an in-memory audit sink.
	 */
	private function audit(): object {
		return new class() implements AuditLog {
			/** @var array<int, AuditEvent> */
			public array $events = array();
			public function record( AuditEvent $event ): void {
				$this->events[] = $event; }
		};
	}
}

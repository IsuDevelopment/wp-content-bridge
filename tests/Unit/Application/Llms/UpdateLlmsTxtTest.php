<?php
/**
 * Unit tests for the update-llms-txt use case.
 *
 * @package IsuDev\WPContentBridge\Tests
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Tests\Unit\Application\Llms;

use IsuDev\WPContentBridge\Application\Llms\LlmsArtifactStore;
use IsuDev\WPContentBridge\Application\Llms\LlmsSourceSelector;
use IsuDev\WPContentBridge\Application\Llms\UpdateLlmsTxt;
use IsuDev\WPContentBridge\Application\Mutation\AuditEvent;
use IsuDev\WPContentBridge\Application\Mutation\AuditLog;
use IsuDev\WPContentBridge\Application\Mutation\MutationConflict;
use IsuDev\WPContentBridge\Application\Mutation\MutationWriteFailed;
use IsuDev\WPContentBridge\Domain\Llms\LlmsArtifact;
use IsuDev\WPContentBridge\Domain\Llms\LlmsConfig;
use IsuDev\WPContentBridge\Domain\Llms\LlmsDocumentBuilder;
use IsuDev\WPContentBridge\Domain\Llms\LlmsSourceEntry;
use IsuDev\WPContentBridge\Domain\Mutation\VersionToken;
use PHPUnit\Framework\TestCase;

/**
 * Verifies validated regeneration, atomic replacement, stale-token
 * rejection, post-write read-back, and redacted audit rows.
 */
final class UpdateLlmsTxtTest extends TestCase {

	/**
	 * Canonical site origin, supplied to the use case the way the WordPress
	 * adapter does — never part of the Ability input array.
	 *
	 * @var string
	 */
	private const SITE_URL = 'https://example.test';

	/**
	 * A first-time configuration write replaces both options, reports every
	 * top-level field as changed, and audits success without leaking values.
	 */
	public function test_writes_first_configuration_and_redacts_audit_payload(): void {
		$store    = $this->store( null, null );
		$selector = $this->selector( array( new LlmsSourceEntry( 'Hello', 'https://example.test/hello', null, 'post' ) ) );
		$audit    = $this->audit_spy();
		$use      = new UpdateLlmsTxt( $store, $selector, new LlmsDocumentBuilder(), $audit, self::SITE_URL );

		$token  = VersionToken::for_llms( null, null )->to_string();
		$result = $use->execute( array_merge( $this->config_input(), array( 'version_token' => $token ) ), 7 );

		self::assertSame( 1, $store->replace_config_calls );
		self::assertSame( 1, $store->replace_artifact_calls );
		self::assertContains( 'site_url', $result->changed_fields );
		self::assertContains( 'sections', $result->changed_fields );
		self::assertSame( 1, $result->artifact->link_count );
		self::assertSame( 'success', $audit->events[0]->outcome );
		self::assertSame( UpdateLlmsTxt::ABILITY, $audit->events[0]->ability );
		self::assertContains( 'site_url', $audit->events[0]->changed_fields );
	}

	/**
	 * A stale version token is rejected before either option is written, and
	 * the conflict is audited with the stable `wpcb_conflict` code.
	 */
	public function test_stale_token_is_rejected_before_any_write(): void {
		$store    = $this->store( null, null );
		$selector = $this->selector( array() );
		$audit    = $this->audit_spy();
		$use      = new UpdateLlmsTxt( $store, $selector, new LlmsDocumentBuilder(), $audit, self::SITE_URL );

		$this->expectException( MutationConflict::class );
		try {
			$use->execute( array_merge( $this->config_input(), array( 'version_token' => 'ffffffffffffffff:stale' ) ), 7 );
		} finally {
			self::assertSame( 0, $store->replace_config_calls );
			self::assertSame( 0, $store->replace_artifact_calls );
			self::assertSame( 'conflict', $audit->events[0]->outcome );
			self::assertSame( 'wpcb_conflict', $audit->events[0]->error_code );
		}
	}

	/**
	 * A resubmission with an unchanged configuration reports no changed
	 * fields, even though both options are still replaced.
	 */
	public function test_resubmitting_the_same_configuration_reports_no_changed_fields(): void {
		$config   = LlmsConfig::from_input( $this->config_input(), self::SITE_URL );
		$entries  = array( new LlmsSourceEntry( 'Hello', 'https://example.test/hello', null, 'post' ) );
		$artifact = ( new LlmsDocumentBuilder() )->build( $config, $entries );
		$store    = $this->store( $config, $artifact );
		$selector = $this->selector( $entries );
		$audit    = $this->audit_spy();
		$use      = new UpdateLlmsTxt( $store, $selector, new LlmsDocumentBuilder(), $audit, self::SITE_URL );

		$token  = VersionToken::for_llms( $config->to_array(), $artifact->content_hash )->to_string();
		$result = $use->execute( array_merge( $this->config_input(), array( 'version_token' => $token ) ), 7 );

		self::assertSame( array(), $result->changed_fields );
	}

	/**
	 * A post-write read-back mismatch is surfaced as a write failure and
	 * audited accordingly.
	 */
	public function test_read_back_mismatch_is_reported_as_write_failure(): void {
		$store    = $this->broken_store();
		$selector = $this->selector( array() );
		$audit    = $this->audit_spy();
		$use      = new UpdateLlmsTxt( $store, $selector, new LlmsDocumentBuilder(), $audit, self::SITE_URL );

		$token = VersionToken::for_llms( null, null )->to_string();

		$this->expectException( MutationWriteFailed::class );
		try {
			$use->execute( array_merge( $this->config_input(), array( 'version_token' => $token ) ), 7 );
		} finally {
			self::assertSame( 'failure', $audit->events[0]->outcome );
			self::assertSame( 'wpcb_write_failed', $audit->events[0]->error_code );
		}
	}

	/**
	 * Returns the valid configuration input shared by every scenario.
	 *
	 * @return array<string, mixed>
	 */
	private function config_input(): array {
		return array(
			'site_title'            => 'Example',
			'site_summary'          => 'A summary.',
			'introduction'          => null,
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
			'curated_links'         => array(),
		);
	}

	/**
	 * Builds a working in-memory store fake.
	 *
	 * @param LlmsConfig|null   $config   Initial configuration to return.
	 * @param LlmsArtifact|null $artifact Initial artifact to return.
	 */
	private function store( ?LlmsConfig $config, ?LlmsArtifact $artifact ): object {
		return new class( $config, $artifact ) implements LlmsArtifactStore {
			/**
			 * Number of `replace_config()` calls.
			 *
			 * @var int
			 */
			public int $replace_config_calls = 0;

			/**
			 * Number of `replace_artifact()` calls.
			 *
			 * @var int
			 */
			public int $replace_artifact_calls = 0;

			/**
			 * Creates the fake.
			 *
			 * @param LlmsConfig|null   $config   Initial configuration.
			 * @param LlmsArtifact|null $artifact Initial artifact.
			 */
			public function __construct( private ?LlmsConfig $config, private ?LlmsArtifact $artifact ) {}

			/**
			 * Returns the current configuration.
			 */
			public function config(): ?LlmsConfig {
				return $this->config;
			}

			/**
			 * Replaces the stored configuration.
			 *
			 * @param LlmsConfig $config Configuration to persist.
			 */
			public function replace_config( LlmsConfig $config ): void {
				++$this->replace_config_calls;
				$this->config = $config;
			}

			/**
			 * Returns the current artifact.
			 */
			public function artifact(): ?LlmsArtifact {
				return $this->artifact;
			}

			/**
			 * Replaces the stored artifact.
			 *
			 * @param LlmsArtifact $artifact Snapshot to persist.
			 */
			public function replace_artifact( LlmsArtifact $artifact ): void {
				++$this->replace_artifact_calls;
				$this->artifact = $artifact;
			}
		};
	}

	/**
	 * Builds a store whose writes silently do not stick, to trigger the
	 * post-write read-back failure path.
	 */
	private function broken_store(): LlmsArtifactStore {
		return new class() implements LlmsArtifactStore {
			/**
			 * Always reports nothing stored, regardless of writes.
			 */
			public function config(): ?LlmsConfig {
				return null;
			}

			/**
			 * Accepts the write but never actually stores it.
			 *
			 * @param LlmsConfig $config Unused.
			 */
			public function replace_config( LlmsConfig $config ): void {}

			/**
			 * Always reports nothing stored, regardless of writes.
			 */
			public function artifact(): ?LlmsArtifact {
				return null;
			}

			/**
			 * Accepts the write but never actually stores it.
			 *
			 * @param LlmsArtifact $artifact Unused.
			 */
			public function replace_artifact( LlmsArtifact $artifact ): void {}
		};
	}

	/**
	 * Builds a fixed selector fake.
	 *
	 * @param array $entries Entries to return.
	 * @phpstan-param list<LlmsSourceEntry> $entries
	 */
	private function selector( array $entries ): LlmsSourceSelector {
		return new class( $entries ) implements LlmsSourceSelector {
			/**
			 * Creates the fake.
			 *
			 * @param array $entries Entries to return.
			 * @phpstan-param list<LlmsSourceEntry> $entries
			 */
			public function __construct( private array $entries ) {}

			/**
			 * Returns the fixed entries.
			 *
			 * @param LlmsConfig $config Unused.
			 * @return array
			 * @phpstan-return list<LlmsSourceEntry>
			 */
			public function select( LlmsConfig $config ): array {
				return $this->entries;
			}
		};
	}

	/**
	 * Builds an in-memory audit sink.
	 *
	 * @return object
	 */
	private function audit_spy(): object {
		return new class() implements AuditLog {
			/**
			 * Recorded events.
			 *
			 * @var list<AuditEvent>
			 */
			public array $events = array();

			/**
			 * Records one event.
			 *
			 * @param AuditEvent $event Audit event.
			 */
			public function record( AuditEvent $event ): void {
				$this->events[] = $event;
			}
		};
	}
}

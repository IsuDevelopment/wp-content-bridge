<?php
/**
 * Unit tests for the regenerate-llms-txt use case.
 *
 * @package IsuDev\WPContentBridge\Tests
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Tests\Unit\Application\Llms;

use InvalidArgumentException;
use IsuDev\WPContentBridge\Application\Llms\LlmsArtifactStore;
use IsuDev\WPContentBridge\Application\Llms\LlmsSourceSelector;
use IsuDev\WPContentBridge\Application\Llms\RegenerateLlmsTxt;
use IsuDev\WPContentBridge\Application\Mutation\AuditEvent;
use IsuDev\WPContentBridge\Application\Mutation\AuditLog;
use IsuDev\WPContentBridge\Domain\Llms\LlmsArtifact;
use IsuDev\WPContentBridge\Domain\Llms\LlmsConfig;
use IsuDev\WPContentBridge\Domain\Llms\LlmsDocumentBuilder;
use IsuDev\WPContentBridge\Domain\Llms\LlmsSourceEntry;
use PHPUnit\Framework\TestCase;
use IsuDev\WPContentBridge\Tests\Support\FixedLlmsOwnershipInspector;

/**
 * Verifies regeneration requires prior configuration, accepts no input, and
 * is idempotent for unchanged source and configuration.
 */
final class RegenerateLlmsTxtTest extends TestCase {

	/**
	 * Canonical site origin used to build fixture configurations.
	 *
	 * @var string
	 */
	private const SITE_URL = 'https://example.test';

	/**
	 * Regenerating before anything has been configured is rejected and
	 * audited as invalid, never as a write failure.
	 */
	public function test_rejects_regeneration_before_configuration_exists(): void {
		$store    = $this->store( null, null );
		$selector = $this->selector( array() );
		$audit    = $this->audit_spy();
		$use      = new RegenerateLlmsTxt( $store, $selector, new LlmsDocumentBuilder(), $audit, new FixedLlmsOwnershipInspector() );

		$this->expectException( InvalidArgumentException::class );
		try {
			$use->execute( array(), 7 );
		} finally {
			self::assertSame( 0, $store->replace_artifact_calls );
			self::assertSame( 'invalid', $audit->events[0]->outcome );
			self::assertSame( 'wpcb_invalid_input', $audit->events[0]->error_code );
		}
	}

	/**
	 * Regeneration accepts no input fields at all.
	 */
	public function test_rejects_any_supplied_input(): void {
		$config   = LlmsConfig::from_input( $this->config_input(), self::SITE_URL );
		$store    = $this->store( $config, null );
		$selector = $this->selector( array() );
		$audit    = $this->audit_spy();
		$use      = new RegenerateLlmsTxt( $store, $selector, new LlmsDocumentBuilder(), $audit, new FixedLlmsOwnershipInspector() );

		$this->expectException( InvalidArgumentException::class );
		try {
			$use->execute( array( 'site_url' => 'https://attacker.test' ), 7 );
		} finally {
			self::assertSame( 0, $selector->select_calls );
		}
	}

	/**
	 * A first regeneration with no prior artifact writes one and reports the
	 * change.
	 */
	public function test_first_regeneration_writes_a_new_artifact(): void {
		$config   = LlmsConfig::from_input( $this->config_input(), self::SITE_URL );
		$store    = $this->store( $config, null );
		$selector = $this->selector( array( new LlmsSourceEntry( 'Hello', 'https://example.test/hello', null, 'post' ) ) );
		$audit    = $this->audit_spy();
		$use      = new RegenerateLlmsTxt( $store, $selector, new LlmsDocumentBuilder(), $audit, new FixedLlmsOwnershipInspector() );

		$result = $use->execute( array(), 7 );

		self::assertSame( 1, $store->replace_artifact_calls );
		self::assertSame( array( 'artifact' ), $result->changed_fields );
		self::assertSame( 'success', $audit->events[0]->outcome );
	}

	/**
	 * Regenerating again from unchanged source and configuration is
	 * idempotent: the store is not written to again, no field is reported
	 * as changed, and the exact same artifact instance is returned so its
	 * hash and generation time do not churn.
	 */
	public function test_unchanged_regeneration_is_idempotent(): void {
		$config   = LlmsConfig::from_input( $this->config_input(), self::SITE_URL );
		$entries  = array( new LlmsSourceEntry( 'Hello', 'https://example.test/hello', null, 'post' ) );
		$artifact = ( new LlmsDocumentBuilder() )->build( $config, $entries );
		$store    = $this->store( $config, $artifact );
		$selector = $this->selector( $entries );
		$audit    = $this->audit_spy();
		$use      = new RegenerateLlmsTxt( $store, $selector, new LlmsDocumentBuilder(), $audit, new FixedLlmsOwnershipInspector() );

		$result = $use->execute( array(), 7 );

		self::assertSame( 0, $store->replace_artifact_calls );
		self::assertSame( array(), $result->changed_fields );
		self::assertSame( $artifact->content_hash, $result->artifact->content_hash );
		self::assertSame( $artifact->generated_at, $result->artifact->generated_at );
	}

	/**
	 * Returns a valid configuration input.
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
	 * Builds a call-counting store fake.
	 *
	 * @param LlmsConfig|null   $config   Fixed configuration to return.
	 * @param LlmsArtifact|null $artifact Initial artifact to return.
	 */
	private function store( ?LlmsConfig $config, ?LlmsArtifact $artifact ): object {
		return new class( $config, $artifact ) implements LlmsArtifactStore {
			/**
			 * Number of `replace_artifact()` calls.
			 *
			 * @var int
			 */
			public int $replace_artifact_calls = 0;

			/**
			 * Creates the fake.
			 *
			 * @param LlmsConfig|null   $config   Fixed configuration.
			 * @param LlmsArtifact|null $artifact Initial artifact.
			 */
			public function __construct( private ?LlmsConfig $config, private ?LlmsArtifact $artifact ) {}

			/**
			 * Returns the fixed configuration.
			 */
			public function config(): ?LlmsConfig {
				return $this->config;
			}

			/**
			 * Unused in this test.
			 *
			 * @param LlmsConfig $config Unused.
			 */
			public function replace_config( LlmsConfig $config ): void {}

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
	 * Builds a call-counting selector fake.
	 *
	 * @param array $entries Entries to return.
	 * @phpstan-param list<LlmsSourceEntry> $entries
	 */
	private function selector( array $entries ): object {
		return new class( $entries ) implements LlmsSourceSelector {
			/**
			 * Number of `select()` calls.
			 *
			 * @var int
			 */
			public int $select_calls = 0;

			/**
			 * Creates the fake.
			 *
			 * @param array $entries Entries to return.
			 * @phpstan-param list<LlmsSourceEntry> $entries
			 */
			public function __construct( private array $entries ) {}

			/**
			 * Records a selection and returns the fixed entries.
			 *
			 * @param LlmsConfig $config Unused.
			 * @return array
			 * @phpstan-return list<LlmsSourceEntry>
			 */
			public function select( LlmsConfig $config ): array {
				++$this->select_calls;

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

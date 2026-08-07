<?php
/**
 * Unit tests for the preview-update-llms-txt use case.
 *
 * @package IsuDev\WPContentBridge\Tests
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Tests\Unit\Application\Llms;

use IsuDev\WPContentBridge\Application\Llms\LlmsArtifactStore;
use IsuDev\WPContentBridge\Application\Llms\LlmsSourceSelector;
use IsuDev\WPContentBridge\Application\Mutation\MutationConflict;
use IsuDev\WPContentBridge\Application\Llms\PreviewUpdateLlmsTxt;
use IsuDev\WPContentBridge\Domain\Llms\LlmsArtifact;
use IsuDev\WPContentBridge\Domain\Llms\LlmsConfig;
use IsuDev\WPContentBridge\Domain\Llms\LlmsDocumentBuilder;
use IsuDev\WPContentBridge\Domain\Llms\LlmsSourceEntry;
use IsuDev\WPContentBridge\Domain\Mutation\VersionToken;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the prospective document is built from live selection, that
 * nothing is written, and that a stale token is rejected before selection.
 */
final class PreviewUpdateLlmsTxtTest extends TestCase {

	/**
	 * Canonical site origin, supplied to the use case the way the WordPress
	 * adapter does — never part of the Ability input array.
	 *
	 * @var string
	 */
	private const SITE_URL = 'https://example.test';

	/**
	 * Building a preview against an unconfigured store reports every new
	 * section as added and never touches the store's write methods.
	 */
	public function test_previews_first_configuration_without_writing(): void {
		$store    = $this->store( null, null );
		$selector = $this->selector( array( new LlmsSourceEntry( 'Hello', 'https://example.test/hello', null, 'post' ) ) );
		$use      = new PreviewUpdateLlmsTxt( $store, $selector, new LlmsDocumentBuilder(), self::SITE_URL );

		$token  = VersionToken::for_llms( null, null )->to_string();
		$result = $use->execute( array_merge( $this->config_input(), array( 'version_token' => $token ) ) )->to_array();

		self::assertFalse( $result['writes_performed'] );
		self::assertNull( $result['current_config'] );
		self::assertNull( $result['current_artifact'] );
		self::assertSame( 1, $result['prospective_artifact']['link_count'] );
		self::assertSame( array( 'Posts' ), $result['diff']['added_sections'] );
		self::assertSame( array(), $result['diff']['removed_sections'] );
		self::assertSame( array(), $result['diff']['changed_sections'] );
		self::assertSame( 0, $store->replace_config_calls );
		self::assertSame( 0, $store->replace_artifact_calls );
	}

	/**
	 * A stale version token is rejected before the selector runs at all.
	 */
	public function test_stale_token_is_rejected_before_selection(): void {
		$store    = $this->store( null, null );
		$selector = $this->selector( array() );
		$use      = new PreviewUpdateLlmsTxt( $store, $selector, new LlmsDocumentBuilder(), self::SITE_URL );

		$this->expectException( MutationConflict::class );
		try {
			$use->execute( array_merge( $this->config_input(), array( 'version_token' => 'ffffffffffffffff:stale' ) ) );
		} finally {
			self::assertSame( 0, $selector->select_calls );
		}
	}

	/**
	 * Previewing against an already-configured, already-generated store
	 * reports no diff when the resubmitted configuration is unchanged.
	 */
	public function test_previews_unchanged_configuration_with_empty_diff(): void {
		$config   = LlmsConfig::from_input( $this->config_input(), self::SITE_URL );
		$entries  = array( new LlmsSourceEntry( 'Hello', 'https://example.test/hello', null, 'post' ) );
		$artifact = ( new LlmsDocumentBuilder() )->build( $config, $entries );
		$store    = $this->store( $config, $artifact );
		$selector = $this->selector( $entries );
		$use      = new PreviewUpdateLlmsTxt( $store, $selector, new LlmsDocumentBuilder(), self::SITE_URL );

		$token  = VersionToken::for_llms( $config->to_array(), $artifact->content_hash )->to_string();
		$result = $use->execute( array_merge( $this->config_input(), array( 'version_token' => $token ) ) )->to_array();

		self::assertSame( array(), $result['diff']['added_sections'] );
		self::assertSame( array(), $result['diff']['removed_sections'] );
		self::assertSame( array(), $result['diff']['changed_sections'] );
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
	 * Builds a call-counting store fake.
	 *
	 * @param LlmsConfig|null   $config   Fixed configuration to return.
	 * @param LlmsArtifact|null $artifact Fixed artifact to return.
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
			 * @param LlmsConfig|null   $config   Fixed configuration.
			 * @param LlmsArtifact|null $artifact Fixed artifact.
			 */
			public function __construct( private ?LlmsConfig $config, private ?LlmsArtifact $artifact ) {}

			/**
			 * Returns the fixed configuration.
			 */
			public function config(): ?LlmsConfig {
				return $this->config;
			}

			/**
			 * Records a config replacement; must never be called by preview.
			 *
			 * @param LlmsConfig $config Unused.
			 */
			public function replace_config( LlmsConfig $config ): void {
				++$this->replace_config_calls;
			}

			/**
			 * Returns the fixed artifact.
			 */
			public function artifact(): ?LlmsArtifact {
				return $this->artifact;
			}

			/**
			 * Records an artifact replacement; must never be called by preview.
			 *
			 * @param LlmsArtifact $artifact Unused.
			 */
			public function replace_artifact( LlmsArtifact $artifact ): void {
				++$this->replace_artifact_calls;
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
}

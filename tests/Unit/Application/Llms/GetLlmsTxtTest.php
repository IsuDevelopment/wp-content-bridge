<?php
/**
 * Unit tests for the get-llms-txt use case.
 *
 * @package IsuDev\WPContentBridge\Tests
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Tests\Unit\Application\Llms;

use InvalidArgumentException;
use IsuDev\WPContentBridge\Application\Llms\GetLlmsTxt;
use IsuDev\WPContentBridge\Application\Llms\LlmsArtifactStore;
use IsuDev\WPContentBridge\Application\Llms\LlmsOwnershipInspector;
use IsuDev\WPContentBridge\Domain\Llms\LlmsArtifact;
use IsuDev\WPContentBridge\Domain\Llms\LlmsConfig;
use IsuDev\WPContentBridge\Domain\Llms\LlmsOwnershipOwner;
use IsuDev\WPContentBridge\Domain\Llms\LlmsOwnershipState;
use IsuDev\WPContentBridge\Domain\Llms\LlmsPublicVerification;
use PHPUnit\Framework\TestCase;

/**
 * Verifies unconfigured defaults, stored-state reporting, and the opt-in
 * public-verification leg of ownership detection.
 */
final class GetLlmsTxtTest extends TestCase {

	/**
	 * With nothing stored, config and artifact are null, the local-only
	 * inspector leg runs, and the version token is well-formed.
	 */
	public function test_reports_unconfigured_state_without_verification(): void {
		$store     = $this->store( null, null );
		$ownership = $this->ownership();
		$result    = ( new GetLlmsTxt( $store, $ownership ) )->execute( array() )->to_array();

		self::assertNull( $result['config'] );
		self::assertNull( $result['artifact'] );
		self::assertSame( 1, $ownership->inspect_calls );
		self::assertSame( 0, $ownership->verified_calls );
		self::assertSame( 'none', $result['ownership']['owner'] );
		self::assertMatchesRegularExpression( '/^[0-9a-f]{16}:none$/', $result['version_token'] );
	}

	/**
	 * With configuration and an artifact stored, both are reported and the
	 * default (no verification requested) still uses the cheap local-only leg.
	 */
	public function test_reports_stored_configuration_and_artifact(): void {
		$config    = $this->config();
		$artifact  = $this->artifact();
		$store     = $this->store( $config, $artifact );
		$ownership = $this->ownership();
		$result    = ( new GetLlmsTxt( $store, $ownership ) )->execute( array() )->to_array();

		self::assertSame( 'https://example.test', $result['config']['site_url'] );
		self::assertSame( $artifact->content_hash, $result['artifact']['content_hash'] );
		self::assertArrayNotHasKey( 'content', $result['artifact'] );
		self::assertSame( 0, $ownership->verified_calls );
	}

	/**
	 * Requesting verification with a stored configuration triggers the
	 * network-touching leg, passing the site URL and current content hash.
	 */
	public function test_verify_public_endpoint_triggers_verification_leg(): void {
		$config    = $this->config();
		$artifact  = $this->artifact();
		$store     = $this->store( $config, $artifact );
		$ownership = $this->ownership();

		( new GetLlmsTxt( $store, $ownership ) )->execute( array( 'verify_public_endpoint' => true ) );

		self::assertSame( 1, $ownership->verified_calls );
		self::assertSame( 0, $ownership->inspect_calls );
		self::assertSame( 'https://example.test', $ownership->last_site_url );
		self::assertSame( $artifact->content_hash, $ownership->last_expected_hash );
	}

	/**
	 * Verification is skipped, falling back to the local-only leg, when no
	 * configuration exists yet: there is no site URL to probe.
	 */
	public function test_verify_public_endpoint_is_skipped_when_unconfigured(): void {
		$store     = $this->store( null, null );
		$ownership = $this->ownership();

		( new GetLlmsTxt( $store, $ownership ) )->execute( array( 'verify_public_endpoint' => true ) );

		self::assertSame( 1, $ownership->inspect_calls );
		self::assertSame( 0, $ownership->verified_calls );
	}

	/**
	 * An unsupported field is rejected before either port is touched.
	 */
	public function test_rejects_unsupported_input_field(): void {
		$store     = $this->store( null, null );
		$ownership = $this->ownership();

		$this->expectException( InvalidArgumentException::class );
		try {
			( new GetLlmsTxt( $store, $ownership ) )->execute( array( 'unexpected' => true ) );
		} finally {
			self::assertSame( 0, $ownership->inspect_calls );
			self::assertSame( 0, $ownership->verified_calls );
		}
	}

	/**
	 * Builds a valid stored configuration.
	 */
	private function config(): LlmsConfig {
		return LlmsConfig::from_input(
			array(
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
			),
			'https://example.test'
		);
	}

	/**
	 * Builds a fixed stored artifact.
	 */
	private function artifact(): LlmsArtifact {
		return new LlmsArtifact( "# Example\n\n> A summary.\n", 'aaaabbbbccccdddd', '2026-08-07T00:00:00Z', 30, 0, array() );
	}

	/**
	 * Builds a fixed store returning the given state.
	 *
	 * @param LlmsConfig|null   $config   Fixed configuration to return.
	 * @param LlmsArtifact|null $artifact Fixed artifact to return.
	 */
	private function store( ?LlmsConfig $config, ?LlmsArtifact $artifact ): LlmsArtifactStore {
		return new class( $config, $artifact ) implements LlmsArtifactStore {
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
			 * Unused in this test.
			 *
			 * @param LlmsConfig $config Unused.
			 */
			public function replace_config( LlmsConfig $config ): void {}

			/**
			 * Returns the fixed artifact.
			 */
			public function artifact(): ?LlmsArtifact {
				return $this->artifact;
			}

			/**
			 * Unused in this test.
			 *
			 * @param LlmsArtifact $artifact Unused.
			 */
			public function replace_artifact( LlmsArtifact $artifact ): void {}
		};
	}

	/**
	 * Builds a call-counting ownership inspector fake.
	 *
	 * @return object
	 */
	private function ownership(): object {
		return new class() implements LlmsOwnershipInspector {
			/**
			 * Number of `inspect()` calls.
			 *
			 * @var int
			 */
			public int $inspect_calls = 0;

			/**
			 * Number of `inspect_with_verification()` calls.
			 *
			 * @var int
			 */
			public int $verified_calls = 0;

			/**
			 * Last site URL passed to `inspect_with_verification()`.
			 *
			 * @var string|null
			 */
			public ?string $last_site_url = null;

			/**
			 * Last expected content hash passed to `inspect_with_verification()`.
			 *
			 * @var string|null
			 */
			public ?string $last_expected_hash = null;

			/**
			 * Records a local-only inspection and returns a fixed unknown state.
			 */
			public function inspect(): LlmsOwnershipState {
				++$this->inspect_calls;

				return new LlmsOwnershipState(
					LlmsOwnershipOwner::NONE,
					false,
					false,
					false,
					LlmsPublicVerification::UNKNOWN,
					null,
					'No ownership conflict was detected, and publication is currently disabled. Enable it here when ready.'
				);
			}

			/**
			 * Records a verified inspection and returns a fixed served-by-bridge state.
			 *
			 * @param string      $site_url              Site URL probed.
			 * @param string|null $expected_content_hash Expected content hash.
			 */
			public function inspect_with_verification( string $site_url, ?string $expected_content_hash ): LlmsOwnershipState {
				++$this->verified_calls;
				$this->last_site_url      = $site_url;
				$this->last_expected_hash = $expected_content_hash;

				return new LlmsOwnershipState(
					LlmsOwnershipOwner::BRIDGE,
					false,
					false,
					true,
					LlmsPublicVerification::SERVED_BY_BRIDGE,
					null,
					'No ownership conflict was detected. Publication is enabled, and this plugin\'s endpoint is expected to be the one serving this site\'s llms.txt request.'
				);
			}
		};
	}
}

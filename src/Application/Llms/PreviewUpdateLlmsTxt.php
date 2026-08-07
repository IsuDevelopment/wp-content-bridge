<?php
/**
 * Preview-update-llms-txt use case.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Application\Llms;

use IsuDev\WPContentBridge\Application\Mutation\MutationConflict;
use IsuDev\WPContentBridge\Domain\Llms\LlmsConfigUpdate;
use IsuDev\WPContentBridge\Domain\Llms\LlmsDocumentBuilder;
use IsuDev\WPContentBridge\Domain\Llms\LlmsPreviewResult;
use IsuDev\WPContentBridge\Domain\Mutation\VersionToken;

/**
 * Builds the whole prospective llms.txt document from live site content —
 * something the caller cannot compute itself — without writing anything.
 *
 * Accepts the exact same configuration input as update-llms-txt, so a
 * validated preview result can be resubmitted to update-llms-txt unchanged.
 * Takes no {@see \IsuDev\WPContentBridge\Application\Mutation\AuditLog}
 * dependency at all: nothing here is a write, so nothing here is audited.
 */
final readonly class PreviewUpdateLlmsTxt {

	public const ABILITY = 'wp-content-bridge/preview-update-llms-txt';

	/**
	 * Creates the use case.
	 *
	 * @param LlmsArtifactStore   $store    Configuration and snapshot read port.
	 * @param LlmsSourceSelector  $selector Eligible-entry selection port.
	 * @param LlmsDocumentBuilder $builder  Pure document generator.
	 * @param string              $site_url Canonical absolute site origin; a site fact supplied by the
	 *                                       WordPress adapter, never taken from caller input.
	 */
	public function __construct(
		private LlmsArtifactStore $store,
		private LlmsSourceSelector $selector,
		private LlmsDocumentBuilder $builder,
		private string $site_url,
	) {
	}

	/**
	 * Builds a prospective document without writing.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return LlmsPreviewResult
	 * @throws MutationConflict When the submitted version token is stale.
	 */
	public function execute( array $input ): LlmsPreviewResult {
		$update = LlmsConfigUpdate::from_input( $input, $this->site_url );

		$current_config   = $this->store->config();
		$current_artifact = $this->store->artifact();

		$current_version = VersionToken::for_llms( $current_config?->to_array(), $current_artifact?->content_hash );
		if ( ! $current_version->equals( $update->expected_version ) ) {
			throw new MutationConflict( 'The submitted version token is stale.' );
		}

		$entries     = $this->selector->select( $update->config );
		$prospective = $this->builder->build( $update->config, $entries );

		return new LlmsPreviewResult( $current_version, $current_config, $current_artifact, $update->config, $prospective );
	}
}

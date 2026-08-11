<?php
/**
 * Update-llms-txt use case.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Application\Llms;

use InvalidArgumentException;
use IsuDev\WPContentBridge\Application\Mutation\AuditEvent;
use IsuDev\WPContentBridge\Application\Mutation\AuditLog;
use IsuDev\WPContentBridge\Application\Mutation\MutationConflict;
use IsuDev\WPContentBridge\Application\Mutation\MutationWriteFailed;
use IsuDev\WPContentBridge\Domain\Llms\LlmsConfig;
use IsuDev\WPContentBridge\Domain\Llms\LlmsConfigUpdate;
use IsuDev\WPContentBridge\Domain\Llms\LlmsDocumentBuilder;
use IsuDev\WPContentBridge\Domain\Llms\LlmsMutationResult;
use IsuDev\WPContentBridge\Domain\Mutation\VersionToken;
use Throwable;

/**
 * Validates a prospective llms.txt configuration, regenerates the document
 * from live site content, and atomically replaces the stored configuration
 * and snapshot, recording exactly one redacted audit row per attempt.
 *
 * Generation runs before either option is replaced, so a validation or
 * selection failure never touches stored state. Each of the two subsequent
 * option replacements is atomic at the store's own boundary (see
 * {@see LlmsArtifactStore}); a post-write read-back confirms both landed
 * before this use case reports success.
 */
final readonly class UpdateLlmsTxt {

	public const ABILITY = 'wp-content-bridge/update-llms-txt';

	/**
	 * Creates the use case.
	 *
	 * @param LlmsArtifactStore      $store    Configuration and snapshot read/write port.
	 * @param LlmsSourceSelector     $selector Eligible-entry selection port.
	 * @param LlmsDocumentBuilder    $builder  Pure document generator.
	 * @param AuditLog               $audit    Audit sink.
	 * @param LlmsOwnershipInspector $ownership Local ownership/readiness inspector.
	 * @param string                 $site_url Canonical absolute site origin; a site fact supplied by the
	 *                                          WordPress adapter, never taken from caller input.
	 */
	public function __construct(
		private LlmsArtifactStore $store,
		private LlmsSourceSelector $selector,
		private LlmsDocumentBuilder $builder,
		private AuditLog $audit,
		private LlmsOwnershipInspector $ownership,
		private string $site_url,
	) {
	}

	/**
	 * Executes the update flow, recording exactly one audit row.
	 *
	 * @param array<string, mixed> $raw_input Normalized Ability input.
	 * @param int                  $user_id   Acting principal.
	 * @return LlmsMutationResult
	 * @throws MutationConflict When the submitted version token is stale.
	 * @throws MutationWriteFailed When the write or post-write re-read fails.
	 * @throws Throwable Re-thrown validation failures (InvalidArgumentException).
	 */
	public function execute( array $raw_input, int $user_id ): LlmsMutationResult {
		$expected_version = null;
		$changed_fields   = array();

		try {
			$update           = LlmsConfigUpdate::from_input( $raw_input, $this->site_url );
			$expected_version = $update->expected_version->to_string();

			$current_config   = $this->store->config();
			$current_artifact = $this->store->artifact();

			$current_version = VersionToken::for_llms( $current_config?->to_array(), $current_artifact?->content_hash );
			if ( ! $current_version->equals( $update->expected_version ) ) {
				throw new MutationConflict( 'The submitted version token is stale.' );
			}

			$entries  = $this->selector->select( $update->config );
			$artifact = $this->builder->build( $update->config, $entries );

			$this->store->replace_config( $update->config );
			$this->store->replace_artifact( $artifact );

			$stored_config   = $this->store->config();
			$stored_artifact = $this->store->artifact();
			if (
				null === $stored_config
				|| null === $stored_artifact
				|| $stored_artifact->content_hash !== $artifact->content_hash
			) {
				throw new MutationWriteFailed( 'The updated llms.txt configuration could not be re-read.' );
			}

			$changed_fields = self::changed_fields( $current_config, $stored_config );
			$version        = VersionToken::for_llms( $stored_config->to_array(), $stored_artifact->content_hash );
		} catch ( Throwable $error ) {
			[ $outcome, $code ] = $this->classify( $error );

			$this->audit->record(
				new AuditEvent(
					$user_id,
					self::ABILITY,
					null,
					null,
					array(),
					$expected_version,
					null,
					$outcome,
					$code
				)
			);

			throw $error;
		}

		$this->audit->record(
			new AuditEvent(
				$user_id,
				self::ABILITY,
				null,
				null,
				$changed_fields,
				$expected_version,
				$version->to_string(),
				'success',
				null
			)
		);

		return new LlmsMutationResult( $version, $stored_config, $stored_artifact, $changed_fields, $this->ownership->inspect() );
	}

	/**
	 * Names the top-level configuration fields that changed, never values.
	 * Every field is reported as changed when nothing was previously stored.
	 *
	 * @param LlmsConfig|null $before Previously stored configuration, if any.
	 * @param LlmsConfig      $after  Newly stored configuration.
	 * @return array<int, string>
	 */
	private static function changed_fields( ?LlmsConfig $before, LlmsConfig $after ): array {
		$before_array = $before?->to_array() ?? array();
		$after_array  = $after->to_array();

		$changed = array();
		foreach ( $after_array as $key => $value ) {
			if ( ! array_key_exists( $key, $before_array ) || $before_array[ $key ] !== $value ) {
				$changed[] = $key;
			}
		}

		return $changed;
	}

	/**
	 * Classifies a failure into a stable audit outcome and error code.
	 *
	 * @param Throwable $error The failure that ended the attempt.
	 * @return array{0: string, 1: string} Outcome and stable error code.
	 */
	private function classify( Throwable $error ): array {
		if ( $error instanceof InvalidArgumentException ) {
			return array( 'invalid', 'wpcb_invalid_input' );
		}
		if ( $error instanceof MutationConflict ) {
			return array( 'conflict', 'wpcb_conflict' );
		}
		if ( $error instanceof MutationWriteFailed ) {
			return array( 'failure', $error->error_code() );
		}

		return array( 'failure', 'wpcb_write_failed' );
	}
}

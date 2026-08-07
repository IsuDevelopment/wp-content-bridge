<?php
/**
 * Regenerate-llms-txt use case.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Application\Llms;

use InvalidArgumentException;
use IsuDev\WPContentBridge\Application\Mutation\AuditEvent;
use IsuDev\WPContentBridge\Application\Mutation\AuditLog;
use IsuDev\WPContentBridge\Domain\Llms\LlmsDocumentBuilder;
use IsuDev\WPContentBridge\Domain\Llms\LlmsMutationResult;
use IsuDev\WPContentBridge\Domain\Mutation\VersionToken;
use Throwable;

/**
 * Rebuilds the stored llms.txt snapshot from the already-stored configuration
 * and live site content. Takes no caller-supplied configuration, path, or
 * content of any kind — there is nothing here for a caller to redirect.
 *
 * Idempotent for unchanged source and configuration: when the rebuilt
 * document's content hash matches what is already stored, the stored
 * snapshot is left untouched rather than being overwritten with an
 * identical document under a new generation time, so an unchanged rebuild
 * never churns the stored hash or generation time in a way that makes a
 * caller believe something changed.
 */
final readonly class RegenerateLlmsTxt {

	public const ABILITY = 'wp-content-bridge/regenerate-llms-txt';

	/**
	 * Creates the use case.
	 *
	 * @param LlmsArtifactStore   $store    Configuration and snapshot read/write port.
	 * @param LlmsSourceSelector  $selector Eligible-entry selection port.
	 * @param LlmsDocumentBuilder $builder  Pure document generator.
	 * @param AuditLog            $audit    Audit sink.
	 */
	public function __construct(
		private LlmsArtifactStore $store,
		private LlmsSourceSelector $selector,
		private LlmsDocumentBuilder $builder,
		private AuditLog $audit,
	) {
	}

	/**
	 * Executes the regeneration, recording exactly one audit row.
	 *
	 * @param array<string, mixed> $raw_input Ability input; must be empty.
	 * @param int                  $user_id   Acting principal.
	 * @return LlmsMutationResult
	 * @throws InvalidArgumentException When input is supplied, or nothing has been configured yet.
	 * @throws Throwable Re-thrown after recording the audit row.
	 */
	public function execute( array $raw_input, int $user_id ): LlmsMutationResult {
		$changed = false;

		try {
			if ( array() !== $raw_input ) {
				throw new InvalidArgumentException( 'Regenerate llms.txt accepts no input fields.' );
			}

			$config = $this->store->config();
			if ( null === $config ) {
				throw new InvalidArgumentException( 'llms.txt has not been configured yet.' );
			}

			$current_artifact = $this->store->artifact();
			$entries          = $this->selector->select( $config );
			$candidate        = $this->builder->build( $config, $entries );

			$changed  = null === $current_artifact || $current_artifact->content_hash !== $candidate->content_hash;
			$artifact = $changed ? $candidate : $current_artifact;

			if ( $changed ) {
				$this->store->replace_artifact( $artifact );
			}

			$changed_fields = $changed ? array( 'artifact' ) : array();
			$version        = VersionToken::for_llms( $config->to_array(), $artifact->content_hash );
		} catch ( Throwable $error ) {
			[ $outcome, $code ] = $this->classify( $error );

			$this->audit->record(
				new AuditEvent(
					$user_id,
					self::ABILITY,
					null,
					null,
					array(),
					null,
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
				null,
				$version->to_string(),
				'success',
				null
			)
		);

		return new LlmsMutationResult( $version, $config, $artifact, $changed_fields );
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

		return array( 'failure', 'wpcb_write_failed' );
	}
}

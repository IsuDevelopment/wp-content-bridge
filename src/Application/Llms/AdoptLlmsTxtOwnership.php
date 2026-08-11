<?php
/**
 * Explicit administrator llms.txt ownership adoption use case.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Application\Llms;

use IsuDev\WPContentBridge\Application\Mutation\AuditEvent;
use IsuDev\WPContentBridge\Application\Mutation\AuditLog;
use IsuDev\WPContentBridge\Domain\Llms\LlmsOwnershipAdoptionResult;
use IsuDev\WPContentBridge\Domain\Mutation\VersionToken;
use Throwable;

/**
 * Archives legacy physical artifacts only after the bridge has a complete
 * snapshot and its virtual route is ready to take over.
 *
 * This is an application use case for the wp-admin adapter only. It is not an
 * Ability and must not be projected to MCP: filesystem ownership migration is
 * a deliberate local administrator operation, not an integration capability.
 */
final readonly class AdoptLlmsTxtOwnership {

	public const ACTION = 'wp-content-bridge/adopt-llms-txt-ownership';

	/**
	 * Creates the use case.
	 *
	 * @param LlmsArtifactStore          $store     Configuration and snapshot store.
	 * @param LlmsOwnershipInspector     $ownership Ownership/readiness inspector.
	 * @param LlmsLegacyArtifactArchiver $archiver  Closed-target filesystem adapter.
	 * @param AuditLog                   $audit      Redacted audit sink.
	 */
	public function __construct(
		private LlmsArtifactStore $store,
		private LlmsOwnershipInspector $ownership,
		private LlmsLegacyArtifactArchiver $archiver,
		private AuditLog $audit,
	) {
	}

	/**
	 * Archives the legacy files and verifies that the blocking root file left.
	 *
	 * @param int $user_id Acting administrator.
	 * @return LlmsOwnershipAdoptionResult
	 * @throws LlmsOwnershipAdoptionProblem When any readiness or archival check fails.
	 * @throws Throwable When audit persistence fails while handling an archival failure.
	 */
	public function execute( int $user_id ): LlmsOwnershipAdoptionResult {
		$version = null;

		try {
			$config   = $this->store->config();
			$artifact = $this->store->artifact();
			if ( null === $config || null === $artifact ) {
				throw new LlmsOwnershipAdoptionProblem( 'snapshot_missing', 'Configure and generate the bridge llms.txt snapshot before adopting public ownership.' );
			}

			$version = VersionToken::for_llms( $config->to_array(), $artifact->content_hash )->to_string();
			$before  = $this->ownership->inspect();

			if ( $before->yoast_llms_txt_enabled ) {
				throw new LlmsOwnershipAdoptionProblem( 'yoast_enabled', 'Disable Yoast SEO llms.txt generation before adopting public ownership.' );
			}
			if ( ! $before->bridge_publication_enabled ) {
				throw new LlmsOwnershipAdoptionProblem( 'publication_disabled', 'Enable bridge llms.txt publication before adopting public ownership.' );
			}
			if ( ! $before->bridge_route_routable ) {
				throw new LlmsOwnershipAdoptionProblem( 'route_unroutable', 'Enable pretty permalinks before adopting public ownership.' );
			}
			if ( ! $before->has_legacy_artifacts() ) {
				throw new LlmsOwnershipAdoptionProblem( 'legacy_artifacts_missing', 'No known legacy llms.txt artifacts were found to archive.' );
			}

			$archived = $this->archiver->archive();
			$after    = $this->ownership->inspect();
			if ( $after->has_legacy_artifacts() ) {
				throw new LlmsOwnershipAdoptionProblem( 'archive_verification_failed', 'A known legacy llms.txt artifact still exists after the archival attempt.' );
			}
		} catch ( Throwable $error ) {
			$code = $error instanceof LlmsOwnershipAdoptionProblem ? $error->error_code : 'archive_failed';
			$this->audit->record(
				new AuditEvent( $user_id, self::ACTION, null, null, array(), $version, null, 'failure', $code )
			);

			if ( $error instanceof LlmsOwnershipAdoptionProblem ) {
				throw $error;
			}

			throw new LlmsOwnershipAdoptionProblem( 'archive_failed', 'The legacy llms.txt artifacts could not be archived safely.' );
		}

		$this->audit->record(
			new AuditEvent( $user_id, self::ACTION, null, null, array( 'legacy_llms_artifacts' ), $version, $version, 'success', null )
		);

		return new LlmsOwnershipAdoptionResult( $archived, $after );
	}
}

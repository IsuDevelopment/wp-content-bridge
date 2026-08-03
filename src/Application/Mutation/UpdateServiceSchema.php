<?php
/**
 * Update-Service-schema use case.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Application\Mutation;

use InvalidArgumentException;
use IsuDev\WPContentBridge\Application\Content\ContentUnavailable;
use IsuDev\WPContentBridge\Application\ContentAccess\ContentAccessManager;
use IsuDev\WPContentBridge\Domain\ContentAccess\ContentOperation;
use IsuDev\WPContentBridge\Domain\Mutation\MutationResult;
use IsuDev\WPContentBridge\Domain\Mutation\ServiceSchemaMutationResult;
use IsuDev\WPContentBridge\Domain\Mutation\ServiceSchemaUpdate;
use Throwable;

/**
 * Orchestrates a policy- and version-tested structured Service entity write.
 */
final readonly class UpdateServiceSchema {

	public const ABILITY = 'wp-content-bridge/update-service-schema';

	/**
	 * Creates the use case.
	 *
	 * @param ContentAccessManager      $access     Per-post-type write policy.
	 * @param ContentMutationRepository $repository Post lookup/version/re-read port.
	 * @param ServiceSchemaWriter       $writer     Service schema write + re-read port.
	 * @param AuditLog                  $audit      Append-only audit sink.
	 */
	public function __construct(
		private ContentAccessManager $access,
		private ContentMutationRepository $repository,
		private ServiceSchemaWriter $writer,
		private AuditLog $audit,
	) {}

	/**
	 * Executes the write and records exactly one audit event.
	 *
	 * @param array<string, mixed> $raw_input Normalized Ability input.
	 * @param int                  $user_id   Acting principal.
	 * @return ServiceSchemaMutationResult
	 * @throws ContentUnavailable When the target is absent.
	 * @throws MutationForbidden When policy denies SEO writes for the type.
	 * @throws MutationConflict When the version token is stale.
	 * @throws ServiceSchemaUnavailable When the optional provider cannot handle the target.
	 * @throws MutationWriteFailed When the write or post-write re-read fails.
	 * @throws Throwable Re-thrown validation failures.
	 */
	public function execute( array $raw_input, int $user_id ): ServiceSchemaMutationResult {
		$post_id          = null;
		$post_type        = null;
		$expected_version = null;

		try {
			$update           = ServiceSchemaUpdate::from_input( $raw_input );
			$post_id          = $update->post_id;
			$expected_version = $update->expected_version->to_string();

			$post_type = $this->repository->post_type( $update->post_id );
			if ( null === $post_type ) {
				throw new ContentUnavailable( 'Content is unavailable.' );
			}

			if ( ! $this->access->allows( $post_type, ContentOperation::UPDATE_SEO ) ) {
				throw new MutationForbidden( 'Service schema updates are not permitted for this type.' );
			}

			$current = $this->repository->current_version( $update->post_id );
			if ( null === $current ) {
				throw new ContentUnavailable( 'Content is unavailable.' );
			}
			if ( ! $current->equals( $update->expected_version ) ) {
				throw new MutationConflict( 'The submitted version token is stale.' );
			}

			if ( ! $this->writer->is_available() || ! $this->writer->supports_post_type( $post_type ) ) {
				throw new ServiceSchemaUnavailable( 'Service schema is unavailable for this content type.' );
			}

			$effective = $this->writer->write( $update->post_id, $update->writable_fields() );
			$base      = $this->repository->result_for( $update->post_id );
			if ( null === $base ) {
				throw new MutationWriteFailed( 'The updated post could not be re-read.' );
			}

			$mutation = new MutationResult(
				$base->post_id,
				$base->post_type,
				$base->status,
				$base->version,
				$update->changed_fields(),
				false
			);
			$result   = new ServiceSchemaMutationResult( $mutation, $effective );
		} catch ( Throwable $error ) {
			[ $outcome, $code ] = $this->classify( $error );
			$this->audit->record(
				new AuditEvent(
					$user_id,
					self::ABILITY,
					$post_id,
					$post_type,
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
				$result->mutation->post_id,
				$result->mutation->post_type,
				$result->mutation->changed_fields,
				$expected_version,
				$result->mutation->version->to_string(),
				'success',
				null
			)
		);

		return $result;
	}

	/**
	 * Classifies a failure for the stable audit vocabulary.
	 *
	 * @param Throwable $error Failure that ended the attempt.
	 * @return array{0: string, 1: string}
	 */
	private function classify( Throwable $error ): array {
		if ( $error instanceof InvalidArgumentException ) {
			return array( 'invalid', 'wpcb_invalid_input' );
		}
		if ( $error instanceof ContentUnavailable ) {
			return array( 'invalid', 'wpcb_content_unavailable' );
		}
		if ( $error instanceof MutationForbidden ) {
			return array( 'denied', 'wpcb_forbidden' );
		}
		if ( $error instanceof MutationConflict ) {
			return array( 'conflict', 'wpcb_conflict' );
		}
		if ( $error instanceof ServiceSchemaUnavailable ) {
			return array( 'invalid', $error->error_code() );
		}

		return array( 'failure', 'wpcb_write_failed' );
	}
}

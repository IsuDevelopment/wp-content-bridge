<?php
/**
 * Update-SEO use case.
 *
 * @package IsuDev\WPContentBridge
 */

declare( strict_types=1 );

namespace IsuDev\WPContentBridge\Application\Mutation;

use InvalidArgumentException;
use IsuDev\WPContentBridge\Application\Content\ContentUnavailable;
use IsuDev\WPContentBridge\Application\ContentAccess\ContentAccessManager;
use IsuDev\WPContentBridge\Domain\ContentAccess\ContentOperation;
use IsuDev\WPContentBridge\Domain\Mutation\MutationResult;
use IsuDev\WPContentBridge\Domain\Mutation\SeoUpdate;
use Throwable;

/**
 * Orchestrates a version-tested Yoast editor-field write with optimistic concurrency.
 * Never changes post title/content/status/taxonomies. Records exactly one
 * audit row per attempt.
 */
final readonly class UpdateSeo {

	public const ABILITY = 'wp-content-bridge/update-seo';

	/**
	 * Creates the use case.
	 *
	 * @param ContentAccessManager      $access     Per-post-type write policy.
	 * @param ContentMutationRepository $repository Post lookup/version/re-read port (shared with update-content).
	 * @param SeoWriter                 $writer     SEO write + re-read port.
	 * @param AuditLog                  $audit      Audit sink.
	 */
	public function __construct(
		private ContentAccessManager $access,
		private ContentMutationRepository $repository,
		private SeoWriter $writer,
		private AuditLog $audit,
	) {
	}

	/**
	 * Executes the update-seo flow, recording exactly one audit row.
	 *
	 * @param array<string, mixed> $raw_input Normalized ability input.
	 * @param int                  $user_id   Acting principal.
	 * @return MutationResult
	 * @throws ContentUnavailable When the target is absent or ineligible.
	 * @throws MutationForbidden When policy denies the type.
	 * @throws MutationConflict When the version token is stale.
	 * @throws SeoFieldUnsupported When a field is outside the allowlist or no writer is available.
	 * @throws MutationWriteFailed When the updated post cannot be re-read after a successful write.
	 * @throws Throwable Re-thrown validation failures (InvalidArgumentException).
	 */
	public function execute( array $raw_input, int $user_id ): MutationResult {
		$post_id          = null;
		$post_type        = null;
		$expected_version = null;

		try {
			$offending = self::unsupported_keys( $raw_input );
			if ( array() !== $offending ) {
				throw new SeoFieldUnsupported( $offending );
			}

			$update           = SeoUpdate::from_input( $raw_input );
			$post_id          = $update->post_id;
			$expected_version = $update->expected_version->to_string();

			$post_type = $this->repository->post_type( $update->post_id );
			if ( null === $post_type ) {
				throw new ContentUnavailable( 'Content is unavailable.' );
			}

			if ( ! $this->access->allows( $post_type, ContentOperation::UPDATE_SEO ) ) {
				throw new MutationForbidden( 'SEO updates are not permitted for this type.' );
			}

			$current = $this->repository->current_version( $update->post_id );
			if ( null === $current ) {
				throw new ContentUnavailable( 'Content is unavailable.' );
			}
			if ( ! $current->equals( $update->expected_version ) ) {
				throw new MutationConflict( 'The submitted version token is stale.' );
			}

			if ( ! $this->writer->is_available() ) {
				throw new SeoFieldUnsupported( $update->changed_fields() );
			}

			$effective_seo = $this->writer->write( $update->post_id, $update->writable_fields() );

			$base = $this->repository->result_for( $update->post_id );
			if ( null === $base ) {
				throw new MutationWriteFailed( 'The updated post could not be re-read.' );
			}

			$result = new MutationResult(
				$base->post_id,
				$base->post_type,
				$base->status,
				$base->version,
				$update->changed_fields(),
				false,
				$effective_seo
			);
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
				$result->post_id,
				$result->post_type,
				$result->changed_fields,
				$expected_version,
				$result->version->to_string(),
				'success',
				null
			)
		);

		return $result;
	}

	/**
	 * Computes wire keys outside the allowlist.
	 *
	 * @param array<string, mixed> $raw_input Raw ability input.
	 * @return list<string>
	 */
	private static function unsupported_keys( array $raw_input ): array {
		return array_values( array_diff( array_keys( $raw_input ), SeoUpdate::ALLOWED_KEYS ) );
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
		if ( $error instanceof ContentUnavailable ) {
			return array( 'invalid', 'wpcb_content_unavailable' );
		}
		if ( $error instanceof MutationForbidden ) {
			return array( 'denied', 'wpcb_forbidden' );
		}
		if ( $error instanceof MutationConflict ) {
			return array( 'conflict', 'wpcb_conflict' );
		}
		if ( $error instanceof SeoFieldUnsupported ) {
			return array( 'invalid', 'wpcb_seo_field_unsupported' );
		}
		if ( $error instanceof SeoImageUnavailable ) {
			return array( 'invalid', $error->error_code() );
		}

		return array( 'failure', 'wpcb_write_failed' );
	}
}

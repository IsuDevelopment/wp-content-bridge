<?php
/**
 * Create-draft use case.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Application\Mutation;

use InvalidArgumentException;
use IsuDev\WPContentBridge\Application\ContentAccess\ContentAccessManager;
use IsuDev\WPContentBridge\Domain\ContentAccess\ContentOperation;
use IsuDev\WPContentBridge\Domain\Mutation\DraftInput;
use IsuDev\WPContentBridge\Domain\Mutation\MutationResult;
use Throwable;

/**
 * Orchestrates draft creation: validate, authorize (policy), idempotency,
 * block validation, write, audit. Capability checks live in the adapter's
 * permission callback; this use case enforces per-post-type policy and records
 * exactly one audit row per attempt.
 */
final readonly class CreateDraft {

	public const ABILITY = 'wp-content-bridge/create-draft';

	/**
	 * Creates the use case.
	 *
	 * @param ContentAccessManager      $access     Per-post-type write policy.
	 * @param BlockMarkupValidator      $validator  Block markup validation port.
	 * @param ContentMutationRepository $repository Content write port.
	 * @param IdempotencyStore          $idempotency Idempotency-key storage port.
	 * @param AuditLog                  $audit      Audit sink.
	 */
	public function __construct(
		private ContentAccessManager $access,
		private BlockMarkupValidator $validator,
		private ContentMutationRepository $repository,
		private IdempotencyStore $idempotency,
		private AuditLog $audit,
	) {
	}

	/**
	 * Executes the create-draft flow, recording exactly one audit row.
	 *
	 * @param array<string, mixed> $raw_input Normalized ability input.
	 * @param int                  $user_id   Acting principal.
	 * @return MutationResult
	 * @throws MutationForbidden When policy denies the type.
	 * @throws InvalidBlockMarkup When block markup is invalid.
	 * @throws Throwable Re-thrown validation or write failures (InvalidArgumentException, MutationWriteFailed).
	 */
	public function execute( array $raw_input, int $user_id ): MutationResult {
		$post_type = is_string( $raw_input['post_type'] ?? null ) ? $raw_input['post_type'] : null;

		try {
			$draft     = DraftInput::from_input( $raw_input );
			$post_type = $draft->post_type;

			if ( ! $this->access->allows( $draft->post_type, ContentOperation::CREATE ) ) {
				throw new MutationForbidden( 'Content creation is not permitted for this type.' );
			}

			if ( null !== $draft->idempotency_key ) {
				$existing_id = $this->idempotency->find( $user_id, $draft->idempotency_key );
				if ( null !== $existing_id ) {
					$replayed = $this->repository->result_for( $existing_id );
					if ( null !== $replayed ) {
						$this->record_success( $user_id, $replayed );
						return $replayed;
					}
				}
			}

			if ( '' !== $draft->block_markup ) {
				$reasons = $this->validator->validate( $draft->block_markup );
				if ( array() !== $reasons ) {
					throw new InvalidBlockMarkup( $reasons );
				}
			}

			$result = $this->repository->create( $draft );

			if ( null !== $draft->idempotency_key ) {
				$this->idempotency->remember( $user_id, $draft->idempotency_key, $result->post_id );
			}

			$this->record_success( $user_id, $result );

			return $result;
		} catch ( Throwable $error ) {
			$this->record_failure( $user_id, $post_type, $error );
			throw $error;
		}
	}

	/**
	 * Records a single successful-attempt audit row.
	 *
	 * @param int            $user_id Acting principal.
	 * @param MutationResult $result  The write (or replay) result.
	 * @return void
	 */
	private function record_success( int $user_id, MutationResult $result ): void {
		$this->audit->record(
			new AuditEvent(
				$user_id,
				self::ABILITY,
				$result->post_id,
				$result->post_type,
				$result->changed_fields,
				null,
				$result->version->to_string(),
				'success',
				null
			)
		);
	}

	/**
	 * Records a single failed-attempt audit row.
	 *
	 * @param int         $user_id   Acting principal.
	 * @param string|null $post_type Target post type, if known.
	 * @param Throwable   $error     The failure that ended the attempt.
	 * @return void
	 */
	private function record_failure( int $user_id, ?string $post_type, Throwable $error ): void {
		[ $outcome, $code ] = $this->classify( $error );

		$this->audit->record(
			new AuditEvent(
				$user_id,
				self::ABILITY,
				null,
				$post_type,
				array(),
				null,
				null,
				$outcome,
				$code
			)
		);
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
		if ( $error instanceof MutationForbidden ) {
			return array( 'denied', 'wpcb_forbidden' );
		}
		if ( $error instanceof InvalidBlockMarkup ) {
			return array( 'invalid', 'wpcb_invalid_blocks' );
		}
		if ( $error instanceof MutationWriteFailed ) {
			return array( 'failure', 'wpcb_write_failed' );
		}

		return array( 'failure', 'wpcb_write_failed' );
	}
}

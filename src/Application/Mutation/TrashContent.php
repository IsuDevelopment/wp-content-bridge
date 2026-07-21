<?php
/**
 * Trash-content use case.
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
use IsuDev\WPContentBridge\Domain\Mutation\TrashInput;
use Throwable;

/**
 * Moves eligible content to reversible trash with concurrency and audit gates.
 */
final readonly class TrashContent {

	public const ABILITY = 'wp-content-bridge/trash-content';

	/**
	 * Creates the trash-content use case.
	 *
	 * @param ContentAccessManager   $access     Per-type operation policy.
	 * @param ContentTrashRepository $repository Reversible trash port.
	 * @param AuditLog               $audit      Redacted audit sink.
	 */
	public function __construct(
		private ContentAccessManager $access,
		private ContentTrashRepository $repository,
		private AuditLog $audit,
	) {}

	/**
	 * Executes one reversible trash mutation.
	 *
	 * @param array<string, mixed> $raw_input Ability input.
	 * @param int                  $user_id   Acting principal ID.
	 * @return MutationResult
	 * @throws ContentUnavailable When the target is unavailable.
	 * @throws MutationForbidden When policy denies trash for the type.
	 * @throws MutationInvalidState When the current state cannot be trashed.
	 * @throws MutationConflict When the version token is stale.
	 * @throws TrashUnavailable When reversible WordPress trash is disabled.
	 * @throws Throwable Re-thrown validation, repository, or audit failure.
	 */
	public function execute( array $raw_input, int $user_id ): MutationResult {
		$post_id          = null;
		$post_type        = null;
		$expected_version = null;

		try {
			$input            = TrashInput::from_input( $raw_input );
			$post_id          = $input->post_id;
			$expected_version = $input->expected_version->to_string();
			$target           = $this->repository->target( $input->post_id );

			if ( null === $target ) {
				throw new ContentUnavailable( 'Content is unavailable.' );
			}
			$post_type = $target->post_type;

			if ( ! $this->access->allows( $target->post_type, ContentOperation::TRASH ) ) {
				throw new MutationForbidden( 'Trashing content is not permitted for this type.' );
			}
			if ( in_array( $target->status, array( 'trash', 'auto-draft', 'inherit' ), true ) ) {
				throw new MutationInvalidState( 'Content cannot be trashed from its current state.' );
			}
			if ( ! $target->version->equals( $input->expected_version ) ) {
				throw new MutationConflict( 'The submitted version token is stale.' );
			}
			if ( ! $this->repository->trash_supported() ) {
				throw new TrashUnavailable( 'Reversible WordPress trash is disabled.' );
			}

			$result = $this->repository->trash( $input->post_id );
		} catch ( Throwable $error ) {
			[ $outcome, $code ] = $this->classify( $error );
			$this->audit->record( new AuditEvent( $user_id, self::ABILITY, $post_id, $post_type, array(), $expected_version, null, $outcome, $code ) );

			throw $error;
		}

		$this->audit->record( new AuditEvent( $user_id, self::ABILITY, $result->post_id, $result->post_type, array( 'status' ), $expected_version, $result->version->to_string(), 'success', null ) );

		return $result;
	}

	/**
	 * Classifies one failure for redacted audit storage.
	 *
	 * @param Throwable $error Failure to classify.
	 * @return array{0: string, 1: string}
	 */
	private function classify( Throwable $error ): array {
		return match ( true ) {
			$error instanceof InvalidArgumentException => array( 'invalid', 'wpcb_invalid_input' ),
			$error instanceof ContentUnavailable => array( 'invalid', 'wpcb_content_unavailable' ),
			$error instanceof MutationForbidden => array( 'denied', 'wpcb_forbidden' ),
			$error instanceof MutationConflict => array( 'conflict', 'wpcb_conflict' ),
			$error instanceof MutationInvalidState => array( 'invalid', $error->error_code() ),
			$error instanceof TrashUnavailable => array( 'denied', $error->error_code() ),
			default => array( 'failure', 'wpcb_write_failed' ),
		};
	}
}

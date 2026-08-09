<?php
/**
 * Transition-content-status use case.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Application\Status;

use InvalidArgumentException;
use IsuDev\WPContentBridge\Application\Content\ContentUnavailable;
use IsuDev\WPContentBridge\Application\ContentAccess\ContentAccessManager;
use IsuDev\WPContentBridge\Application\Mutation\AuditEvent;
use IsuDev\WPContentBridge\Application\Mutation\AuditLog;
use IsuDev\WPContentBridge\Application\Mutation\ContentMutationRepository;
use IsuDev\WPContentBridge\Application\Mutation\MutationConflict;
use IsuDev\WPContentBridge\Application\Mutation\MutationForbidden;
use IsuDev\WPContentBridge\Application\Mutation\MutationInvalidState;
use IsuDev\WPContentBridge\Application\Mutation\MutationWriteFailed;
use IsuDev\WPContentBridge\Domain\ContentAccess\ContentOperation;
use IsuDev\WPContentBridge\Domain\Mutation\MutationResult;
use IsuDev\WPContentBridge\Domain\Mutation\TransitionStatusInput;
use IsuDev\WPContentBridge\Domain\Status\ContentStatus;
use IsuDev\WPContentBridge\Domain\Status\PublishAt;
use Throwable;

/**
 * Moves one object to a status permitted by the configured per-type
 * transition graph, enforcing every ADR 0024 gate in the exact documented
 * order, entirely in this one method, before any write is attempted:
 *
 * 1. the post exists (else the same non-enumerating failure the reads use);
 * 2. the per-type `transition_content_status` policy;
 * 3. `wpcb_edit_content` (checked redundantly by the adapter) and native `edit_post`;
 * 4. the `(current, target)` pair is in the configured graph for the post type;
 * 5. for `publish`/`future` targets: `wpcb_publish_enabled`, `wpcb_publish_content`, native `publish_post`;
 * 6. the version token is still current;
 * 7. `publish_at` is present, parseable, and strictly in the future for `future`; absent otherwise.
 *
 * The adapter's permission callback duplicates part of gate 3 as a cheap,
 * target-status-blind pre-filter (matching every other write ability in
 * this codebase), but correctness never depends on it: this method
 * re-verifies everything, because gate 5 needs `target_status` and gates
 * 1/2/4/6/7 need business state the adapter does not have.
 */
final readonly class TransitionContentStatus {

	public const ABILITY = 'wp-content-bridge/transition-content-status';

	/**
	 * Target statuses that additionally require the publication gates.
	 *
	 * @var list<string>
	 */
	private const PRIVILEGED_TARGETS = array( 'publish', 'future' );

	/**
	 * Creates the use case.
	 *
	 * @param ContentAccessManager             $access          Per-post-type write policy.
	 * @param StatusTransitionTargetRepository $targets         Object resolution and native capability port.
	 * @param StatusTransitionManager          $transitions     Effective transition graph.
	 * @param ContentMutationRepository        $repository      Content write port.
	 * @param SiteClock                        $clock           Site timezone and current-instant port.
	 * @param bool                             $publish_enabled Whether `wpcb_publish_enabled` is on.
	 * @param AuditLog                         $audit           Audit sink.
	 */
	public function __construct(
		private ContentAccessManager $access,
		private StatusTransitionTargetRepository $targets,
		private StatusTransitionManager $transitions,
		private ContentMutationRepository $repository,
		private SiteClock $clock,
		private bool $publish_enabled,
		private AuditLog $audit,
	) {
	}

	/**
	 * Executes one status transition, recording exactly one audit row.
	 *
	 * @param array<string, mixed> $raw_input Ability input.
	 * @param int                  $user_id   Acting principal ID.
	 * @return MutationResult
	 * @throws ContentUnavailable When the target is unavailable.
	 * @throws MutationForbidden When policy or capability denies the transition.
	 * @throws MutationInvalidState When the pair is not in the configured graph.
	 * @throws MutationConflict When the version token is stale.
	 * @throws Throwable Re-thrown validation (InvalidArgumentException) or write failures (MutationWriteFailed).
	 */
	public function execute( array $raw_input, int $user_id ): MutationResult {
		$post_id          = null;
		$post_type        = null;
		$expected_version = null;

		try {
			$input            = TransitionStatusInput::from_input( $raw_input );
			$post_id          = $input->post_id;
			$expected_version = $input->expected_version->to_string();

			// Gate 1: existence, using the same non-enumerating failure the reads use.
			$target = $this->targets->target( $post_id );
			if ( null === $target ) {
				throw new ContentUnavailable( 'Content is unavailable.' );
			}
			$post_type = $target->post_type;

			// Gate 2: per-type policy.
			if ( ! $this->access->allows( $post_type, ContentOperation::TRANSITION_STATUS ) ) {
				throw new MutationForbidden( 'Status transitions are not permitted for this type.' );
			}

			// Gate 3: native edit capability (wpcb_edit_content is the adapter's job).
			if ( ! $this->targets->native_can_edit( $post_id ) ) {
				throw new MutationForbidden( 'You are not permitted to edit this content.' );
			}

			// Gate 4: the exact ordered pair must be configured for this post type.
			$graph = $this->transitions->config()->graph;
			if ( ! $graph->permits( $post_type, $target->status, $input->target_status->value ) ) {
				throw new MutationInvalidState( 'That status transition is not configured for this content type.' );
			}

			// Gate 5: publication gates, only for publish/future.
			$is_privileged = in_array( $input->target_status->value, self::PRIVILEGED_TARGETS, true );
			if ( $is_privileged
				&& ( ! $this->publish_enabled
					|| ! $this->targets->has_publish_capability()
					|| ! $this->targets->native_can_publish( $post_id )
				)
			) {
				throw new MutationForbidden( 'Publishing or scheduling content is not permitted.' );
			}

			// Gate 6: optimistic concurrency.
			if ( ! $target->version->equals( $input->expected_version ) ) {
				throw new MutationConflict( 'The submitted version token is stale.' );
			}

			// Gate 7: publish_at, only for future; parsed against the site clock.
			[ $scheduled_at, $publish_at_output ] = $this->resolve_schedule( $input );

			$result = $this->repository->transition_status( $post_id, $input->target_status->value, $scheduled_at );

			if ( null !== $publish_at_output ) {
				$result = new MutationResult(
					$result->post_id,
					$result->post_type,
					$result->status,
					$result->version,
					$result->changed_fields,
					$result->created,
					null,
					$publish_at_output
				);
			}
		} catch ( Throwable $error ) {
			[ $outcome, $code ] = $this->classify( $error );
			$this->audit->record( new AuditEvent( $user_id, self::ABILITY, $post_id, $post_type, array(), $expected_version, null, $outcome, $code ) );

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
	 * Resolves the exact local/UTC date pair to write, if any, for the
	 * requested target status: the validated `publish_at` for `future`,
	 * "now" for `publish` (so a stale future date carried over from a prior
	 * `future` status cannot keep WordPress from treating it as published —
	 * measured: `wp_update_post()` silently keeps `publish` at `future` when
	 * the merged `post_date_gmt` is still ahead of now), and nothing for the
	 * remaining statuses, which are not subject to that coherency check.
	 *
	 * @param TransitionStatusInput $input Validated request.
	 * @return array{0: array{local: string, utc: string}|null, 1: array{local: string, utc: string}|null}
	 * @throws InvalidArgumentException When `publish_at` is malformed, nonexistent, or not strictly in the future.
	 */
	private function resolve_schedule( TransitionStatusInput $input ): array {
		if ( ContentStatus::FUTURE === $input->target_status ) {
			$publish_at = PublishAt::from_local_string( (string) $input->publish_at, $this->clock->timezone(), $this->clock->now() );

			return array(
				array(
					'local' => $publish_at->local_mysql(),
					'utc'   => $publish_at->utc_mysql(),
				),
				$publish_at->to_array(),
			);
		}

		if ( ContentStatus::PUBLISH === $input->target_status ) {
			$now_utc   = $this->clock->now();
			$now_local = $now_utc->setTimezone( $this->clock->timezone() );

			return array(
				array(
					'local' => $now_local->format( PublishAt::MYSQL_FORMAT ),
					'utc'   => $now_utc->format( PublishAt::MYSQL_FORMAT ),
				),
				null,
			);
		}

		return array( null, null );
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
			$error instanceof MutationWriteFailed => array( 'failure', 'wpcb_write_failed' ),
			default => array( 'failure', 'wpcb_write_failed' ),
		};
	}
}

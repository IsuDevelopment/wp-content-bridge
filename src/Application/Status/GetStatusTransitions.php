<?php
/**
 * Get-status-transitions use case.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Application\Status;

use IsuDev\WPContentBridge\Application\Content\ContentUnavailable;
use IsuDev\WPContentBridge\Application\ContentAccess\ContentAccessManager;
use IsuDev\WPContentBridge\Domain\ContentAccess\ContentOperation;
use IsuDev\WPContentBridge\Domain\Status\ContentStatus;
use IsuDev\WPContentBridge\Domain\Status\StatusTransitionsResult;

/**
 * Reads the transitions permitted from one object's current status, and
 * which of the additional publication gates the acting principal currently
 * satisfies for each — without ever revealing whether a post the principal
 * cannot see exists (ADR 0024).
 *
 * Requires `wpcb_read_content` (checked by the adapter's permission
 * callback) plus, here, native `edit_post` and the per-type `get_content`
 * (read) policy — deliberately the same non-enumerating
 * {@see ContentUnavailable} failure the other reads use, and deliberately
 * *not* the `transition_content_status` policy: that policy gates whether
 * any target is returned, not whether the object itself may be looked at.
 */
final readonly class GetStatusTransitions {

	public const ABILITY = 'wp-content-bridge/get-status-transitions';

	/**
	 * Creates the use case.
	 *
	 * @param ContentAccessManager             $access          Shared content policy.
	 * @param StatusTransitionTargetRepository $targets         Object resolution and native capability port.
	 * @param StatusTransitionManager          $transitions     Effective transition graph.
	 * @param SiteClock                        $clock           Site timezone and cron-runnability port.
	 * @param bool                             $publish_enabled Whether `wpcb_publish_enabled` is on.
	 */
	public function __construct(
		private ContentAccessManager $access,
		private StatusTransitionTargetRepository $targets,
		private StatusTransitionManager $transitions,
		private SiteClock $clock,
		private bool $publish_enabled,
	) {
	}

	/**
	 * Reads the transitions available from one object's current status.
	 *
	 * @param int $post_id Object ID.
	 * @return StatusTransitionsResult
	 * @throws ContentUnavailable When missing, unreadable, or the read policy is disabled for the type.
	 */
	public function execute( int $post_id ): StatusTransitionsResult {
		$target = $this->targets->target( $post_id );

		if (
			null === $target
			|| ! $this->access->allows( $target->post_type, ContentOperation::READ )
			|| ! $this->targets->native_can_edit( $post_id )
		) {
			throw new ContentUnavailable( 'Content is unavailable.' );
		}

		$permitted = array();
		if ( $this->access->allows( $target->post_type, ContentOperation::TRANSITION_STATUS ) ) {
			$graph = $this->transitions->config()->graph;
			foreach ( $graph->permitted_targets( $target->post_type, $target->status ) as $candidate ) {
				$permitted[] = $this->describe_target( $post_id, $candidate );
			}
		}

		$timezone   = $this->clock->timezone();
		$offset_now = $this->clock->now()->setTimezone( $timezone )->getOffset();

		return new StatusTransitionsResult(
			$post_id,
			$target->post_type,
			$target->status,
			$target->version->to_string(),
			$permitted,
			$timezone->getName(),
			$offset_now,
			$this->clock->scheduled_publication_can_run()
		);
	}

	/**
	 * Describes one permitted target status and the additional gates the
	 * principal currently satisfies for it.
	 *
	 * The three publication sub-gates are reported as trivially satisfied
	 * for a non-privileged target: they are not evaluated at all for
	 * `draft`/`pending`/`private` at write time, so reporting `false` there
	 * would name a blocker that does not exist. `requires_publish_gates`
	 * tells a client whether the trio is meaningful for this target at all.
	 *
	 * @param int    $post_id      Object ID.
	 * @param string $target_status One of the fixed five statuses.
	 * @return array<string, mixed>
	 * @phpstan-return array{target_status: string, requires_publish_at: bool, requires_publish_gates: bool, gates: array{publish_enabled: bool, publish_capability: bool, native_publish_post: bool}}
	 */
	private function describe_target( int $post_id, string $target_status ): array {
		$is_privileged = in_array( $target_status, array( ContentStatus::PUBLISH->value, ContentStatus::FUTURE->value ), true );

		return array(
			'target_status'          => $target_status,
			'requires_publish_at'    => ContentStatus::FUTURE->value === $target_status,
			'requires_publish_gates' => $is_privileged,
			'gates'                  => array(
				'publish_enabled'     => ! $is_privileged || $this->publish_enabled,
				'publish_capability'  => ! $is_privileged || $this->targets->has_publish_capability(),
				'native_publish_post' => ! $is_privileged || $this->targets->native_can_publish( $post_id ),
			),
		);
	}
}

<?php
/**
 * Outcome of a get-status-transitions read.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Domain\Status;

/**
 * Immutable result returned by {@see \IsuDev\WPContentBridge\Application\Status\GetStatusTransitions}.
 */
final readonly class StatusTransitionsResult {

	/**
	 * Creates a status-transitions result.
	 *
	 * @param int    $post_id                       The post ID.
	 * @param string $post_type                      The post type.
	 * @param string $current_status                 The object's current status.
	 * @param string $version_token                  Optimistic-concurrency token.
	 * @param array  $targets                        Permitted target descriptors.
	 * @param string $site_timezone                  Site timezone name (named zone or fixed offset).
	 * @param int    $utc_offset_seconds             Current UTC offset of the site timezone, in seconds.
	 * @param bool   $scheduled_publication_can_run  Whether a `future`-scheduled post can actually publish itself on this site.
	 * @phpstan-param list<array{target_status: string, requires_publish_at: bool, requires_publish_gates: bool, gates: array{publish_enabled: bool, publish_capability: bool, native_publish_post: bool}}> $targets
	 */
	public function __construct(
		public int $post_id,
		public string $post_type,
		public string $current_status,
		public string $version_token,
		public array $targets,
		public string $site_timezone,
		public int $utc_offset_seconds,
		public bool $scheduled_publication_can_run,
	) {
	}

	/**
	 * Wire representation.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'schema_version' => '1.0',
			'post_id'        => $this->post_id,
			'post_type'      => $this->post_type,
			'current_status' => $this->current_status,
			'version_token'  => $this->version_token,
			'targets'        => $this->targets,
			'scheduling'     => array(
				'site_timezone'                 => $this->site_timezone,
				'utc_offset_seconds'            => $this->utc_offset_seconds,
				'scheduled_publication_can_run' => $this->scheduled_publication_can_run,
			),
			'provenance'     => array(
				'source'    => 'wordpress',
				'untrusted' => true,
			),
		);
	}
}

<?php
/**
 * Mutation audit event.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Application\Mutation;

/**
 * Immutable, pre-redacted audit event (field names only, never values).
 */
final readonly class AuditEvent {

	/**
	 * Creates an audit event.
	 *
	 * @param int                $user_id           Acting principal.
	 * @param string             $ability           Ability id.
	 * @param int|null           $object_id         Target post ID, if any.
	 * @param string|null        $object_type       Target post type, if any.
	 * @param array<int, string> $changed_fields    Changed field names only.
	 * @param string|null        $expected_version  Incoming version token string.
	 * @param string|null        $resulting_version Resulting version token string.
	 * @param string             $outcome           success|conflict|invalid|denied|failure.
	 * @param string|null        $error_code        Stable error code, if any.
	 */
	public function __construct(
		public int $user_id,
		public string $ability,
		public ?int $object_id,
		public ?string $object_type,
		public array $changed_fields,
		public ?string $expected_version,
		public ?string $resulting_version,
		public string $outcome,
		public ?string $error_code,
	) {
	}
}

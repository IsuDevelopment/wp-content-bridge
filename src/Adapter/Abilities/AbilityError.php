<?php
/**
 * HTTP status mapping for this plugin's public ability error vocabulary.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Adapter\Abilities;

use WP_Error;

/**
 * Builds every `WP_Error` an ability returns, carrying an HTTP status.
 *
 * WordPress defaults an ability's `WP_Error` to HTTP 500 unless the error data
 * declares a `status`
 * ({@see \WP_REST_Abilities_V1_Run_Controller::ensure_error_status()}, which
 * respects a status already present). Before this class every domain rejection —
 * an unknown `post_id`, a disallowed post type, an invalid selector — answered
 * 500, so a client could not distinguish "your request was wrong" from "the
 * server broke", agent retry heuristics treated ordinary refusals as transient,
 * and monitoring read them as an outage.
 *
 * The mapping lives here rather than at the 86 construction sites because the
 * error-code vocabulary is closed, stable public API, and a status chosen
 * per-site would drift. `AbilityErrorTest` discovers the vocabulary from the
 * source and fails when a code has no status, so a new code cannot quietly
 * inherit 500.
 *
 * The rules behind the choices, so a new code lands in the right class:
 *
 * - **400** the request itself is wrong: malformed input, an unusable
 *   reference inside the input, or an address that does not resolve within the
 *   addressed object.
 * - **403** the principal is not permitted. (A denial caught by
 *   `permission_callback` never reaches here; core answers those 403 itself.)
 * - **404** the object this ability addresses does not exist, or is not visible
 *   to this principal. A bad reference *inside* the input is 400, not 404.
 * - **409** the target's stored state conflicts with the request; the client
 *   should re-read and retry.
 * - **413** the response would exceed a declared payload bound.
 * - **501** this install cannot implement the operation, because an optional
 *   provider or a WordPress feature it needs is absent. Deliberately not 503:
 *   nothing is temporarily overloaded and retrying will not help.
 * - **500** the plugin or WordPress failed. Reserved for genuine faults, so
 *   that a 500 from this plugin is once again worth investigating.
 */
final class AbilityError {

	/**
	 * HTTP status per public error code.
	 *
	 * @var array<string, int>
	 */
	private const STATUSES = array(
		// 400 — the request is wrong.
		'wpcb_invalid_input'                   => 400,
		'wpcb_invalid_selector'                => 400,
		'wpcb_invalid_blocks'                  => 400,
		'wpcb_invalid_custom_schema'           => 400,
		'wpcb_block_mismatch'                  => 400,
		'wpcb_block_path_not_found'            => 400,
		'wpcb_seo_field_unsupported'           => 400,
		'wpcb_seo_image_unavailable'           => 400,
		'wpcb_redirect_source_rejected'        => 400,

		// 403 — the principal is not permitted.
		'wpcb_forbidden'                       => 403,

		// 404 — the addressed object does not exist or is not visible.
		'wpcb_content_unavailable'             => 404,
		'wpcb_media_unavailable'               => 404,
		'wpcb_pattern_unavailable'             => 404,

		// 409 — stored state conflicts with the request.
		'wpcb_conflict'                        => 409,
		'wpcb_invalid_state'                   => 409,
		// A provider holds a rule for this path that the neutral contract
		// cannot express, so the request conflicts with stored state. It is
		// deliberately not a 404: the path is taken, not missing.
		'wpcb_redirect_rule_not_representable' => 409,

		// 413 — a declared payload bound would be exceeded.
		'wpcb_content_too_large'               => 413,
		'wpcb_pattern_content_too_large'       => 413,

		// 501 — this install cannot implement the operation.
		'wpcb_service_schema_unavailable'      => 501,
		'wpcb_custom_schema_unavailable'       => 501,
		'wpcb_seo_data_unavailable'            => 501,
		'wpcb_trash_unavailable'               => 501,
		'wpcb_redirect_provider_unavailable'   => 501,

		// 500 — the plugin or WordPress failed.
		'wpcb_internal_error'                  => 500,
		'wpcb_write_failed'                    => 500,
	);

	/**
	 * Status used for a code with no mapping.
	 *
	 * An unmapped code is a defect, not a category, so it answers 500 rather
	 * than guessing a 4xx that would tell a client the request was at fault.
	 */
	private const UNMAPPED_STATUS = 500;

	/**
	 * Builds an ability error carrying its HTTP status.
	 *
	 * @param string $code    Public error code from this plugin's vocabulary.
	 * @param string $message Human-readable message. Never includes stored content.
	 * @param array  $data    Optional additional error data, such as validation
	 *                       detail. The mapped status is applied last, so no
	 *                       caller can override the status for its code.
	 * @return WP_Error
	 * @phpstan-param array<string, mixed> $data
	 */
	public static function create( string $code, string $message, array $data = array() ): WP_Error {
		return new WP_Error(
			$code,
			$message,
			array_merge( $data, array( 'status' => self::status_for( $code ) ) )
		);
	}

	/**
	 * Returns the HTTP status for a public error code.
	 *
	 * @param string $code Public error code.
	 * @return int
	 */
	public static function status_for( string $code ): int {
		return self::STATUSES[ $code ] ?? self::UNMAPPED_STATUS;
	}

	/**
	 * Returns the complete mapping, for contract tests and diagnostics.
	 *
	 * @return array<string, int>
	 */
	public static function statuses(): array {
		return self::STATUSES;
	}
}

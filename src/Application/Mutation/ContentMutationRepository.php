<?php
/**
 * Port for persisting content mutations.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Application\Mutation;

use IsuDev\WPContentBridge\Domain\Mutation\ContentUpdate;
use IsuDev\WPContentBridge\Domain\Mutation\DraftInput;
use IsuDev\WPContentBridge\Domain\Mutation\MutationResult;
use IsuDev\WPContentBridge\Domain\Mutation\VersionToken;

/**
 * Persists new and updated content. The only implementation calls WordPress.
 */
interface ContentMutationRepository {

	/**
	 * Post type of an existing, eligible object, or null when absent/ineligible.
	 *
	 * @param int $post_id Post ID.
	 * @return string|null
	 */
	public function post_type( int $post_id ): ?string;

	/**
	 * Current version token for an existing object, or null when absent.
	 *
	 * @param int $post_id Post ID.
	 * @return VersionToken|null
	 */
	public function current_version( int $post_id ): ?VersionToken;

	/**
	 * Creates a new draft. Always returns a result with created = true.
	 *
	 * @param DraftInput $input Validated draft input.
	 * @return MutationResult
	 * @throws MutationWriteFailed When WordPress rejects the write.
	 */
	public function create( DraftInput $input ): MutationResult;

	/**
	 * Applies an update to an existing post. Returns created = false.
	 *
	 * @param int           $post_id Post ID to update.
	 * @param ContentUpdate $update  Validated update input.
	 * @return MutationResult
	 * @throws MutationWriteFailed When WordPress rejects the write.
	 */
	public function update( int $post_id, ContentUpdate $update ): MutationResult;

	/**
	 * Rebuilds a result for an already-existing post (idempotent replay).
	 *
	 * Returns created = false with empty changed_fields, or null if absent.
	 *
	 * @param int $post_id Post ID.
	 * @return MutationResult|null
	 */
	public function result_for( int $post_id ): ?MutationResult;

	/**
	 * Applies a status transition, optionally pinning `post_date` and
	 * `post_date_gmt` together, and re-reads the result from storage to
	 * confirm WordPress stored exactly what was requested.
	 *
	 * `$scheduled_at` is provided for `future` (the validated `publish_at`)
	 * and for `publish` (the current instant, so a stale future date
	 * carried over from a prior `future` status cannot keep WordPress
	 * treating the post as still scheduled); it is omitted for every other
	 * target, which are not subject to that date/status coherency check.
	 *
	 * @param int                                    $post_id       Post ID to transition.
	 * @param string                                 $target_status One of the five fixed statuses.
	 * @param array{local: string, utc: string}|null $scheduled_at  MySQL-format local/UTC date pair to pin together, or null to leave dates untouched.
	 * @return MutationResult
	 * @throws MutationWriteFailed When WordPress rejects the write, or stores a status or scheduled date other than what was requested.
	 */
	public function transition_status( int $post_id, string $target_status, ?array $scheduled_at ): MutationResult;
}

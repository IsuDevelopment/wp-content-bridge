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
}

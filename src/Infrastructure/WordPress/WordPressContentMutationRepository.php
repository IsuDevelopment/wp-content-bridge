<?php
/**
 * WordPress-backed content mutation repository.
 *
 * @package IsuDev\WPContentBridge
 */

declare( strict_types=1 );

namespace IsuDev\WPContentBridge\Infrastructure\WordPress;

use IsuDev\WPContentBridge\Application\Mutation\ContentMutationRepository;
use IsuDev\WPContentBridge\Application\Mutation\ContentSnapshotRepository;
use IsuDev\WPContentBridge\Application\Mutation\MutationWriteFailed;
use IsuDev\WPContentBridge\Domain\Mutation\ContentUpdate;
use IsuDev\WPContentBridge\Domain\Mutation\DraftInput;
use IsuDev\WPContentBridge\Domain\Mutation\MutationResult;
use IsuDev\WPContentBridge\Domain\Mutation\TaxonomyAssignment;
use IsuDev\WPContentBridge\Domain\Mutation\VersionToken;
use WP_Post;

/**
 * The only place create/update actually touch WordPress. Never publishes.
 */
final class WordPressContentMutationRepository implements ContentMutationRepository, ContentSnapshotRepository {

	/**
	 * Post type of an existing, eligible object, or null when absent/ineligible.
	 *
	 * @param int $post_id Post ID.
	 * @return string|null
	 */
	public function post_type( int $post_id ): ?string {
		$post = get_post( $post_id );

		return $post instanceof WP_Post ? $post->post_type : null;
	}

	/**
	 * Current version token for an existing object, or null when absent.
	 *
	 * @param int $post_id Post ID.
	 * @return VersionToken|null
	 */
	public function current_version( int $post_id ): ?VersionToken {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return null;
		}

		return VersionToken::for_content(
			$post->post_modified_gmt,
			$post->post_title,
			$post->post_content,
			$post->post_status
		);
	}

	/**
	 * Creates a new draft. Always returns a result with created = true.
	 *
	 * @param DraftInput $input Validated draft input.
	 * @return MutationResult
	 * @throws MutationWriteFailed When WordPress rejects the write.
	 */
	public function create( DraftInput $input ): MutationResult {
		$id = wp_insert_post(
			array(
				'post_type'    => $input->post_type,
				'post_status'  => 'draft',
				'post_title'   => $input->title,
				'post_content' => $input->block_markup,
				'post_excerpt' => (string) $input->excerpt,
			),
			true
		);

		// @phpstan-ignore identical.alwaysFalse
		if ( is_wp_error( $id ) || 0 === $id ) {
			throw new MutationWriteFailed( 'WordPress rejected the new draft.' );
		}

		$this->apply_taxonomies( (int) $id, $input->taxonomies );

		return $this->built_result( (int) $id, true, $this->created_field_names( $input ) );
	}

	/**
	 * Applies an update to an existing post. Returns created = false.
	 *
	 * @param int           $post_id Post ID to update.
	 * @param ContentUpdate $update  Validated update input.
	 * @return MutationResult
	 * @throws MutationWriteFailed When WordPress rejects the write.
	 */
	public function update( int $post_id, ContentUpdate $update ): MutationResult {
		$args = array( 'ID' => $post_id );
		if ( null !== $update->title ) {
			$args['post_title'] = $update->title;
		}
		if ( null !== $update->block_markup ) {
			$args['post_content'] = $update->block_markup;
		}
		if ( null !== $update->excerpt ) {
			$args['post_excerpt'] = $update->excerpt;
		}

		$result = wp_update_post( $args, true );
		// @phpstan-ignore identical.alwaysFalse
		if ( is_wp_error( $result ) || 0 === $result ) {
			throw new MutationWriteFailed( 'WordPress rejected the update.' );
		}

		if ( null !== $update->taxonomies ) {
			$this->apply_taxonomies( $post_id, $update->taxonomies );
		}

		return $this->built_result( $post_id, false, $update->changed_fields() );
	}

	/**
	 * Current content field values, or null when the target is absent.
	 *
	 * @param int $post_id Target post ID.
	 * @return array{title: string, block_markup: string, excerpt: string}|null
	 */
	public function content_snapshot( int $post_id ): ?array {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return null;
		}

		return array(
			'title'        => $post->post_title,
			'block_markup' => $post->post_content,
			'excerpt'      => $post->post_excerpt,
		);
	}

	/**
	 * Rebuilds a result for an already-existing post (idempotent replay).
	 *
	 * Returns created = false with empty changed_fields, or null if absent.
	 *
	 * @param int $post_id Post ID.
	 * @return MutationResult|null
	 */
	public function result_for( int $post_id ): ?MutationResult {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return null;
		}

		return $this->built_result( $post_id, false, array() );
	}

	/**
	 * Applies taxonomy assignments to a post, replacing existing terms.
	 *
	 * @param int   $post_id    Post ID to assign terms to.
	 * @param array $taxonomies Assignments to apply (replace mode).
	 * @phpstan-param array<int, TaxonomyAssignment> $taxonomies Assignments to apply (replace mode).
	 * @return void
	 * @throws MutationWriteFailed When WordPress rejects a term assignment.
	 */
	private function apply_taxonomies( int $post_id, array $taxonomies ): void {
		foreach ( $taxonomies as $assignment ) {
			$result = wp_set_object_terms( $post_id, $assignment->term_ids, $assignment->taxonomy, false );
			if ( is_wp_error( $result ) ) {
				throw new MutationWriteFailed( 'WordPress rejected a taxonomy assignment.' );
			}
		}
	}

	/**
	 * Re-reads a written post and builds its mutation result.
	 *
	 * @param int   $post_id       Post ID to re-read.
	 * @param bool  $created       Whether the post was just created.
	 * @param array $changed_fields Names of changed fields.
	 * @phpstan-param array<int, string> $changed_fields Names of changed fields.
	 * @return MutationResult
	 * @throws MutationWriteFailed When the freshly written post cannot be re-read.
	 */
	private function built_result( int $post_id, bool $created, array $changed_fields ): MutationResult {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			throw new MutationWriteFailed( 'The written post could not be re-read.' );
		}

		return new MutationResult(
			$post_id,
			$post->post_type,
			$post->post_status,
			VersionToken::for_content(
				$post->post_modified_gmt,
				$post->post_title,
				$post->post_content,
				$post->post_status
			),
			$changed_fields,
			$created
		);
	}

	/**
	 * Determines which field names were set on create.
	 *
	 * @param DraftInput $input Validated draft input.
	 * @return array
	 * @phpstan-return array<int, string> Field names set on create.
	 */
	private function created_field_names( DraftInput $input ): array {
		$fields = array( 'title' );
		if ( '' !== $input->block_markup ) {
			$fields[] = 'content';
		}
		if ( null !== $input->excerpt ) {
			$fields[] = 'excerpt';
		}
		if ( array() !== $input->taxonomies ) {
			$fields[] = 'taxonomies';
		}

		return $fields;
	}
}

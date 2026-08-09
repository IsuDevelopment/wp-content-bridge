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
				'post_title'   => self::slashed( $input->title ),
				'post_content' => self::slashed( $input->block_markup ),
				'post_excerpt' => self::slashed( (string) $input->excerpt ),
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
			$args['post_title'] = self::slashed( $update->title );
		}
		if ( null !== $update->block_markup ) {
			$args['post_content'] = self::slashed( $update->block_markup );
		}
		if ( null !== $update->excerpt ) {
			$args['post_excerpt'] = self::slashed( $update->excerpt );
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
	 * Slashes a value for the post APIs, which unslash whatever they are given.
	 *
	 * `wp_insert_post()` and `wp_update_post()` expect slashed data and call
	 * `wp_unslash()` on it, so passing raw input silently strips every
	 * backslash. That corrupts block markup: `serialize_block()` escapes
	 * quotes inside a block's attribute JSON as `"`, which would be
	 * stored as a literal `u0022` and read back as broken text.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	private static function slashed( string $value ): string {
		return wp_slash( $value );
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
	 * Applies a status transition and re-reads the result to confirm
	 * WordPress stored exactly what was requested.
	 *
	 * `edit_date => true` is required whenever `$scheduled_at` is given:
	 * measured on WordPress 7.0.3, `wp_update_post()` otherwise silently
	 * ignores an explicit `post_date`/`post_date_gmt` on an update and
	 * recomputes both to "now", which downgrades a `future` request to
	 * `publish` regardless of the date actually supplied. `post_date` and
	 * `post_date_gmt` are always set together for the same reason ADR 0024
	 * calls out: setting one and letting WordPress derive the other is how
	 * scheduled times drift.
	 *
	 * @param int                                    $post_id       Post ID to transition.
	 * @param string                                 $target_status One of the five fixed statuses.
	 * @param array{local: string, utc: string}|null $scheduled_at MySQL-format local/UTC date pair, or null to leave dates untouched.
	 * @return MutationResult
	 * @throws MutationWriteFailed When WordPress rejects the write, or stores a status or scheduled date other than what was requested.
	 */
	public function transition_status( int $post_id, string $target_status, ?array $scheduled_at ): MutationResult {
		$args = array(
			'ID'          => $post_id,
			'post_status' => self::slashed( $target_status ),
		);

		if ( null !== $scheduled_at ) {
			$args['post_date']     = self::slashed( $scheduled_at['local'] );
			$args['post_date_gmt'] = self::slashed( $scheduled_at['utc'] );
			$args['edit_date']     = true;
		}

		$result = wp_update_post( $args, true );
		// @phpstan-ignore identical.alwaysFalse
		if ( is_wp_error( $result ) || 0 === $result ) {
			throw new MutationWriteFailed( 'WordPress rejected the status transition.' );
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			throw new MutationWriteFailed( 'The transitioned post could not be re-read.' );
		}

		if ( $post->post_status !== $target_status ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal exception message, never rendered as HTML.
			throw new MutationWriteFailed( "WordPress stored status '{$post->post_status}' instead of the requested '{$target_status}'." );
		}
		if ( null !== $scheduled_at && $post->post_date_gmt !== $scheduled_at['utc'] ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal exception message, never rendered as HTML.
			throw new MutationWriteFailed( "WordPress stored a scheduled time of '{$post->post_date_gmt}' instead of the requested '{$scheduled_at['utc']}'." );
		}

		return $this->built_result( $post_id, false, array( 'status' ) );
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

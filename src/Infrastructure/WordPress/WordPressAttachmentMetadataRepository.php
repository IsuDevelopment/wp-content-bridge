<?php
/**
 * WordPress-backed attachment-metadata writer.
 *
 * @package IsuDev\WPContentBridge
 */

declare( strict_types=1 );

namespace IsuDev\WPContentBridge\Infrastructure\WordPress;

use IsuDev\WPContentBridge\Application\Media\AttachmentMetadataRepository;
use IsuDev\WPContentBridge\Application\Mutation\MutationWriteFailed;
use IsuDev\WPContentBridge\Domain\Media\MediaMetadataUpdate;
use IsuDev\WPContentBridge\Domain\Mutation\VersionToken;
use WP_Post;

/**
 * Writes the four descriptive fields and reads every one of them back.
 */
final class WordPressAttachmentMetadataRepository implements AttachmentMetadataRepository {

	/**
	 * Maps public field names to their `wp_update_post` columns.
	 *
	 * `alt_text` is absent because it is postmeta, not a column.
	 *
	 * @var array<string, string>
	 */
	private const COLUMNS = array(
		'title'       => 'post_title',
		'caption'     => 'post_excerpt',
		'description' => 'post_content',
	);

	private const ALT_META_KEY = '_wp_attachment_image_alt';

	/**
	 * Current version token for an existing attachment, or null when absent.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return VersionToken|null
	 */
	public function current_version( int $attachment_id ): ?VersionToken {
		$post = get_post( $attachment_id );
		if ( ! $post instanceof WP_Post || 'attachment' !== $post->post_type ) {
			return null;
		}

		return PostVersionTokenFactory::for_post( $post );
	}

	/**
	 * Applies the present descriptive fields and confirms them by re-reading.
	 *
	 * @param MediaMetadataUpdate $update Validated update.
	 * @return VersionToken
	 * @throws MutationWriteFailed When WordPress rejects the write or stores something else.
	 */
	public function apply( MediaMetadataUpdate $update ): VersionToken {
		$columns = array();
		foreach ( self::COLUMNS as $field => $column ) {
			if ( array_key_exists( $field, $update->fields ) ) {
				$columns[ $column ] = wp_slash( $update->fields[ $field ] );
			}
		}

		if ( array() !== $columns ) {
			$columns['ID'] = $update->attachment_id;
			$result        = wp_update_post( $columns, true );
			if ( is_wp_error( $result ) || 0 === $result ) {
				throw new MutationWriteFailed( 'WordPress rejected the attachment metadata write.' );
			}
		}

		if ( array_key_exists( 'alt_text', $update->fields ) ) {
			update_post_meta( $update->attachment_id, self::ALT_META_KEY, $update->fields['alt_text'] );
		}

		$this->confirm( $update );

		$version = $this->current_version( $update->attachment_id );
		if ( null === $version ) {
			throw new MutationWriteFailed( 'The updated attachment could not be re-read.' );
		}

		return $version;
	}

	/**
	 * Verifies every requested field against storage.
	 *
	 * Read back rather than trusted: `wp_update_post()` returns the post ID on
	 * success even when a filter rewrote or discarded a value, and
	 * `update_post_meta()` returns false both for "unchanged" and for
	 * "short-circuited by a filter". Neither return value answers the only
	 * question that matters, which is what is now stored.
	 *
	 * @param MediaMetadataUpdate $update Validated update.
	 * @return void
	 * @throws MutationWriteFailed When storage does not hold what was requested.
	 */
	private function confirm( MediaMetadataUpdate $update ): void {
		clean_post_cache( $update->attachment_id );
		$post = get_post( $update->attachment_id );
		if ( ! $post instanceof WP_Post ) {
			throw new MutationWriteFailed( 'The updated attachment could not be re-read.' );
		}

		foreach ( $update->fields as $field => $expected ) {
			$stored = 'alt_text' === $field
				? get_post_meta( $update->attachment_id, self::ALT_META_KEY, true )
				: $post->{self::COLUMNS[ $field ]};

			if ( ! is_string( $stored ) || $stored !== $expected ) {
				throw new MutationWriteFailed( 'The attachment metadata was not stored as requested.' );
			}
		}
	}
}

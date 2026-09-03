<?php
/**
 * Single source of the post version token.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Infrastructure\WordPress;

use IsuDev\WPContentBridge\Domain\Mutation\VersionToken;
use WP_Post;

/**
 * Builds every version token this plugin issues, so no two abilities can
 * derive one differently.
 *
 * That mattered more than it looked: a token handed out by `get-content` is
 * compared against one computed by the mutation repository, so a divergence
 * would make every write conflict forever. Six call sites duplicated the
 * derivation before this class existed.
 *
 * **The token covers post meta, and that is a fix, not an optimization.**
 * It used to hash only `post_modified_gmt`, the title, the content and the
 * status. Every meta-only write this plugin performs — `update-seo` through
 * Yoast's `WPSEO_Meta::set_value()`, and Custom/Service Schema through their
 * provider's `update_post_meta()` — leaves all four untouched, so the token
 * came back **identical after a successful write**. Two agents could each read
 * the same token, each write SEO or schema, and the second would silently
 * overwrite the first with no conflict raised: the one thing the token exists
 * to prevent.
 */
final class PostVersionTokenFactory {

	/**
	 * Meta keys excluded from the fingerprint because WordPress or an editor
	 * churns them without the post's meaning changing. Including them would
	 * turn the concurrency guard into a source of spurious conflicts, which
	 * an automated caller answers by retrying forever.
	 */
	private const VOLATILE_KEYS = array(
		'_edit_lock',
		'_edit_last',
		'_wp_old_slug',
		'_wp_old_date',
		'_pingme',
		'_encloseme',
	);

	/**
	 * Builds the token for one post.
	 *
	 * @param WP_Post $post Target post.
	 * @return VersionToken
	 */
	public static function for_post( WP_Post $post ): VersionToken {
		return VersionToken::for_content(
			$post->post_modified_gmt,
			$post->post_title,
			$post->post_content,
			$post->post_status,
			self::state_fingerprint( $post )
		);
	}

	/**
	 * Hashes everything mutable about the post that the three explicit
	 * arguments above do not already carry.
	 *
	 * @param WP_Post $post Target post.
	 * @return string
	 */
	private static function state_fingerprint( WP_Post $post ): string {
		return hash(
			'sha256',
			self::column_fingerprint( $post ) . '|' . self::meta_fingerprint( (int) $post->ID )
		);
	}

	/**
	 * Hashes the mutable post columns outside title, content, and status.
	 *
	 * `post_modified_gmt` is not enough on its own: it has one-second
	 * resolution, so an edit within the same second as the previous one leaves
	 * it unchanged and the token would not move. Listed explicitly rather than
	 * hashing the whole row, because the row also carries derived and
	 * churning values (`guid`, `comment_count`, `post_modified` in local time)
	 * that would move the token without the post's meaning changing.
	 *
	 * @param WP_Post $post Target post.
	 * @return string
	 */
	private static function column_fingerprint( WP_Post $post ): string {
		$columns = array(
			'post_name'      => $post->post_name,
			'post_excerpt'   => $post->post_excerpt,
			'post_parent'    => (string) $post->post_parent,
			'post_author'    => (string) $post->post_author,
			'post_date_gmt'  => $post->post_date_gmt,
			// The value, not a "set" flag: the fingerprint is hashed twice more
			// before it reaches output, so nothing is exposed, and a password
			// change is a change a concurrent writer needs to see.
			'post_password'  => $post->post_password,
			'menu_order'     => (string) $post->menu_order,
			'post_mime_type' => $post->post_mime_type,
		);

		return (string) wp_json_encode( $columns );
	}

	/**
	 * Hashes the post's meta so any meta-only write moves the token.
	 *
	 * Fingerprints *all* meta rather than an allowlist of the keys this
	 * plugin writes. Two of those key sets belong to third parties — Yoast's
	 * and the Schema Extended provider's — and one of them is reached only
	 * through an opaque integration API, so an allowlist would silently stop
	 * covering a write the day either plugin renamed a key. Over-sensitivity
	 * is the safe direction here; the volatile-key exclusions above and the
	 * filter below handle the cost.
	 *
	 * @param int $post_id Target post ID.
	 * @return string
	 */
	private static function meta_fingerprint( int $post_id ): string {
		$meta = get_post_meta( $post_id );
		if ( ! is_array( $meta ) ) {
			return '';
		}

		/**
		 * Filters the meta keys excluded from the version token.
		 *
		 * A site whose plugins write meta on every page view (hit counters,
		 * "last seen" stamps) would otherwise see the token move under a
		 * caller that changed nothing, producing conflicts it cannot resolve.
		 *
		 * @param array<int, string> $excluded Meta keys to ignore.
		 * @param int                $post_id  Target post ID.
		 */
		$excluded = apply_filters( 'wp_content_bridge_version_token_meta_exclusions', self::VOLATILE_KEYS, $post_id );
		$excluded = is_array( $excluded ) ? $excluded : self::VOLATILE_KEYS;

		$relevant = array();
		foreach ( $meta as $key => $values ) {
			if ( ! is_string( $key ) || in_array( $key, $excluded, true ) ) {
				continue;
			}

			$flattened = array();
			foreach ( is_array( $values ) ? $values : array( $values ) as $value ) {
				// Meta values are unserialized arbitrary PHP; encode rather
				// than cast, so an array or object value cannot throw here and
				// so two different structures cannot collapse to one string.
				$flattened[] = is_scalar( $value ) || null === $value
					? (string) $value
					: (string) wp_json_encode( $value );
			}

			$relevant[ $key ] = $flattened;
		}

		// Sorted so storage order can never change the token for identical data.
		ksort( $relevant );

		return substr( hash( 'sha256', (string) wp_json_encode( $relevant ) ), 0, 16 );
	}
}

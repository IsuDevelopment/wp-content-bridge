<?php
/**
 * Pure llms.txt regeneration-trigger decision.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Domain\Llms;

/**
 * Decides whether one post-status transition warrants enqueueing a debounced
 * llms.txt regeneration, given nothing but the transition itself and the
 * currently configured post types.
 *
 * Pure: no WordPress function, no I/O, no clock. The only caller,
 * {@see \IsuDev\WPContentBridge\Infrastructure\WordPress\LlmsRegenerationScheduler},
 * supplies every argument from already-resolved WordPress state, which is
 * what makes this extraction worth having — the decision itself needs no
 * WordPress runtime to test, even though the trigger that calls it does.
 *
 * "Eligible" here means exactly what
 * {@see \IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressLlmsSourceSelector}
 * requires of publish status: `publish`, nothing else. A transition matters
 * only when it crosses that boundary, in either direction. That symmetry is
 * why the caller hooks `transition_post_status` rather than `save_post`:
 * `save_post` never exposes the status a post is leaving, so "did this post
 * just become ineligible" is not decidable from it at all. LLMagnet 3.4.3
 * hooks `save_post` and returns early unless the *new* status is `publish`,
 * which silently drops every un-publish transition — the post stays in the
 * public artifact until an unrelated daily cron happens to run. Reusing
 * `$old_status` here is the entire fix for that class of leak.
 */
final class LlmsRegenerationTrigger {

	/**
	 * Decides whether a transition warrants enqueueing regeneration.
	 *
	 * A post type absent from `$enabled_post_types` can never affect the
	 * artifact regardless of status, so none of its transitions are worth a
	 * regeneration run. For an enabled type, a transition matters exactly
	 * when it crosses the `publish` boundary: `draft` to `publish` (newly
	 * eligible) and `publish` to anything else (no longer eligible) both
	 * matter equally, and neither is more urgent than the other — a
	 * regeneration run cannot tell "this must run to add a post" from "this
	 * must run to remove one" and does not need to. A transition that stays
	 * on the same side of that boundary (`draft` to `pending`, or `publish`
	 * to `publish` on an unrelated re-save) never needs one.
	 *
	 * @param string $old_status         Post status before the transition.
	 * @param string $new_status         Post status after the transition.
	 * @param string $post_type          The transitioning post's type.
	 * @param array  $enabled_post_types Currently configured, enabled post types.
	 * @return bool
	 * @phpstan-param list<string> $enabled_post_types
	 */
	public static function transition_warrants_regeneration(
		string $old_status,
		string $new_status,
		string $post_type,
		array $enabled_post_types
	): bool {
		if ( ! in_array( $post_type, $enabled_post_types, true ) ) {
			return false;
		}

		return ( 'publish' === $old_status ) !== ( 'publish' === $new_status );
	}
}

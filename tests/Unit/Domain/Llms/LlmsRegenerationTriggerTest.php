<?php
/**
 * Unit tests for the llms.txt regeneration-trigger decision.
 *
 * @package IsuDev\WPContentBridge\Tests
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Tests\Unit\Domain\Llms;

use IsuDev\WPContentBridge\Domain\Llms\LlmsRegenerationTrigger;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the pure transition-eligibility decision that drives
 * {@see \IsuDev\WPContentBridge\Infrastructure\WordPress\LlmsRegenerationScheduler}.
 */
final class LlmsRegenerationTriggerTest extends TestCase {

	/**
	 * A newly published post of an enabled type is a transition into
	 * eligibility and warrants regeneration.
	 */
	public function test_draft_to_publish_of_enabled_type_warrants_regeneration(): void {
		self::assertTrue(
			LlmsRegenerationTrigger::transition_warrants_regeneration( 'draft', 'publish', 'post', array( 'post' ) )
		);
	}

	/**
	 * A post leaving `publish` is a transition out of eligibility and
	 * warrants regeneration just as much as one entering it — this is the
	 * un-publish leak LLMagnet's `save_post` hook misses.
	 */
	public function test_publish_to_draft_of_enabled_type_warrants_regeneration(): void {
		self::assertTrue(
			LlmsRegenerationTrigger::transition_warrants_regeneration( 'publish', 'draft', 'post', array( 'post' ) )
		);
	}

	/**
	 * A transition to trash is also a transition out of `publish` and
	 * warrants regeneration.
	 */
	public function test_publish_to_trash_of_enabled_type_warrants_regeneration(): void {
		self::assertTrue(
			LlmsRegenerationTrigger::transition_warrants_regeneration( 'publish', 'trash', 'page', array( 'page' ) )
		);
	}

	/**
	 * A transition between two non-`publish` statuses never crosses the
	 * eligibility boundary and does not warrant regeneration.
	 */
	public function test_draft_to_pending_does_not_warrant_regeneration(): void {
		self::assertFalse(
			LlmsRegenerationTrigger::transition_warrants_regeneration( 'draft', 'pending', 'post', array( 'post' ) )
		);
	}

	/**
	 * A re-save that leaves a post published does not warrant regeneration:
	 * `transition_post_status` fires on every `wp_insert_post()` call, not
	 * only on an actual status change.
	 */
	public function test_publish_to_publish_does_not_warrant_regeneration(): void {
		self::assertFalse(
			LlmsRegenerationTrigger::transition_warrants_regeneration( 'publish', 'publish', 'post', array( 'post' ) )
		);
	}

	/**
	 * A post type the stored configuration does not enable can never affect
	 * the artifact, so its transitions never warrant regeneration even when
	 * they cross the `publish` boundary.
	 */
	public function test_disabled_post_type_never_warrants_regeneration(): void {
		self::assertFalse(
			LlmsRegenerationTrigger::transition_warrants_regeneration( 'draft', 'publish', 'attachment', array( 'post', 'page' ) )
		);
	}
}

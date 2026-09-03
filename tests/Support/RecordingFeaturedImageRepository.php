<?php
/**
 * Recording featured-image repository test double.
 *
 * @package IsuDev\WPContentBridge\Tests
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Tests\Support;

use IsuDev\WPContentBridge\Application\Media\FeaturedImageRepository;
use IsuDev\WPContentBridge\Application\Mutation\MutationWriteFailed;

/**
 * In-memory featured-image store that records what was asked of it.
 */
final class RecordingFeaturedImageRepository implements FeaturedImageRepository {

	/**
	 * Attachment IDs passed to assign(), in order.
	 *
	 * @var list<int>
	 */
	public array $assigned = array();

	/**
	 * Post IDs passed to remove(), in order.
	 *
	 * @var list<int>
	 */
	public array $removed = array();

	/**
	 * Currently stored assignment.
	 *
	 * @var int|null
	 */
	private ?int $stored;

	/**
	 * Creates the double.
	 *
	 * @param int|null $stored     Initially stored assignment.
	 * @param array    $assignable Attachment IDs that count as assignable images.
	 * @param bool     $fail_write Whether writes report a failure.
	 * @phpstan-param list<int> $assignable
	 */
	public function __construct(
		?int $stored = null,
		private array $assignable = array( 7 ),
		private bool $fail_write = false,
	) {
		$this->stored = $stored;
	}

	/**
	 * Whether the attachment is treated as an assignable image.
	 *
	 * @param int $attachment_id Candidate attachment ID.
	 * @return bool
	 */
	public function is_assignable_image( int $attachment_id ): bool {
		return in_array( $attachment_id, $this->assignable, true );
	}

	/**
	 * Records and applies an assignment.
	 *
	 * @param int $post_id       Target post ID.
	 * @param int $attachment_id Attachment to assign.
	 * @return void
	 * @throws MutationWriteFailed When configured to fail.
	 */
	public function assign( int $post_id, int $attachment_id ): void {
		$this->assigned[] = $attachment_id;
		if ( $this->fail_write ) {
			throw new MutationWriteFailed( 'configured failure' );
		}
		$this->stored = $attachment_id;
	}

	/**
	 * Records and applies a removal.
	 *
	 * @param int $post_id Target post ID.
	 * @return void
	 * @throws MutationWriteFailed When configured to fail.
	 */
	public function remove( int $post_id ): void {
		$this->removed[] = $post_id;
		if ( $this->fail_write ) {
			throw new MutationWriteFailed( 'configured failure' );
		}
		$this->stored = null;
	}

	/**
	 * Returns the stored assignment.
	 *
	 * @param int $post_id Target post ID.
	 * @return int|null
	 */
	public function current( int $post_id ): ?int {
		return $this->stored;
	}
}

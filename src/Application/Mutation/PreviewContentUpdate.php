<?php
/**
 * Preview-update-content use case.
 *
 * @package IsuDev\WPContentBridge
 */

declare( strict_types=1 );

namespace IsuDev\WPContentBridge\Application\Mutation;

use IsuDev\WPContentBridge\Application\Content\ContentUnavailable;
use IsuDev\WPContentBridge\Application\ContentAccess\ContentAccessManager;
use IsuDev\WPContentBridge\Domain\ContentAccess\ContentOperation;
use IsuDev\WPContentBridge\Domain\Mutation\ContentPreviewResult;
use IsuDev\WPContentBridge\Domain\Mutation\ContentUpdate;

/**
 * Builds a bounded preview of a content update while performing no mutation.
 */
final readonly class PreviewContentUpdate {

	public const ABILITY = 'wp-content-bridge/preview-update-content';

	private const MAX_WARNINGS = 20;

	/**
	 * Creates the use case.
	 *
	 * @param ContentAccessManager      $access     Per-post-type write policy.
	 * @param BlockMarkupValidator      $validator  Block markup validation port.
	 * @param ContentMutationRepository $repository Post identity/version lookup port (shared with update-content).
	 * @param ContentSnapshotRepository $snapshots  Current content field read port.
	 */
	public function __construct(
		private ContentAccessManager $access,
		private BlockMarkupValidator $validator,
		private ContentMutationRepository $repository,
		private ContentSnapshotRepository $snapshots,
	) {
	}

	/**
	 * Previews one validated update without writing.
	 *
	 * @param array<string, mixed> $raw_input Ability input.
	 * @return ContentPreviewResult
	 * @throws ContentUnavailable When the target is absent or ineligible.
	 * @throws MutationForbidden When policy denies the type.
	 * @throws MutationConflict When the version token is stale.
	 * @throws InvalidBlockMarkup When block markup is invalid.
	 */
	public function execute( array $raw_input ): ContentPreviewResult {
		$update = ContentUpdate::from_input( $raw_input );

		$post_type = $this->repository->post_type( $update->post_id );
		if ( null === $post_type ) {
			throw new ContentUnavailable( 'Content is unavailable.' );
		}

		if ( ! $this->access->allows( $post_type, ContentOperation::UPDATE ) ) {
			throw new MutationForbidden( 'Content updates are not permitted for this type.' );
		}

		$current_version = $this->repository->current_version( $update->post_id );
		if ( null === $current_version ) {
			throw new ContentUnavailable( 'Content is unavailable.' );
		}
		if ( ! $current_version->equals( $update->expected_version ) ) {
			throw new MutationConflict( 'The submitted version token is stale.' );
		}

		$snapshot = $this->snapshots->content_snapshot( $update->post_id );
		if ( null === $snapshot ) {
			throw new ContentUnavailable( 'Content is unavailable.' );
		}

		$warnings             = array();
		$preview_block_markup = $snapshot['block_markup'];

		if ( null !== $update->block_markup ) {
			$reasons = $this->validator->validate( $update->block_markup );
			if ( array() !== $reasons ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- structured field names, not rendered output.
				throw new InvalidBlockMarkup( $reasons );
			}

			$preview_block_markup = $this->validator->normalize( $update->block_markup );

			if ( '' !== trim( $snapshot['block_markup'] ) ) {
				$warnings[] = '' === $preview_block_markup
					? self::warning( 'content_deleted', 'block_markup', 'This update removes all existing block content.' )
					: self::warning( 'content_replaced', 'block_markup', 'This update replaces the existing block content.' );
			}
		}

		$preview_taxonomies = array();
		if ( null !== $update->taxonomies ) {
			foreach ( $update->taxonomies as $assignment ) {
				$preview_taxonomies[] = array(
					'taxonomy' => $assignment->taxonomy,
					'term_ids' => array_values( $assignment->term_ids ),
				);
			}
			$warnings[] = self::warning(
				'taxonomies_replaced',
				'taxonomies',
				'This update replaces existing term assignments for the listed taxonomies.'
			);
		}

		return new ContentPreviewResult(
			$update->post_id,
			$post_type,
			$current_version,
			$update->changed_fields(),
			array(
				'title'        => $snapshot['title'],
				'block_markup' => $snapshot['block_markup'],
				'excerpt'      => $snapshot['excerpt'],
			),
			array(
				'title'        => $update->title ?? $snapshot['title'],
				'block_markup' => $preview_block_markup,
				'excerpt'      => $update->excerpt ?? $snapshot['excerpt'],
			),
			$preview_taxonomies,
			array_slice( $warnings, 0, self::MAX_WARNINGS )
		);
	}

	/**
	 * Builds one bounded machine-readable warning.
	 *
	 * @param string $code    Stable warning code.
	 * @param string $field   Affected field name.
	 * @param string $message Human-readable explanation.
	 * @return array{code: string, field: string, message: string}
	 */
	private static function warning( string $code, string $field, string $message ): array {
		return array(
			'code'    => $code,
			'field'   => $field,
			'message' => $message,
		);
	}
}

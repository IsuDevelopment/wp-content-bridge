<?php
/**
 * Preview-update-seo use case.
 *
 * @package IsuDev\WPContentBridge
 */

declare( strict_types=1 );

namespace IsuDev\WPContentBridge\Application\Mutation;

use IsuDev\WPContentBridge\Application\Content\ContentUnavailable;
use IsuDev\WPContentBridge\Application\ContentAccess\ContentAccessManager;
use IsuDev\WPContentBridge\Domain\ContentAccess\ContentOperation;
use IsuDev\WPContentBridge\Domain\Mutation\SeoPreviewResult;
use IsuDev\WPContentBridge\Domain\Mutation\SeoUpdate;

/**
 * Builds a preview of an SEO update while performing no metadata write.
 */
final readonly class PreviewSeoUpdate {

	public const ABILITY = 'wp-content-bridge/preview-update-seo';

	private const MAX_WARNINGS = 17;

	/**
	 * Creates the use case.
	 *
	 * @param ContentAccessManager      $access     Per-post-type write policy.
	 * @param ContentMutationRepository $repository Post identity/version lookup port (shared with update-seo).
	 * @param SeoPreviewProvider        $provider   Read-only SEO preview port.
	 */
	public function __construct(
		private ContentAccessManager $access,
		private ContentMutationRepository $repository,
		private SeoPreviewProvider $provider,
	) {
	}

	/**
	 * Previews one validated SEO update without writing.
	 *
	 * @param array<string, mixed> $raw_input Ability input.
	 * @return SeoPreviewResult
	 * @throws ContentUnavailable When the target is absent or ineligible.
	 * @throws MutationForbidden When policy denies the type.
	 * @throws MutationConflict When the version token is stale.
	 * @throws SeoFieldUnsupported When a field is outside the allowlist or no provider is available.
	 */
	public function execute( array $raw_input ): SeoPreviewResult {
		$offending = self::unsupported_keys( $raw_input );
		if ( array() !== $offending ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- structured field names, not rendered output.
			throw new SeoFieldUnsupported( $offending );
		}

		$update = SeoUpdate::from_input( $raw_input );

		$post_type = $this->repository->post_type( $update->post_id );
		if ( null === $post_type ) {
			throw new ContentUnavailable( 'Content is unavailable.' );
		}

		if ( ! $this->access->allows( $post_type, ContentOperation::UPDATE_SEO ) ) {
			throw new MutationForbidden( 'SEO updates are not permitted for this type.' );
		}

		$current_version = $this->repository->current_version( $update->post_id );
		if ( null === $current_version ) {
			throw new ContentUnavailable( 'Content is unavailable.' );
		}
		if ( ! $current_version->equals( $update->expected_version ) ) {
			throw new MutationConflict( 'The submitted version token is stale.' );
		}

		if ( ! $this->provider->is_available() ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- structured field names, not rendered output.
			throw new SeoFieldUnsupported( $update->changed_fields() );
		}

		$fields      = $update->writable_fields();
		$preview_seo = $this->provider->preview( $update->post_id, $fields );

		$warnings = array();
		foreach ( $fields as $field => $value ) {
			if ( '' === $value ) {
				$warnings[] = array(
					'code'    => 'field_cleared',
					'field'   => $field,
					'message' => 'This update clears the ' . $field . ' override.',
				);
			}
		}

		return new SeoPreviewResult(
			$update->post_id,
			$post_type,
			$current_version,
			$update->changed_fields(),
			$this->provider->current( $update->post_id ),
			$preview_seo,
			array_slice( $warnings, 0, self::MAX_WARNINGS )
		);
	}

	/**
	 * Computes wire keys outside the allowlist.
	 *
	 * @param array<string, mixed> $raw_input Raw ability input.
	 * @return list<string>
	 */
	private static function unsupported_keys( array $raw_input ): array {
		return array_values( array_diff( array_keys( $raw_input ), SeoUpdate::ALLOWED_KEYS ) );
	}
}

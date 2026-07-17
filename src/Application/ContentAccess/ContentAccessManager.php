<?php
/**
 * Content access policy application service.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Application\ContentAccess;

use IsuDev\WPContentBridge\Domain\ContentAccess\ContentOperation;
use IsuDev\WPContentBridge\Domain\ContentAccess\ContentTypeDefinition;
use IsuDev\WPContentBridge\Domain\ContentAccess\ContentTypePolicy;

/**
 * Shared policy service for admin, abilities, REST, and CLI adapters.
 */
final readonly class ContentAccessManager {

	/**
	 * Creates the shared policy service.
	 *
	 * @param ContentAccessSettingsRepository $repository Settings storage port.
	 * @param ContentTypeCatalog              $catalog    Content-type discovery port.
	 */
	public function __construct(
		private ContentAccessSettingsRepository $repository,
		private ContentTypeCatalog $catalog,
	) {
	}

	/**
	 * Lists configurable content types.
	 *
	 * @return list<ContentTypeDefinition>
	 */
	public function content_types(): array {
		return $this->catalog->list_eligible();
	}

	/**
	 * Gets the effective policy for one content type.
	 *
	 * Unsaved post/page policies are readable by default. Every other type is
	 * deny-by-default until an administrator opts in.
	 *
	 * @param string $post_type Content-type name.
	 * @return ContentTypePolicy
	 */
	public function policy_for( string $post_type ): ContentTypePolicy {
		$stored = $this->repository->load();

		if ( isset( $stored[ $post_type ] ) ) {
			return ContentTypePolicy::from_input( $stored[ $post_type ] );
		}

		return in_array( $post_type, array( 'post', 'page' ), true )
			? ContentTypePolicy::default_readable()
			: ContentTypePolicy::deny_all();
	}

	/**
	 * Checks the configuration gate only.
	 *
	 * Callers must additionally enforce plugin and native WordPress capabilities.
	 *
	 * @param string           $post_type Content-type name.
	 * @param ContentOperation $operation Operation to check.
	 * @return bool
	 */
	public function allows( string $post_type, ContentOperation $operation ): bool {
		$is_eligible = array_filter(
			$this->catalog->list_eligible(),
			static fn ( ContentTypeDefinition $definition ): bool => $definition->name === $post_type
		);

		if ( array() === $is_eligible ) {
			return false;
		}

		return $this->policy_for( $post_type )->allows( $operation );
	}

	/**
	 * Normalizes the settings form while preserving temporarily unavailable types.
	 *
	 * @param mixed $submitted Submitted option value.
	 * @return array<string, array<string, bool>>
	 */
	public function normalize_submitted( mixed $submitted ): array {
		$normalized = array();

		foreach ( $this->repository->load() as $post_type => $operations ) {
			if ( $this->is_valid_post_type_name( $post_type ) ) {
				$normalized[ $post_type ] = ContentTypePolicy::from_input( $operations )->to_array();
			}
		}

		$submitted_rows = is_array( $submitted ) ? $submitted : array();

		foreach ( $this->catalog->list_eligible() as $definition ) {
			$row = $this->normalize_row( $submitted_rows[ $definition->name ] ?? array() );

			$normalized[ $definition->name ] = ContentTypePolicy::from_input( $row )->to_array();
		}

		ksort( $normalized );

		return $normalized;
	}

	/**
	 * Validates a WordPress post-type key without calling WordPress.
	 *
	 * @param string $post_type Content-type name.
	 * @return bool
	 */
	private function is_valid_post_type_name( string $post_type ): bool {
		return 1 === preg_match( '/^[a-z0-9_-]{1,20}$/', $post_type );
	}

	/**
	 * Keeps only string operation keys from an untrusted settings row.
	 *
	 * @param mixed $row Raw row.
	 * @return array<string, mixed>
	 */
	private function normalize_row( mixed $row ): array {
		if ( ! is_array( $row ) ) {
			return array();
		}

		$normalized = array();

		foreach ( $row as $operation => $value ) {
			if ( is_string( $operation ) ) {
				$normalized[ $operation ] = $value;
			}
		}

		return $normalized;
	}
}

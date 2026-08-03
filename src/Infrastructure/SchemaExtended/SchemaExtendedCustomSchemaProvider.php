<?php
/**
 * IsuDev Schema Extended Custom Schema adapter.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Infrastructure\SchemaExtended;

use IsuDev\SchemaExtended\Custom\Integration_API;
use IsuDev\WPContentBridge\Application\Mutation\CustomSchemaInvalid;
use IsuDev\WPContentBridge\Application\Mutation\CustomSchemaReader;
use IsuDev\WPContentBridge\Application\Mutation\CustomSchemaUnavailable;
use IsuDev\WPContentBridge\Application\Mutation\CustomSchemaWriter;
use IsuDev\WPContentBridge\Application\Mutation\MutationForbidden;
use IsuDev\WPContentBridge\Application\Mutation\MutationWriteFailed;
use WP_Error;

/**
 * Uses only Schema Extended's stable Integration_API contract.
 *
 * The adapter never reads private metadata keys and never calls the provider's
 * parser or persistence internals directly.
 */
final class SchemaExtendedCustomSchemaProvider implements CustomSchemaReader, CustomSchemaWriter {

	private const EXPECTED_CONTRACT_VERSION = '1.0';
	private const MAX_NODES                 = 20;
	private const MAX_DIAGNOSTICS           = 50;
	private const MAX_SOURCE_LENGTH         = 100000;
	private const REQUIRED_METHODS          = array(
		'get_configuration',
		'validate_source',
		'update_configuration',
	);

	/**
	 * Whether a compatible public provider API is currently loaded.
	 */
	public function is_available(): bool {
		if ( ! defined( 'ISUDEV_SCHEMA_EXTENDED_VERSION' ) || ! class_exists( Integration_API::class, false ) ) {
			return false;
		}
		$contract_constant = Integration_API::class . '::CONTRACT_VERSION';
		$contract_version  = defined( $contract_constant ) ? constant( $contract_constant ) : null;
		if ( self::EXPECTED_CONTRACT_VERSION !== $contract_version ) {
			return false;
		}

		foreach ( self::REQUIRED_METHODS as $method ) {
			if ( ! method_exists( Integration_API::class, $method ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Reads one effective configuration through the provider contract.
	 *
	 * @param int $post_id Target post ID.
	 * @return array<string, mixed>
	 */
	public function read( int $post_id ): array {
		$this->require_provider();
		$result = Integration_API::get_configuration( $post_id );
		if ( $result instanceof WP_Error ) {
			$this->throw_provider_error( $result, false );
		}

		return $this->normalize_configuration( $result );
	}

	/**
	 * Validates a prospective configuration without writing.
	 *
	 * @param int                        $post_id Target post ID.
	 * @param array<string, bool|string> $fields Validated update fields.
	 * @return array<string, mixed>
	 * @throws CustomSchemaUnavailable When the provider cannot handle the target.
	 * @throws MutationForbidden When provider authorization fails closed.
	 */
	public function preview( int $post_id, array $fields ): array {
		$current = $this->read( $post_id );
		$merged  = $this->merge_fields( $current, $fields );

		return $this->configuration(
			$merged['enabled'],
			$merged['source'],
			$this->normalize_validation( Integration_API::validate_source( $merged['source'] ) )
		);
	}

	/**
	 * Writes through the provider API and verifies the effective result.
	 *
	 * @param int                        $post_id Target post ID.
	 * @param array<string, bool|string> $fields Validated update fields.
	 * @return array<string, mixed>
	 * @throws MutationWriteFailed When persistence cannot be verified.
	 */
	public function write( int $post_id, array $fields ): array {
		$current = $this->read( $post_id );
		$merged  = $this->merge_fields( $current, $fields );
		$result  = Integration_API::update_configuration( $post_id, $merged['enabled'], $merged['source'] );
		if ( $result instanceof WP_Error ) {
			$this->throw_provider_error( $result, true );
		}

		$effective = $this->normalize_configuration( $result );
		if ( $merged['enabled'] !== $effective['enabled'] || $merged['source'] !== $effective['source'] ) {
			$this->restore_configuration( $post_id, $current );
			throw new MutationWriteFailed( 'The Custom Schema provider did not persist the requested configuration.' );
		}

		return $effective;
	}

	/**
	 * Best-effort restore through the same public API after verification fails.
	 *
	 * @param int                  $post_id Target post ID.
	 * @param array<string, mixed> $current Pre-write normalized configuration.
	 */
	private function restore_configuration( int $post_id, array $current ): void {
		$enabled = $current['enabled'] ?? null;
		$source  = $current['source'] ?? null;
		if ( is_bool( $enabled ) && is_string( $source ) ) {
			Integration_API::update_configuration( $post_id, $enabled, $source );
		}
	}

	/**
	 * Requires the optional provider at execution time.
	 *
	 * @throws CustomSchemaUnavailable When the public contract is unavailable.
	 */
	private function require_provider(): void {
		if ( ! $this->is_available() ) {
			throw new CustomSchemaUnavailable( 'The Custom Schema provider is unavailable or incompatible.' );
		}
	}

	/**
	 * Applies only the two explicit mutable fields.
	 *
	 * @param array<string, mixed>       $current Current normalized configuration.
	 * @param array<string, bool|string> $fields  Validated update fields.
	 * @return array{enabled: bool, source: string}
	 * @throws CustomSchemaUnavailable When a field or value escapes the contract.
	 */
	private function merge_fields( array $current, array $fields ): array {
		foreach ( array_keys( $fields ) as $field ) {
			if ( ! in_array( $field, array( 'enabled', 'source' ), true ) ) {
				throw new CustomSchemaUnavailable( 'The Custom Schema field is unsupported.' );
			}
		}

		$enabled = $fields['enabled'] ?? $current['enabled'];
		$source  = $fields['source'] ?? $current['source'];
		if ( ! is_bool( $enabled ) || ! is_string( $source ) ) {
			throw new CustomSchemaUnavailable( 'The Custom Schema provider document is incompatible.' );
		}

		return array(
			'enabled' => $enabled,
			'source'  => $source,
		);
	}

	/**
	 * Normalizes and verifies a provider configuration.
	 *
	 * @param array<mixed> $configuration Provider result.
	 * @return array<string, mixed>
	 * @throws CustomSchemaUnavailable When the provider document is incompatible.
	 */
	private function normalize_configuration( array $configuration ): array {
		$contract = $configuration['contract_version'] ?? null;
		$enabled  = $configuration['enabled'] ?? null;
		$source   = $configuration['source'] ?? null;
		$raw      = $configuration['validation'] ?? null;
		if (
			self::EXPECTED_CONTRACT_VERSION !== $contract
			|| ! is_bool( $enabled )
			|| ! is_string( $source )
			|| self::MAX_SOURCE_LENGTH < strlen( $source )
			|| ! mb_check_encoding( $source, 'UTF-8' )
			|| ! is_array( $raw )
		) {
			throw new CustomSchemaUnavailable( 'The Custom Schema provider returned an incompatible document.' );
		}

		return $this->configuration( $enabled, $source, $this->normalize_validation( $raw ) );
	}

	/**
	 * Builds the strict public configuration document.
	 *
	 * @param bool                 $enabled    Whether rendering is enabled.
	 * @param string               $source     Editable JSON source.
	 * @param array<string, mixed> $validation Normalized diagnostics.
	 * @return array<string, mixed>
	 */
	private function configuration( bool $enabled, string $source, array $validation ): array {
		$valid   = true === $validation['valid'];
		$version = constant( 'ISUDEV_SCHEMA_EXTENDED_VERSION' );

		return array(
			'contract_version' => self::EXPECTED_CONTRACT_VERSION,
			'enabled'          => $enabled,
			'source'           => $source,
			'save_allowed'     => ! $enabled || $valid,
			'render_eligible'  => $enabled && $valid,
			'validation'       => $validation,
			'provider'         => array(
				'name'    => 'isudev-schema-extended',
				'version' => is_string( $version ) ? $version : '',
			),
		);
	}

	/**
	 * Verifies bounded provider validation output.
	 *
	 * @param array<mixed> $validation Provider validation result.
	 * @return array<string, mixed>
	 * @throws CustomSchemaUnavailable When diagnostics or nodes escape bounds.
	 */
	private function normalize_validation( array $validation ): array {
		$valid    = $validation['valid'] ?? null;
		$nodes    = $validation['nodes'] ?? null;
		$errors   = $validation['errors'] ?? null;
		$warnings = $validation['warnings'] ?? null;
		if ( ! is_bool( $valid ) || ! is_array( $nodes ) || ! array_is_list( $nodes ) || self::MAX_NODES < count( $nodes ) ) {
			throw new CustomSchemaUnavailable( 'The Custom Schema validation result is incompatible.' );
		}
		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) || array_is_list( $node ) ) {
				throw new CustomSchemaUnavailable( 'The Custom Schema validation nodes are incompatible.' );
			}
		}
		$encoded = wp_json_encode( $nodes );
		if ( ! is_string( $encoded ) || self::MAX_SOURCE_LENGTH < strlen( $encoded ) ) {
			throw new CustomSchemaUnavailable( 'The Custom Schema validation nodes exceed the output limit.' );
		}

		return array(
			'valid'            => $valid,
			'context_resolved' => false,
			'nodes'            => $nodes,
			'errors'           => $this->normalize_diagnostics( $errors ),
			'warnings'         => $this->normalize_diagnostics( $warnings ),
		);
	}

	/**
	 * Normalizes one bounded diagnostics list.
	 *
	 * @param mixed $diagnostics Provider diagnostics.
	 * @return list<array{code: string, message: string}>
	 * @throws CustomSchemaUnavailable When a diagnostic is malformed.
	 */
	private function normalize_diagnostics( mixed $diagnostics ): array {
		if ( ! is_array( $diagnostics ) || ! array_is_list( $diagnostics ) || self::MAX_DIAGNOSTICS < count( $diagnostics ) ) {
			throw new CustomSchemaUnavailable( 'The Custom Schema diagnostics are incompatible.' );
		}

		$normalized = array();
		foreach ( $diagnostics as $diagnostic ) {
			if ( ! is_array( $diagnostic ) || array() !== array_diff( array_keys( $diagnostic ), array( 'code', 'message' ) ) ) {
				throw new CustomSchemaUnavailable( 'A Custom Schema diagnostic is incompatible.' );
			}
			$code    = $diagnostic['code'] ?? null;
			$message = $diagnostic['message'] ?? null;
			if ( ! is_string( $code ) || '' === $code || 191 < strlen( $code ) || ! is_string( $message ) || 2000 < mb_strlen( $message ) ) {
				throw new CustomSchemaUnavailable( 'A Custom Schema diagnostic is incompatible.' );
			}
			$normalized[] = array(
				'code'    => $code,
				'message' => $message,
			);
		}

		return $normalized;
	}

	/**
	 * Maps provider failures to the bridge's stable vocabulary.
	 *
	 * @param WP_Error $error Provider failure.
	 * @param bool     $write Whether the operation attempted persistence.
	 * @return never
	 * @throws CustomSchemaInvalid When enabled JSON fails provider validation.
	 * @throws CustomSchemaUnavailable When the provider cannot handle the target.
	 * @throws MutationForbidden When provider authorization fails closed.
	 * @throws MutationWriteFailed When the provider rejects persistence.
	 */
	private function throw_provider_error( WP_Error $error, bool $write ): never {
		$code = $error->get_error_code();
		if ( 'isudev_schema_extended_forbidden' === $code ) {
			throw new MutationForbidden( 'Custom Schema access is not permitted.' );
		}
		if ( 'isudev_schema_extended_unsupported_post' === $code ) {
			throw new CustomSchemaUnavailable( 'Custom Schema is unavailable for this content type.' );
		}
		if ( 'isudev_schema_extended_invalid_custom_schema' === $code ) {
			$data       = $error->get_error_data();
			$validation = is_array( $data ) && is_array( $data['validation'] ?? null )
				? $this->normalize_validation( $data['validation'] )
				: array(
					'valid'            => false,
					'context_resolved' => false,
					'nodes'            => array(),
					'errors'           => array(),
					'warnings'         => array(),
				);
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- diagnostics are structured data, not rendered output.
			throw new CustomSchemaInvalid( 'Enabled Custom Schema failed provider validation.', $validation );
		}

		if ( $write ) {
			throw new MutationWriteFailed( 'The Custom Schema provider rejected the write.' );
		}

		throw new CustomSchemaUnavailable( 'The Custom Schema provider rejected the request.' );
	}
}

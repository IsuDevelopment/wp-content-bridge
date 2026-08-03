<?php
/**
 * Result of a successful Service schema mutation.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Domain\Mutation;

/**
 * Adds the effective Service configuration to the standard mutation envelope.
 */
final readonly class ServiceSchemaMutationResult {

	/**
	 * Creates the result.
	 *
	 * @param MutationResult       $mutation                 Standard content mutation identity.
	 * @param array<string, mixed> $effective_service_schema Sanitized post-write configuration.
	 */
	public function __construct(
		public MutationResult $mutation,
		public array $effective_service_schema,
	) {}

	/**
	 * Returns the public wire document.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array_merge(
			$this->mutation->to_array(),
			array( 'effective_service_schema' => $this->effective_service_schema )
		);
	}
}

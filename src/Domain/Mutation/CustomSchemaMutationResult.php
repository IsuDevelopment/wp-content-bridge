<?php
/**
 * Result of a successful Custom Schema mutation.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Domain\Mutation;

/**
 * Adds the effective Custom Schema configuration to the mutation envelope.
 */
final readonly class CustomSchemaMutationResult {

	/**
	 * Creates the result.
	 *
	 * @param MutationResult       $mutation                Standard mutation identity.
	 * @param array<string, mixed> $effective_custom_schema Sanitized post-write configuration.
	 */
	public function __construct(
		public MutationResult $mutation,
		public array $effective_custom_schema,
	) {}

	/**
	 * Returns the public wire document.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array_merge(
			$this->mutation->to_array(),
			array( 'effective_custom_schema' => $this->effective_custom_schema )
		);
	}
}

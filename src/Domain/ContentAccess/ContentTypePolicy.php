<?php
/**
 * Content-type access policy.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Domain\ContentAccess;

/**
 * Immutable operation matrix for one content type.
 */
final readonly class ContentTypePolicy {

	/**
	 * Normalized operation flags.
	 *
	 * @var array<string, bool>
	 */
	private array $operations;

	/**
	 * Creates an immutable normalized policy.
	 *
	 * @param array<string, bool> $operations Normalized operation flags.
	 */
	private function __construct( array $operations ) {
		$this->operations = $operations;
	}

	/**
	 * Builds a policy from untrusted scalar settings input.
	 *
	 * Dependent operations are disabled when their prerequisites are disabled.
	 *
	 * @param array<string, mixed> $input Raw settings row.
	 * @return self
	 */
	public static function from_input( array $input ): self {
		$operations = array();

		foreach ( ContentOperation::cases() as $operation ) {
			$operations[ $operation->value ] = self::to_boolean( $input[ $operation->value ] ?? false );
		}

		foreach ( ContentOperation::cases() as $operation ) {
			foreach ( $operation->prerequisites() as $prerequisite ) {
				if ( ! $operations[ $prerequisite->value ] ) {
					$operations[ $operation->value ] = false;
				}
			}
		}

		return new self( $operations );
	}

	/**
	 * Returns a policy with read and search enabled.
	 *
	 * @return self
	 */
	public static function default_readable(): self {
		return self::from_input(
			array(
				ContentOperation::READ->value   => true,
				ContentOperation::SEARCH->value => true,
			)
		);
	}

	/**
	 * Returns a policy with every operation disabled.
	 *
	 * @return self
	 */
	public static function deny_all(): self {
		return self::from_input( array() );
	}

	/**
	 * Checks whether an operation is enabled by configuration.
	 *
	 * @param ContentOperation $operation Operation to check.
	 * @return bool
	 */
	public function allows( ContentOperation $operation ): bool {
		return $this->operations[ $operation->value ];
	}

	/**
	 * Serializes the policy for WordPress option storage.
	 *
	 * @return array<string, bool>
	 */
	public function to_array(): array {
		return $this->operations;
	}

	/**
	 * Converts a narrow set of checkbox values to boolean.
	 *
	 * @param mixed $value Raw value.
	 * @return bool
	 */
	private static function to_boolean( mixed $value ): bool {
		return true === $value || 1 === $value || '1' === $value || 'yes' === $value || 'on' === $value;
	}
}

<?php
/**
 * One recorded ability invocation attempt.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Application\Telemetry;

/**
 * Immutable record of an invocation attempt — shapes only, never values.
 *
 * There is deliberately no field for ability input, an error message, or a
 * result payload. `wp_ability_invoked` hands the listener the raw input, and
 * ability input can contain the site's content; the absence of a field is what
 * stops it reaching storage, rather than reviewer discipline (ADR 0029).
 */
final readonly class InvocationAttempt {

	/**
	 * The call was made and did not complete.
	 *
	 * Named `attempted` rather than `denied` or `failed` because that is all it
	 * can mean: no WordPress hook fires on the failure paths, so a permission
	 * denial, invalid input, and an internal error are indistinguishable here.
	 */
	public const ATTEMPTED = 'attempted';

	/**
	 * The call ran and its output validated.
	 */
	public const COMPLETED = 'completed';

	/**
	 * Creates an attempt record.
	 *
	 * @param string $ability     Ability name.
	 * @param int    $user_id     Acting principal, 0 when unauthenticated.
	 * @param string $channel     rest|cli|admin|other.
	 * @param string $outcome     self::ATTEMPTED or self::COMPLETED.
	 * @param string $occurred_at GMT timestamp, `Y-m-d H:i:s`.
	 */
	public function __construct(
		public string $ability,
		public int $user_id,
		public string $channel,
		public string $outcome,
		public string $occurred_at,
	) {
	}

	/**
	 * Returns the same attempt marked completed.
	 *
	 * @return self
	 */
	public function completed(): self {
		return new self(
			$this->ability,
			$this->user_id,
			$this->channel,
			self::COMPLETED,
			$this->occurred_at
		);
	}

	/**
	 * Returns the storable representation.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'ability'     => $this->ability,
			'user_id'     => $this->user_id,
			'channel'     => $this->channel,
			'outcome'     => $this->outcome,
			'occurred_at' => $this->occurred_at,
		);
	}
}

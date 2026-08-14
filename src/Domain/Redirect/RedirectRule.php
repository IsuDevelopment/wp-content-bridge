<?php
/**
 * Redirect rule aggregate.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Domain\Redirect;

use InvalidArgumentException;

/**
 * A provider-neutral redirect rule (P0, ADR 0026). The same shape whichever
 * backend answered; `provider` carries the provenance every redirect result
 * must report.
 */
final readonly class RedirectRule {

	/**
	 * Creates a redirect rule.
	 *
	 * @param string|null            $id       Provider-assigned identity, or null before creation.
	 * @param RedirectSourcePath     $source   Bounded, exact source path.
	 * @param RedirectStatusCode     $status   Allowed HTTP status.
	 * @param RedirectTargetUrl|null $target   Destination; required unless `status` is GONE.
	 * @param bool                   $enabled  Whether the rule is active.
	 * @param RedirectProviderStatus $provider Provider that owns (or will own) this rule.
	 * @throws InvalidArgumentException When the target is missing for a
	 *                                   non-Gone status, or present for Gone.
	 */
	public function __construct(
		public ?string $id,
		public RedirectSourcePath $source,
		public RedirectStatusCode $status,
		public ?RedirectTargetUrl $target,
		public bool $enabled,
		public RedirectProviderStatus $provider,
	) {
		if ( RedirectStatusCode::GONE === $status ) {
			if ( null !== $target ) {
				throw new InvalidArgumentException( 'A Gone redirect rule must not have a target.' );
			}
		} elseif ( null === $target ) {
			throw new InvalidArgumentException( 'A redirect rule requires a target unless its status is Gone.' );
		}
	}

	/**
	 * Serializes the rule for a redirect Ability result.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'id'       => $this->id,
			'source'   => $this->source->value(),
			'status'   => $this->status->value,
			'target'   => $this->target?->value(),
			'enabled'  => $this->enabled,
			'provider' => $this->provider->to_array(),
		);
	}
}

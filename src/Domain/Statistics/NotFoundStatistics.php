<?php
/**
 * One provider's answer to "which paths 404 here, and how often".
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Domain\Statistics;

use InvalidArgumentException;

/**
 * A single provider's aggregate 404 result, carrying its own availability so
 * one backend's switched-off log never reads as the site being healthy
 * (ADR 0030 s2).
 *
 * `paths` is non-empty only when `availability` is `measured`. The other three
 * states carry no counts at all rather than an empty list that a caller could
 * mistake for an observation.
 */
final readonly class NotFoundStatistics {

	/**
	 * Creates one provider's result.
	 *
	 * @param ErrorStatisticsProviderStatus $provider     Answering provider.
	 * @param ErrorStatisticsAvailability   $availability What kind of answer this is.
	 * @param ErrorStatisticsWindow         $window       Window the counts cover.
	 * @param array                         $paths        Aggregated counts, highest first.
	 * @param string|null                   $disabled_by  Provider setting responsible for a `disabled` result.
	 * @param string|null                   $detail       Safe explanation for a non-measured result.
	 * @phpstan-param list<NotFoundCount> $paths
	 * @throws InvalidArgumentException When the result contradicts its own availability.
	 */
	public function __construct(
		public ErrorStatisticsProviderStatus $provider,
		public ErrorStatisticsAvailability $availability,
		public ErrorStatisticsWindow $window,
		public array $paths = array(),
		public ?string $disabled_by = null,
		public ?string $detail = null,
	) {
		if ( ErrorStatisticsAvailability::MEASURED !== $availability && array() !== $paths ) {
			throw new InvalidArgumentException( 'Only a measured result may carry counts.' );
		}
		if ( ErrorStatisticsAvailability::DISABLED === $availability && null === $disabled_by ) {
			// A disabled result exists to tell the operator which setting to
			// change; without that it is just a zero by another name.
			throw new InvalidArgumentException( 'A disabled result must name the setting responsible.' );
		}
	}

	/**
	 * Whether this result is an observation rather than a reported gap.
	 *
	 * @return bool
	 */
	public function is_measured(): bool {
		return ErrorStatisticsAvailability::MEASURED === $this->availability;
	}

	/**
	 * Serializes the result.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'provider'     => $this->provider->to_array(),
			'availability' => $this->availability->value,
			'window'       => $this->window->to_array(),
			'disabled_by'  => $this->disabled_by,
			'detail'       => $this->detail,
			'paths'        => array_map(
				static fn ( NotFoundCount $count ): array => $count->to_array(),
				$this->paths
			),
		);
	}
}

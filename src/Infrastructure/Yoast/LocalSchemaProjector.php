<?php
/**
 * Public Local SEO Schema projection.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Infrastructure\Yoast;

/**
 * Derives bounded public local-business profiles from provider-emitted Schema.
 */
final class LocalSchemaProjector {

	private const MAX_ENTITIES = 50;

	private const SCALAR_KEYS  = array( '@id', 'name', 'url', 'email', 'faxNumber', 'priceRange', 'currenciesAccepted', 'paymentAccepted', 'vatID', 'taxID' );
	private const ADDRESS_KEYS = array( '@id', '@type', 'streetAddress', 'addressLocality', 'addressRegion', 'postalCode', 'postOfficeBoxNumber', 'addressCountry' );
	private const GEO_KEYS     = array( '@id', '@type', 'latitude', 'longitude' );
	private const HOURS_KEYS   = array( '@id', '@type', 'dayOfWeek', 'opens', 'closes', 'validFrom', 'validThrough' );
	private const REF_KEYS     = array( '@id', '@type', 'url', 'contentUrl', 'name' );

	/**
	 * Extracts public local entities without reading Local SEO options or meta.
	 *
	 * @param array $graph Provider-emitted Schema graph.
	 * @return list<array<string, mixed>>
	 * @phpstan-param list<array<string, mixed>> $graph
	 */
	public function project( array $graph ): array {
		$by_id = array();
		foreach ( $graph as $node ) {
			if ( isset( $node['@id'] ) && is_string( $node['@id'] ) ) {
				$by_id[ $node['@id'] ] = $node;
			}
		}

		$entities = array();
		foreach ( $graph as $node ) {
			$types = $this->scalar_list( $node['@type'] ?? null );
			if ( ! in_array( 'Place', $types, true ) && ! in_array( 'LocalBusiness', $types, true ) ) {
				continue;
			}
			$entity = $this->project_entity( $node, $by_id );
			if ( array() !== $entity ) {
				$entities[] = $entity;
			}
			if ( count( $entities ) >= self::MAX_ENTITIES ) {
				break;
			}
		}

		return $entities;
	}

	/**
	 * Projects one local entity through explicit nested allowlists.
	 *
	 * @param array $node  Schema entity.
	 * @param array $by_id Schema nodes keyed by public identifier.
	 * @return array<string, mixed>
	 * @phpstan-param array<string, mixed> $node
	 * @phpstan-param array<string, array<string, mixed>> $by_id
	 */
	private function project_entity( array $node, array $by_id ): array {
		$entity = array();
		foreach ( self::SCALAR_KEYS as $key ) {
			if ( isset( $node[ $key ] ) && is_scalar( $node[ $key ] ) ) {
				$entity[ $key ] = $node[ $key ];
			}
		}
		$types                               = $this->scalar_list( $node['@type'] ?? null );
		$entity['@type']                     = 1 === count( $types ) ? $types[0] : $types;
		$entity['telephone']                 = $this->scalar_or_list( $node['telephone'] ?? null );
		$entity['areaServed']                = $this->scalar_or_list( $node['areaServed'] ?? null );
		$entity['address']                   = $this->project_nested( $node['address'] ?? null, self::ADDRESS_KEYS, $by_id );
		$entity['geo']                       = $this->project_nested( $node['geo'] ?? null, self::GEO_KEYS, $by_id );
		$entity['openingHoursSpecification'] = $this->project_nested_list( $node['openingHoursSpecification'] ?? null, self::HOURS_KEYS, $by_id );
		foreach ( array( 'image', 'logo', 'branchOf', 'mainEntityOfPage' ) as $key ) {
			$entity[ $key ] = $this->project_nested( $node[ $key ] ?? null, self::REF_KEYS, $by_id );
		}

		return array_filter( $entity, static fn ( mixed $value ): bool => null !== $value && array() !== $value );
	}

	/**
	 * Projects one nested object and resolves its public @id reference.
	 *
	 * @param mixed $value Nested value.
	 * @param array $keys  Allowed keys.
	 * @param array $by_id Schema nodes keyed by public identifier.
	 * @return array<string, mixed>|null
	 * @phpstan-param list<string> $keys
	 * @phpstan-param array<string, array<string, mixed>> $by_id
	 */
	private function project_nested( mixed $value, array $keys, array $by_id ): ?array {
		if ( ! is_array( $value ) ) {
			return null;
		}
		if ( isset( $value['@id'] ) && is_string( $value['@id'] ) && isset( $by_id[ $value['@id'] ] ) ) {
			$value = array_merge( $by_id[ $value['@id'] ], $value );
		}
		$output = array();
		foreach ( $keys as $key ) {
			if ( ! array_key_exists( $key, $value ) ) {
				continue;
			}
			$normalized = '@type' === $key || 'dayOfWeek' === $key
				? $this->scalar_or_list( $value[ $key ] )
				: ( is_scalar( $value[ $key ] ) ? $value[ $key ] : null );
			if ( null !== $normalized && array() !== $normalized ) {
				$output[ $key ] = $normalized;
			}
		}

		return array() === $output ? null : $output;
	}

	/**
	 * Projects a nested object or list of objects.
	 *
	 * @param mixed $value Nested object(s).
	 * @param array $keys  Allowed keys.
	 * @param array $by_id Schema nodes keyed by public identifier.
	 * @return list<array<string, mixed>>
	 * @phpstan-param list<string> $keys
	 * @phpstan-param array<string, array<string, mixed>> $by_id
	 */
	private function project_nested_list( mixed $value, array $keys, array $by_id ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}
		$values = array_is_list( $value ) ? $value : array( $value );
		$output = array();
		foreach ( $values as $item ) {
			$projected = $this->project_nested( $item, $keys, $by_id );
			if ( null !== $projected ) {
				$output[] = $projected;
			}
		}

		return $output;
	}

	/**
	 * Normalizes a scalar or bounded scalar list.
	 *
	 * @param mixed $value Candidate value.
	 * @return string|int|float|bool|array|null
	 * @phpstan-return string|int|float|bool|list<string|int|float|bool>|null
	 */
	private function scalar_or_list( mixed $value ): string|int|float|bool|array|null {
		if ( is_scalar( $value ) ) {
			return $value;
		}
		$values = $this->scalar_list( $value );

		return array() === $values ? null : $values;
	}

	/**
	 * Keeps at most 100 scalar list members.
	 *
	 * @param mixed $value Candidate list.
	 * @return list<string|int|float|bool>
	 */
	private function scalar_list( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return is_scalar( $value ) ? array( $value ) : array();
		}

		return array_values( array_filter( array_slice( $value, 0, 100 ), 'is_scalar' ) );
	}
}

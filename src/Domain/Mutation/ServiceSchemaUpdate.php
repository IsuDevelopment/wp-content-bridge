<?php
/**
 * Validated input for a structured Service entity update.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Domain\Mutation;

use InvalidArgumentException;

/**
 * Provider-neutral Service schema write intent.
 *
 * Omitted fields remain unchanged. Empty strings and empty lists are explicit
 * clear operations. Arbitrary metadata keys never cross this boundary.
 */
final readonly class ServiceSchemaUpdate {

	private const MAX_SHORT_TEXT        = 191;
	private const MAX_DESCRIPTION       = 2000;
	private const MAX_OFFER_DESCRIPTION = 1000;
	private const MAX_AREAS             = 100;
	private const MAX_BRANDS            = 50;
	private const MAX_OFFERS            = 20;
	private const AREA_TYPES            = array( 'City', 'AdministrativeArea', 'Country' );

	/**
	 * Complete public wire-key allowlist.
	 *
	 * @var list<string>
	 */
	public const ALLOWED_KEYS = array(
		'post_id',
		'version_token',
		'enabled',
		'name',
		'service_type',
		'description',
		'areas',
		'brands',
		'catalog_name',
		'offers',
	);

	/**
	 * Creates a validated update.
	 *
	 * @param int          $post_id          Target post ID.
	 * @param VersionToken $expected_version Optimistic-concurrency token.
	 * @param bool|null    $enabled          Whether the Service entity is enabled.
	 * @param string|null  $name             Service name; empty clears.
	 * @param string|null  $service_type     Service type; empty clears.
	 * @param string|null  $description      Service description; empty clears.
	 * @param array|null   $areas            Typed service areas; empty clears.
	 * @param array|null   $brands           Brand names; empty clears.
	 * @param string|null  $catalog_name     Offer catalog name; empty clears.
	 * @param array|null   $offers           Offer catalog items; empty clears.
	 * @phpstan-param list<array{type: string, name: string}>|null $areas
	 * @phpstan-param list<string>|null $brands
	 * @phpstan-param list<array{name: string, description: string}>|null $offers
	 */
	public function __construct(
		public int $post_id,
		public VersionToken $expected_version,
		public ?bool $enabled,
		public ?string $name,
		public ?string $service_type,
		public ?string $description,
		public ?array $areas,
		public ?array $brands,
		public ?string $catalog_name,
		public ?array $offers,
	) {}

	/**
	 * Builds an update from untrusted Ability input.
	 *
	 * @param array<string, mixed> $input Raw input.
	 * @throws InvalidArgumentException When the request is malformed or empty.
	 */
	public static function from_input( array $input ): self {
		foreach ( array_keys( $input ) as $key ) {
			if ( ! in_array( $key, self::ALLOWED_KEYS, true ) ) {
				throw new InvalidArgumentException( 'Service schema input contains an unsupported field.' );
			}
		}

		$post_id = $input['post_id'] ?? null;
		if ( ! is_int( $post_id ) || 0 >= $post_id ) {
			throw new InvalidArgumentException( 'A post ID must be a positive integer.' );
		}

		$token = $input['version_token'] ?? null;
		if ( ! is_string( $token ) ) {
			throw new InvalidArgumentException( 'A version token is required.' );
		}

		$enabled      = self::optional_bool( $input, 'enabled' );
		$name         = self::optional_string( $input, 'name', self::MAX_SHORT_TEXT );
		$service_type = self::optional_string( $input, 'service_type', self::MAX_SHORT_TEXT );
		$description  = self::optional_string( $input, 'description', self::MAX_DESCRIPTION );
		$areas        = self::optional_areas( $input );
		$brands       = self::optional_brands( $input );
		$catalog_name = self::optional_string( $input, 'catalog_name', self::MAX_SHORT_TEXT );
		$offers       = self::optional_offers( $input );

		if (
			null === $enabled
			&& null === $name
			&& null === $service_type
			&& null === $description
			&& null === $areas
			&& null === $brands
			&& null === $catalog_name
			&& null === $offers
		) {
			throw new InvalidArgumentException( 'A Service schema update must change at least one field.' );
		}

		return new self(
			$post_id,
			VersionToken::from_string( $token ),
			$enabled,
			$name,
			$service_type,
			$description,
			$areas,
			$brands,
			$catalog_name,
			$offers
		);
	}

	/**
	 * Names of fields present in this update.
	 *
	 * @return list<string>
	 */
	public function changed_fields(): array {
		return array_keys( $this->writable_fields() );
	}

	/**
	 * Returns the fixed, provider-neutral write document.
	 *
	 * @return array<string, mixed>
	 */
	public function writable_fields(): array {
		$fields = array();
		foreach ( array( 'enabled', 'name', 'service_type', 'description', 'areas', 'brands', 'catalog_name', 'offers' ) as $field ) {
			if ( null !== $this->{$field} ) {
				$fields[ $field ] = $this->{$field};
			}
		}

		return $fields;
	}

	/**
	 * Validates one optional string. Empty strings intentionally clear values.
	 *
	 * @param array<string, mixed> $input      Raw input.
	 * @param string               $key        Field name.
	 * @param int                  $max_length Maximum character count.
	 * @throws InvalidArgumentException When the field is invalid.
	 */
	private static function optional_string( array $input, string $key, int $max_length ): ?string {
		if ( ! array_key_exists( $key, $input ) ) {
			return null;
		}

		$value = $input[ $key ];
		if ( ! is_string( $value ) ) {
			throw new InvalidArgumentException( 'A Service schema text field is invalid.' );
		}

		$value = trim( $value );
		if ( $max_length < mb_strlen( $value ) ) {
			throw new InvalidArgumentException( 'A Service schema text field is too long.' );
		}

		return $value;
	}

	/**
	 * Validates the optional enabled flag.
	 *
	 * @param array<string, mixed> $input Raw input.
	 * @param string               $key   Field name.
	 * @throws InvalidArgumentException When present but not boolean.
	 */
	private static function optional_bool( array $input, string $key ): ?bool {
		if ( ! array_key_exists( $key, $input ) ) {
			return null;
		}
		if ( ! is_bool( $input[ $key ] ) ) {
			throw new InvalidArgumentException( 'The Service schema enabled field must be boolean.' );
		}

		return $input[ $key ];
	}

	/**
	 * Validates typed Schema.org service areas.
	 *
	 * @param array<string, mixed> $input Raw input.
	 * @return list<array{type: string, name: string}>|null
	 * @throws InvalidArgumentException When the list is malformed.
	 */
	private static function optional_areas( array $input ): ?array {
		if ( ! array_key_exists( 'areas', $input ) ) {
			return null;
		}

		$value = $input['areas'];
		if ( ! is_array( $value ) || ! array_is_list( $value ) || self::MAX_AREAS < count( $value ) ) {
			throw new InvalidArgumentException( 'Service areas must be a bounded list.' );
		}

		$areas = array();
		$seen  = array();
		foreach ( $value as $area ) {
			if ( ! is_array( $area ) || array() !== array_diff( array_keys( $area ), array( 'type', 'name' ) ) ) {
				throw new InvalidArgumentException( 'A service area is invalid.' );
			}
			$type = $area['type'] ?? null;
			$name = $area['name'] ?? null;
			if ( ! is_string( $type ) || ! in_array( $type, self::AREA_TYPES, true ) || ! is_string( $name ) ) {
				throw new InvalidArgumentException( 'A service area is invalid.' );
			}
			$name = trim( $name );
			if ( '' === $name || self::MAX_SHORT_TEXT < mb_strlen( $name ) ) {
				throw new InvalidArgumentException( 'A service area name is invalid.' );
			}
			$identity = $type . "\0" . $name;
			if ( isset( $seen[ $identity ] ) ) {
				throw new InvalidArgumentException( 'Service areas must be unique.' );
			}
			$seen[ $identity ] = true;
			$areas[]           = array(
				'type' => $type,
				'name' => $name,
			);
		}

		return $areas;
	}

	/**
	 * Validates brand names without ambiguous storage delimiters.
	 *
	 * @param array<string, mixed> $input Raw input.
	 * @return list<string>|null
	 * @throws InvalidArgumentException When the list is malformed.
	 */
	private static function optional_brands( array $input ): ?array {
		if ( ! array_key_exists( 'brands', $input ) ) {
			return null;
		}

		$value = $input['brands'];
		if ( ! is_array( $value ) || ! array_is_list( $value ) || self::MAX_BRANDS < count( $value ) ) {
			throw new InvalidArgumentException( 'Brands must be a bounded list.' );
		}

		$brands = array();
		foreach ( $value as $brand ) {
			if ( ! is_string( $brand ) ) {
				throw new InvalidArgumentException( 'A brand name is invalid.' );
			}
			$brand = trim( $brand );
			if ( '' === $brand || self::MAX_SHORT_TEXT < mb_strlen( $brand ) || 1 === preg_match( '/[\r\n,]/u', $brand ) ) {
				throw new InvalidArgumentException( 'A brand name is invalid.' );
			}
			if ( in_array( $brand, $brands, true ) ) {
				throw new InvalidArgumentException( 'Brand names must be unique.' );
			}
			$brands[] = $brand;
		}

		return $brands;
	}

	/**
	 * Validates OfferCatalog entries.
	 *
	 * @param array<string, mixed> $input Raw input.
	 * @return list<array{name: string, description: string}>|null
	 * @throws InvalidArgumentException When the list is malformed.
	 */
	private static function optional_offers( array $input ): ?array {
		if ( ! array_key_exists( 'offers', $input ) ) {
			return null;
		}

		$value = $input['offers'];
		if ( ! is_array( $value ) || ! array_is_list( $value ) || self::MAX_OFFERS < count( $value ) ) {
			throw new InvalidArgumentException( 'Offers must be a bounded list.' );
		}

		$offers = array();
		$seen   = array();
		foreach ( $value as $offer ) {
			if ( ! is_array( $offer ) || array() !== array_diff( array_keys( $offer ), array( 'name', 'description' ) ) ) {
				throw new InvalidArgumentException( 'An offer is invalid.' );
			}
			$name        = $offer['name'] ?? null;
			$description = $offer['description'] ?? '';
			if ( ! is_string( $name ) || ! is_string( $description ) ) {
				throw new InvalidArgumentException( 'An offer is invalid.' );
			}
			$name        = trim( $name );
			$description = trim( $description );
			if ( '' === $name || self::MAX_SHORT_TEXT < mb_strlen( $name ) || self::MAX_OFFER_DESCRIPTION < mb_strlen( $description ) ) {
				throw new InvalidArgumentException( 'An offer is invalid.' );
			}
			if ( isset( $seen[ $name ] ) ) {
				throw new InvalidArgumentException( 'Offer names must be unique.' );
			}
			$seen[ $name ] = true;
			$offers[]      = array(
				'name'        => $name,
				'description' => $description,
			);
		}

		return $offers;
	}
}

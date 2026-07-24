<?php
/**
 * Validated input for writing the Yoast SEO editor allowlist.
 *
 * @package IsuDev\WPContentBridge
 */

declare( strict_types=1 );

namespace IsuDev\WPContentBridge\Domain\Mutation;

use InvalidArgumentException;

/**
 * Immutable, validated SEO-write input. At least one allowlisted field must
 * be present. Any wire key outside ALLOWED_KEYS is rejected.
 */
final readonly class SeoUpdate {

	private const MAX_TITLE         = 500;
	private const MAX_DESCRIPTION   = 320;
	private const MAX_KEYPHRASE     = 200;
	private const MAX_PREMIUM_ITEMS = 20;
	private const MAX_PREMIUM_TERM  = 191;
	private const MAX_CANONICAL     = 2048;
	private const CANONICAL_PATTERN = '/^https?:\/\//i';

	/**
	 * The complete set of wire keys this ability accepts. Reused by the
	 * UpdateSeo use case to classify unknown-field rejections distinctly.
	 *
	 * @var array<int, string>
	 */
	public const ALLOWED_KEYS = array(
		'post_id',
		'version_token',
		'seo_title',
		'meta_description',
		'focus_keyphrase',
		'keyphrase_synonyms',
		'related_keyphrases',
		'canonical',
		'robots_index',
		'robots_follow',
		'robots_noarchive',
		'robots_noimageindex',
		'robots_nosnippet',
		'og_title',
		'og_description',
		'og_image_id',
		'twitter_title',
		'twitter_description',
		'twitter_image_id',
	);

	/**
	 * Creates a validated SEO update.
	 *
	 * @param int          $post_id             Target post ID.
	 * @param VersionToken $expected_version    Optimistic-concurrency token.
	 * @param string|null  $seo_title           Yoast SEO title override.
	 * @param string|null  $meta_description    Yoast meta description override.
	 * @param string|null  $focus_keyphrase     Yoast focus keyphrase override.
	 * @param array|null   $keyphrase_synonyms  Yoast Premium synonyms for the primary keyphrase.
	 * @param array|null   $related_keyphrases  Yoast Premium related keyphrases.
	 * @param string|null  $canonical           Yoast canonical URL override.
	 * @param bool|null    $robots_index        True: force index. False: force noindex. Null: unchanged.
	 * @param bool|null    $robots_follow       True: force follow. False: force nofollow. Null: unchanged.
	 * @param bool|null    $robots_noarchive    True: add noarchive. False: remove it. Null: unchanged.
	 * @param bool|null    $robots_noimageindex True: add noimageindex. False: remove it. Null: unchanged.
	 * @param bool|null    $robots_nosnippet    True: add nosnippet. False: remove it. Null: unchanged.
	 * @param string|null  $og_title            Yoast Open Graph title override.
	 * @param string|null  $og_description      Yoast Open Graph description override.
	 * @param int|null     $og_image_id         Image attachment ID; zero clears; null leaves unchanged.
	 * @param string|null  $twitter_title       Yoast Twitter title override.
	 * @param string|null  $twitter_description Yoast Twitter description override.
	 * @param int|null     $twitter_image_id    Image attachment ID; zero clears; null leaves unchanged.
	 * @phpstan-param list<string>|null $keyphrase_synonyms
	 * @phpstan-param list<string>|null $related_keyphrases
	 */
	public function __construct(
		public int $post_id,
		public VersionToken $expected_version,
		public ?string $seo_title,
		public ?string $meta_description,
		public ?string $focus_keyphrase,
		public ?array $keyphrase_synonyms,
		public ?array $related_keyphrases,
		public ?string $canonical,
		public ?bool $robots_index,
		public ?bool $robots_follow,
		public ?bool $robots_noarchive,
		public ?bool $robots_noimageindex,
		public ?bool $robots_nosnippet,
		public ?string $og_title,
		public ?string $og_description,
		public ?int $og_image_id,
		public ?string $twitter_title,
		public ?string $twitter_description,
		public ?int $twitter_image_id,
	) {}

	/**
	 * Build from untrusted input.
	 *
	 * @param array<string, mixed> $input Raw update-seo input.
	 * @throws InvalidArgumentException When input is malformed or empty.
	 */
	public static function from_input( array $input ): self {
		foreach ( array_keys( $input ) as $key ) {
			if ( ! in_array( $key, self::ALLOWED_KEYS, true ) ) {
				throw new InvalidArgumentException( 'Update-seo input contains an unsupported field.' );
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
		$expected_version = VersionToken::from_string( $token );

		$seo_title           = self::optional_string( $input, 'seo_title', self::MAX_TITLE );
		$meta_description    = self::optional_string( $input, 'meta_description', self::MAX_DESCRIPTION );
		$focus_keyphrase     = self::optional_string( $input, 'focus_keyphrase', self::MAX_KEYPHRASE );
		$keyphrase_synonyms  = self::optional_string_list( $input, 'keyphrase_synonyms', true );
		$related_keyphrases  = self::optional_string_list( $input, 'related_keyphrases', false );
		$canonical           = self::optional_canonical( $input );
		$robots_index        = self::optional_bool( $input, 'robots_index' );
		$robots_follow       = self::optional_bool( $input, 'robots_follow' );
		$robots_noarchive    = self::optional_bool( $input, 'robots_noarchive' );
		$robots_noimageindex = self::optional_bool( $input, 'robots_noimageindex' );
		$robots_nosnippet    = self::optional_bool( $input, 'robots_nosnippet' );
		$og_title            = self::optional_string( $input, 'og_title', self::MAX_TITLE );
		$og_description      = self::optional_string( $input, 'og_description', self::MAX_DESCRIPTION );
		$og_image_id         = self::optional_non_negative_int( $input, 'og_image_id' );
		$twitter_title       = self::optional_string( $input, 'twitter_title', self::MAX_TITLE );
		$twitter_description = self::optional_string( $input, 'twitter_description', self::MAX_DESCRIPTION );
		$twitter_image_id    = self::optional_non_negative_int( $input, 'twitter_image_id' );

		if ( null === $seo_title && null === $meta_description && null === $focus_keyphrase
			&& null === $keyphrase_synonyms && null === $related_keyphrases
			&& null === $canonical && null === $robots_index && null === $robots_follow
			&& null === $robots_noarchive && null === $robots_noimageindex && null === $robots_nosnippet
			&& null === $og_title && null === $og_description && null === $og_image_id
			&& null === $twitter_title && null === $twitter_description && null === $twitter_image_id
		) {
			throw new InvalidArgumentException( 'An SEO update must change at least one field.' );
		}

		return new self(
			$post_id,
			$expected_version,
			$seo_title,
			$meta_description,
			$focus_keyphrase,
			$keyphrase_synonyms,
			$related_keyphrases,
			$canonical,
			$robots_index,
			$robots_follow,
			$robots_noarchive,
			$robots_noimageindex,
			$robots_nosnippet,
			$og_title,
			$og_description,
			$og_image_id,
			$twitter_title,
			$twitter_description,
			$twitter_image_id
		);
	}

	/**
	 * Names of the fields this update changes (for audit + result).
	 *
	 * @return list<string>
	 */
	public function changed_fields(): array {
		return array_keys( $this->present_fields() );
	}

	/**
	 * Present field name to value, for the SeoWriter port.
	 *
	 * @return array<string, string|int|bool|list<string>>
	 */
	public function writable_fields(): array {
		return $this->present_fields();
	}

	/**
	 * Collects the present (non-null) allowlisted fields in stable order.
	 *
	 * @return array<string, string|int|bool|list<string>>
	 */
	private function present_fields(): array {
		$fields = array();
		if ( null !== $this->seo_title ) {
			$fields['seo_title'] = $this->seo_title;
		}
		if ( null !== $this->meta_description ) {
			$fields['meta_description'] = $this->meta_description;
		}
		if ( null !== $this->focus_keyphrase ) {
			$fields['focus_keyphrase'] = $this->focus_keyphrase;
		}
		if ( null !== $this->keyphrase_synonyms ) {
			$fields['keyphrase_synonyms'] = $this->keyphrase_synonyms;
		}
		if ( null !== $this->related_keyphrases ) {
			$fields['related_keyphrases'] = $this->related_keyphrases;
		}
		if ( null !== $this->canonical ) {
			$fields['canonical'] = $this->canonical;
		}
		if ( null !== $this->robots_index ) {
			$fields['robots_index'] = $this->robots_index;
		}
		if ( null !== $this->robots_follow ) {
			$fields['robots_follow'] = $this->robots_follow;
		}
		if ( null !== $this->robots_noarchive ) {
			$fields['robots_noarchive'] = $this->robots_noarchive;
		}
		if ( null !== $this->robots_noimageindex ) {
			$fields['robots_noimageindex'] = $this->robots_noimageindex;
		}
		if ( null !== $this->robots_nosnippet ) {
			$fields['robots_nosnippet'] = $this->robots_nosnippet;
		}
		if ( null !== $this->og_title ) {
			$fields['og_title'] = $this->og_title;
		}
		if ( null !== $this->og_description ) {
			$fields['og_description'] = $this->og_description;
		}
		if ( null !== $this->og_image_id ) {
			$fields['og_image_id'] = $this->og_image_id;
		}
		if ( null !== $this->twitter_title ) {
			$fields['twitter_title'] = $this->twitter_title;
		}
		if ( null !== $this->twitter_description ) {
			$fields['twitter_description'] = $this->twitter_description;
		}
		if ( null !== $this->twitter_image_id ) {
			$fields['twitter_image_id'] = $this->twitter_image_id;
		}

		return $fields;
	}

	/**
	 * Validates an optional bounded string field.
	 *
	 * @param array<string, mixed> $input      Raw input.
	 * @param string               $key        Field key.
	 * @param int                  $max_length Maximum character length.
	 * @throws InvalidArgumentException When present but invalid.
	 */
	private static function optional_string( array $input, string $key, int $max_length ): ?string {
		if ( ! array_key_exists( $key, $input ) || null === $input[ $key ] ) {
			return null;
		}
		$value = $input[ $key ];
		if ( ! is_string( $value ) || mb_strlen( $value ) > $max_length ) {
			throw new InvalidArgumentException( 'An SEO field is invalid.' );
		}

		return $value;
	}

	/**
	 * Validates an optional bounded list of Premium keyphrase strings.
	 *
	 * Empty arrays intentionally clear the corresponding Yoast Premium field;
	 * null or an omitted key leaves it unchanged.
	 *
	 * @param array<string, mixed> $input         Raw input.
	 * @param string               $key           Field key.
	 * @param bool                 $reject_commas Whether commas are reserved as Yoast's synonym delimiter.
	 * @return list<string>|null
	 * @throws InvalidArgumentException When the list is malformed or ambiguous.
	 */
	private static function optional_string_list( array $input, string $key, bool $reject_commas ): ?array {
		if ( ! array_key_exists( $key, $input ) || null === $input[ $key ] ) {
			return null;
		}
		$value = $input[ $key ];
		if ( ! is_array( $value ) || ! array_is_list( $value ) || self::MAX_PREMIUM_ITEMS < count( $value ) ) {
			throw new InvalidArgumentException( 'A Premium keyphrase field is invalid.' );
		}

		$normalized = array();
		foreach ( $value as $item ) {
			if ( ! is_string( $item ) ) {
				throw new InvalidArgumentException( 'A Premium keyphrase field is invalid.' );
			}
			$item = trim( $item );
			if ( '' === $item || self::MAX_PREMIUM_TERM < mb_strlen( $item ) || ( $reject_commas && str_contains( $item, ',' ) ) ) {
				throw new InvalidArgumentException( 'A Premium keyphrase field is invalid.' );
			}
			if ( in_array( $item, $normalized, true ) ) {
				throw new InvalidArgumentException( 'A Premium keyphrase field contains duplicates.' );
			}
			$normalized[] = $item;
		}

		return $normalized;
	}

	/**
	 * Validates the optional canonical URL field.
	 *
	 * @param array<string, mixed> $input Raw input.
	 * @throws InvalidArgumentException When present but invalid.
	 */
	private static function optional_canonical( array $input ): ?string {
		$value = self::optional_string( $input, 'canonical', self::MAX_CANONICAL );
		if ( null !== $value && '' !== $value && 1 !== preg_match( self::CANONICAL_PATTERN, $value ) ) {
			throw new InvalidArgumentException( 'The canonical field must be an absolute http(s) URL.' );
		}

		return $value;
	}

	/**
	 * Validates an optional boolean field.
	 *
	 * @param array<string, mixed> $input Raw input.
	 * @param string               $key   Field key.
	 * @throws InvalidArgumentException When present but not a boolean.
	 */
	private static function optional_bool( array $input, string $key ): ?bool {
		if ( ! array_key_exists( $key, $input ) || null === $input[ $key ] ) {
			return null;
		}
		$value = $input[ $key ];
		if ( ! is_bool( $value ) ) {
			throw new InvalidArgumentException( 'A robots field must be a boolean.' );
		}

		return $value;
	}

	/**
	 * Validates an optional attachment ID where zero is the explicit clear operation.
	 *
	 * @param array<string, mixed> $input Raw input.
	 * @param string               $key   Field key.
	 * @throws InvalidArgumentException When present but not a non-negative integer.
	 */
	private static function optional_non_negative_int( array $input, string $key ): ?int {
		if ( ! array_key_exists( $key, $input ) || null === $input[ $key ] ) {
			return null;
		}
		$value = $input[ $key ];
		if ( ! is_int( $value ) || 0 > $value ) {
			throw new InvalidArgumentException( 'A social image attachment ID must be a non-negative integer.' );
		}

		return $value;
	}
}

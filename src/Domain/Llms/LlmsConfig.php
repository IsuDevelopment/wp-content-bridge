<?php
/**
 * Validated llms.txt publication configuration.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Domain\Llms;

use InvalidArgumentException;

/**
 * Immutable, provider-neutral llms.txt configuration.
 *
 * This is administrator-authored configuration, not public content: unlike
 * {@see LlmsDocumentBuilder}'s entry input, invalid configuration is rejected
 * outright rather than dropped or truncated, matching the codebase's existing
 * invalid-input failure style for validated domain inputs.
 *
 * `enabled_post_types`, `sections`, and `curated_links` are the only inputs
 * that decide what a *future* generation run may consider; this class does
 * not itself select or authorize any content.
 *
 * @phpstan-type SectionConfig array{key: string, label: string}
 * @phpstan-type CuratedLinkConfig array{title: string, url: string, section: string|null}
 * @phpstan-type ParsedUrl array{scheme: string, host: string, port?: int, path?: string, query?: string, user?: string, pass?: string}
 */
final readonly class LlmsConfig {

	/**
	 * Hard ceiling on configured sections; also the generator's own bound.
	 *
	 * @var int
	 */
	public const MAX_SECTIONS = 20;

	/**
	 * Hard ceiling on items per section; also the generator's own bound.
	 *
	 * @var int
	 */
	public const MAX_ITEMS_PER_SECTION = 100;

	/**
	 * Hard ceiling on excerpt length in characters; also the generator's own bound.
	 *
	 * @var int
	 */
	public const MAX_EXCERPT_LENGTH = 200;

	private const MAX_POST_TYPES           = 50;
	private const MAX_POST_TYPE_LENGTH     = 20;
	private const MAX_SITE_URL_LENGTH      = 2048;
	private const MAX_SITE_TITLE_LENGTH    = 200;
	private const MAX_SITE_SUMMARY_LENGTH  = 300;
	private const MAX_INTRODUCTION_LENGTH  = 2000;
	private const MAX_SECTION_KEY_LENGTH   = 64;
	private const MAX_SECTION_LABEL_LENGTH = 100;
	private const MAX_CURATED_LINKS        = 200;
	private const MAX_CURATED_TITLE_LENGTH = 200;

	/**
	 * Complete public wire-key allowlist.
	 *
	 * @var list<string>
	 */
	public const ALLOWED_KEYS = array(
		'site_url',
		'site_title',
		'site_summary',
		'introduction',
		'enabled_post_types',
		'sections',
		'group_by_section',
		'show_excerpts',
		'excerpt_length',
		'max_items_per_section',
		'curated_links',
	);

	/**
	 * Creates a configuration. Callers should normally use {@see self::from_input()};
	 * this constructor performs no validation, matching the codebase's other
	 * domain value objects.
	 *
	 * @param string      $site_url              Canonical absolute site origin, used for same-site link checks.
	 * @param string      $site_title            Document `# ` title.
	 * @param string      $site_summary          Document `> ` one-sentence summary.
	 * @param string|null $introduction          Optional introduction paragraph.
	 * @param array       $enabled_post_types    Post types eligible for selection.
	 * @param array       $sections              Ordered section key/label pairs.
	 * @param bool        $group_by_section      Whether entries group by their own section, or collapse into one.
	 * @param bool        $show_excerpts         Whether excerpts are ever emitted.
	 * @param int         $excerpt_length        Configured excerpt character limit.
	 * @param int         $max_items_per_section Configured per-section item limit.
	 * @param array       $curated_links         Optional same-site curated links.
	 * @phpstan-param list<string> $enabled_post_types
	 * @phpstan-param list<SectionConfig> $sections
	 * @phpstan-param list<CuratedLinkConfig> $curated_links
	 */
	public function __construct(
		public string $site_url,
		public string $site_title,
		public string $site_summary,
		public ?string $introduction,
		public array $enabled_post_types,
		public array $sections,
		public bool $group_by_section,
		public bool $show_excerpts,
		public int $excerpt_length,
		public int $max_items_per_section,
		public array $curated_links,
	) {
	}

	/**
	 * Builds a validated configuration from untrusted Ability input.
	 *
	 * @param array<string, mixed> $input Raw input.
	 * @return self
	 * @throws InvalidArgumentException When input is malformed or out of bounds.
	 */
	public static function from_input( array $input ): self {
		foreach ( array_keys( $input ) as $key ) {
			if ( ! in_array( $key, self::ALLOWED_KEYS, true ) ) {
				throw new InvalidArgumentException( 'Llms configuration input contains an unsupported field.' );
			}
		}

		$site_url = $input['site_url'] ?? null;
		if ( ! is_string( $site_url ) || self::MAX_SITE_URL_LENGTH < strlen( $site_url ) || null === self::parse_absolute_http_url( $site_url ) ) {
			throw new InvalidArgumentException( 'site_url must be an absolute HTTP URL.' );
		}

		$site_title   = self::single_line_text( $input, 'site_title', self::MAX_SITE_TITLE_LENGTH, true );
		$site_summary = self::single_line_text( $input, 'site_summary', self::MAX_SITE_SUMMARY_LENGTH, true );
		$introduction = self::optional_introduction( $input );

		$enabled_post_types = self::post_types( $input );
		$sections           = self::sections( $input );

		$group_by_section = $input['group_by_section'] ?? null;
		if ( ! is_bool( $group_by_section ) ) {
			throw new InvalidArgumentException( 'group_by_section must be boolean.' );
		}

		$show_excerpts = $input['show_excerpts'] ?? null;
		if ( ! is_bool( $show_excerpts ) ) {
			throw new InvalidArgumentException( 'show_excerpts must be boolean.' );
		}

		$excerpt_length        = self::integer( $input['excerpt_length'] ?? null, 1, self::MAX_EXCERPT_LENGTH, 'excerpt_length' );
		$max_items_per_section = self::integer( $input['max_items_per_section'] ?? null, 1, self::MAX_ITEMS_PER_SECTION, 'max_items_per_section' );

		$curated_links = self::curated_links( $input, $site_url, $sections );

		return new self(
			$site_url,
			$site_title,
			$site_summary,
			$introduction,
			$enabled_post_types,
			$sections,
			$group_by_section,
			$show_excerpts,
			$excerpt_length,
			$max_items_per_section,
			$curated_links,
		);
	}

	/**
	 * Serializes the wire document `from_input()` accepts back unchanged.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'site_url'              => $this->site_url,
			'site_title'            => $this->site_title,
			'site_summary'          => $this->site_summary,
			'introduction'          => $this->introduction,
			'enabled_post_types'    => $this->enabled_post_types,
			'sections'              => $this->sections,
			'group_by_section'      => $this->group_by_section,
			'show_excerpts'         => $this->show_excerpts,
			'excerpt_length'        => $this->excerpt_length,
			'max_items_per_section' => $this->max_items_per_section,
			'curated_links'         => $this->curated_links,
		);
	}

	/**
	 * Checks whether a candidate URL is a canonical, credential-free, same-origin
	 * absolute HTTP(S) URL, and free of characters that could break out of
	 * Markdown link syntax.
	 *
	 * @param string $candidate_url Candidate URL.
	 * @param string $site_url      Canonical site origin to compare against.
	 * @return bool
	 */
	public static function is_same_site_absolute_url( string $candidate_url, string $site_url ): bool {
		$site      = self::parse_absolute_http_url( $site_url );
		$candidate = self::parse_absolute_http_url( $candidate_url );
		if ( null === $site || null === $candidate ) {
			return false;
		}

		if ( isset( $candidate['user'] ) || isset( $candidate['pass'] ) ) {
			return false;
		}

		return strtolower( $site['scheme'] ) === strtolower( $candidate['scheme'] )
			&& self::normalize_host( $site['host'] ) === self::normalize_host( $candidate['host'] )
			&& self::effective_port( $site ) === self::effective_port( $candidate );
	}

	/**
	 * Validates the required single-line site title or summary.
	 *
	 * @param array<string, mixed> $input      Raw input.
	 * @param string               $key        Field name.
	 * @param int                  $max_length Maximum character count.
	 * @param bool                 $required   Whether an empty value is rejected.
	 * @throws InvalidArgumentException When the field is missing, invalid, or too long.
	 */
	private static function single_line_text( array $input, string $key, int $max_length, bool $required ): string {
		$value = $input[ $key ] ?? null;
		if ( ! is_string( $value ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- structured field name, not rendered output.
			throw new InvalidArgumentException( "{$key} must be a string." );
		}

		$sanitized = self::sanitize_single_line( $value );
		if ( $required && '' === $sanitized ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- structured field name, not rendered output.
			throw new InvalidArgumentException( "{$key} must not be empty." );
		}
		if ( $max_length < mb_strlen( $sanitized ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- structured field name, not rendered output.
			throw new InvalidArgumentException( "{$key} exceeds its length limit." );
		}

		return $sanitized;
	}

	/**
	 * Validates the optional introduction paragraph. Unlike single-line fields,
	 * newlines are permitted since this is prose, not a heading or list item.
	 *
	 * @param array<string, mixed> $input Raw input.
	 * @return string|null
	 * @throws InvalidArgumentException When present but invalid or too long.
	 */
	private static function optional_introduction( array $input ): ?string {
		if ( ! array_key_exists( 'introduction', $input ) || null === $input['introduction'] ) {
			return null;
		}

		$value = $input['introduction'];
		if ( ! is_string( $value ) || ! mb_check_encoding( $value, 'UTF-8' ) ) {
			throw new InvalidArgumentException( 'introduction must be a valid UTF-8 string.' );
		}

		$value = str_replace( array( "\r\n", "\r" ), "\n", $value );
		$value = preg_replace( '/[\x00-\x09\x0B-\x1F\x7F]/u', '', $value );
		$value = trim( (string) $value );

		if ( '' === $value ) {
			return null;
		}
		if ( self::MAX_INTRODUCTION_LENGTH < mb_strlen( $value ) ) {
			throw new InvalidArgumentException( 'introduction exceeds its length limit.' );
		}

		return $value;
	}

	/**
	 * Validates the eligible post-type slugs.
	 *
	 * @param array<string, mixed> $input Raw input.
	 * @return list<string>
	 * @throws InvalidArgumentException When the list is malformed, empty, or too large.
	 */
	private static function post_types( array $input ): array {
		$value = $input['enabled_post_types'] ?? null;
		if ( ! is_array( $value ) || ! array_is_list( $value ) || array() === $value || self::MAX_POST_TYPES < count( $value ) ) {
			throw new InvalidArgumentException( 'enabled_post_types must be a non-empty, bounded list.' );
		}

		$post_types = array();
		foreach ( $value as $post_type ) {
			if ( ! is_string( $post_type ) || 1 !== preg_match( '/^[a-z0-9_-]{1,' . self::MAX_POST_TYPE_LENGTH . '}$/', $post_type ) ) {
				throw new InvalidArgumentException( 'enabled_post_types contains an invalid post type.' );
			}
			$post_types[] = $post_type;
		}

		return array_values( array_unique( $post_types ) );
	}

	/**
	 * Validates the ordered section key/label list.
	 *
	 * @param array<string, mixed> $input Raw input.
	 * @return list<array{key: string, label: string}>
	 * @throws InvalidArgumentException When the list is malformed, empty, too large, or has duplicate keys.
	 */
	private static function sections( array $input ): array {
		$value = $input['sections'] ?? null;
		if ( ! is_array( $value ) || ! array_is_list( $value ) || array() === $value || self::MAX_SECTIONS < count( $value ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- bound constant, not rendered output.
			throw new InvalidArgumentException( 'sections must be a non-empty list of at most ' . self::MAX_SECTIONS . ' entries.' );
		}

		$sections = array();
		$seen     = array();
		foreach ( $value as $section ) {
			if ( ! is_array( $section ) || array() !== array_diff( array_keys( $section ), array( 'key', 'label' ) ) ) {
				throw new InvalidArgumentException( 'A section entry is invalid.' );
			}

			$key = $section['key'] ?? null;
			if ( ! is_string( $key ) || 1 !== preg_match( '/^[a-z0-9_-]{1,' . self::MAX_SECTION_KEY_LENGTH . '}$/', $key ) ) {
				throw new InvalidArgumentException( 'A section key is invalid.' );
			}
			if ( isset( $seen[ $key ] ) ) {
				throw new InvalidArgumentException( 'Section keys must be unique.' );
			}
			$seen[ $key ] = true;

			$label = $section['label'] ?? null;
			if ( ! is_string( $label ) ) {
				throw new InvalidArgumentException( 'A section label is invalid.' );
			}
			$label = self::sanitize_single_line( $label );
			if ( '' === $label || self::MAX_SECTION_LABEL_LENGTH < mb_strlen( $label ) ) {
				throw new InvalidArgumentException( 'A section label is invalid.' );
			}

			$sections[] = array(
				'key'   => $key,
				'label' => $label,
			);
		}

		return $sections;
	}

	/**
	 * Validates the optional curated-link list.
	 *
	 * @param array<string, mixed>                    $input    Raw input.
	 * @param string                                  $site_url Validated site origin.
	 * @param list<array{key: string, label: string}> $sections Already-validated section list.
	 * @return list<array{title: string, url: string, section: string|null}>
	 * @throws InvalidArgumentException When the list is malformed, too large, or references an unknown section.
	 */
	private static function curated_links( array $input, string $site_url, array $sections ): array {
		if ( ! array_key_exists( 'curated_links', $input ) ) {
			return array();
		}

		$value = $input['curated_links'];
		if ( ! is_array( $value ) || ! array_is_list( $value ) || self::MAX_CURATED_LINKS < count( $value ) ) {
			throw new InvalidArgumentException( 'curated_links must be a bounded list.' );
		}

		$known_keys = array_column( $sections, 'key' );
		$links      = array();
		foreach ( $value as $link ) {
			if ( ! is_array( $link ) || array() !== array_diff( array_keys( $link ), array( 'title', 'url', 'section' ) ) ) {
				throw new InvalidArgumentException( 'A curated link is invalid.' );
			}

			$title = $link['title'] ?? null;
			if ( ! is_string( $title ) ) {
				throw new InvalidArgumentException( 'A curated link title is invalid.' );
			}
			$title = self::sanitize_single_line( $title );
			if ( '' === $title || self::MAX_CURATED_TITLE_LENGTH < mb_strlen( $title ) ) {
				throw new InvalidArgumentException( 'A curated link title is invalid.' );
			}

			$url = $link['url'] ?? null;
			if ( ! is_string( $url ) || ! self::is_same_site_absolute_url( $url, $site_url ) ) {
				throw new InvalidArgumentException( 'A curated link URL must be a canonical same-site absolute URL.' );
			}

			$section = array_key_exists( 'section', $link ) ? $link['section'] : null;
			if ( null !== $section && ( ! is_string( $section ) || ! in_array( $section, $known_keys, true ) ) ) {
				throw new InvalidArgumentException( 'A curated link section must match a configured section key.' );
			}

			$links[] = array(
				'title'   => $title,
				'url'     => $url,
				'section' => $section,
			);
		}

		return $links;
	}

	/**
	 * Validates a bounded integer field.
	 *
	 * @param mixed  $value   Candidate value.
	 * @param int    $minimum Lower bound.
	 * @param int    $maximum Upper bound.
	 * @param string $field   Field name for the error message.
	 * @return int
	 * @throws InvalidArgumentException When invalid or out of range.
	 */
	private static function integer( mixed $value, int $minimum, int $maximum, string $field ): int {
		if ( ! is_int( $value ) || $value < $minimum || $value > $maximum ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- structured field name and bound constants, not rendered output.
			throw new InvalidArgumentException( "{$field} must be an integer between {$minimum} and {$maximum}." );
		}

		return $value;
	}

	/**
	 * Normalizes a single-line text field: valid UTF-8 required, C0 control
	 * characters (including newlines) removed, internal whitespace collapsed.
	 *
	 * @param string $value Raw string.
	 * @return string
	 * @throws InvalidArgumentException When the value is not valid UTF-8.
	 */
	private static function sanitize_single_line( string $value ): string {
		if ( ! mb_check_encoding( $value, 'UTF-8' ) ) {
			throw new InvalidArgumentException( 'A text field must be valid UTF-8.' );
		}

		$value = preg_replace( '/[\x00-\x1F\x7F]/u', ' ', $value );
		$value = preg_replace( '/\s+/u', ' ', (string) $value );

		return trim( (string) $value );
	}

	/**
	 * Parses one absolute HTTP(S) URL conservatively, rejecting characters that
	 * could break out of Markdown link syntax.
	 *
	 * @param string $url URL input.
	 * @return array<string, mixed>|null
	 * @phpstan-return ParsedUrl|null
	 */
	private static function parse_absolute_http_url( string $url ): ?array {
		$url = trim( $url );
		if (
			'' === $url
			|| self::MAX_SITE_URL_LENGTH < strlen( $url )
			|| str_contains( $url, '\\' )
			|| 1 === preg_match( '/[\x00-\x20\x7f()<>"\']/', $url )
		) {
			return null;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- domain code must not depend on WordPress being loaded.
		$parts = parse_url( $url );
		if (
			false === $parts
			|| ! isset( $parts['scheme'], $parts['host'] )
			|| ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true )
			|| false === filter_var( $url, FILTER_VALIDATE_URL )
		) {
			return null;
		}

		$parsed = array(
			'scheme' => $parts['scheme'],
			'host'   => $parts['host'],
		);
		foreach ( array( 'path', 'query', 'user', 'pass' ) as $key ) {
			if ( isset( $parts[ $key ] ) ) {
				$parsed[ $key ] = $parts[ $key ];
			}
		}
		if ( isset( $parts['port'] ) ) {
			$parsed['port'] = $parts['port'];
		}

		return $parsed;
	}

	/**
	 * Normalizes a URL host for exact origin comparison.
	 *
	 * @param string $host Host value.
	 * @return string
	 */
	private static function normalize_host( string $host ): string {
		return strtolower( rtrim( $host, '.' ) );
	}

	/**
	 * Resolves an explicit or default port.
	 *
	 * @param array<string, mixed> $parts Parsed URL.
	 * @return int
	 * @phpstan-param ParsedUrl $parts
	 */
	private static function effective_port( array $parts ): int {
		if ( isset( $parts['port'] ) ) {
			return $parts['port'];
		}

		return 'https' === strtolower( $parts['scheme'] ) ? 443 : 80;
	}
}

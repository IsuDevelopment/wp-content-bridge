<?php
/**
 * Safe initial llms.txt configuration factory.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Application\Llms;

use InvalidArgumentException;
use IsuDev\WPContentBridge\Domain\ContentAccess\ContentTypeDefinition;
use IsuDev\WPContentBridge\Domain\Llms\LlmsConfig;

/**
 * Builds the conservative first configuration used by the wp-admin workflow.
 *
 * The factory knows nothing about WordPress or Kormas. The adapter supplies
 * site-owned title/description values and only content types already permitted
 * by the site's read policy; this class merely validates and bounds them using
 * the same domain object every Ability write uses.
 */
final class LlmsInitialConfigFactory {

	private const DEFAULT_EXCERPT_LENGTH        = 180;
	private const DEFAULT_ITEMS_PER_SECTION     = 50;
	private const FALLBACK_DOCUMENT_DESCRIPTION = 'Published content index.';

	/**
	 * Builds a validated initial configuration.
	 *
	 * @param string $site_url     Canonical home URL.
	 * @param string $site_title   WordPress site name.
	 * @param string $site_summary WordPress site tagline.
	 * @param array  $content_types Read-policy-approved content types.
	 * @phpstan-param list<ContentTypeDefinition> $content_types
	 * @return LlmsConfig
	 * @throws InvalidArgumentException When no public content type can be configured.
	 */
	public function create( string $site_url, string $site_title, string $site_summary, array $content_types ): LlmsConfig {
		$sections   = array();
		$post_types = array();

		foreach ( $content_types as $definition ) {
			if ( ! $definition->is_public || isset( $sections[ $definition->name ] ) ) {
				continue;
			}

			$label = $this->single_line( $definition->label, 100 );
			if ( '' === $label ) {
				$label = $definition->name;
			}

			$post_types[]                  = $definition->name;
			$sections[ $definition->name ] = array(
				'key'   => $definition->name,
				'label' => $label,
			);

			if ( LlmsConfig::MAX_SECTIONS === count( $post_types ) ) {
				break;
			}
		}

		if ( array() === $post_types ) {
			throw new InvalidArgumentException( 'No public, read-enabled content type is available for the initial llms.txt snapshot.' );
		}

		$title = $this->single_line( $site_title, 200 );
		if ( '' === $title ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Pure application service; the URL is validated immediately by LlmsConfig and WordPress is intentionally not a dependency.
			$host  = parse_url( $site_url, PHP_URL_HOST );
			$title = is_string( $host ) && '' !== $host ? $host : 'Website';
		}

		$summary = $this->single_line( $site_summary, 300 );
		if ( '' === $summary ) {
			$summary = self::FALLBACK_DOCUMENT_DESCRIPTION;
		}

		return LlmsConfig::from_input(
			array(
				'site_title'            => $title,
				'site_summary'          => $summary,
				'introduction'          => null,
				'enabled_post_types'    => $post_types,
				'sections'              => array_values( $sections ),
				'group_by_section'      => true,
				'show_excerpts'         => true,
				'excerpt_length'        => self::DEFAULT_EXCERPT_LENGTH,
				'max_items_per_section' => self::DEFAULT_ITEMS_PER_SECTION,
				'curated_links'         => array(),
			),
			$site_url
		);
	}

	/**
	 * Normalizes one site-owned single-line value before bounding it.
	 *
	 * @param string $value      Raw site setting.
	 * @param int    $max_length Maximum characters.
	 * @return string
	 */
	private function single_line( string $value, int $max_length ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Pure application service shared outside WordPress; final validation is performed by LlmsConfig.
		$value = strip_tags( $value );
		$value = preg_replace( '/\s+/u', ' ', $value );

		return mb_substr( trim( (string) $value ), 0, $max_length );
	}
}

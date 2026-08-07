<?php
/**
 * WordPress llms.txt ownership-conflict detection adapter.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Infrastructure\WordPress;

use Closure;
use IsuDev\WPContentBridge\Application\Llms\LlmsOwnershipInspector;
use IsuDev\WPContentBridge\Domain\Llms\LlmsDocumentBuilder;
use IsuDev\WPContentBridge\Domain\Llms\LlmsOwnershipConflict;
use IsuDev\WPContentBridge\Domain\Llms\LlmsOwnershipOwner;
use IsuDev\WPContentBridge\Domain\Llms\LlmsOwnershipState;
use IsuDev\WPContentBridge\Domain\Llms\LlmsPublicVerification;
use Throwable;

/**
 * Reads local WordPress signals, and optionally the public endpoint, to
 * report `/llms.txt` ownership without ever resolving a conflict.
 *
 * Every probe defaults to a real WordPress/filesystem read but accepts an
 * injected closure instead, so tests can supply fakes without touching the
 * filesystem, an option table, or the network. Nothing this class reads is
 * ever placed on the returned state except as a plain boolean or enum value:
 * the physical-artifact check reports existence only, never a path, and the
 * Yoast read touches exactly one key of the `wpseo` option and discards the
 * rest before it leaves this class.
 */
final readonly class WordPressLlmsOwnershipInspector implements LlmsOwnershipInspector {

	/**
	 * The only filename this class checks for at the web root. `llms-full.txt`
	 * and `llms-docs` are out of this slice's scope.
	 *
	 * @var string
	 */
	private const ARTIFACT_FILENAME = 'llms.txt';

	/**
	 * Non-autoloaded bridge publication flag; see `WordPressLlmsArtifactStore`
	 * for the config/artifact options this class deliberately does not touch.
	 *
	 * @var string
	 */
	public const BRIDGE_ENABLED_OPTION = 'wpcb_llms_enabled';

	private const YOAST_OPTION   = 'wpseo';
	private const YOAST_FLAG_KEY = 'enable_llms_txt';

	private const FETCH_TIMEOUT = 3;

	/**
	 * Bounded response-body read for the public verification request: the
	 * generator's own document ceiling plus slack for incidental whitespace.
	 *
	 * @var int
	 */
	private const FETCH_MAX_BODY_BYTES = LlmsDocumentBuilder::MAX_DOCUMENT_BYTES + 4096;

	/**
	 * Creates the inspector.
	 *
	 * @param Closure|null $physical_artifact_probe Optional override for the physical-artifact check.
	 * @param Closure|null $yoast_flag_reader        Optional override for the Yoast flag read.
	 * @param Closure|null $bridge_enabled_reader    Optional override for the bridge publication-flag read.
	 * @param Closure|null $fetcher                  Optional override for the public verification request.
	 * @phpstan-param (Closure(): bool)|null $physical_artifact_probe
	 * @phpstan-param (Closure(): bool)|null $yoast_flag_reader
	 * @phpstan-param (Closure(): bool)|null $bridge_enabled_reader
	 * @phpstan-param (Closure(string): (array{code: int, body: string}|null))|null $fetcher
	 */
	public function __construct(
		private ?Closure $physical_artifact_probe = null,
		private ?Closure $yoast_flag_reader = null,
		private ?Closure $bridge_enabled_reader = null,
		private ?Closure $fetcher = null,
	) {
	}

	/**
	 * Detects ownership from local signals only. Performs no network request.
	 *
	 * @return LlmsOwnershipState
	 */
	public function inspect(): LlmsOwnershipState {
		return $this->build_state( LlmsPublicVerification::UNKNOWN );
	}

	/**
	 * Detects ownership from local signals, then confirms it with a bounded,
	 * fail-soft, same-site `GET` of the public `/llms.txt` path.
	 *
	 * @param string      $site_url              Canonical absolute site origin to probe.
	 * @param string|null $expected_content_hash Stored artifact content hash to compare against, if any.
	 * @return LlmsOwnershipState
	 */
	public function inspect_with_verification( string $site_url, ?string $expected_content_hash ): LlmsOwnershipState {
		return $this->build_state( $this->verify_public_endpoint( $site_url, $expected_content_hash ) );
	}

	/**
	 * Assembles the state from the three local signals plus a given public
	 * verification result.
	 *
	 * @param LlmsPublicVerification $public_verification Already-resolved public verification result.
	 * @return LlmsOwnershipState
	 */
	private function build_state( LlmsPublicVerification $public_verification ): LlmsOwnershipState {
		$physical_artifact_exists   = $this->probe_physical_artifact();
		$yoast_llms_txt_enabled     = $this->read_yoast_flag();
		$bridge_publication_enabled = $this->read_bridge_enabled();

		if ( $yoast_llms_txt_enabled ) {
			$owner    = LlmsOwnershipOwner::YOAST;
			$conflict = LlmsOwnershipConflict::YOAST_LLMS_TXT_ENABLED;
			$action   = 'Yoast SEO\'s llms.txt feature is enabled and can take precedence over this plugin\'s endpoint. '
				. 'In Yoast SEO, go to Settings -> Site features -> AI tools -> llms.txt, disable it, save, and confirm '
				. 'its file is gone before enabling publication here.';
		} elseif ( $physical_artifact_exists ) {
			$owner    = LlmsOwnershipOwner::THIRD_PARTY;
			$conflict = LlmsOwnershipConflict::PHYSICAL_ARTIFACT_PRESENT;
			$action   = 'A file already answers this site\'s llms.txt request outside WordPress, and it will keep winning '
				. 'over this plugin\'s endpoint. Remove or rename it as a deployment step, then verify removal before '
				. 'enabling publication here.';
		} elseif ( $bridge_publication_enabled ) {
			$owner    = LlmsOwnershipOwner::BRIDGE;
			$conflict = null;
			$action   = 'No ownership conflict was detected. Publication is enabled, and this plugin\'s endpoint is expected '
				. 'to be the one serving this site\'s llms.txt request.';
		} else {
			$owner    = LlmsOwnershipOwner::NONE;
			$conflict = null;
			$action   = 'No ownership conflict was detected, and publication is currently disabled. Enable it here when ready.';
		}

		return new LlmsOwnershipState(
			$owner,
			$physical_artifact_exists,
			$yoast_llms_txt_enabled,
			$bridge_publication_enabled,
			$public_verification,
			$conflict,
			$action
		);
	}

	/**
	 * Checks whether a physical `llms.txt` exists at the web root, reporting
	 * existence only.
	 *
	 * The file that shadows the virtual endpoint is the one the web server
	 * resolves for the **home** URL, which is not `ABSPATH` whenever WordPress
	 * lives in a subdirectory — a Bedrock layout, LocalWP's default, or any
	 * install where `WP_SITEURL` differs from `WP_HOME`. Probing `ABSPATH`
	 * there returns false while a real file wins routing, so the blocking
	 * ownership gate would report "no conflict" and the bridge would claim an
	 * artifact is public when it is not.
	 *
	 * Core's `get_home_path()` is not usable here: it derives the path from
	 * `$_SERVER['SCRIPT_FILENAME']` and returns `/` under WP-CLI and cron,
	 * which are exactly the contexts regeneration runs in. Probing `/` would
	 * be worse than the bug being fixed. The directory is instead derived from
	 * how many path segments `site_url()` adds to `home_url()`, walking that
	 * many levels up from `ABSPATH`.
	 *
	 * @return bool
	 */
	private function probe_physical_artifact(): bool {
		if ( null !== $this->physical_artifact_probe ) {
			return ( $this->physical_artifact_probe )();
		}
		if ( ! defined( 'ABSPATH' ) ) {
			return false;
		}

		return is_file( $this->web_root() . self::ARTIFACT_FILENAME );
	}

	/**
	 * Resolves the trailing-slashed directory that serves the home URL.
	 *
	 * @return string
	 */
	private function web_root(): string {
		$root = rtrim( ABSPATH, '/\\' );

		if ( ! function_exists( 'home_url' ) || ! function_exists( 'site_url' ) ) {
			return $root . '/';
		}

		$home = rtrim( (string) home_url(), '/' );
		$site = rtrim( (string) site_url(), '/' );

		if ( $home === $site || ! str_starts_with( $site, $home . '/' ) ) {
			return $root . '/';
		}

		$segments = array_filter( explode( '/', trim( substr( $site, strlen( $home ) ), '/' ) ) );

		foreach ( $segments as $ignored ) {
			$parent = dirname( $root );
			if ( $parent === $root ) {
				break;
			}
			$root = $parent;
		}

		return $root . '/';
	}

	/**
	 * Reads exactly one key of Yoast's `wpseo` option and discards the rest.
	 *
	 * @return bool
	 */
	private function read_yoast_flag(): bool {
		if ( null !== $this->yoast_flag_reader ) {
			return ( $this->yoast_flag_reader )();
		}
		if ( ! function_exists( 'YoastSEO' ) || ! defined( 'WPSEO_VERSION' ) || ! function_exists( 'get_option' ) ) {
			return false;
		}

		$wpseo = get_option( self::YOAST_OPTION, false );
		if ( ! is_array( $wpseo ) || ! array_key_exists( self::YOAST_FLAG_KEY, $wpseo ) ) {
			return false;
		}

		return (bool) $wpseo[ self::YOAST_FLAG_KEY ];
	}

	/**
	 * Reads the bridge's own publication flag.
	 *
	 * @return bool
	 */
	private function read_bridge_enabled(): bool {
		if ( null !== $this->bridge_enabled_reader ) {
			return ( $this->bridge_enabled_reader )();
		}
		if ( ! function_exists( 'get_option' ) ) {
			return false;
		}

		return (bool) get_option( self::BRIDGE_ENABLED_OPTION, false );
	}

	/**
	 * Performs the bounded, fail-soft public verification request.
	 *
	 * @param string      $site_url              Canonical absolute site origin to probe.
	 * @param string|null $expected_content_hash Stored artifact content hash to compare against, if any.
	 * @return LlmsPublicVerification
	 */
	private function verify_public_endpoint( string $site_url, ?string $expected_content_hash ): LlmsPublicVerification {
		$site_url = trim( $site_url );
		if ( '' === $site_url ) {
			return LlmsPublicVerification::UNKNOWN;
		}

		try {
			$response = $this->fetch( rtrim( $site_url, '/' ) . '/' . self::ARTIFACT_FILENAME );
		} catch ( Throwable ) {
			return LlmsPublicVerification::UNKNOWN;
		}

		if ( null === $response ) {
			return LlmsPublicVerification::UNKNOWN;
		}
		if ( 404 === $response['code'] ) {
			return LlmsPublicVerification::NOT_FOUND;
		}
		if ( 200 !== $response['code'] ) {
			return LlmsPublicVerification::UNKNOWN;
		}
		if ( null !== $expected_content_hash && hash( 'sha256', $response['body'] ) === $expected_content_hash ) {
			return LlmsPublicVerification::SERVED_BY_BRIDGE;
		}

		return LlmsPublicVerification::SERVED_BY_OTHER;
	}

	/**
	 * Performs the request through an injected fetcher or `wp_safe_remote_get()`.
	 *
	 * @param string $url Same-site URL to request.
	 * @return array{code: int, body: string}|null
	 */
	private function fetch( string $url ): ?array {
		if ( null !== $this->fetcher ) {
			$result = ( $this->fetcher )( $url );

			return is_array( $result ) ? $result : null;
		}

		if ( ! function_exists( 'wp_safe_remote_get' ) ) {
			return null;
		}

		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'     => self::FETCH_TIMEOUT,
				'redirection' => 0,
			)
		);
		if ( function_exists( 'is_wp_error' ) && is_wp_error( $response ) ) {
			return null;
		}

		$body = (string) wp_remote_retrieve_body( $response );
		if ( strlen( $body ) > self::FETCH_MAX_BODY_BYTES ) {
			$body = substr( $body, 0, self::FETCH_MAX_BODY_BYTES );
		}

		return array(
			'code' => (int) wp_remote_retrieve_response_code( $response ),
			'body' => $body,
		);
	}
}

<?php
/**
 * Analysis-only declarations for the Yoast SEO Premium redirect surface.
 *
 * Premium publishes no compatibility promise for these classes, so this file
 * exists to type the calls, not to bless them. It mirrors Premium 28.0 as read
 * from source on 2026-09-01 (ADR 0026 amendment). The runtime probes in
 * `YoastPremiumRedirectProvider::is_available()` are the real gate: a rename
 * in a later Premium release must make the provider report itself absent, and
 * this stub must never be trusted to say otherwise.
 */

namespace {

	/**
	 * Premium's plugin version, defined in its main plugin file.
	 */
	define( 'WPSEO_PREMIUM_VERSION', '28.0' );

	/**
	 * Analysis-only declaration of Premium's redirect format tokens.
	 */
	class WPSEO_Redirect_Formats {

		/**
		 * Exact-match redirect format.
		 *
		 * @var string
		 */
		public const PLAIN = 'plain';

		/**
		 * Regular-expression redirect format, outside this plugin's contract.
		 *
		 * @var string
		 */
		public const REGEX = 'regex';
	}

	/**
	 * Analysis-only declaration of one stored Premium redirect.
	 */
	class WPSEO_Redirect {

		/**
		 * Creates a redirect value.
		 *
		 * @param string $origin Origin, stored without surrounding slashes for the plain format.
		 * @param string $target Target; empty for a Gone redirect.
		 * @param int    $type   HTTP status code.
		 * @param string $format One of the `WPSEO_Redirect_Formats` tokens.
		 */
		public function __construct( string $origin, string $target = '', int $type = 301, string $format = 'plain' ) {
		}

		/**
		 * Returns the stored origin.
		 *
		 * @return string
		 */
		public function get_origin(): string {
		}

		/**
		 * Returns the stored target.
		 *
		 * @return string
		 */
		public function get_target(): string {
		}

		/**
		 * Returns the stored HTTP status code.
		 *
		 * @return int
		 */
		public function get_type(): int {
		}

		/**
		 * Returns the stored format token.
		 *
		 * @return string
		 */
		public function get_format(): string {
		}
	}

	/**
	 * Analysis-only declaration of Premium's redirect manager.
	 *
	 * Note what this class does *not* declare, because both absences are
	 * load-bearing: it performs no capability check, and `create_redirect()`
	 * persists by calling `save_redirects()`, which also rewrites the derived
	 * export options the front-end matcher reads.
	 */
	class WPSEO_Redirect_Manager {

		/**
		 * Creates a manager scoped to one redirect format.
		 *
		 * @param string $redirect_format One of the `WPSEO_Redirect_Formats` tokens.
		 */
		public function __construct( string $redirect_format = 'plain' ) {
		}

		/**
		 * Returns the stored redirect for an origin, or `false` when absent.
		 *
		 * @param string $origin Origin to look up.
		 * @return WPSEO_Redirect|false
		 */
		public function get_redirect( string $origin ) {
		}

		/**
		 * Adds a redirect and persists the option plus derived exports.
		 *
		 * @param WPSEO_Redirect $redirect Redirect to add.
		 * @return bool
		 */
		public function create_redirect( WPSEO_Redirect $redirect ): bool {
		}

		/**
		 * Persists the redirect option and rewrites the derived exports.
		 *
		 * @return void
		 */
		public function save_redirects(): void {
		}
	}
}

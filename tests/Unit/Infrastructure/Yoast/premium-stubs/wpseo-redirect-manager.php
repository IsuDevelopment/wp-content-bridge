<?php
/**
 * Runtime stand-in for Yoast Premium's redirect manager.
 *
 * @package IsuDev\WPContentBridge\Tests
 */

declare(strict_types=1);

if ( ! class_exists( 'WPSEO_Redirect_Manager' ) ) {
	/**
	 * Stand-in for Premium's redirect manager over an in-memory store.
	 */
	class WPSEO_Redirect_Manager {

		/**
		 * Stored redirects for the active test, keyed by stored origin.
		 *
		 * @var array<string, WPSEO_Redirect>
		 */
		public static array $stored = array();

		/**
		 * Whether `create_redirect()` should report failure.
		 *
		 * @var bool
		 */
		public static bool $refuse_create = false;

		/**
		 * Whether update and delete should report failure.
		 *
		 * @var bool
		 */
		public static bool $refuse_write = false;

		/**
		 * How many times `save_redirects()` ran, so a test can prove a write
		 * regenerated the derived export options.
		 *
		 * @var int
		 */
		public static int $saves = 0;

		/**
		 * Creates a manager scoped to one format.
		 *
		 * @param string $redirect_format Format token.
		 */
		public function __construct( private string $redirect_format = 'plain' ) {
		}

		/**
		 * Returns the stored redirect for an origin, or false when absent.
		 *
		 * @param string $origin Origin to look up.
		 * @return WPSEO_Redirect|false
		 */
		public function get_redirect( string $origin ) {
			$key = '/' === $origin ? $origin : trim( $origin, '/' );

			return self::$stored[ $key ] ?? false;
		}

		/**
		 * Adds a redirect and persists, as Premium's own create does.
		 *
		 * @param WPSEO_Redirect $redirect Redirect to add.
		 * @return bool
		 */
		public function create_redirect( WPSEO_Redirect $redirect ): bool {
			if ( self::$refuse_create ) {
				return false;
			}

			self::$stored[ $redirect->get_origin() ] = $redirect;
			$this->save_redirects();

			return true;
		}

		/**
		 * Replaces one stored redirect and persists.
		 *
		 * @param WPSEO_Redirect $current_redirect Redirect to replace.
		 * @param WPSEO_Redirect $redirect         Replacement redirect.
		 * @return bool
		 */
		public function update_redirect( WPSEO_Redirect $current_redirect, WPSEO_Redirect $redirect ): bool {
			if ( self::$refuse_write ) {
				return false;
			}

			unset( self::$stored[ $current_redirect->get_origin() ] );
			self::$stored[ $redirect->get_origin() ] = $redirect;
			$this->save_redirects();

			return true;
		}

		/**
		 * Removes redirects and persists. Like Premium's own method, reports
		 * whether *any* removal happened, not whether a specific one did.
		 *
		 * @param array<int, WPSEO_Redirect> $delete_redirects Redirects to remove.
		 * @return bool
		 */
		public function delete_redirects( array $delete_redirects ): bool {
			$deleted = false;

			foreach ( $delete_redirects as $redirect ) {
				if ( isset( self::$stored[ $redirect->get_origin() ] ) && ! self::$refuse_write ) {
					unset( self::$stored[ $redirect->get_origin() ] );
					$deleted = true;
				}
			}

			if ( $deleted ) {
				$this->save_redirects();
			}

			return $deleted;
		}

		/**
		 * Records a persistence cycle.
		 *
		 * @return void
		 */
		public function save_redirects(): void {
			++self::$saves;
		}
	}
}

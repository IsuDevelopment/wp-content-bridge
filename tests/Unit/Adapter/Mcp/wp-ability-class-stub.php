<?php
/**
 * Minimal `WP_Ability` stand-in for the MCP projection tests.
 *
 * The unit suite boots Composer only, so the two accessors discovery reads are
 * declared here rather than pulled from WordPress.
 *
 * @package IsuDev\WPContentBridge\Tests
 */

declare(strict_types=1);

if ( ! class_exists( 'WP_Ability' ) ) {
	/**
	 * Stand-in exposing only the accessors discovery reads.
	 */
	class WP_Ability {

		/**
		 * Registered ability name.
		 *
		 * @var string
		 */
		private string $name;

		/**
		 * Registered category slug.
		 *
		 * @var string
		 */
		private string $category;

		/**
		 * Records the arranged name and category.
		 *
		 * @param string $name     Ability name.
		 * @param string $category Category slug.
		 */
		public function __construct( string $name, string $category ) {
			$this->name     = $name;
			$this->category = $category;
		}

		/**
		 * Returns the ability name.
		 *
		 * @return string
		 */
		public function get_name(): string {
			return $this->name;
		}

		/**
		 * Returns the category slug.
		 *
		 * @return string
		 */
		public function get_category(): string {
			return $this->category;
		}
	}
}

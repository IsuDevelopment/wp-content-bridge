<?php
/**
 * Minimal analysis-only declaration for Yoast's documented global surface.
 */

namespace {

	/**
	 * Analysis proxy for the dynamic Yoast main surface.
	 */
	class WPCB_Yoast_Main_Stub {

		/**
		 * Yoast's dependency container, used to reach stored-data repositories
		 * that the documented Meta surface does not expose.
		 *
		 * @var WPCB_Yoast_Container_Stub
		 */
		public WPCB_Yoast_Container_Stub $classes;

		/**
		 * Returns a documented Yoast surface.
		 *
		 * @param string $name Surface name.
		 * @return object
		 */
		public function __get( string $name ): object {
		}
	}

	/**
	 * Analysis proxy for Yoast's dependency container.
	 */
	class WPCB_Yoast_Container_Stub {

		/**
		 * Resolves one Yoast internal class from the container.
		 *
		 * @template T of object
		 * @param class-string<T> $class Fully qualified Yoast class name.
		 * @return T
		 */
		public function get( string $class ): object {
		}
	}

	/**
	 * Returns Yoast's documented integration surface.
	 *
	 * @return WPCB_Yoast_Main_Stub
	 */
	function YoastSEO(): WPCB_Yoast_Main_Stub {
	}

	/**
	 * Analysis-only declaration for Yoast's editor-meta API.
	 */
	class WPSEO_Meta {

		/**
		 * Writes one registered Yoast editor value.
		 *
		 * @param string $key        Internal key without Yoast's prefix.
		 * @param mixed  $meta_value Value to write.
		 * @param int    $post_id    Target post ID.
		 * @return bool
		 */
		public static function set_value( string $key, mixed $meta_value, int $post_id ): bool {
		}
	}
}

namespace Yoast\WP\SEO\Models {

	/**
	 * Analysis-only declaration of Yoast's stored indexable row.
	 */
	class Indexable {

		/**
		 * Explicit editor `noindex` override; `null` means no override is stored
		 * and the post-type default applies.
		 *
		 * @var bool|null
		 */
		public ?bool $is_robots_noindex;
	}
}

namespace Yoast\WP\SEO\Repositories {

	use Yoast\WP\SEO\Models\Indexable;

	/**
	 * Analysis-only declaration for Yoast's stored-indexable repository, read
	 * from data rather than the rendered Meta presentation.
	 */
	class Indexable_Repository {

		/**
		 * Finds the stored indexable for one object, or null if it has none.
		 *
		 * @param int    $object_id   Object ID.
		 * @param string $object_type Object type, for example `post`.
		 * @return Indexable|null
		 */
		public function find_by_id_and_type( int $object_id, string $object_type ): ?Indexable {
		}
	}
}

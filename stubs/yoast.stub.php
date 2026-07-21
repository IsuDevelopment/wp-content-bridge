<?php
/**
 * Minimal analysis-only declaration for Yoast's documented global surface.
 */

/**
 * Analysis proxy for the dynamic Yoast main surface.
 */
class WPCB_Yoast_Main_Stub {

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

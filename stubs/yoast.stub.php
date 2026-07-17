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

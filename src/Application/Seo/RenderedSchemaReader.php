<?php
/**
 * Rendered schema reader port.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Application\Seo;

use IsuDev\WPContentBridge\Domain\Seo\RenderedGraph;

/**
 * Reads a same-origin page's public JSON-LD graph.
 *
 * Some provider schema (for example Yoast Local multiple-location branch data)
 * is emitted only during a front-end render and is absent from the resolved
 * meta surface. Implementations fetch the public page and return its
 * `application/ld+json` graph so the existing allowlist projector can consume it.
 */
interface RenderedSchemaReader {

	/**
	 * Returns the rendered graph capture outcome for a same-origin URL.
	 *
	 * Implementations must enforce a same-origin constraint, bound the request
	 * and response, and never throw. Failure is reported through the returned
	 * outcome rather than as an empty node list, because a blocked loopback
	 * request and a page that emits no JSON-LD are different facts that need
	 * different responses from the operator. Callers still fall back to the
	 * resolved surface in both cases.
	 *
	 * @param string $url Same-origin, already-authorized public URL.
	 * @return RenderedGraph
	 */
	public function graph_for_url( string $url ): RenderedGraph;
}

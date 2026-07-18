<?php
/**
 * Rendered schema reader port.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Application\Seo;

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
	 * Returns the public JSON-LD graph nodes rendered at a same-origin URL.
	 *
	 * Implementations must enforce a same-origin constraint, bound the request
	 * and response, and never throw. An empty array means no usable graph was
	 * captured; callers fall back to the resolved surface.
	 *
	 * @param string $url Same-origin, already-authorized public URL.
	 * @return list<array<string, mixed>>
	 */
	public function graph_for_url( string $url ): array;
}

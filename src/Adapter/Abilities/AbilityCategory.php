<?php
/**
 * The plugin's single ability category.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Adapter\Abilities;

/**
 * Holds the one category slug every WP Content Bridge ability registers under.
 *
 * This is the canonical source of truth for "which abilities are ours". It is
 * deliberately a shared constant rather than a per-class literal: ADR 0025
 * makes the MCP projection discover its tool set by category at runtime, so a
 * class that drifted onto a different slug would silently drop out of the
 * projection instead of failing loudly.
 */
final class AbilityCategory {

	/**
	 * Registered category slug, matching the `wp-content-bridge/` ability namespace.
	 *
	 * @var string
	 */
	public const SLUG = 'wp-content-bridge';

	/**
	 * Not instantiable.
	 */
	private function __construct() {
	}
}

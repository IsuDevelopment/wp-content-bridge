<?php
/**
 * Runtime stand-in for the installed WordPress/mcp-adapter plugin's stable class, used only
 * by unit tests to prove diagnostics detection without requiring the real plugin.
 *
 * @package IsuDev\WPContentBridge\Tests
 */

declare(strict_types=1);

namespace WP\MCP\Core;

if ( ! class_exists( __NAMESPACE__ . '\McpAdapter' ) ) {
	/**
	 * Minimal stand-in matching the real plugin's class name/namespace (mcp-adapter v0.5.0).
	 */
	final class McpAdapter {
	}
}

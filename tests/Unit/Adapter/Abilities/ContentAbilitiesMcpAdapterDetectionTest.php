<?php
/**
 * MCP adapter detection tests.
 *
 * @package IsuDev\WPContentBridge\Tests
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Tests\Unit\Adapter\Abilities;

require_once __DIR__ . '/mcp-adapter-class-stub.php';

use IsuDev\WPContentBridge\Adapter\Abilities\ContentAbilities;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Confirms diagnostics detects the installed WordPress/mcp-adapter plugin (v0.5.0), which
 * defines neither `WP_MCP_ADAPTER_VERSION` nor `wp_register_mcp_server()`.
 */
final class ContentAbilitiesMcpAdapterDetectionTest extends TestCase {

	/**
	 * Detection succeeds via the adapter's stable `WP\MCP\Core\McpAdapter` class even
	 * though neither legacy detection symbol is present.
	 */
	public function test_detects_adapter_via_stable_core_class(): void {
		$method = new ReflectionMethod( ContentAbilities::class, 'mcp_adapter_active' );
		$method->setAccessible( true );

		self::assertTrue( $method->invoke( null ) );
	}
}

<?php
/**
 * Unit tests for optional Schema Extended discovery.
 *
 * @package IsuDev\WPContentBridge\Tests
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Tests\Unit\Infrastructure\SchemaExtended;

use IsuDev\WPContentBridge\Infrastructure\SchemaExtended\SchemaExtendedServiceSchemaWriter;
use PHPUnit\Framework\TestCase;

/**
 * Verifies that an inactive optional plugin leaves no writable integration.
 */
final class SchemaExtendedServiceSchemaWriterTest extends TestCase {

	/**
	 * Discovery fails closed when WordPress did not load Schema Extended.
	 */
	public function test_is_unavailable_when_standalone_plugin_is_not_loaded(): void {
		self::assertFalse( ( new SchemaExtendedServiceSchemaWriter() )->is_available() );
	}
}

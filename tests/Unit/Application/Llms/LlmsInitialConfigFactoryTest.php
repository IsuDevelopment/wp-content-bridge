<?php
/**
 * Initial llms.txt configuration factory tests.
 *
 * @package IsuDev\WPContentBridge\Tests
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Tests\Unit\Application\Llms;

use InvalidArgumentException;
use IsuDev\WPContentBridge\Application\Llms\LlmsInitialConfigFactory;
use IsuDev\WPContentBridge\Domain\ContentAccess\ContentTypeDefinition;
use PHPUnit\Framework\TestCase;

/**
 * Verifies conservative defaults and public-type filtering.
 */
final class LlmsInitialConfigFactoryTest extends TestCase {

	/**
	 * Site-owned settings and public approved types become a complete config.
	 */
	public function test_builds_complete_configuration_from_site_facts(): void {
		$config = ( new LlmsInitialConfigFactory() )->create(
			'https://example.test/',
			'Example <strong>Site</strong>',
			'A useful site.',
			array(
				new ContentTypeDefinition( 'page', 'Pages', true, true, true ),
				new ContentTypeDefinition( 'private_type', 'Private', false, true, false ),
				new ContentTypeDefinition( 'story', 'Stories', true, true, false ),
			)
		);

		self::assertSame( 'Example Site', $config->site_title );
		self::assertSame( 'A useful site.', $config->site_summary );
		self::assertSame( array( 'page', 'story' ), $config->enabled_post_types );
		self::assertSame( array( 'page', 'story' ), array_column( $config->sections, 'key' ) );
		self::assertSame( 180, $config->excerpt_length );
		self::assertSame( 50, $config->max_items_per_section );
	}

	/**
	 * Empty WordPress identity settings degrade to bounded neutral text.
	 */
	public function test_uses_neutral_fallbacks_for_empty_site_identity(): void {
		$config = ( new LlmsInitialConfigFactory() )->create(
			'https://example.test/',
			'',
			'',
			array( new ContentTypeDefinition( 'page', 'Pages', true, true, true ) )
		);

		self::assertSame( 'example.test', $config->site_title );
		self::assertSame( 'Published content index.', $config->site_summary );
	}

	/**
	 * An initial snapshot cannot silently configure a non-public type.
	 */
	public function test_rejects_when_no_public_type_is_available(): void {
		$this->expectException( InvalidArgumentException::class );

		( new LlmsInitialConfigFactory() )->create(
			'https://example.test/',
			'Example',
			'Summary',
			array( new ContentTypeDefinition( 'internal', 'Internal', false, true, false ) )
		);
	}

	/**
	 * The convenience workflow cannot exceed the domain section limit.
	 */
	public function test_caps_initial_sections_at_domain_limit(): void {
		$content_types = array();
		for ( $index = 1; $index <= 25; $index++ ) {
			$content_types[] = new ContentTypeDefinition( 'type_' . $index, 'Type ' . $index, true, true, false );
		}

		$config = ( new LlmsInitialConfigFactory() )->create(
			'https://example.test/',
			'Example',
			'Summary',
			$content_types
		);

		self::assertCount( 20, $config->enabled_post_types );
		self::assertCount( 20, $config->sections );
	}
}

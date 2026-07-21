<?php
/**
 * Content access manager tests.
 *
 * @package IsuDev\WPContentBridge\Tests
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Tests\Unit\Application\ContentAccess;

use IsuDev\WPContentBridge\Application\ContentAccess\ContentAccessManager;
use IsuDev\WPContentBridge\Application\ContentAccess\ContentAccessSettingsRepository;
use IsuDev\WPContentBridge\Application\ContentAccess\ContentTypeCatalog;
use IsuDev\WPContentBridge\Domain\ContentAccess\ContentOperation;
use IsuDev\WPContentBridge\Domain\ContentAccess\ContentTypeDefinition;
use PHPUnit\Framework\TestCase;

/**
 * Verifies defaults, normalization, and the eligible-type boundary.
 */
final class ContentAccessManagerTest extends TestCase {

	/**
	 * Post/page are readable by default while custom types deny access.
	 */
	public function test_applies_safe_unsaved_defaults(): void {
		$manager = $this->manager( array(), array( 'post', 'page', 'book' ) );

		self::assertTrue( $manager->allows( 'post', ContentOperation::READ ) );
		self::assertTrue( $manager->allows( 'page', ContentOperation::SEARCH ) );
		self::assertFalse( $manager->allows( 'book', ContentOperation::READ ) );
		self::assertFalse( $manager->allows( 'book', ContentOperation::CREATE ) );
	}

	/**
	 * Submitted unknown types cannot become newly enabled.
	 */
	public function test_normalizes_only_eligible_submitted_types(): void {
		$manager = $this->manager( array(), array( 'post', 'book' ) );
		$result  = $manager->normalize_submitted(
			array(
				'post'       => array( 'get_content' => '1' ),
				'book'       => array(
					'get_content'  => '0',
					'create_draft' => '1',
				),
				'private_db' => array( 'get_content' => '1' ),
			)
		);

		self::assertArrayNotHasKey( 'private_db', $result );
		self::assertTrue( $result['post']['get_content'] );
		self::assertFalse( $result['book']['create_draft'] );
	}

	/**
	 * Missing-type policies survive storage but cannot authorize execution.
	 */
	public function test_preserves_but_does_not_execute_unavailable_type_policy(): void {
		$manager = $this->manager(
			array(
				'legacy_type' => array(
					'get_content'    => true,
					'search_content' => true,
				),
			),
			array( 'post' )
		);
		$result  = $manager->normalize_submitted( array( 'post' => array() ) );

		self::assertArrayHasKey( 'legacy_type', $result );
		self::assertFalse( $manager->allows( 'legacy_type', ContentOperation::READ ) );
	}

	/**
	 * Creates a manager with in-memory ports.
	 *
	 * @param array<string, array<string, mixed>> $stored     Stored settings.
	 * @param array                               $post_types Eligible post-type names.
	 * @return ContentAccessManager
	 */
	private function manager( array $stored, array $post_types ): ContentAccessManager {
		$repository = new class( $stored ) implements ContentAccessSettingsRepository {

			/**
			 * Creates an in-memory settings repository.
			 *
			 * @param array<string, array<string, mixed>> $settings In-memory settings.
			 */
			public function __construct( private array $settings ) {
			}

			/**
			 * Loads in-memory settings.
			 *
			 * @return array<string, array<string, mixed>>
			 */
			public function load(): array {
				return $this->settings;
			}
		};

		$catalog = new class( $post_types ) implements ContentTypeCatalog {

			/**
			 * Creates an in-memory content-type catalog.
			 *
			 * @param array $post_types Eligible post-type names.
			 */
			public function __construct( private array $post_types ) {
			}

			/**
			 * Lists in-memory content types.
			 *
			 * @return list<ContentTypeDefinition>
			 */
			public function list_eligible(): array {
				return array_map(
					static fn ( string $post_type ): ContentTypeDefinition => new ContentTypeDefinition(
						$post_type,
						ucfirst( $post_type ),
						true,
						true,
						in_array( $post_type, array( 'post', 'page' ), true )
					),
					$this->post_types
				);
			}
		};

		return new ContentAccessManager( $repository, $catalog );
	}
}

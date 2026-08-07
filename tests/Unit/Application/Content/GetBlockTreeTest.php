<?php
/**
 * Get-block-tree use-case tests.
 *
 * @package IsuDev\WPContentBridge\Tests
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Tests\Unit\Application\Content;

use IsuDev\WPContentBridge\Application\Content\BlockTreeRepository;
use IsuDev\WPContentBridge\Application\Content\ContentUnavailable;
use IsuDev\WPContentBridge\Application\Content\GetBlockTree;
use IsuDev\WPContentBridge\Application\ContentAccess\ContentAccessManager;
use IsuDev\WPContentBridge\Application\ContentAccess\ContentAccessSettingsRepository;
use IsuDev\WPContentBridge\Application\ContentAccess\ContentTypeCatalog;
use IsuDev\WPContentBridge\Domain\Content\BlockTree;
use IsuDev\WPContentBridge\Domain\Content\BlockTreeNode;
use IsuDev\WPContentBridge\Domain\ContentAccess\ContentTypeDefinition;
use IsuDev\WPContentBridge\Domain\Mutation\VersionToken;
use IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressBlockTreeRepository;
use PHPUnit\Framework\TestCase;

/**
 * Verifies non-enumerating gating and pass-through of the contract, the
 * 500-node bound, freeform-node inclusion, the include_attrs opt-in, and
 * text_source values. The attrs-omitted-by-default and attribute-text
 * fallback logic itself lives in WordPressBlockTreeRepository (WordPress
 * function calls not available under plain PHPUnit); these tests verify that
 * whatever the repository decides survives GetBlockTree and BlockTree/
 * BlockTreeNode serialization unchanged.
 */
final class GetBlockTreeTest extends TestCase {

	/**
	 * A readable post returns the repository's tree, including a freeform node,
	 * unchanged and in the documented wire shape.
	 */
	public function test_returns_tree_with_freeform_node_in_contract_shape(): void {
		$nodes      = array(
			new BlockTreeNode( array( 7 ), 'core/accordion', 1, 'Najczęściej zadawane pytania', 'inner_html', array( 'faqStructuredData' => true ) ),
			new BlockTreeNode( array( 8 ), null, 0, null, null ),
		);
		$tree       = new BlockTree( 42, 'post', VersionToken::from_string( 'abcdef0123456789:2026-08-07 01:00:00' ), $nodes, false );
		$repository = $this->repository( 'post', true, true, $tree );
		$result     = ( new GetBlockTree( $this->manager( true ), $repository ) )->execute( 42, array(), null, true );

		$wire = $result->to_array();

		self::assertSame( 42, $wire['post_id'] );
		self::assertSame( 'post', $wire['post_type'] );
		self::assertSame( 'abcdef0123456789:2026-08-07 01:00:00', $wire['version_token'] );
		self::assertFalse( $wire['truncated'] );
		self::assertSame(
			array(
				'path'         => array( 7 ),
				'block_name'   => 'core/accordion',
				'inner_blocks' => 1,
				'text'         => 'Najczęściej zadawane pytania',
				'text_source'  => 'inner_html',
				'attrs'        => array( 'faqStructuredData' => true ),
			),
			$wire['nodes'][0]
		);
		self::assertSame(
			array(
				'path'         => array( 8 ),
				'block_name'   => null,
				'inner_blocks' => 0,
				'text'         => null,
				'text_source'  => null,
			),
			$wire['nodes'][1]
		);
	}

	/**
	 * A truncated result at the node bound passes through unchanged.
	 */
	public function test_truncated_bound_passes_through(): void {
		$nodes      = array( new BlockTreeNode( array( 0 ), 'core/paragraph', 0, 'x', 'inner_html' ) );
		$tree       = new BlockTree( 42, 'post', VersionToken::from_string( 'abcdef0123456789:2026-08-07 01:00:00' ), $nodes, true );
		$repository = $this->repository( 'post', true, true, $tree );
		$result     = ( new GetBlockTree( $this->manager( true ), $repository ) )->execute( 42, array(), WordPressBlockTreeRepository::MAX_NODES, false );

		self::assertTrue( $result->to_array()['truncated'] );
	}

	/**
	 * Attrs is absent by default (include_attrs: false) and no attrs_omitted key
	 * is emitted, because omission is then the request's own contract, not a
	 * size omission.
	 */
	public function test_attrs_is_absent_when_include_attrs_is_false(): void {
		$nodes      = array( new BlockTreeNode( array( 0 ), 'core/paragraph', 0, 'x', 'inner_html' ) );
		$tree       = new BlockTree( 42, 'post', VersionToken::from_string( 'abcdef0123456789:2026-08-07 01:00:00' ), $nodes, false );
		$repository = $this->repository( 'post', true, true, $tree );

		( new GetBlockTree( $this->manager( true ), $repository ) )->execute( 42, array(), null, false );

		self::assertFalse( $repository->received_include_attrs );

		$wire = $tree->to_array()['nodes'][0];
		self::assertArrayNotHasKey( 'attrs', $wire );
		self::assertArrayNotHasKey( 'attrs_omitted', $wire );
	}

	/**
	 * Attrs is present, and attrs_omitted still applies, only when
	 * include_attrs is true; the flag is forwarded to the repository.
	 */
	public function test_attrs_is_present_when_include_attrs_is_true(): void {
		$nodes      = array(
			new BlockTreeNode( array( 0 ), 't2/extended-selling-point', 0, 'Sprawdzone rozwiązania', 'attrs', array( 'title' => 'Sprawdzone rozwiązania' ) ),
			new BlockTreeNode( array( 1 ), 'core/paragraph', 0, 'y', 'inner_html', null, true ),
		);
		$tree       = new BlockTree( 42, 'post', VersionToken::from_string( 'abcdef0123456789:2026-08-07 01:00:00' ), $nodes, false );
		$repository = $this->repository( 'post', true, true, $tree );

		( new GetBlockTree( $this->manager( true ), $repository ) )->execute( 42, array(), null, true );

		self::assertTrue( $repository->received_include_attrs );

		$wire = $tree->to_array()['nodes'];
		self::assertSame( array( 'title' => 'Sprawdzone rozwiązania' ), $wire[0]['attrs'] );
		self::assertArrayNotHasKey( 'attrs_omitted', $wire[0] );
		self::assertArrayNotHasKey( 'attrs', $wire[1] );
		self::assertTrue( $wire[1]['attrs_omitted'] );
	}

	/**
	 * Text_source distinguishes an innerHTML-derived preview, an
	 * attribute-fallback preview, and the absence of any text.
	 */
	public function test_text_source_values_survive_serialization(): void {
		$nodes = array(
			new BlockTreeNode( array( 0 ), 'core/paragraph', 0, 'From markup', 'inner_html' ),
			new BlockTreeNode( array( 1 ), 'isudev/icon-link', 0, 'Sprawdzone rozwiązania i praktyka', 'attrs' ),
			new BlockTreeNode( array( 2 ), 'core/spacer', 0, null, null ),
		);
		$tree  = new BlockTree( 42, 'post', VersionToken::from_string( 'abcdef0123456789:2026-08-07 01:00:00' ), $nodes, false );

		$wire = $tree->to_array()['nodes'];

		self::assertSame( 'inner_html', $wire[0]['text_source'] );
		self::assertSame( 'attrs', $wire[1]['text_source'] );
		self::assertSame( 'Sprawdzone rozwiązania i praktyka', $wire[1]['text'] );
		self::assertNull( $wire[2]['text'] );
		self::assertNull( $wire[2]['text_source'] );
	}

	/**
	 * A missing content type fails closed without enumerating the reason.
	 */
	public function test_missing_post_type_is_unavailable(): void {
		$repository = $this->repository( null, true, true, null );
		$this->expectException( ContentUnavailable::class );

		( new GetBlockTree( $this->manager( true ), $repository ) )->execute( 42, array(), null, false );
	}

	/**
	 * A policy denial fails closed identically to a missing target.
	 */
	public function test_policy_denial_is_unavailable(): void {
		$repository = $this->repository( 'post', true, true, null );
		$this->expectException( ContentUnavailable::class );

		( new GetBlockTree( $this->manager( false ), $repository ) )->execute( 42, array(), null, false );
	}

	/**
	 * A missing native capability fails closed identically.
	 */
	public function test_native_capability_denial_is_unavailable(): void {
		$repository = $this->repository( 'post', false, true, null );
		$this->expectException( ContentUnavailable::class );

		( new GetBlockTree( $this->manager( true ), $repository ) )->execute( 42, array(), null, false );
	}

	/**
	 * The repository declining to resolve the target after the gate also fails closed.
	 */
	public function test_repository_null_result_is_unavailable(): void {
		$repository = $this->repository( 'post', true, false, null );
		$this->expectException( ContentUnavailable::class );

		( new GetBlockTree( $this->manager( true ), $repository ) )->execute( 42, array( 7 ), 2, false );
	}

	/**
	 * Creates a content access manager with a fixed read policy.
	 *
	 * @param bool $allowed Whether the get_content operation is enabled.
	 * @return ContentAccessManager
	 */
	private function manager( bool $allowed ): ContentAccessManager {
		$settings = new class( $allowed ) implements ContentAccessSettingsRepository {
			/**
			 * Creates the fixed settings repository.
			 *
			 * @param bool $allowed Whether reads are allowed.
			 */
			public function __construct( private bool $allowed ) {}

			/**
			 * Returns the fixed settings map.
			 *
			 * @return array<string, array<string, mixed>>
			 */
			public function load(): array {
				return array( 'post' => array( 'get_content' => $this->allowed ) );
			}
		};
		$catalog  = new class() implements ContentTypeCatalog {
			/**
			 * Returns one eligible post type.
			 *
			 * @return list<ContentTypeDefinition>
			 */
			public function list_eligible(): array {
				return array( new ContentTypeDefinition( 'post', 'Posts', true, true, true ) );
			}
		};

		return new ContentAccessManager( $settings, $catalog );
	}

	/**
	 * Creates a deterministic block-tree repository fake.
	 *
	 * @param string|null    $post_type Fixed post type, or null when unavailable.
	 * @param bool           $can_read  Fixed native capability result.
	 * @param bool           $resolves  Whether tree() returns the given tree or null.
	 * @param BlockTree|null $tree      Fixed tree returned when $resolves is true.
	 * @return BlockTreeRepository&object{received_include_attrs: bool|null}
	 */
	private function repository( ?string $post_type, bool $can_read, bool $resolves, ?BlockTree $tree ): BlockTreeRepository {
		return new class( $post_type, $can_read, $resolves, $tree ) implements BlockTreeRepository {
			/**
			 * Last include_attrs value received by tree(), or null when not yet called.
			 *
			 * @var bool|null
			 */
			public ?bool $received_include_attrs = null;

			/**
			 * Creates the deterministic repository fake.
			 *
			 * @param string|null    $post_type Fixed post type.
			 * @param bool           $can_read  Fixed native capability result.
			 * @param bool           $resolves  Whether tree() resolves to $tree.
			 * @param BlockTree|null $tree      Fixed tree.
			 */
			public function __construct(
				private ?string $post_type,
				private bool $can_read,
				private bool $resolves,
				private ?BlockTree $tree,
			) {}

			/**
			 * Returns the fixed post type.
			 *
			 * @param int $post_id Object ID.
			 * @return string|null
			 */
			public function post_type( int $post_id ): ?string {
				return $this->post_type;
			}

			/**
			 * Returns the fixed native capability result.
			 *
			 * @param int $post_id Object ID.
			 * @return bool
			 */
			public function can_read( int $post_id ): bool {
				return $this->can_read;
			}

			/**
			 * Returns the fixed tree, or null when configured to decline.
			 *
			 * @param int      $post_id       Object ID.
			 * @param array    $path          Subtree path.
			 * @param int|null $max_depth     Maximum depth.
			 * @param bool     $include_attrs Whether attrs were requested.
			 * @return BlockTree|null
			 */
			public function tree( int $post_id, array $path, ?int $max_depth, bool $include_attrs ): ?BlockTree {
				$this->received_include_attrs = $include_attrs;

				return $this->resolves ? $this->tree : null;
			}
		};
	}
}

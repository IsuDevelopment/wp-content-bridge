<?php
/**
 * Runtime verification for the block-level edits slice.
 *
 * Run: wp eval 'require "<abs path>/tests/Integration/block-edits-verification.php";'
 *
 * @package IsuDev\WPContentBridgeTests
 */

declare(strict_types=1);

use IsuDev\WPContentBridge\Adapter\Abilities\BlockMutationAbilities;
use IsuDev\WPContentBridge\Application\ContentAccess\ContentAccessManager;
use IsuDev\WPContentBridge\Application\Mutation\PreviewBlockUpdate;
use IsuDev\WPContentBridge\Application\Mutation\UpdateBlock;
use IsuDev\WPContentBridge\Application\Mutation\UpdateBlockAttributes;
use IsuDev\WPContentBridge\Domain\Mutation\VersionToken;
use IsuDev\WPContentBridge\Infrastructure\WordPress\Installer;
use IsuDev\WPContentBridge\Infrastructure\WordPress\PhpBlockMarkupValidator;
use IsuDev\WPContentBridge\Infrastructure\WordPress\PhpBlockTreeSplicer;
use IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressAuditLog;
use IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressContentAccessSettingsRepository;
use IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressContentMutationRepository;
use IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressContentTypeCatalog;

// phpcs:disable Squiz.Commenting.FunctionCommentThrowTag.Missing -- assertion helpers intentionally fail the runtime harness fast.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery -- isolated CLI verifier reads and prunes the dedicated audit table.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching -- caching one-off verifier queries would be pointless.
// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON is emitted to CLI only.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception messages are CLI diagnostics.
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI diagnostic output, not a filesystem write.

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Must run inside WordPress via wp eval.\n" );
	exit( 1 );
}

Installer::activate();

/**
 * Exercises get-block-tree, update-block, preview-update-block, and
 * update-block-attributes: path addressing, byte-identical round trips,
 * concurrency, recursive validation, preview purity, and the attribute
 * escaping regression. Needs WordPress core only.
 */
final class WPCB_Block_Edits_Verification {

	/**
	 * Exact fixture post IDs for cleanup.
	 *
	 * @var list<int>
	 */
	private array $post_ids = array();

	/**
	 * Administrator fixture user ID, resolved once and reused throughout.
	 *
	 * @var int
	 */
	private int $admin_id = 0;

	/**
	 * Current user ID from before the run.
	 *
	 * @var int
	 */
	private int $original_user_id = 0;

	/**
	 * Original per-post-type policy option.
	 *
	 * @var mixed
	 */
	private mixed $original_policy;

	/**
	 * Original global writes option.
	 *
	 * @var mixed
	 */
	private mixed $original_writes_enabled;

	/**
	 * Highest audit row ID existing before the run.
	 *
	 * @var int
	 */
	private int $audit_baseline_id = 0;

	/**
	 * Unique fixture marker embedded in every fixture text value.
	 *
	 * @var string
	 */
	private string $token = '';

	/**
	 * Always-registered read ability under verification.
	 *
	 * @var WP_Ability
	 */
	private WP_Ability $get_block_tree_ability;

	/**
	 * Update-block ability under verification.
	 *
	 * @var WP_Ability
	 */
	private WP_Ability $update_ability;

	/**
	 * Preview-update-block ability under verification.
	 *
	 * @var WP_Ability
	 */
	private WP_Ability $preview_ability;

	/**
	 * Update-block-attributes ability under verification.
	 *
	 * @var WP_Ability
	 */
	private WP_Ability $update_attributes_ability;

	/**
	 * Runs the complete verifier and always restores the prior installation state.
	 *
	 * @return void
	 */
	public function run(): void {
		$this->original_user_id        = get_current_user_id();
		$this->original_writes_enabled = get_option( Installer::WRITES_ENABLED_OPTION, false );
		$this->original_policy         = get_option( WordPressContentAccessSettingsRepository::OPTION_NAME, null );
		$this->token                   = 'wpcbblk' . strtolower( wp_generate_password( 8, false, false ) );

		global $wpdb;
		/**
		 * WordPress database abstraction object.
		 *
		 * @var wpdb $wpdb
		 */
		$this->audit_baseline_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(MAX(id), 0) FROM %i', Installer::audit_table_name() ) );

		try {
			$this->resolve_admin();
			$this->enable_policy();
			$this->register_block_abilities();
			$this->resolve_get_block_tree_ability();

			$this->verify_tree_round_trip();
			$this->verify_single_block_replacement();
			$this->verify_block_mismatch();
			$this->verify_path_not_found();
			$this->verify_stale_version_conflict();
			$this->verify_recursive_invalid_markup();
			$this->verify_preview();
			$this->verify_freeform_node();
			$this->verify_get_block_tree_attrs_and_text_source();
			$this->verify_update_block_attributes();
			$this->verify_attribute_escaping();
		} finally {
			$this->cleanup();
		}
	}

	/** Resolves the administrator fixture and makes it the acting principal. */
	private function resolve_admin(): void {
		$administrators = get_users(
			array(
				'role'   => 'administrator',
				'number' => 1,
				'fields' => 'ids',
			)
		);
		$this->assert_true( array() !== $administrators && is_numeric( $administrators[0] ), 'An administrator fixture is required.' );
		$this->admin_id = (int) $administrators[0];
		wp_set_current_user( $this->admin_id );
	}

	/** Enables global writes and the post type's Update policy (with its Read prerequisite). */
	private function enable_policy(): void {
		update_option( Installer::WRITES_ENABLED_OPTION, true, false );
		update_option(
			WordPressContentAccessSettingsRepository::OPTION_NAME,
			array(
				'post' => array(
					'get_content'    => true,
					'update_content' => true,
				),
			),
			false
		);
	}

	/**
	 * Registers update-block, preview-update-block, and update-block-attributes
	 * directly from real infrastructure, independent of whatever
	 * wpcb_writes_enabled was set to when the current request originally booted.
	 */
	private function register_block_abilities(): void {
		foreach ( array( UpdateBlock::ABILITY, PreviewBlockUpdate::ABILITY, UpdateBlockAttributes::ABILITY ) as $ability_id ) {
			if ( wp_has_ability( $ability_id ) ) {
				wp_unregister_ability( $ability_id );
			}
		}

		$manager    = new ContentAccessManager( new WordPressContentAccessSettingsRepository(), new WordPressContentTypeCatalog() );
		$repository = new WordPressContentMutationRepository();
		$splicer    = new PhpBlockTreeSplicer();
		$validator  = new PhpBlockMarkupValidator();
		$audit      = new WordPressAuditLog();

		$adapter = new BlockMutationAbilities(
			new UpdateBlock( $manager, $repository, $repository, $splicer, $validator, $audit ),
			new PreviewBlockUpdate( $manager, $repository, $repository, $splicer, $validator ),
			new UpdateBlockAttributes( $manager, $repository, $repository, $splicer, $audit )
		);

		global $wp_current_filter;
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- scopes doing_action() to this direct registration and restores immediately.
		$wp_current_filter[] = 'wp_abilities_api_init';
		try {
			$adapter->register_abilities();
		} finally {
			array_pop( $wp_current_filter );
		}

		$update            = wp_get_ability( UpdateBlock::ABILITY );
		$preview           = wp_get_ability( PreviewBlockUpdate::ABILITY );
		$update_attributes = wp_get_ability( UpdateBlockAttributes::ABILITY );
		$this->assert_true( $update instanceof WP_Ability, 'update-block did not register.' );
		$this->assert_true( $preview instanceof WP_Ability, 'preview-update-block did not register.' );
		$this->assert_true( $update_attributes instanceof WP_Ability, 'update-block-attributes did not register.' );

		$this->update_ability            = $update;
		$this->preview_ability           = $preview;
		$this->update_attributes_ability = $update_attributes;
	}

	/** Resolves the always-on get-block-tree ability registered by the real boot. */
	private function resolve_get_block_tree_ability(): void {
		$ability = wp_get_ability( 'wp-content-bridge/get-block-tree' );
		$this->assert_true( $ability instanceof WP_Ability, 'wp-content-bridge/get-block-tree is not registered.' );
		$this->get_block_tree_ability = $ability;
	}

	/**
	 * Check 1: for every node get-block-tree returns, calling update-block at
	 * that path with the node's own serialized markup and its own block name
	 * leaves post_content byte-identical. This is the whole slice: if
	 * addressing is sound, this property holds for every node at every depth.
	 *
	 * @return void
	 */
	private function verify_tree_round_trip(): void {
		$post_id    = $this->create_fixture_post();
		$normalized = (string) get_post( $post_id )->post_content;

		$tree = $this->get_block_tree_ability->execute( array( 'post_id' => $post_id ) );
		$this->assert_not_error( $tree, 'get-block-tree for the round-trip sweep' );
		if ( ! is_array( $tree ) ) {
			throw new RuntimeException( 'get-block-tree did not return an array.' );
		}
		$nodes = $tree['nodes'] ?? null;
		$this->assert_true( is_array( $nodes ) && array() !== $nodes, 'get-block-tree returned no nodes for the round-trip fixture.' );

		$token = (string) $tree['version_token'];
		foreach ( $nodes as $node ) {
			$path       = $node['path'];
			$block_name = $node['block_name'];
			$markup     = $this->block_markup_at( $normalized, $path );

			$result = $this->update_ability->execute(
				array(
					'post_id'             => $post_id,
					'version_token'       => $token,
					'path'                => $path,
					'expected_block_name' => $block_name,
					'block_markup'        => $markup,
				)
			);
			$this->assert_not_error( $result, 'update-block round trip at path ' . wp_json_encode( $path ) );
			if ( ! is_array( $result ) ) {
				throw new RuntimeException( 'update-block round-trip result is not an array.' );
			}

			$after = get_post( $post_id );
			$this->assert_true( $after instanceof WP_Post, 'Round-trip fixture could not be re-read.' );
			$this->assert_true(
				$normalized === $after->post_content,
				'Round trip at path ' . wp_json_encode( $path ) . ' altered post_content.'
			);

			$token = (string) $result['version_token'];
		}
	}

	/**
	 * Check 2: replacing one block changes that block and leaves every
	 * sibling and unrelated subtree byte-identical, verified against an
	 * independently computed expected document rather than the production
	 * splicer's own output.
	 *
	 * @return void
	 */
	private function verify_single_block_replacement(): void {
		$post_id    = $this->create_fixture_post();
		$normalized = (string) get_post( $post_id )->post_content;
		$token      = $this->current_version_token( $post_id );

		$path       = array( 2 );
		$new_markup = "<!-- wp:paragraph -->\n<p>" . $this->token . " replaced paragraph text.</p>\n<!-- /wp:paragraph -->";
		$expected   = $this->expected_after_replacement( $normalized, $path, $new_markup );

		$result = $this->update_ability->execute(
			array(
				'post_id'             => $post_id,
				'version_token'       => $token,
				'path'                => $path,
				'expected_block_name' => 'core/paragraph',
				'block_markup'        => $new_markup,
			)
		);
		$this->assert_not_error( $result, 'Single block replacement' );

		$after = get_post( $post_id );
		$this->assert_true( $after instanceof WP_Post, 'Replacement fixture could not be re-read.' );
		$this->assert_true( str_contains( (string) $after->post_content, 'replaced paragraph text' ), 'Replacement text was not applied.' );
		$this->assert_true( $expected === $after->post_content, 'Replacing one block altered a sibling or unrelated subtree.' );
	}

	/**
	 * Check 3: a wrong expected_block_name fails closed with
	 * wpcb_block_mismatch and writes nothing.
	 *
	 * @return void
	 */
	private function verify_block_mismatch(): void {
		$post_id    = $this->create_fixture_post();
		$normalized = (string) get_post( $post_id )->post_content;
		$token      = $this->current_version_token( $post_id );

		$result = $this->update_ability->execute(
			array(
				'post_id'             => $post_id,
				'version_token'       => $token,
				'path'                => array( 2 ),
				'expected_block_name' => 'core/heading',
				'block_markup'        => "<!-- wp:paragraph -->\n<p>" . $this->token . " should not land.</p>\n<!-- /wp:paragraph -->",
			)
		);
		$this->assert_error_code( $result, 'wpcb_block_mismatch', 'Wrong expected_block_name' );

		$after = get_post( $post_id );
		$this->assert_true( $normalized === $after->post_content, 'A rejected block mismatch altered post_content.' );
	}

	/**
	 * Check 4: an out-of-range path fails closed with
	 * wpcb_block_path_not_found and writes nothing.
	 *
	 * @return void
	 */
	private function verify_path_not_found(): void {
		$post_id    = $this->create_fixture_post();
		$normalized = (string) get_post( $post_id )->post_content;
		$token      = $this->current_version_token( $post_id );

		$result = $this->update_ability->execute(
			array(
				'post_id'             => $post_id,
				'version_token'       => $token,
				'path'                => array( 99 ),
				'expected_block_name' => 'core/paragraph',
				'block_markup'        => "<!-- wp:paragraph -->\n<p>" . $this->token . " unreachable.</p>\n<!-- /wp:paragraph -->",
			)
		);
		$this->assert_error_code( $result, 'wpcb_block_path_not_found', 'Out-of-range path' );

		$after = get_post( $post_id );
		$this->assert_true( $normalized === $after->post_content, 'A rejected out-of-range path altered post_content.' );
	}

	/**
	 * Check 5: a stale version_token is rejected with wpcb_conflict before
	 * any mutation, and the out-of-band edit that made it stale is left
	 * untouched by the rejected attempt.
	 *
	 * @return void
	 */
	private function verify_stale_version_conflict(): void {
		$post_id     = $this->create_fixture_post();
		$stale_token = $this->current_version_token( $post_id );

		$out_of_band = wp_update_post(
			array(
				'ID'         => $post_id,
				'post_title' => 'WPCB block-edits out-of-band ' . $this->token,
			),
			true
		);
		$this->assert_true( ! is_wp_error( $out_of_band ), 'The out-of-band fixture edit failed.' );
		$content_after_out_of_band = (string) get_post( $post_id )->post_content;

		$result = $this->update_ability->execute(
			array(
				'post_id'             => $post_id,
				'version_token'       => $stale_token,
				'path'                => array( 2 ),
				'expected_block_name' => 'core/paragraph',
				'block_markup'        => "<!-- wp:paragraph -->\n<p>" . $this->token . " stale attempt.</p>\n<!-- /wp:paragraph -->",
			)
		);
		$this->assert_error_code( $result, 'wpcb_conflict', 'Stale version token' );

		$after = get_post( $post_id );
		$this->assert_true( $content_after_out_of_band === $after->post_content, 'A rejected stale update altered post_content.' );
	}

	/**
	 * Check 6: block_markup containing an unregistered nested block is
	 * rejected with wpcb_invalid_blocks and nothing is written. Regression
	 * for the recursive-validation fix (task 1).
	 *
	 * @return void
	 */
	private function verify_recursive_invalid_markup(): void {
		$post_id    = $this->create_fixture_post();
		$normalized = (string) get_post( $post_id )->post_content;
		$token      = $this->current_version_token( $post_id );

		$invalid_markup = '<!-- wp:group --><!-- wp:acme/nope /--><!-- /wp:group -->';
		$result         = $this->update_ability->execute(
			array(
				'post_id'             => $post_id,
				'version_token'       => $token,
				'path'                => array( 0 ),
				'expected_block_name' => 'core/group',
				'block_markup'        => $invalid_markup,
			)
		);
		$this->assert_error_code( $result, 'wpcb_invalid_blocks', 'Unregistered nested block' );

		$after = get_post( $post_id );
		$this->assert_true( $normalized === $after->post_content, 'A rejected unregistered nested block altered post_content.' );
	}

	/**
	 * Check 7: preview-update-block is deterministic across repeated calls,
	 * adds no audit row, creates no revision, does not change
	 * post_modified_gmt, and reports writes_performed: false; a preview
	 * followed by the matching write produces exactly the previewed content.
	 *
	 * @return void
	 */
	private function verify_preview(): void {
		$post_id    = $this->create_fixture_post();
		$normalized = (string) get_post( $post_id )->post_content;
		$token      = $this->current_version_token( $post_id );

		$input = array(
			'post_id'             => $post_id,
			'version_token'       => $token,
			'path'                => array( 2 ),
			'expected_block_name' => 'core/paragraph',
			'block_markup'        => "<!-- wp:paragraph -->\n<p>" . $this->token . " previewed paragraph.</p>\n<!-- /wp:paragraph -->",
		);

		global $wpdb;
		/**
		 * WordPress database abstraction object.
		 *
		 * @var wpdb $wpdb
		 */
		$audit_before     = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(MAX(id), 0) FROM %i', Installer::audit_table_name() ) );
		$revisions_before = count( wp_get_post_revisions( $post_id ) );
		$modified_before  = (string) get_post( $post_id )->post_modified_gmt;

		$first = $this->preview_ability->execute( $input );
		$this->assert_not_error( $first, 'First preview call' );
		$second = $this->preview_ability->execute( $input );
		$this->assert_not_error( $second, 'Second preview call' );
		if ( ! is_array( $first ) || ! is_array( $second ) ) {
			throw new RuntimeException( 'Preview results are not arrays.' );
		}

		$this->assert_true( false === $first['writes_performed'], 'Preview did not report writes_performed = false.' );
		$this->assert_true( $first['preview_content'] === $second['preview_content'], 'Preview was not deterministic across repeated calls.' );
		$this->assert_true( $normalized === $first['current_content'], 'Preview current_content did not match the stored content.' );

		$audit_after     = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(MAX(id), 0) FROM %i', Installer::audit_table_name() ) );
		$revisions_after = count( wp_get_post_revisions( $post_id ) );
		$modified_after  = (string) get_post( $post_id )->post_modified_gmt;
		$this->assert_true( $audit_before === $audit_after, 'Preview added an audit row.' );
		$this->assert_true( $revisions_before === $revisions_after, 'Preview created a revision.' );
		$this->assert_true( $modified_before === $modified_after, 'Preview changed post_modified_gmt.' );

		$write = $this->update_ability->execute( $input );
		$this->assert_not_error( $write, 'Write following a matching preview' );

		$after = get_post( $post_id );
		$this->assert_true( $after instanceof WP_Post, 'Preview-then-write fixture could not be re-read.' );
		$this->assert_true(
			$first['preview_content'] === $after->post_content,
			'A write following a matching preview did not produce exactly the previewed content.'
		);
	}

	/**
	 * Check 8: a freeform node (block_name === null) addressed with
	 * expected_block_name: null is replaced correctly, leaving every sibling
	 * and unrelated subtree byte-identical.
	 *
	 * @return void
	 */
	private function verify_freeform_node(): void {
		$post_id    = $this->create_fixture_post();
		$normalized = (string) get_post( $post_id )->post_content;
		$token      = $this->current_version_token( $post_id );

		$path = array( 1 );
		$this->assert_true( null === $this->block_name_at( $normalized, $path ), 'Fixture path [1] is not the expected freeform node.' );

		$new_markup = $this->token . ' replaced freeform text';
		$expected   = $this->expected_after_replacement( $normalized, $path, $new_markup );

		$result = $this->update_ability->execute(
			array(
				'post_id'             => $post_id,
				'version_token'       => $token,
				'path'                => $path,
				'expected_block_name' => null,
				'block_markup'        => $new_markup,
			)
		);
		$this->assert_not_error( $result, 'Freeform node replacement' );

		$after = get_post( $post_id );
		$this->assert_true( $after instanceof WP_Post, 'Freeform fixture could not be re-read.' );
		$this->assert_true( str_contains( (string) $after->post_content, 'replaced freeform text' ), 'Freeform replacement text was not applied.' );
		$this->assert_true( $expected === $after->post_content, 'Replacing a freeform node altered a sibling or unrelated subtree.' );
	}

	/**
	 * Check 9: get-block-tree omits attrs by default, includes them under
	 * include_attrs: true, and text_source reports "attrs" for a block whose
	 * prose lives in an attribute and "inner_html" for a plain paragraph.
	 *
	 * @return void
	 */
	private function verify_get_block_tree_attrs_and_text_source(): void {
		$post_id = $this->create_fixture_post();

		$default_tree = $this->get_block_tree_ability->execute( array( 'post_id' => $post_id ) );
		$this->assert_not_error( $default_tree, 'get-block-tree without include_attrs' );
		if ( ! is_array( $default_tree ) || ! is_array( $default_tree['nodes'] ) ) {
			throw new RuntimeException( 'get-block-tree default result is not an array.' );
		}
		foreach ( $default_tree['nodes'] as $node ) {
			$this->assert_true( is_array( $node ) && ! array_key_exists( 'attrs', $node ), 'attrs was present without include_attrs.' );
		}

		$paragraph_node = $this->find_node( $default_tree['nodes'], array( 2 ) );
		$this->assert_true( null !== $paragraph_node, 'Top-level paragraph node was not returned.' );
		$this->assert_true( 'inner_html' === ( $paragraph_node['text_source'] ?? null ), 'A plain paragraph did not report text_source = inner_html.' );
		$this->assert_true(
			is_string( $paragraph_node['text'] ?? null ) && str_contains( (string) $paragraph_node['text'], 'top level paragraph text' ),
			'The paragraph node text did not contain the expected fixture text.'
		);

		$image_node = $this->find_node( $default_tree['nodes'], array( 4 ) );
		$this->assert_true( null !== $image_node, 'Image node was not returned.' );
		$this->assert_true( 'attrs' === ( $image_node['text_source'] ?? null ), 'An image whose prose lives in attrs did not report text_source = attrs.' );
		$this->assert_true(
			is_string( $image_node['text'] ?? null ) && str_contains( (string) $image_node['text'], 'descriptive alt text' ),
			'The image node text did not fall back to its alt attribute.'
		);

		$attrs_tree = $this->get_block_tree_ability->execute(
			array(
				'post_id'       => $post_id,
				'include_attrs' => true,
			)
		);
		$this->assert_not_error( $attrs_tree, 'get-block-tree with include_attrs' );
		if ( ! is_array( $attrs_tree ) || ! is_array( $attrs_tree['nodes'] ) ) {
			throw new RuntimeException( 'get-block-tree include_attrs result is not an array.' );
		}
		$image_node_with_attrs = $this->find_node( $attrs_tree['nodes'], array( 4 ) );
		$this->assert_true( null !== $image_node_with_attrs, 'Image node was not returned with include_attrs.' );
		$this->assert_true(
			is_array( $image_node_with_attrs['attrs'] ?? null )
			&& ( $this->token . ' descriptive alt text' ) === ( $image_node_with_attrs['attrs']['alt'] ?? null ),
			"include_attrs did not return the image node's alt attribute."
		);
	}

	/**
	 * Check 10: update-block-attributes shallow-merges, removes a key when
	 * the value is null, and rejects a freeform node.
	 *
	 * @return void
	 */
	private function verify_update_block_attributes(): void {
		$post_id = $this->create_fixture_post();
		$token   = $this->current_version_token( $post_id );

		$merge_one = $this->update_attributes_ability->execute(
			array(
				'post_id'             => $post_id,
				'version_token'       => $token,
				'path'                => array( 0 ),
				'expected_block_name' => 'core/group',
				'attributes'          => array( 'wpcbTestKeyOne' => 'hello' ),
			)
		);
		$this->assert_not_error( $merge_one, 'First attribute merge' );
		if ( ! is_array( $merge_one ) ) {
			throw new RuntimeException( 'First attribute merge result is not an array.' );
		}
		$attrs_after_first = $this->attrs_at( (string) get_post( $post_id )->post_content, array( 0 ) );
		$this->assert_true( 'hello' === ( $attrs_after_first['wpcbTestKeyOne'] ?? null ), 'update-block-attributes did not add the new key.' );

		$merge_two = $this->update_attributes_ability->execute(
			array(
				'post_id'             => $post_id,
				'version_token'       => (string) $merge_one['version_token'],
				'path'                => array( 0 ),
				'expected_block_name' => 'core/group',
				'attributes'          => array( 'wpcbTestKeyTwo' => 'world' ),
			)
		);
		$this->assert_not_error( $merge_two, 'Second attribute merge' );
		if ( ! is_array( $merge_two ) ) {
			throw new RuntimeException( 'Second attribute merge result is not an array.' );
		}
		$attrs_after_second = $this->attrs_at( (string) get_post( $post_id )->post_content, array( 0 ) );
		$this->assert_true(
			'hello' === ( $attrs_after_second['wpcbTestKeyOne'] ?? null ) && 'world' === ( $attrs_after_second['wpcbTestKeyTwo'] ?? null ),
			'A shallow merge did not leave an untouched key in place.'
		);

		$remove = $this->update_attributes_ability->execute(
			array(
				'post_id'             => $post_id,
				'version_token'       => (string) $merge_two['version_token'],
				'path'                => array( 0 ),
				'expected_block_name' => 'core/group',
				'attributes'          => array( 'wpcbTestKeyOne' => null ),
			)
		);
		$this->assert_not_error( $remove, 'Attribute removal via null' );
		if ( ! is_array( $remove ) ) {
			throw new RuntimeException( 'Attribute removal result is not an array.' );
		}
		$attrs_after_removal = $this->attrs_at( (string) get_post( $post_id )->post_content, array( 0 ) );
		$this->assert_true(
			! array_key_exists( 'wpcbTestKeyOne', $attrs_after_removal ) && 'world' === ( $attrs_after_removal['wpcbTestKeyTwo'] ?? null ),
			'A null attribute value did not remove the key while leaving others in place.'
		);

		$freeform_rejection = $this->update_attributes_ability->execute(
			array(
				'post_id'             => $post_id,
				'version_token'       => (string) $remove['version_token'],
				'path'                => array( 1 ),
				'expected_block_name' => null,
				'attributes'          => array( 'foo' => 'bar' ),
			)
		);
		$this->assert_error_code( $freeform_rejection, 'wpcb_block_mismatch', 'update-block-attributes against a freeform node' );
	}

	/**
	 * Check 11: the escaping regression. A block attribute value containing
	 * a double quote and a backslash, re-read through parse_blocks(), comes
	 * back byte-for-byte identical. Fixed in
	 * WordPressContentMutationRepository::slashed(); this must fail if that
	 * fix is removed.
	 *
	 * @return void
	 */
	private function verify_attribute_escaping(): void {
		$post_id = $this->create_fixture_post();
		$token   = $this->current_version_token( $post_id );

		$value  = 'Cudzyslow " backslash \\ i "cytat"';
		$result = $this->update_attributes_ability->execute(
			array(
				'post_id'             => $post_id,
				'version_token'       => $token,
				'path'                => array( 2 ),
				'expected_block_name' => 'core/paragraph',
				'attributes'          => array( 'wpcbEscapeTest' => $value ),
			)
		);
		$this->assert_not_error( $result, 'Escaping regression attribute merge' );

		$stored_content = (string) get_post( $post_id )->post_content;
		$stored_value   = $this->attrs_at( $stored_content, array( 2 ) )['wpcbEscapeTest'] ?? null;
		$this->assert_true(
			$value === $stored_value,
			'A stored attribute value containing a quote and a backslash did not round-trip exactly (got: ' . wp_json_encode( $stored_value ) . ').'
		);
	}

	/**
	 * Builds the fixture document already in normalized form: core/group
	 * containing core/columns/core/column/core/paragraph nested three levels
	 * deep, a top-level paragraph, an image whose only prose lives in its alt
	 * attribute, and blank lines producing freeform nodes.
	 *
	 * @return string
	 */
	private function fixture_markup(): string {
		$token = $this->token;

		$raw = "<!-- wp:group -->\n"
			. '<div class="wp-block-group"><!-- wp:columns -->' . "\n"
			. '<div class="wp-block-columns"><!-- wp:column -->' . "\n"
			. '<div class="wp-block-column"><!-- wp:paragraph -->' . "\n"
			. '<p>' . $token . " nested paragraph text.</p>\n"
			. "<!-- /wp:paragraph --></div>\n"
			. "<!-- /wp:column --></div>\n"
			. "<!-- /wp:columns --></div>\n"
			. "<!-- /wp:group -->\n"
			. "\n"
			. "<!-- wp:paragraph -->\n"
			. '<p>' . $token . " top level paragraph text.</p>\n"
			. "<!-- /wp:paragraph -->\n"
			. "\n"
			. '<!-- wp:image {"alt":"' . $token . ' descriptive alt text"} -->' . "\n"
			. '<figure class="wp-block-image"><img alt="' . $token . ' descriptive alt text"/></figure>' . "\n"
			. '<!-- /wp:image -->';

		return serialize_blocks( parse_blocks( $raw ) );
	}

	/**
	 * Creates one fixture post with the normalized fixture document.
	 *
	 * @return int
	 */
	private function create_fixture_post(): int {
		$post_id = wp_insert_post(
			array(
				'post_type'    => 'post',
				'post_status'  => 'draft',
				'post_author'  => $this->admin_id,
				'post_title'   => 'WPCB block-edits fixture ' . count( $this->post_ids ),
				'post_content' => $this->fixture_markup(),
			),
			true
		);
		$this->assert_true( ! is_wp_error( $post_id ), 'Could not create the block-edits fixture post.' );
		$post_id          = (int) $post_id;
		$this->post_ids[] = $post_id;

		return $post_id;
	}

	/**
	 * Reads the current optimistic-concurrency token for a fixture post.
	 *
	 * @param int $post_id Fixture post ID.
	 * @return string
	 */
	private function current_version_token( int $post_id ): string {
		$post = get_post( $post_id );
		$this->assert_true( $post instanceof WP_Post, 'Fixture post could not be re-read.' );

		return VersionToken::for_content( $post->post_modified_gmt, $post->post_title, $post->post_content, $post->post_status )->to_string();
	}

	/**
	 * Resolves the raw parse_blocks() entry at a path, independent of the
	 * production splicer.
	 *
	 * @param string $content Raw post_content to parse.
	 * @param array  $path    Zero-based indices into successive innerBlocks arrays.
	 * @return array
	 * @phpstan-param list<int> $path
	 * @phpstan-return array<string, mixed>
	 */
	private function resolve_block_at( string $content, array $path ): array {
		$current = parse_blocks( $content );
		$target  = null;
		foreach ( $path as $index ) {
			if ( ! array_key_exists( $index, $current ) || ! is_array( $current[ $index ] ) ) {
				throw new RuntimeException( 'Fixture path does not resolve: ' . wp_json_encode( $path ) );
			}
			$target  = $current[ $index ];
			$current = is_array( $target['innerBlocks'] ?? null ) ? $target['innerBlocks'] : array();
		}
		if ( null === $target ) {
			throw new RuntimeException( 'An empty path cannot be resolved.' );
		}

		return $target;
	}

	/**
	 * Returns one node's own serialized markup, independent of the
	 * production splicer.
	 *
	 * @param string $content Raw post_content to parse.
	 * @param array  $path    Zero-based indices into successive innerBlocks arrays.
	 * @return string
	 * @phpstan-param list<int> $path
	 */
	private function block_markup_at( string $content, array $path ): string {
		return serialize_blocks( array( $this->resolve_block_at( $content, $path ) ) );
	}

	/**
	 * Returns one node's registered block name, or null for a freeform node.
	 *
	 * @param string $content Raw post_content to parse.
	 * @param array  $path    Zero-based indices into successive innerBlocks arrays.
	 * @return string|null
	 * @phpstan-param list<int> $path
	 */
	private function block_name_at( string $content, array $path ): ?string {
		$name = $this->resolve_block_at( $content, $path )['blockName'] ?? null;

		return is_string( $name ) ? $name : null;
	}

	/**
	 * Returns one node's raw attrs.
	 *
	 * @param string $content Raw post_content to parse.
	 * @param array  $path    Zero-based indices into successive innerBlocks arrays.
	 * @return array
	 * @phpstan-param list<int> $path
	 * @phpstan-return array<string, mixed>
	 */
	private function attrs_at( string $content, array $path ): array {
		$attrs = $this->resolve_block_at( $content, $path )['attrs'] ?? array();

		return is_array( $attrs ) ? $attrs : array();
	}

	/**
	 * Rebuilds the expected post_content after replacing exactly one path's
	 * node, using a clean-room tree walk kept independent of
	 * PhpBlockTreeSplicer so this check does not compare production output
	 * against itself.
	 *
	 * @param string $content      Current post_content.
	 * @param array  $path         Zero-based indices into successive innerBlocks arrays.
	 * @param string $block_markup Replacement markup; empty deletes the node.
	 * @return string
	 * @phpstan-param list<int> $path
	 */
	private function expected_after_replacement( string $content, array $path, string $block_markup ): string {
		$blocks      = parse_blocks( $content );
		$replacement = '' === $block_markup ? array() : parse_blocks( $block_markup );

		return serialize_blocks( $this->replace_at( $blocks, $path, $replacement ) );
	}

	/**
	 * Recursive helper for expected_after_replacement().
	 *
	 * @param array $blocks      Sibling entries at this depth.
	 * @param array $path        Remaining path indices, outermost first.
	 * @param array $replacement Replacement entries; empty deletes the addressed node.
	 * @return array
	 * @phpstan-param array<int, array<string, mixed>> $blocks
	 * @phpstan-param list<int> $path
	 * @phpstan-param array<int, array<string, mixed>> $replacement
	 * @phpstan-return array<int, array<string, mixed>>
	 */
	private function replace_at( array $blocks, array $path, array $replacement ): array {
		$index = $path[0];

		if ( 1 === count( $path ) ) {
			return array_merge( array_slice( $blocks, 0, $index ), $replacement, array_slice( $blocks, $index + 1 ) );
		}

		$block                = $blocks[ $index ];
		$inner                = is_array( $block['innerBlocks'] ?? null ) ? $block['innerBlocks'] : array();
		$block['innerBlocks'] = $this->replace_at( $inner, array_slice( $path, 1 ), $replacement );
		$blocks[ $index ]     = $block;

		return $blocks;
	}

	/**
	 * Finds a get-block-tree node by its exact path.
	 *
	 * @param array $nodes Flat node list from a get-block-tree result.
	 * @param array $path  Path to match.
	 * @return array|null
	 * @phpstan-param list<mixed> $nodes
	 * @phpstan-param list<int> $path
	 * @phpstan-return array<string, mixed>|null
	 */
	private function find_node( array $nodes, array $path ): ?array {
		foreach ( $nodes as $node ) {
			if ( is_array( $node ) && ( $node['path'] ?? null ) === $path ) {
				return $node;
			}
		}

		return null;
	}

	/** Restores all prior state and removes only exact verifier fixtures. */
	private function cleanup(): void {
		$administrators = get_users(
			array(
				'role'   => 'administrator',
				'number' => 1,
				'fields' => 'ids',
			)
		);
		if ( array() !== $administrators && is_numeric( $administrators[0] ) ) {
			wp_set_current_user( (int) $administrators[0] );
		}
		foreach ( $this->post_ids as $post_id ) {
			wp_delete_post( $post_id, true );
		}

		$this->restore_option( WordPressContentAccessSettingsRepository::OPTION_NAME, $this->original_policy );
		$this->restore_option( Installer::WRITES_ENABLED_OPTION, $this->original_writes_enabled );

		foreach ( array( UpdateBlock::ABILITY, PreviewBlockUpdate::ABILITY, UpdateBlockAttributes::ABILITY ) as $ability_id ) {
			if ( wp_has_ability( $ability_id ) ) {
				wp_unregister_ability( $ability_id );
			}
		}

		global $wpdb;
		/**
		 * WordPress database abstraction object.
		 *
		 * @var wpdb $wpdb
		 */
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id > %d', Installer::audit_table_name(), $this->audit_baseline_id ) );
		wp_set_current_user( $this->original_user_id );
	}

	/**
	 * Restores an option, preserving whether it existed before the run.
	 *
	 * @param string $name  Option name.
	 * @param mixed  $value Original option value, or null when absent.
	 * @return void
	 */
	private function restore_option( string $name, mixed $value ): void {
		if ( null === $value ) {
			delete_option( $name );
			return;
		}
		update_option( $name, $value, false );
	}

	/**
	 * Throws when a verifier invariant is false.
	 *
	 * @param mixed  $condition Invariant outcome.
	 * @param string $message   Failure diagnostic.
	 * @return void
	 */
	private function assert_true( mixed $condition, string $message ): void {
		if ( true !== $condition ) {
			throw new RuntimeException( $message );
		}
	}

	/**
	 * Throws when an ability result unexpectedly is a WP_Error.
	 *
	 * @param mixed  $value Ability execution result.
	 * @param string $label Assertion label for the diagnostic.
	 * @return void
	 */
	private function assert_not_error( mixed $value, string $label ): void {
		if ( $value instanceof WP_Error ) {
			throw new RuntimeException( $label . ' returned ' . $value->get_error_code() . ': ' . $value->get_error_message() );
		}
	}

	/**
	 * Throws unless an ability result is a WP_Error carrying an exact code.
	 *
	 * @param mixed  $value         Ability execution result.
	 * @param string $expected_code Required error code.
	 * @param string $label         Assertion label for the diagnostic.
	 * @return void
	 */
	private function assert_error_code( mixed $value, string $expected_code, string $label ): void {
		if ( ! $value instanceof WP_Error ) {
			throw new RuntimeException( $label . ' did not return a WP_Error.' );
		}
		$this->assert_true( $expected_code === $value->get_error_code(), $label . ' returned ' . $value->get_error_code() . ' instead of ' . $expected_code . '.' );
	}
}

$failures = array();
try {
	( new WPCB_Block_Edits_Verification() )->run();
} catch ( Throwable $error ) {
	$failures[] = $error->getMessage();
}

echo wp_json_encode(
	array(
		'status'   => array() === $failures ? 'PASS' : 'FAIL',
		'failures' => $failures,
	)
), PHP_EOL;
exit( array() === $failures ? 0 : 1 );

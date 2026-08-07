<?php
/**
 * Runtime verification for registered block-pattern discovery.
 *
 * Run: wp eval 'require "<abs path>/tests/Integration/block-patterns-verification.php";'
 *
 * @package IsuDev\WPContentBridgeTests
 */

declare(strict_types=1);

use IsuDev\WPContentBridge\Adapter\Abilities\PatternAbilities;
use IsuDev\WPContentBridge\Application\Pattern\ListBlockPatterns;
use IsuDev\WPContentBridge\Application\Pattern\PatternAccessManager;
use IsuDev\WPContentBridge\Infrastructure\WordPress\Installer;
use IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressBlockPatternAccess;
use IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressBlockPatternCatalog;

// phpcs:disable Squiz.Commenting.FunctionCommentThrowTag.Missing -- assertion helpers intentionally fail the runtime harness fast.
// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON is emitted to CLI only.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception messages are CLI diagnostics, not rendered HTML.
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI diagnostic output, not a filesystem write.

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Must run inside WordPress via wp eval.\n" );
	exit( 1 );
}

Installer::activate();

/**
 * Exercises pattern registration gating, the authorization matrix, the
 * metadata-default/no-filesystem-path-leak contract (ADR 0013), the 2 MiB
 * complete-content bound, and deterministic filters/pagination.
 */
final class WPCB_Block_Patterns_Verification {

	private const ABILITY             = 'wp-content-bridge/list-block-patterns';
	private const CONTENT_LIMIT_BYTES = 2 * 1024 * 1024;
	private const PRIVATE_PATH_MARKER = '/wpcb-private-pattern-path/fixture.php';

	/**
	 * Registered fixture pattern names, unregistered in the teardown.
	 *
	 * @var list<string>
	 */
	private array $pattern_names = array();

	/**
	 * Disposable editor fixture ID (native access, no plugin capability by default).
	 *
	 * @var int
	 */
	private int $editor_id = 0;

	/**
	 * Disposable subscriber fixture ID (plugin capability only).
	 *
	 * @var int
	 */
	private int $subscriber_id = 0;

	/**
	 * Administrator ID used to drive the authorized path.
	 *
	 * @var int
	 */
	private int $admin_id = 0;

	/**
	 * Principal active before the run.
	 *
	 * @var int
	 */
	private int $original_user_id = 0;

	/**
	 * Prior feature-flag option value.
	 *
	 * @var bool
	 */
	private bool $original_enabled = false;

	/**
	 * Ability instance under verification once registered.
	 *
	 * @var WP_Ability
	 */
	private WP_Ability $ability;

	/**
	 * Runs the verifier and restores every changed runtime value.
	 *
	 * @return void
	 */
	public function run(): void {
		$this->original_user_id = get_current_user_id();
		$this->original_enabled = (bool) get_option( Installer::PATTERN_READS_ENABLED_OPTION, false );

		try {
			$this->set_up();
			$this->verify_registration_gate();
			$this->verify_contract();
			$this->verify_authorization_matrix();
			$this->verify_metadata_default_and_no_path_leak();
			$this->verify_content_bound();
			$this->verify_filters_and_pagination_determinism();
		} finally {
			$this->cleanup();
		}
	}

	/**
	 * Resolves the administrator fixture and switches to it.
	 *
	 * @return void
	 */
	private function set_up(): void {
		$ids = get_users(
			array(
				'role'   => 'administrator',
				'number' => 1,
				'fields' => 'ids',
			)
		);
		$this->assert_true( isset( $ids[0] ), 'No administrator is available.' );
		$this->admin_id = (int) $ids[0];
		wp_set_current_user( $this->admin_id );
	}

	/**
	 * Proves the ability is absent while the flag is off and present when it
	 * is on, mirroring Plugin.php's own conditional registration exactly.
	 *
	 * @return void
	 */
	private function verify_registration_gate(): void {
		$this->apply_registration_state( false );
		$this->assert_true(
			null === wp_get_ability( self::ABILITY ),
			'list-block-patterns remained registered while wpcb_pattern_reads_enabled is disabled.'
		);

		$this->apply_registration_state( true );
		$ability = wp_get_ability( self::ABILITY );
		$this->assert_true(
			$ability instanceof WP_Ability,
			'list-block-patterns did not register while wpcb_pattern_reads_enabled is enabled.'
		);
		$this->ability = $ability;
		update_option( Installer::PATTERN_READS_ENABLED_OPTION, true, false );
	}

	/**
	 * Applies one registration state exactly as Plugin.php composes it.
	 *
	 * @param bool $enabled Whether pattern reads are enabled.
	 * @return void
	 */
	private function apply_registration_state( bool $enabled ): void {
		if ( wp_has_ability( self::ABILITY ) ) {
			wp_unregister_ability( self::ABILITY );
		}

		$access = new PatternAccessManager( $enabled, new WordPressBlockPatternAccess() );
		if ( ! $enabled ) {
			return;
		}

		$projection = new PatternAbilities( $access, new ListBlockPatterns( $access, new WordPressBlockPatternCatalog() ) );

		global $wp_current_filter;
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- scopes doing_action() to this direct registration and restores immediately.
		$wp_current_filter[] = 'wp_abilities_api_init';
		try {
			$projection->register_abilities();
		} finally {
			array_pop( $wp_current_filter );
		}
	}

	/**
	 * Proves the strict schema and safety annotations documented in ADR 0013.
	 *
	 * @return void
	 */
	private function verify_contract(): void {
		$annotations = $this->ability->get_meta()['annotations'] ?? array();
		$this->assert_true( true === ( $annotations['readonly'] ?? null ), 'list-block-patterns must be read-only.' );
		$this->assert_true( false === ( $annotations['destructive'] ?? null ), 'list-block-patterns must not be destructive.' );
		$this->assert_true( true === ( $annotations['idempotent'] ?? null ), 'list-block-patterns must be idempotent.' );
		$this->assert_true(
			false === ( $this->ability->get_input_schema()['additionalProperties'] ?? null ),
			'list-block-patterns input must reject unknown fields.'
		);
	}

	/**
	 * Proves the required-capability + native-editor-access matrix, isolating
	 * each variable so a principal missing only wpcb_read_patterns is denied
	 * and granting exactly that capability is what flips the outcome.
	 *
	 * @return void
	 */
	private function verify_authorization_matrix(): void {
		$this->editor_id = $this->create_user( 'editor' );
		wp_set_current_user( $this->editor_id );
		$this->assert_true(
			false === $this->ability->check_permissions( array() ),
			'An editor without wpcb_read_patterns was not denied despite native editor access.'
		);

		$editor = get_user_by( 'id', $this->editor_id );
		$this->assert_true( $editor instanceof WP_User, 'Could not resolve the editor fixture.' );
		$editor->add_cap( 'wpcb_read_patterns' );
		// wp_set_current_user() early-returns for the same ID, so the global principal
		// still holds the capabilities cached before add_cap(). Force a rebuild.
		wp_set_current_user( 0 );
		wp_set_current_user( $this->editor_id );
		$this->assert_true(
			true === $this->ability->check_permissions( array() ),
			'Granting wpcb_read_patterns to an editor with native access did not authorize pattern reads.'
		);

		$this->subscriber_id = $this->create_user( 'subscriber' );
		$subscriber          = get_user_by( 'id', $this->subscriber_id );
		$this->assert_true( $subscriber instanceof WP_User, 'Could not resolve the subscriber fixture.' );
		$subscriber->add_cap( 'wpcb_read_patterns' );
		wp_set_current_user( $this->subscriber_id );
		$this->assert_true(
			false === $this->ability->check_permissions( array() ),
			'A subscriber with only the plugin capability bypassed native editor access.'
		);

		wp_set_current_user( 0 );
		$this->assert_true( false === $this->ability->check_permissions( array() ), 'Anonymous pattern access was granted.' );

		wp_set_current_user( $this->admin_id );
	}

	/**
	 * Proves metadata-only is the default, complete markup is opt-in, no
	 * remote HTTP request is triggered, and pattern filesystem paths never
	 * appear in any response field (ADR 0013).
	 *
	 * @return void
	 */
	private function verify_metadata_default_and_no_path_leak(): void {
		$namespace = 'wpcb-verifier-' . strtolower( wp_generate_password( 8, false, false ) );
		$content   = '<!-- wp:paragraph --><p>WPCB exact pattern content</p><!-- /wp:paragraph -->';
		$name      = $namespace . '/hero';
		$this->register_pattern( $name, $content, array( 'featured' ), array( 'page' ) );

		$http_requests = 0;
		$http_guard    = static function ( mixed $response ) use ( &$http_requests ): mixed {
			++$http_requests;
			return $response;
		};
		add_filter( 'pre_http_request', $http_guard );
		$metadata     = $this->execute_array(
			array(
				'namespace' => $namespace,
				'category'  => 'featured',
				'post_type' => 'page',
			)
		);
		$with_content = $this->execute_array(
			array(
				'namespace'       => $namespace,
				'include_content' => true,
			)
		);
		remove_filter( 'pre_http_request', $http_guard );
		$this->assert_true( 0 === $http_requests, 'Pattern listing triggered a remote HTTP request.' );

		$this->assert_item( $metadata, $name, $namespace, null, $content );
		$this->assert_item( $with_content, $name, $namespace, $content, $content );

		foreach ( array( $metadata, $with_content ) as $result ) {
			$this->assert_true(
				! str_contains( (string) wp_json_encode( $result ), self::PRIVATE_PATH_MARKER ),
				'Pattern output leaked a filesystem path.'
			);
		}
	}

	/**
	 * Proves the combined 2 MiB complete-content bound fails atomically
	 * rather than truncating, while metadata-only stays unaffected.
	 *
	 * @return void
	 */
	private function verify_content_bound(): void {
		$namespace = 'wpcb-verifier-oversized-' . strtolower( wp_generate_password( 8, false, false ) );
		$name      = $namespace . '/big';
		$this->register_pattern( $name, str_repeat( 'a', self::CONTENT_LIMIT_BYTES + 1024 ), array(), array() );

		$result = $this->ability->execute(
			array(
				'namespace'       => $namespace,
				'include_content' => true,
			)
		);
		$this->assert_true(
			is_wp_error( $result ) && 'wpcb_pattern_content_too_large' === $result->get_error_code(),
			'Requesting complete content over the 2 MiB bound did not fail with wpcb_pattern_content_too_large.'
		);

		$metadata_only = $this->execute_array( array( 'namespace' => $namespace ) );
		$this->assert_true(
			isset( $metadata_only['items'][0] ) && 0 === $metadata_only['items'][0]['content_bytes'],
			'Metadata-only listing was affected by the oversized content bound.'
		);
	}

	/**
	 * Proves filters narrow results correctly and both filters and pagination
	 * are byte-identical across repeated calls with the same input.
	 *
	 * @return void
	 */
	private function verify_filters_and_pagination_determinism(): void {
		$namespace = 'wpcb-verifier-paging-' . strtolower( wp_generate_password( 8, false, false ) );
		$this->register_pattern( $namespace . '/item-1', '<!-- wp:paragraph --><p>One</p><!-- /wp:paragraph -->', array( 'alpha' ), array() );
		$this->register_pattern( $namespace . '/item-2', '<!-- wp:paragraph --><p>Two</p><!-- /wp:paragraph -->', array( 'alpha' ), array() );
		$this->register_pattern( $namespace . '/item-3', '<!-- wp:paragraph --><p>Three</p><!-- /wp:paragraph -->', array( 'beta' ), array() );

		$first_filtered  = $this->execute_array(
			array(
				'namespace' => $namespace,
				'category'  => 'alpha',
				'per_page'  => 50,
			)
		);
		$second_filtered = $this->execute_array(
			array(
				'namespace' => $namespace,
				'category'  => 'alpha',
				'per_page'  => 50,
			)
		);
		$this->assert_true(
			wp_json_encode( $first_filtered ) === wp_json_encode( $second_filtered ),
			'Repeated identical category-filtered queries were not deterministic.'
		);
		$this->assert_true( 2 === $first_filtered['pagination']['total_items'], 'Category filter did not narrow to the expected two patterns.' );
		$this->assert_true(
			array( $namespace . '/item-1', $namespace . '/item-2' ) === array_column( $first_filtered['items'], 'name' ),
			'Category filter returned patterns in an unexpected order.'
		);

		$names_by_page = array();
		foreach ( array( 1, 2, 3 ) as $page ) {
			$first_call  = $this->execute_array(
				array(
					'namespace' => $namespace,
					'page'      => $page,
					'per_page'  => 1,
				)
			);
			$second_call = $this->execute_array(
				array(
					'namespace' => $namespace,
					'page'      => $page,
					'per_page'  => 1,
				)
			);
			$this->assert_true(
				wp_json_encode( $first_call ) === wp_json_encode( $second_call ),
				"Repeated identical page {$page} queries were not deterministic."
			);
			$this->assert_true( 3 === $first_call['pagination']['total_items'], "Page {$page} reported the wrong total_items." );
			$this->assert_true( 3 === $first_call['pagination']['total_pages'], "Page {$page} reported the wrong total_pages." );
			$this->assert_true(
				( 3 > $page ) === $first_call['pagination']['has_more'],
				"Page {$page} reported the wrong has_more."
			);
			$names_by_page[ $page ] = $first_call['items'][0]['name'] ?? null;
		}
		$this->assert_true(
			array(
				1 => $namespace . '/item-1',
				2 => $namespace . '/item-2',
				3 => $namespace . '/item-3',
			) === $names_by_page,
			'Pagination did not return patterns in stable name order.'
		);
	}

	/**
	 * Registers one fixture pattern and tracks it for teardown.
	 *
	 * @param string        $name       Namespaced pattern name.
	 * @param string        $content    Complete block markup.
	 * @param array<string> $categories Category slugs.
	 * @param array<string> $post_types Allowed post types.
	 * @return void
	 */
	private function register_pattern( string $name, string $content, array $categories, array $post_types ): void {
		$registered = register_block_pattern(
			$name,
			array(
				'title'       => 'WPCB fixture ' . $name,
				'description' => 'Runtime verifier fixture pattern.',
				'content'     => $content,
				'filePath'    => self::PRIVATE_PATH_MARKER,
				'categories'  => $categories,
				'postTypes'   => $post_types,
				'source'      => 'plugin',
			)
		);
		$this->assert_true( $registered, "Could not register the pattern fixture {$name}." );
		$this->pattern_names[] = $name;
	}

	/**
	 * Creates one disposable fixture user with the given role and no plugin capability.
	 *
	 * @param string $role WordPress role slug.
	 * @return int
	 */
	private function create_user( string $role ): int {
		$suffix  = strtolower( wp_generate_password( 8, false, false ) );
		$user_id = wp_insert_user(
			array(
				'user_login' => 'wpcb-pattern-' . $role . '-' . $suffix,
				'user_pass'  => wp_generate_password( 32, true, true ),
				'user_email' => 'wpcb-pattern-' . $role . '-' . $suffix . '@example.invalid',
				'role'       => $role,
			)
		);
		if ( is_wp_error( $user_id ) ) {
			throw new RuntimeException( "Could not create the {$role} fixture user." );
		}

		return (int) $user_id;
	}

	/**
	 * Verifies the strict item projection for one selection mode.
	 *
	 * @param array<string, mixed> $result           Ability output.
	 * @param string               $expected_name    Expected pattern name.
	 * @param string               $expected_namespace Expected pattern namespace.
	 * @param string|null          $expected_content Expected content, or null when omitted.
	 * @param string               $exact_content    The registered exact content, for byte accounting.
	 * @return void
	 */
	private function assert_item( array $result, string $expected_name, string $expected_namespace, ?string $expected_content, string $exact_content ): void {
		$this->assert_true( isset( $result['items'][0] ) && is_array( $result['items'][0] ), 'Pattern result did not use an item envelope.' );
		$item = $result['items'][0];
		$this->assert_true( $expected_name === $item['name'], 'Pattern identity drifted.' );
		$this->assert_true( $expected_namespace === $item['namespace'], 'Pattern namespace drifted.' );
		$this->assert_true( $expected_content === $item['content'], 'Pattern content selection drifted.' );
		$this->assert_true(
			( null === $expected_content ? 0 : strlen( $exact_content ) ) === $item['content_bytes'],
			'Pattern content byte accounting drifted.'
		);
		$this->assert_true( true === $item['untrusted'], 'Pattern output was not marked untrusted.' );
		$this->assert_true( ! array_key_exists( 'filePath', $item ), 'Pattern output exposed a non-allowlisted field.' );
	}

	/**
	 * Executes the ability as the current principal and requires an array result.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>
	 */
	private function execute_array( array $input ): array {
		$result = $this->ability->execute( $input );
		if ( is_wp_error( $result ) || ! is_array( $result ) ) {
			throw new RuntimeException( 'Pattern ability execution failed: ' . ( is_wp_error( $result ) ? $result->get_error_message() : 'non-array result' ) );
		}

		return $result;
	}

	/**
	 * Removes disposable state and restores every touched option.
	 *
	 * @return void
	 */
	private function cleanup(): void {
		if ( wp_has_ability( self::ABILITY ) ) {
			wp_unregister_ability( self::ABILITY );
		}

		foreach ( $this->pattern_names as $pattern_name ) {
			if ( WP_Block_Patterns_Registry::get_instance()->is_registered( $pattern_name ) ) {
				unregister_block_pattern( $pattern_name );
			}
		}

		if ( ! function_exists( 'wp_delete_user' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}
		foreach ( array( $this->editor_id, $this->subscriber_id ) as $user_id ) {
			if ( 0 < $user_id ) {
				wp_delete_user( $user_id );
			}
		}

		update_option( Installer::PATTERN_READS_ENABLED_OPTION, $this->original_enabled, false );
		wp_set_current_user( $this->original_user_id );
	}

	/**
	 * Fails with one bounded message.
	 *
	 * @param bool   $condition Condition.
	 * @param string $message   Failure message.
	 * @return void
	 */
	private function assert_true( bool $condition, string $message ): void {
		if ( ! $condition ) {
			throw new RuntimeException( $message );
		}
	}
}

$failures = array();
try {
	( new WPCB_Block_Patterns_Verification() )->run();
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

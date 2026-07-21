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

// phpcs:disable Squiz.Commenting.FunctionCommentThrowTag.Missing -- verifier fails fast with bounded CLI diagnostics.
// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI output, not HTML.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- bounded CLI diagnostics, not HTML.
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI diagnostic output.

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Must run inside WordPress via wp eval.\n" );
	exit( 1 );
}

/**
 * Exercises pattern registration, filters, payload policy, and authorization.
 */
final class WPCB_Block_Patterns_Verification {

	private const NAMESPACE = 'wpcb-verifier';
	private const CONTENT   = '<!-- wp:paragraph --><p>WPCB exact pattern content</p><!-- /wp:paragraph -->';

	/**
	 * Registered fixture name.
	 *
	 * @var string
	 */
	private string $pattern_name = '';

	/**
	 * Disposable subscriber ID.
	 *
	 * @var int
	 */
	private int $subscriber_id = 0;

	/**
	 * Prior feature option.
	 *
	 * @var bool
	 */
	private bool $original_enabled = false;

	/**
	 * Runs the verifier and restores every changed runtime value.
	 *
	 * @return void
	 */
	public function run(): void {
		$this->original_enabled = (bool) get_option( Installer::PATTERN_READS_ENABLED_OPTION, false );
		Installer::activate();
		update_option( Installer::PATTERN_READS_ENABLED_OPTION, true, false );

		try {
			wp_set_current_user( $this->administrator_id() );
			$this->create_fixture();

			$access     = new PatternAccessManager( true, new WordPressBlockPatternAccess() );
			$projection = new PatternAbilities(
				$access,
				new ListBlockPatterns( $access, new WordPressBlockPatternCatalog() )
			);
			if ( null === wp_get_ability( 'wp-content-bridge/list-block-patterns' ) ) {
				$projection->register_abilities();
			}

			$ability = wp_get_ability( 'wp-content-bridge/list-block-patterns' );
			$this->assert_true( null !== $ability, 'Pattern ability was not registered.' );
			$this->assert_true( true === $ability->check_permissions( array() ), 'Administrator lacks pattern permission.' );

			$http_requests = 0;
			$http_guard    = static function ( mixed $response ) use ( &$http_requests ): mixed {
				++$http_requests;

				return $response;
			};
			add_filter( 'pre_http_request', $http_guard );
			$metadata = $this->execute_array(
				$ability,
				array(
					'namespace' => self::NAMESPACE,
					'category'  => 'featured',
					'post_type' => 'page',
				)
			);
			$content  = $this->execute_array(
				$ability,
				array(
					'query'           => 'exact pattern content',
					'include_content' => true,
				)
			);
			remove_filter( 'pre_http_request', $http_guard );

			$this->assert_true( 0 === $http_requests, 'Pattern listing triggered a remote HTTP request.' );
			$this->assert_item( $metadata, false );
			$this->assert_item( $content, true );
			$this->assert_true( ! str_contains( (string) wp_json_encode( $metadata ), 'wpcb-private-pattern-path' ), 'Pattern output leaked a filesystem path.' );

			wp_set_current_user( $this->subscriber_id );
			$this->assert_true( false === $ability->check_permissions( array() ), 'Pattern capability bypassed native editor access.' );
			wp_set_current_user( 0 );
			$this->assert_true( false === $ability->check_permissions( array() ), 'Anonymous pattern access was granted.' );

			echo "PASS: block patterns (settings/capability gate, native editor access, filters, metadata default, content opt-in, no remote request/path leak)\n";
		} finally {
			$this->cleanup();
		}
	}

	/**
	 * Creates one registered pattern and a plugin-capability-only subscriber.
	 *
	 * @return void
	 */
	private function create_fixture(): void {
		$this->pattern_name = self::NAMESPACE . '/hero-' . strtolower( wp_generate_password( 8, false, false ) );
		$registered         = register_block_pattern(
			$this->pattern_name,
			array(
				'title'         => 'WPCB fixture hero',
				'description'   => 'Exact pattern content fixture',
				'content'       => self::CONTENT,
				'filePath'      => '/wpcb-private-pattern-path/fixture.php',
				'categories'    => array( 'featured' ),
				'keywords'      => array( 'exact', 'fixture' ),
				'blockTypes'    => array( 'core/paragraph' ),
				'postTypes'     => array( 'page' ),
				'templateTypes' => array( 'page' ),
				'viewportWidth' => 1200,
				'source'        => 'plugin',
			)
		);
		$this->assert_true( $registered, 'Could not register the pattern fixture.' );

		$user_id = wp_insert_user(
			array(
				'user_login' => 'wpcb-pattern-' . strtolower( wp_generate_password( 8, false, false ) ),
				'user_pass'  => wp_generate_password( 32, true, true ),
				'user_email' => 'wpcb-pattern-' . strtolower( wp_generate_password( 8, false, false ) ) . '@example.invalid',
				'role'       => 'subscriber',
			)
		);
		if ( is_wp_error( $user_id ) ) {
			throw new RuntimeException( 'Could not create the subscriber fixture.' );
		}
		$this->subscriber_id = (int) $user_id;
		$user                = get_user_by( 'id', $this->subscriber_id );
		if ( ! $user instanceof WP_User ) {
			throw new RuntimeException( 'Could not resolve the subscriber fixture.' );
		}
		$user->add_cap( 'wpcb_read_patterns' );
	}

	/**
	 * Verifies the strict item projection and content selection.
	 *
	 * @param array<string, mixed> $result          Ability output.
	 * @param bool                 $content_expected Whether content was requested.
	 * @return void
	 */
	private function assert_item( array $result, bool $content_expected ): void {
		$this->assert_true( isset( $result['items'][0] ) && is_array( $result['items'][0] ), 'Pattern result did not use an item envelope.' );
		$item = $result['items'][0];
		$this->assert_true( $this->pattern_name === $item['name'], 'Pattern identity drifted.' );
		$this->assert_true( self::NAMESPACE === $item['namespace'], 'Pattern namespace drifted.' );
		$this->assert_true( $content_expected ? self::CONTENT === $item['content'] : null === $item['content'], 'Pattern content selection drifted.' );
		$this->assert_true( $content_expected ? strlen( self::CONTENT ) === $item['content_bytes'] : 0 === $item['content_bytes'], 'Pattern content byte accounting drifted.' );
		$this->assert_true( true === $item['untrusted'], 'Pattern output was not marked untrusted.' );
		$this->assert_true( ! array_key_exists( 'filePath', $item ), 'Pattern output exposed a non-allowlisted field.' );
	}

	/**
	 * Executes an ability and requires an array result.
	 *
	 * @param object               $ability Ability object.
	 * @param array<string, mixed> $input   Ability input.
	 * @return array<string, mixed>
	 */
	private function execute_array( object $ability, array $input ): array {
		$result = $ability->execute( $input );
		if ( is_wp_error( $result ) || ! is_array( $result ) ) {
			throw new RuntimeException( 'Pattern ability execution failed.' );
		}

		return $result;
	}

	/**
	 * Resolves one administrator user ID.
	 *
	 * @return int
	 */
	private function administrator_id(): int {
		$ids = get_users(
			array(
				'role'   => 'administrator',
				'number' => 1,
				'fields' => 'ids',
			)
		);
		if ( ! isset( $ids[0] ) ) {
			throw new RuntimeException( 'No administrator is available.' );
		}

		return (int) $ids[0];
	}

	/**
	 * Removes disposable state and restores the option.
	 *
	 * @return void
	 */
	private function cleanup(): void {
		if ( '' !== $this->pattern_name && WP_Block_Patterns_Registry::get_instance()->is_registered( $this->pattern_name ) ) {
			unregister_block_pattern( $this->pattern_name );
		}
		if ( 0 < $this->subscriber_id ) {
			if ( ! function_exists( 'wp_delete_user' ) ) {
				require_once ABSPATH . 'wp-admin/includes/user.php';
			}
			wp_delete_user( $this->subscriber_id );
		}
		update_option( Installer::PATTERN_READS_ENABLED_OPTION, $this->original_enabled, false );
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

( new WPCB_Block_Patterns_Verification() )->run();

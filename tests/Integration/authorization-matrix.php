<?php
/**
 * Repeatable local WordPress authorization matrix.
 *
 * Execute with: wp eval 'require "/absolute/path/to/wp-content-bridge/tests/Integration/authorization-matrix.php";'
 *
 * @package IsuDev\WPContentBridge\Tests
 */

declare(strict_types=1);

use IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressContentAccessSettingsRepository;
use IsuDev\WPContentBridge\Application\Content\GetContent;

// phpcs:disable Squiz.Commenting.FunctionCommentThrowTag.Missing -- assertion helpers intentionally fail the runtime harness fast.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception messages are CLI diagnostics, not rendered HTML.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates isolated fixtures, exercises the matrix, and removes exact fixtures.
 */
final class WPCB_Authorization_Matrix {

	/**
	 * Exact fixture post IDs for cleanup.
	 *
	 * @var list<int>
	 */
	private array $post_ids = array();

	/**
	 * Exact fixture user IDs for cleanup.
	 *
	 * @var list<int>
	 */
	private array $user_ids = array();

	/**
	 * Original access policy restored after the run.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $original_policy;

	/**
	 * Unique fixture marker.
	 *
	 * @var string
	 */
	private string $token = '';

	/**
	 * Ephemeral fixture post type.
	 *
	 * @var string
	 */
	private string $fixture_post_type = '';

	/**
	 * Search ability.
	 *
	 * @var WP_Ability
	 */
	private WP_Ability $search;

	/**
	 * Detail ability.
	 *
	 * @var WP_Ability
	 */
	private WP_Ability $get;

	/**
	 * SEO read ability.
	 *
	 * @var WP_Ability
	 */
	private WP_Ability $seo;

	/**
	 * Editorial-context ability.
	 *
	 * @var WP_Ability
	 */
	private WP_Ability $editorial;

	/**
	 * Payload measurements reported by the successful run.
	 *
	 * @var array<string, int>
	 */
	private array $payload_measurements = array();

	/**
	 * Runs the complete matrix.
	 *
	 * @return void
	 */
	public function run(): void {
		$stored_policy           = get_option( WordPressContentAccessSettingsRepository::OPTION_NAME, null );
		$this->original_policy   = is_array( $stored_policy ) ? $stored_policy : null;
		$this->token             = 'wpcbauth' . strtolower( wp_generate_password( 8, false, false ) );
		$this->fixture_post_type = 'wpcbfx' . strtolower( wp_generate_password( 6, false, false ) );

		try {
			$this->register_fixture_post_type();
			$this->resolve_abilities();
			$principals = $this->create_principals();
			$objects    = $this->create_content( $principals );
			$this->enable_fixture_policies();

			$this->verify_permission_roundtrip( $principals );
			$this->verify_detail_matrix( $principals, $objects );
			$this->verify_search_matrix( $principals, $objects );
			$this->verify_policy_gate( $principals, $objects );
			$this->verify_content_contract( $principals, $objects );
			$this->verify_payload_contract( $principals, $objects );

			echo wp_json_encode(
				array(
					'status'       => 'PASS',
					'fixture'      => $this->token,
					'principals'   => array_keys( $principals ),
					'object_count' => count( $objects ),
					'payload'      => $this->payload_measurements,
				),
				JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
			), PHP_EOL;
		} finally {
			$this->cleanup();
		}
	}

	/**
	 * Registers a public CPT that uses post capabilities.
	 *
	 * @return void
	 */
	private function register_fixture_post_type(): void {
		register_post_type(
			$this->fixture_post_type,
			array(
				'label'           => 'WPCB Fixture',
				'public'          => true,
				'show_ui'         => true,
				'show_in_rest'    => true,
				'capability_type' => 'post',
				'map_meta_cap'    => true,
				'supports'        => array( 'title', 'editor', 'author' ),
			)
		);
	}

	/**
	 * Resolves required abilities.
	 *
	 * @return void
	 */
	private function resolve_abilities(): void {
		$search    = wp_get_ability( 'wp-content-bridge/search-content' );
		$get       = wp_get_ability( 'wp-content-bridge/get-content' );
		$seo       = wp_get_ability( 'wp-content-bridge/get-url-seo' );
		$editorial = wp_get_ability( 'wp-content-bridge/get-editorial-context' );
		if ( ! $search instanceof WP_Ability || ! $get instanceof WP_Ability || ! $seo instanceof WP_Ability || ! $editorial instanceof WP_Ability ) {
			throw new RuntimeException( 'Required WP Content Bridge abilities are not registered.' );
		}

		$this->search    = $search;
		$this->get       = $get;
		$this->seo       = $seo;
		$this->editorial = $editorial;
	}

	/**
	 * Creates users for every authorization role.
	 *
	 * @return array<string, int>
	 */
	private function create_principals(): array {
		$administrators = get_users(
			array(
				'role'   => 'administrator',
				'number' => 1,
				'fields' => 'ids',
			)
		);
		if ( array() === $administrators || ! is_numeric( $administrators[0] ) ) {
			throw new RuntimeException( 'An administrator fixture is required.' );
		}
		$administrator_id = (int) $administrators[0];

		$principals = array(
			'admin'       => $administrator_id,
			'subscriber'  => $this->create_user( 'subscriber' ),
			'author_a'    => $this->create_user( 'author', true ),
			'author_b'    => $this->create_user( 'author', true ),
			'editor'      => $this->create_user( 'editor', true ),
			'integration' => $this->create_user( 'subscriber', true ),
		);

		$this->assert_true( user_can( $principals['admin'], 'wpcb_read_content' ), 'Administrator lacks wpcb_read_content.' );

		return $principals;
	}

	/**
	 * Creates one temporary user.
	 *
	 * @param string $role           WordPress role.
	 * @param bool   $grant_wpcb_cap Whether to grant bridge read access.
	 * @return int
	 */
	private function create_user( string $role, bool $grant_wpcb_cap = false ): int {
		$login   = $this->token . '_' . $role . '_' . count( $this->user_ids );
		$user_id = wp_insert_user(
			array(
				'user_login' => $login,
				'user_pass'  => wp_generate_password( 32, true, true ),
				'user_email' => $login . '@example.invalid',
				'role'       => $role,
			)
		);
		if ( is_wp_error( $user_id ) ) {
			throw new RuntimeException( 'Could not create authorization fixture user: ' . $user_id->get_error_message() );
		}

		$this->user_ids[] = $user_id;
		if ( $grant_wpcb_cap ) {
			$user = get_user_by( 'id', $user_id );
			if ( ! $user instanceof WP_User ) {
				throw new RuntimeException( 'Could not resolve authorization fixture user.' );
			}
			$user->add_cap( 'wpcb_read_content' );
		}

		return $user_id;
	}

	/**
	 * Creates content for visibility and representation checks.
	 *
	 * @param array<string, int> $principals Principal IDs.
	 * @return array<string, int>
	 */
	private function create_content( array $principals ): array {
		$block_content = '<!-- wp:paragraph --><p>' . $this->token . ' Alpha <strong>Beta</strong></p><!-- /wp:paragraph -->';
		$long_content  = str_repeat( $block_content, 500 );
		$large_content = '<!-- wp:paragraph --><p>' . str_repeat( 'X', GetContent::MAX_REPRESENTATION_BYTES + 1 ) . '</p><!-- /wp:paragraph -->';
		$objects       = array(
			'published_post'  => $this->create_post( 'post', 'publish', $principals['author_a'], $block_content ),
			'own_draft'       => $this->create_post( 'post', 'draft', $principals['author_a'], $block_content ),
			'foreign_draft'   => $this->create_post( 'post', 'draft', $principals['author_b'], $block_content ),
			'private_post'    => $this->create_post( 'post', 'private', $principals['author_a'], $block_content ),
			'foreign_private' => $this->create_post( 'post', 'private', $principals['author_b'], $block_content ),
			'published_page'  => $this->create_post( 'page', 'publish', $principals['author_a'], $long_content ),
			'published_cpt'   => $this->create_post( $this->fixture_post_type, 'publish', $principals['author_a'], $block_content ),
			'oversized_page'  => $this->create_post( 'page', 'publish', $principals['author_a'], $large_content ),
		);

		update_post_meta( $objects['published_post'], '_wpcb_fixture_secret', 'must-not-leak-' . $this->token );

		return $objects;
	}

	/**
	 * Creates one exact fixture post.
	 *
	 * @param string $post_type Post type.
	 * @param string $status    Post status.
	 * @param int    $author_id Author ID.
	 * @param string $content   Block content.
	 * @return int
	 */
	private function create_post( string $post_type, string $status, int $author_id, string $content ): int {
		$post_id = wp_insert_post(
			array(
				'post_type'    => $post_type,
				'post_status'  => $status,
				'post_author'  => $author_id,
				'post_title'   => $this->token . ' ' . $post_type . ' ' . $status . ' ' . count( $this->post_ids ),
				'post_content' => $content,
			),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			throw new RuntimeException( 'Could not create authorization fixture post: ' . $post_id->get_error_message() );
		}

		$this->post_ids[] = $post_id;

		return $post_id;
	}

	/**
	 * Enables read/search for fixture types.
	 *
	 * @return void
	 */
	private function enable_fixture_policies(): void {
		$enabled = array(
			'get_content'    => true,
			'search_content' => true,
		);
		update_option(
			WordPressContentAccessSettingsRepository::OPTION_NAME,
			array(
				'post'                   => $enabled,
				'page'                   => $enabled,
				$this->fixture_post_type => $enabled,
			),
			false
		);
	}

	/**
	 * Verifies plugin-level permission gates.
	 *
	 * @param array<string, int> $principals Principal IDs.
	 * @return void
	 */
	private function verify_permission_roundtrip( array $principals ): void {
		wp_set_current_user( 0 );
		$this->assert_false( $this->search->check_permissions( array() ), 'Anonymous search permission was granted.' );
		$this->assert_false( $this->seo->check_permissions( array() ), 'Anonymous SEO permission was granted.' );
		$this->assert_false( $this->editorial->check_permissions( array() ), 'Anonymous editorial permission was granted.' );

		wp_set_current_user( $principals['subscriber'] );
		$this->assert_false( $this->search->check_permissions( array() ), 'Subscriber search permission was granted.' );
		$this->assert_false( $this->seo->check_permissions( array() ), 'Subscriber SEO permission was granted.' );
		$this->assert_false( $this->editorial->check_permissions( array() ), 'Subscriber editorial permission was granted.' );

		foreach ( array( 'author_a', 'author_b', 'editor', 'admin', 'integration' ) as $principal ) {
			wp_set_current_user( $principals[ $principal ] );
			$this->assert_true( $this->search->check_permissions( array() ), $principal . ' search permission was denied.' );
			$this->assert_true( $this->seo->check_permissions( array() ), $principal . ' SEO permission was denied.' );
			$this->assert_true( $this->editorial->check_permissions( array() ), $principal . ' editorial permission was denied.' );
		}
	}

	/**
	 * Verifies native object visibility for detail reads.
	 *
	 * @param array<string, int> $principals Principal IDs.
	 * @param array<string, int> $objects    Object IDs.
	 * @return void
	 */
	private function verify_detail_matrix( array $principals, array $objects ): void {
		$this->assert_detail( $principals['author_a'], $objects['published_post'], true, 'Author published post.' );
		$this->assert_detail( $principals['author_a'], $objects['own_draft'], true, 'Author own draft.' );
		$this->assert_detail( $principals['author_a'], $objects['foreign_draft'], false, 'Author foreign draft.' );
		$this->assert_detail( $principals['author_a'], $objects['private_post'], true, 'Author own private post.' );
		$this->assert_detail( $principals['author_a'], $objects['foreign_private'], false, 'Author foreign private post.' );

		foreach ( array( 'editor', 'admin' ) as $principal ) {
			foreach ( array( 'published_post', 'own_draft', 'foreign_draft', 'private_post', 'foreign_private', 'published_page', 'published_cpt' ) as $object ) {
				$this->assert_detail( $principals[ $principal ], $objects[ $object ], true, $principal . ' ' . $object . '.' );
			}
		}

		$this->assert_detail( $principals['integration'], $objects['published_post'], true, 'Integration published post.' );
		$this->assert_detail( $principals['integration'], $objects['published_page'], true, 'Integration published page.' );
		$this->assert_detail( $principals['integration'], $objects['published_cpt'], true, 'Integration published CPT.' );
		$this->assert_detail( $principals['integration'], $objects['own_draft'], false, 'Integration draft.' );
		$this->assert_detail( $principals['integration'], $objects['private_post'], false, 'Integration private post.' );

		$this->assert_seo( $principals['author_a'], $objects['published_post'], true, 'Author published SEO.' );
		$this->assert_seo( $principals['author_a'], $objects['own_draft'], true, 'Author own draft SEO.' );
		$this->assert_seo( $principals['author_a'], $objects['foreign_draft'], false, 'Author foreign draft SEO.' );
		$this->assert_seo( $principals['integration'], $objects['published_post'], true, 'Integration published SEO.' );
		$this->assert_seo( $principals['integration'], $objects['private_post'], false, 'Integration private SEO.' );
		$this->assert_seo( $principals['editor'], $objects['foreign_private'], true, 'Editor foreign private SEO.' );
	}

	/**
	 * Verifies search IDs and totals do not disclose unreadable fixtures.
	 *
	 * @param array<string, int> $principals Principal IDs.
	 * @param array<string, int> $objects    Object IDs.
	 * @return void
	 */
	private function verify_search_matrix( array $principals, array $objects ): void {
		$this->assert_search(
			$principals['author_a'],
			array( $objects['published_post'], $objects['own_draft'], $objects['private_post'] ),
			'Author search.'
		);
		$this->assert_search(
			$principals['integration'],
			array( $objects['published_post'] ),
			'Integration search.'
		);
		$this->assert_search(
			$principals['editor'],
			array( $objects['published_post'], $objects['own_draft'], $objects['foreign_draft'], $objects['private_post'], $objects['foreign_private'] ),
			'Editor search.'
		);
		$this->assert_search(
			$principals['admin'],
			array( $objects['published_post'], $objects['own_draft'], $objects['foreign_draft'], $objects['private_post'], $objects['foreign_private'] ),
			'Administrator search.'
		);
	}

	/**
	 * Proves configuration remains an independent authorization gate.
	 *
	 * @param array<string, int> $principals Principal IDs.
	 * @param array<string, int> $objects    Object IDs.
	 * @return void
	 */
	private function verify_policy_gate( array $principals, array $objects ): void {
		$policy = get_option( WordPressContentAccessSettingsRepository::OPTION_NAME, array() );
		if ( ! is_array( $policy ) ) {
			throw new RuntimeException( 'Fixture policy is invalid.' );
		}

		$policy['post'] = array();
		update_option( WordPressContentAccessSettingsRepository::OPTION_NAME, $policy, false );
		$this->assert_detail( $principals['admin'], $objects['published_post'], false, 'Policy-disabled administrator post.' );
		$this->assert_seo( $principals['admin'], $objects['published_post'], false, 'Policy-disabled administrator SEO.' );
		wp_set_current_user( $principals['admin'] );
		$editorial = $this->editorial->execute( array( 'post_types' => array( 'post' ) ) );
		$this->assert_true( is_wp_error( $editorial ) && 'wpcb_invalid_input' === $editorial->get_error_code(), 'Policy-disabled editorial context was not denied.' );
		$this->enable_fixture_policies();
	}

	/**
	 * Verifies representations and absence of arbitrary post meta.
	 *
	 * @param array<string, int> $principals Principal IDs.
	 * @param array<string, int> $objects    Object IDs.
	 * @return void
	 */
	private function verify_content_contract( array $principals, array $objects ): void {
		wp_set_current_user( $principals['admin'] );
		$result = $this->get->execute(
			array(
				'post_id'         => $objects['published_post'],
				'representations' => array( 'raw', 'rendered', 'plain_text' ),
				'include'         => array( 'author', 'taxonomies', 'revision' ),
			)
		);
		$this->assert_not_error( $result, 'Content contract.' );
		if ( ! is_array( $result ) ) {
			throw new RuntimeException( 'Content contract result is not an array.' );
		}

		$post = get_post( $objects['published_post'] );
		$this->assert_true( null !== $post && $result['representations']['raw'] === $post->post_content, 'Raw Gutenberg source drifted.' );
		$this->assert_true( str_contains( $result['representations']['plain_text'], $this->token . ' Alpha Beta' ), 'Plain text was not normalized.' );
		$encoded = wp_json_encode( $result );
		$this->assert_true( is_string( $encoded ) && ! str_contains( $encoded, '_wpcb_fixture_secret' ) && ! str_contains( $encoded, 'must-not-leak' ), 'Arbitrary post meta leaked.' );

		$seo = $this->seo->execute( array( 'post_id' => $objects['published_post'] ) );
		$this->assert_not_error( $seo, 'SEO leakage contract.' );
		$encoded_seo = wp_json_encode( $seo );
		$this->assert_true( is_string( $encoded_seo ) && ! str_contains( $encoded_seo, '_wpcb_fixture_secret' ) && ! str_contains( $encoded_seo, 'must-not-leak' ), 'Arbitrary post meta leaked through SEO.' );

		$editorial = $this->editorial->execute(
			array(
				'post_types'   => array( 'post' ),
				'recent_limit' => 50,
			)
		);
		$this->assert_not_error( $editorial, 'Editorial leakage contract.' );
		$encoded_editorial = wp_json_encode( $editorial );
		$this->assert_true(
			is_string( $encoded_editorial )
			&& ! str_contains( $encoded_editorial, '_wpcb_fixture_secret' )
			&& ! str_contains( $encoded_editorial, 'must-not-leak' )
			&& ! str_contains( $encoded_editorial, 'user_email' )
			&& ! str_contains( $encoded_editorial, 'user_login' ),
			'Private data leaked through editorial context.'
		);
	}

	/**
	 * Verifies payload measurements and the hard representation-size boundary.
	 *
	 * @param array<string, int> $principals Principal IDs.
	 * @param array<string, int> $objects    Object IDs.
	 * @return void
	 */
	private function verify_payload_contract( array $principals, array $objects ): void {
		wp_set_current_user( $principals['admin'] );
		$result = $this->get->execute(
			array(
				'post_id'         => $objects['published_page'],
				'representations' => array( 'raw', 'rendered', 'plain_text' ),
			)
		);
		$this->assert_not_error( $result, 'Block-heavy payload contract.' );
		if ( ! is_array( $result ) ) {
			throw new RuntimeException( 'Block-heavy payload result is not an array.' );
		}

		$bytes = $result['payload']['representation_bytes'] ?? null;
		$total = $result['payload']['total_representation_bytes'] ?? null;
		$this->assert_true( is_array( $bytes ) && is_int( $total ), 'Payload measurements are missing.' );
		$this->assert_true( array_sum( $bytes ) === $total, 'Payload byte total does not match representation measurements.' );
		$this->assert_true( $total <= GetContent::MAX_REPRESENTATION_BYTES, 'Block-heavy payload exceeded the configured limit.' );

		$encoded = wp_json_encode( $result );
		$this->assert_true( is_string( $encoded ), 'Block-heavy payload could not be encoded.' );
		$this->payload_measurements = array(
			'blocks'                     => 500,
			'raw_bytes'                  => isset( $bytes['raw'] ) ? (int) $bytes['raw'] : 0,
			'rendered_bytes'             => isset( $bytes['rendered'] ) ? (int) $bytes['rendered'] : 0,
			'plain_text_bytes'           => isset( $bytes['plain_text'] ) ? (int) $bytes['plain_text'] : 0,
			'total_representation_bytes' => $total,
			'encoded_response_bytes'     => strlen( $encoded ),
			'limit_bytes'                => GetContent::MAX_REPRESENTATION_BYTES,
		);

		$oversized = $this->get->execute(
			array(
				'post_id'         => $objects['oversized_page'],
				'representations' => array( 'raw' ),
			)
		);
		$this->assert_true( is_wp_error( $oversized ) && 'wpcb_content_too_large' === $oversized->get_error_code(), 'Oversized payload was not rejected with the stable error code.' );
	}

	/**
	 * Asserts one detail call.
	 *
	 * @param int    $user_id User ID.
	 * @param int    $post_id Post ID.
	 * @param bool   $allowed Expected access.
	 * @param string $label   Assertion label.
	 * @return void
	 */
	private function assert_detail( int $user_id, int $post_id, bool $allowed, string $label ): void {
		wp_set_current_user( $user_id );
		$result = $this->get->execute( array( 'post_id' => $post_id ) );
		if ( $allowed ) {
			$this->assert_not_error( $result, $label );
			return;
		}

		$this->assert_true( is_wp_error( $result ) && 'wpcb_content_unavailable' === $result->get_error_code(), $label . ' Expected non-enumerating denial.' );
	}

	/**
	 * Asserts one SEO call uses the same non-enumerating object boundary.
	 *
	 * @param int    $user_id User ID.
	 * @param int    $post_id Post ID.
	 * @param bool   $allowed Expected access.
	 * @param string $label   Assertion label.
	 * @return void
	 */
	private function assert_seo( int $user_id, int $post_id, bool $allowed, string $label ): void {
		wp_set_current_user( $user_id );
		$result = $this->seo->execute( array( 'post_id' => $post_id ) );
		if ( $allowed ) {
			$this->assert_not_error( $result, $label );
			return;
		}

		$this->assert_true( is_wp_error( $result ) && 'wpcb_content_unavailable' === $result->get_error_code(), $label . ' Expected non-enumerating denial.' );
	}

	/**
	 * Asserts exact search IDs and totals.
	 *
	 * @param int    $user_id     User ID.
	 * @param array  $expected_ids Expected IDs.
	 * @param string $label       Assertion label.
	 * @return void
	 * @phpstan-param list<int> $expected_ids
	 */
	private function assert_search( int $user_id, array $expected_ids, string $label ): void {
		wp_set_current_user( $user_id );
		$result = $this->search->execute(
			array(
				'query'      => $this->token,
				'post_types' => array( 'post' ),
				'statuses'   => array( 'publish', 'draft', 'private' ),
				'per_page'   => 100,
			)
		);
		$this->assert_not_error( $result, $label );
		if ( ! is_array( $result ) ) {
			throw new RuntimeException( $label . ' Search result is not an array.' );
		}

		$actual_ids = array_map( 'intval', array_column( $result['items'], 'id' ) );
		sort( $actual_ids );
		sort( $expected_ids );
		$this->assert_true( $expected_ids === $actual_ids, $label . ' Returned IDs differ.' );
		$this->assert_true( count( $expected_ids ) === $result['pagination']['total_items'], $label . ' Pagination total disclosed unreadable objects.' );
	}

	/**
	 * Asserts a true value.
	 *
	 * @param mixed  $value Value to check.
	 * @param string $message Failure message.
	 * @return void
	 */
	private function assert_true( mixed $value, string $message ): void {
		if ( true !== $value ) {
			throw new RuntimeException( $message );
		}
	}

	/**
	 * Asserts a denied permission result.
	 *
	 * @param mixed  $value Value to check.
	 * @param string $message Failure message.
	 * @return void
	 */
	private function assert_false( mixed $value, string $message ): void {
		if ( false !== $value && ! is_wp_error( $value ) ) {
			throw new RuntimeException( $message );
		}
	}

	/**
	 * Asserts an ability result is not an error.
	 *
	 * @param mixed  $value Value to check.
	 * @param string $label Assertion label.
	 * @return void
	 */
	private function assert_not_error( mixed $value, string $label ): void {
		if ( $value instanceof WP_Error ) {
			throw new RuntimeException( $label . ' ' . $value->get_error_code() . ': ' . $value->get_error_message() );
		}
	}

	/**
	 * Removes exact fixture IDs and restores the prior option.
	 *
	 * @return void
	 */
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

		require_once ABSPATH . 'wp-admin/includes/user.php';
		foreach ( $this->user_ids as $user_id ) {
			wp_delete_user( $user_id );
		}

		if ( null === $this->original_policy ) {
			delete_option( WordPressContentAccessSettingsRepository::OPTION_NAME );
		} else {
			update_option( WordPressContentAccessSettingsRepository::OPTION_NAME, $this->original_policy, false );
		}

		if ( post_type_exists( $this->fixture_post_type ) ) {
			unregister_post_type( $this->fixture_post_type );
		}
	}
}

( new WPCB_Authorization_Matrix() )->run();

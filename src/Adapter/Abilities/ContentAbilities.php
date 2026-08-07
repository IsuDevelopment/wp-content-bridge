<?php
/**
 * WordPress Abilities projection for content reads.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Adapter\Abilities;

use InvalidArgumentException;
use IsuDev\WPContentBridge\Application\Content\ContentUnavailable;
use IsuDev\WPContentBridge\Application\Content\ContentPayloadTooLarge;
use IsuDev\WPContentBridge\Application\Content\GetBlockTree;
use IsuDev\WPContentBridge\Application\Content\GetContent;
use IsuDev\WPContentBridge\Application\Content\SearchContent;
use IsuDev\WPContentBridge\Application\ContentAccess\ContentAccessManager;
use IsuDev\WPContentBridge\Application\Editorial\GetEditorialContext;
use IsuDev\WPContentBridge\Application\Seo\SeoProviderRegistry;
use IsuDev\WPContentBridge\Domain\Content\ContentQuery;
use IsuDev\WPContentBridge\Domain\ContentAccess\ContentOperation;
use IsuDev\WPContentBridge\Domain\Editorial\EditorialContextQuery;
use Throwable;
use WP_Error;

/**
 * Registers thin ability callbacks around application services.
 */
final readonly class ContentAbilities {

	private const CATEGORY = 'wp-content-bridge';

	/**
	 * Creates the Abilities projection.
	 *
	 * @param SearchContent        $search Search use case.
	 * @param GetContent           $get    Detail use case.
	 * @param GetBlockTree         $get_block_tree Block-tree use case.
	 * @param GetEditorialContext  $editorial Editorial context use case.
	 * @param ContentAccessManager $access Shared policy.
	 * @param SeoProviderRegistry  $seo_providers SEO provider selection.
	 */
	public function __construct(
		private SearchContent $search,
		private GetContent $get,
		private GetBlockTree $get_block_tree,
		private GetEditorialContext $editorial,
		private ContentAccessManager $access,
		private SeoProviderRegistry $seo_providers,
	) {
	}

	/**
	 * Registers WordPress lifecycle hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'wp_abilities_api_categories_init', array( $this, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
	}

	/**
	 * Registers the public ability category.
	 *
	 * @return void
	 */
	public function register_category(): void {
		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'WP Content Bridge', 'wp-content-bridge' ),
				'description' => __( 'Access-aware WordPress content and SEO operations for agent clients.', 'wp-content-bridge' ),
			)
		);
	}

	/**
	 * Registers the read-only abilities.
	 *
	 * @return void
	 */
	public function register_abilities(): void {
		wp_register_ability(
			'wp-content-bridge/search-content',
			array(
				'label'               => __( 'Search content', 'wp-content-bridge' ),
				'description'         => __( 'Searches configured WordPress content types and returns only objects readable by the current principal.', 'wp-content-bridge' ),
				'category'            => self::CATEGORY,
				'input_schema'        => AbilitySchemas::search_input(),
				'output_schema'       => AbilitySchemas::search_output(),
				'permission_callback' => array( $this, 'can_read' ),
				'execute_callback'    => array( $this, 'execute_search' ),
				'meta'                => self::read_meta(),
			)
		);

		wp_register_ability(
			'wp-content-bridge/get-content',
			array(
				'label'               => __( 'Get content', 'wp-content-bridge' ),
				'description'         => __( 'Returns selected source, rendered, or plain-text representations of one readable WordPress content object.', 'wp-content-bridge' ),
				'category'            => self::CATEGORY,
				'input_schema'        => AbilitySchemas::get_input(),
				'output_schema'       => AbilitySchemas::get_output(),
				'permission_callback' => array( $this, 'can_read' ),
				'execute_callback'    => array( $this, 'execute_get' ),
				'meta'                => self::read_meta(),
			)
		);

		wp_register_ability(
			'wp-content-bridge/get-block-tree',
			array(
				'label'               => __( 'Get block tree', 'wp-content-bridge' ),
				'description'         => __( "Returns one readable WordPress content object's Gutenberg block structure as a flat, path-addressed node list, without full block markup. parse_blocks() emits block_name: null freeform nodes for whitespace between blocks; these occupy real array indices that a later block-level write mutates and are always included, never skipped. Each node's text prefers its own innerHTML but falls back to prose-bearing string attributes when that is empty; raw attrs are omitted by default and returned only when include_attrs is true.", 'wp-content-bridge' ),
				'category'            => self::CATEGORY,
				'input_schema'        => AbilitySchemas::get_block_tree_input(),
				'output_schema'       => AbilitySchemas::get_block_tree_output(),
				'permission_callback' => array( $this, 'can_read' ),
				'execute_callback'    => array( $this, 'execute_get_block_tree' ),
				'meta'                => self::read_meta(),
			)
		);

		wp_register_ability(
			'wp-content-bridge/get-diagnostics',
			array(
				'label'               => __( 'Get bridge diagnostics', 'wp-content-bridge' ),
				'description'         => __( 'Returns safe compatibility and content-policy diagnostics without paths, secrets, or user data.', 'wp-content-bridge' ),
				'category'            => self::CATEGORY,
				'output_schema'       => AbilitySchemas::diagnostics_output(),
				'permission_callback' => array( $this, 'can_read' ),
				'execute_callback'    => array( $this, 'execute_diagnostics' ),
				'meta'                => self::read_meta(),
			)
		);

		wp_register_ability(
			'wp-content-bridge/get-editorial-context',
			array(
				'label'               => __( 'Get editorial context', 'wp-content-bridge' ),
				'description'         => __( 'Returns bounded policy-approved content vocabulary, readable recent inventory, observed authors, and normalized public Local business data for editorial planning.', 'wp-content-bridge' ),
				'category'            => self::CATEGORY,
				'input_schema'        => AbilitySchemas::editorial_context_input(),
				'output_schema'       => AbilitySchemas::editorial_context_output(),
				'permission_callback' => array( $this, 'can_read' ),
				'execute_callback'    => array( $this, 'execute_editorial_context' ),
				'meta'                => self::read_meta(),
			)
		);
	}

	/**
	 * Checks the plugin-level capability.
	 *
	 * @param mixed $input Unused ability input.
	 * @return bool
	 */
	public function can_read( mixed $input = null ): bool {
		unset( $input );

		return current_user_can( 'wpcb_read_content' );
	}

	/**
	 * Executes content search.
	 *
	 * @param mixed $input Validated ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public function execute_search( mixed $input = array() ): array|WP_Error {
		if ( ! $this->can_read() ) {
			return self::forbidden();
		}

		try {
			$normalized = self::normalize_input( $input );

			return $this->search->execute( ContentQuery::from_input( $normalized ) )->to_array();
		} catch ( InvalidArgumentException $exception ) {
			return new WP_Error( 'wpcb_invalid_input', $exception->getMessage() );
		} catch ( Throwable ) {
			return self::internal_error();
		}
	}

	/**
	 * Executes content detail retrieval.
	 *
	 * @param mixed $input Validated ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public function execute_get( mixed $input = array() ): array|WP_Error {
		if ( ! $this->can_read() ) {
			return self::forbidden();
		}

		try {
			$normalized      = self::normalize_input( $input );
			$post_id         = isset( $normalized['post_id'] ) && is_int( $normalized['post_id'] ) ? $normalized['post_id'] : 0;
			$representations = self::selected_strings( $normalized['representations'] ?? array( 'raw', 'plain_text' ) );
			$include         = self::selected_strings( $normalized['include'] ?? array() );

			if ( $post_id < 1 ) {
				return new WP_Error( 'wpcb_invalid_input', 'post_id must be a positive integer.' );
			}

			return $this->get->execute( $post_id, $representations, $include )->to_array();
		} catch ( ContentPayloadTooLarge ) {
			return new WP_Error( 'wpcb_content_too_large', __( 'Selected content representations exceed the 2 MiB response limit. Request fewer representations.', 'wp-content-bridge' ) );
		} catch ( ContentUnavailable ) {
			return new WP_Error( 'wpcb_content_unavailable', __( 'Content is unavailable.', 'wp-content-bridge' ) );
		} catch ( InvalidArgumentException $exception ) {
			return new WP_Error( 'wpcb_invalid_input', $exception->getMessage() );
		} catch ( Throwable ) {
			return self::internal_error();
		}
	}

	/**
	 * Executes block-tree retrieval.
	 *
	 * @param mixed $input Validated ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public function execute_get_block_tree( mixed $input = array() ): array|WP_Error {
		if ( ! $this->can_read() ) {
			return self::forbidden();
		}

		try {
			$normalized    = self::normalize_block_tree_input( $input );
			$post_id       = isset( $normalized['post_id'] ) && is_int( $normalized['post_id'] ) ? $normalized['post_id'] : 0;
			$path          = self::selected_path( $normalized['path'] ?? array() );
			$max_depth     = isset( $normalized['max_depth'] ) && is_int( $normalized['max_depth'] ) ? $normalized['max_depth'] : null;
			$include_attrs = $normalized['include_attrs'] ?? false;

			if ( $post_id < 1 ) {
				return new WP_Error( 'wpcb_invalid_input', 'post_id must be a positive integer.' );
			}
			if ( ! is_bool( $include_attrs ) ) {
				return new WP_Error( 'wpcb_invalid_input', 'include_attrs must be a boolean.' );
			}

			return $this->get_block_tree->execute( $post_id, $path, $max_depth, $include_attrs )->to_array();
		} catch ( ContentUnavailable ) {
			return new WP_Error( 'wpcb_content_unavailable', __( 'Content is unavailable.', 'wp-content-bridge' ) );
		} catch ( InvalidArgumentException $exception ) {
			return new WP_Error( 'wpcb_invalid_input', $exception->getMessage() );
		} catch ( Throwable ) {
			return self::internal_error();
		}
	}

	/**
	 * Returns bounded context for client-side editorial planning.
	 *
	 * @param mixed $input Validated ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public function execute_editorial_context( mixed $input = array() ): array|WP_Error {
		if ( ! $this->can_read() ) {
			return self::forbidden();
		}

		try {
			return $this->editorial->execute( EditorialContextQuery::from_input( self::normalize_editorial_input( $input ) ) )->to_array();
		} catch ( InvalidArgumentException $exception ) {
			return new WP_Error( 'wpcb_invalid_input', $exception->getMessage() );
		} catch ( Throwable ) {
			return self::internal_error();
		}
	}

	/**
	 * Returns safe compatibility diagnostics.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public function execute_diagnostics(): array|WP_Error {
		if ( ! $this->can_read() ) {
			return self::forbidden();
		}

		$readable = array();
		foreach ( $this->access->content_types() as $definition ) {
			if ( $this->access->allows( $definition->name, ContentOperation::READ ) ) {
				$readable[] = $definition->name;
			}
		}

		return array(
			'schema_version'                   => '1.0',
			'plugin_version'                   => defined( 'WPCB_VERSION' ) ? WPCB_VERSION : 'unknown',
			'wordpress_version'                => get_bloginfo( 'version' ),
			'abilities_api'                    => function_exists( 'wp_register_ability' ),
			'mcp_adapter'                      => self::mcp_adapter_active(),
			'max_content_representation_bytes' => GetContent::MAX_REPRESENTATION_BYTES,
			'seo_provider'                     => $this->seo_providers->active()->status()->to_array(),
			'readable_post_types'              => $readable,
		);
	}

	/**
	 * Detects the official WordPress/mcp-adapter plugin across supported versions.
	 *
	 * The pre-stable adapter defined `WP_MCP_ADAPTER_VERSION`/`wp_register_mcp_server()`.
	 * The stable v0.5.0 release defines neither; it registers `WP\MCP\Core\McpAdapter` and
	 * fires the `mcp_adapter_init` action instead, so both are checked as well.
	 *
	 * @return bool
	 */
	private static function mcp_adapter_active(): bool {
		return defined( 'WP_MCP_ADAPTER_VERSION' )
			|| function_exists( 'wp_register_mcp_server' )
			|| class_exists( '\WP\MCP\Core\McpAdapter' )
			|| has_action( 'mcp_adapter_init' );
	}

	/**
	 * Normalizes a selected string list.
	 *
	 * @param mixed $value Candidate selection.
	 * @return list<string>
	 * @throws InvalidArgumentException When invalid.
	 */
	private static function selected_strings( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			throw new InvalidArgumentException( 'Selection must be an array.' );
		}

		$result = array();
		foreach ( $value as $item ) {
			if ( ! is_string( $item ) ) {
				throw new InvalidArgumentException( 'Selection contains an invalid value.' );
			}
			$result[] = $item;
		}

		return array_values( array_unique( $result ) );
	}

	/**
	 * Keeps string keys from arbitrary callback input.
	 *
	 * @param mixed $input Callback input.
	 * @return array<string, mixed>
	 */
	private static function normalize_input( mixed $input ): array {
		if ( ! is_array( $input ) ) {
			return array();
		}

		$normalized = array();
		foreach ( $input as $key => $value ) {
			if ( is_string( $key ) ) {
				$normalized[ $key ] = $value;
			}
		}

		return $normalized;
	}

	/**
	 * Normalizes REST query-string integers before domain validation.
	 *
	 * Direct Ability calls retain their integer values. WordPress REST query
	 * parsing may preserve nested scalar integers as decimal strings.
	 *
	 * @param mixed $input Callback input.
	 * @return array<string, mixed>
	 */
	private static function normalize_editorial_input( mixed $input ): array {
		$normalized = self::normalize_input( $input );
		foreach ( array( 'recent_limit', 'terms_per_taxonomy' ) as $key ) {
			if ( isset( $normalized[ $key ] ) && is_string( $normalized[ $key ] ) && ctype_digit( $normalized[ $key ] ) ) {
				$normalized[ $key ] = (int) $normalized[ $key ];
			}
		}

		return $normalized;
	}

	/**
	 * Normalizes REST query-string integers before domain validation.
	 *
	 * Direct Ability calls retain their integer values. WordPress REST query
	 * parsing may preserve nested scalar integers as decimal strings.
	 *
	 * @param mixed $input Callback input.
	 * @return array<string, mixed>
	 */
	private static function normalize_block_tree_input( mixed $input ): array {
		$normalized = self::normalize_input( $input );
		foreach ( array( 'post_id', 'max_depth' ) as $key ) {
			if ( isset( $normalized[ $key ] ) && is_string( $normalized[ $key ] ) && ctype_digit( $normalized[ $key ] ) ) {
				$normalized[ $key ] = (int) $normalized[ $key ];
			}
		}

		return $normalized;
	}

	/**
	 * Normalizes a block-tree path.
	 *
	 * @param mixed $value Candidate path.
	 * @return list<int>
	 * @throws InvalidArgumentException When invalid.
	 */
	private static function selected_path( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			throw new InvalidArgumentException( 'path must be an array of non-negative integers.' );
		}

		$result = array();
		foreach ( $value as $item ) {
			if ( is_string( $item ) && ctype_digit( $item ) ) {
				$item = (int) $item;
			}
			if ( ! is_int( $item ) || 0 > $item ) {
				throw new InvalidArgumentException( 'path must contain only non-negative integers.' );
			}
			$result[] = $item;
		}

		return $result;
	}

	/**
	 * Returns standard read annotations.
	 *
	 * @return array<string, mixed>
	 */
	private static function read_meta(): array {
		return array(
			'annotations'  => array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			),
			'show_in_rest' => true,
			'mcp'          => array( 'public' => true ),
		);
	}

	/**
	 * Creates a stable forbidden result.
	 *
	 * @return WP_Error
	 */
	private static function forbidden(): WP_Error {
		return new WP_Error( 'wpcb_forbidden', __( 'You are not allowed to read content through WP Content Bridge.', 'wp-content-bridge' ) );
	}

	/**
	 * Returns an opaque error without leaking internal details.
	 *
	 * @return WP_Error
	 */
	private static function internal_error(): WP_Error {
		return new WP_Error( 'wpcb_internal_error', __( 'The content operation could not be completed.', 'wp-content-bridge' ) );
	}
}

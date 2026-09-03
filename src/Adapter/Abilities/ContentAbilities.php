<?php
/**
 * WordPress Abilities projection for content reads.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Adapter\Abilities;

use InvalidArgumentException;
use IsuDev\WPContentBridge\Adapter\Mcp\McpServerProvider;
use IsuDev\WPContentBridge\Application\Content\ContentUnavailable;
use IsuDev\WPContentBridge\Application\Content\ContentPayloadTooLarge;
use IsuDev\WPContentBridge\Application\Content\GetBlockTree;
use IsuDev\WPContentBridge\Application\Content\GetContent;
use IsuDev\WPContentBridge\Application\Content\SearchContent;
use IsuDev\WPContentBridge\Application\ContentAccess\ContentAccessManager;
use IsuDev\WPContentBridge\Application\Editorial\GetEditorialContext;
use IsuDev\WPContentBridge\Application\Redirect\RedirectProviderRegistry;
use IsuDev\WPContentBridge\Application\Seo\SeoProviderRegistry;
use IsuDev\WPContentBridge\Domain\Content\ContentQuery;
use IsuDev\WPContentBridge\Domain\ContentAccess\ContentOperation;
use IsuDev\WPContentBridge\Domain\Editorial\EditorialContextQuery;
use ReflectionException;
use ReflectionFunction;
use Throwable;
use WP_Error;

/**
 * Registers thin ability callbacks around application services.
 */
final readonly class ContentAbilities {

	private const CATEGORY = AbilityCategory::SLUG;

	/**
	 * Creates the Abilities projection.
	 *
	 * @param SearchContent            $search Search use case.
	 * @param GetContent               $get    Detail use case.
	 * @param GetBlockTree             $get_block_tree Block-tree use case.
	 * @param GetEditorialContext      $editorial Editorial context use case.
	 * @param ContentAccessManager     $access Shared policy.
	 * @param SeoProviderRegistry      $seo_providers SEO provider selection.
	 * @param RedirectProviderRegistry $redirect_providers Redirect provider detection, for diagnostics only.
	 * @param bool                     $redirects_enabled Whether the redirect abilities are switched on.
	 */
	public function __construct(
		private SearchContent $search,
		private GetContent $get,
		private GetBlockTree $get_block_tree,
		private GetEditorialContext $editorial,
		private ContentAccessManager $access,
		private SeoProviderRegistry $seo_providers,
		private RedirectProviderRegistry $redirect_providers,
		private bool $redirects_enabled,
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
				'meta'                => AbilityMeta::read(),
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
				'meta'                => AbilityMeta::read(),
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
				'meta'                => AbilityMeta::read(),
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
				'meta'                => AbilityMeta::read(),
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
				'meta'                => AbilityMeta::read(),
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
			return AbilityError::create( 'wpcb_invalid_input', $exception->getMessage() );
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
				return AbilityError::create( 'wpcb_invalid_input', 'post_id must be a positive integer.' );
			}

			return $this->get->execute( $post_id, $representations, $include )->to_array();
		} catch ( ContentPayloadTooLarge ) {
			return AbilityError::create( 'wpcb_content_too_large', __( 'Selected content representations exceed the 2 MiB response limit. Request fewer representations.', 'wp-content-bridge' ) );
		} catch ( ContentUnavailable ) {
			return AbilityError::create( 'wpcb_content_unavailable', __( 'Content is unavailable.', 'wp-content-bridge' ) );
		} catch ( InvalidArgumentException $exception ) {
			return AbilityError::create( 'wpcb_invalid_input', $exception->getMessage() );
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
				return AbilityError::create( 'wpcb_invalid_input', 'post_id must be a positive integer.' );
			}
			if ( ! is_bool( $include_attrs ) ) {
				return AbilityError::create( 'wpcb_invalid_input', 'include_attrs must be a boolean.' );
			}

			return $this->get_block_tree->execute( $post_id, $path, $max_depth, $include_attrs )->to_array();
		} catch ( ContentUnavailable ) {
			return AbilityError::create( 'wpcb_content_unavailable', __( 'Content is unavailable.', 'wp-content-bridge' ) );
		} catch ( InvalidArgumentException $exception ) {
			return AbilityError::create( 'wpcb_invalid_input', $exception->getMessage() );
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
			return AbilityError::create( 'wpcb_invalid_input', $exception->getMessage() );
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
			'schema_version'                   => '1.2',
			'plugin_version'                   => defined( 'WPCB_VERSION' ) ? WPCB_VERSION : 'unknown',
			'wordpress_version'                => get_bloginfo( 'version' ),
			'minimum_wordpress_version'        => self::minimum_wordpress_version(),
			'abilities_api'                    => function_exists( 'wp_register_ability' ),
			'abilities_api_features'           => self::abilities_api_features(),
			'mcp_adapter'                      => self::mcp_adapter_active(),
			'mcp_projection'                   => McpServerProvider::projection_status(),
			'max_content_representation_bytes' => GetContent::MAX_REPRESENTATION_BYTES,
			'seo_provider'                     => $this->seo_providers->active()->status()->to_array(),
			'redirects'                        => $this->redirect_status(),
			'readable_post_types'              => $readable,
		);
	}

	/**
	 * Reports redirect provider detection, which ADR 0026 s4 requires
	 * diagnostics to carry.
	 *
	 * `enabled` and `providers` are separate on purpose. An operator whose
	 * redirect abilities are absent has two very different problems - the
	 * switch is off, or neither backend is installed - and one combined
	 * answer would not tell them apart. That is also why this is reported
	 * with the feature off: the registry is built outside the switch (see
	 * `Plugin::boot()`) precisely so the second question stays answerable.
	 *
	 * `providers` reports every *configured* adapter with its own `detected`
	 * flag, not only the available ones, so "Yoast Premium is not installed
	 * here" is visible rather than absent. Statistics are deliberately not
	 * reported here: they are a separate port with its own availability, and
	 * statistics availability does not follow redirect availability
	 * (ADR 0030 s1).
	 *
	 * @return array<string, mixed>
	 */
	private function redirect_status(): array {
		return array(
			'enabled'   => $this->redirects_enabled,
			'providers' => array_map(
				static fn ( $status ): array => $status->to_array(),
				$this->redirect_providers->statuses()
			),
		);
	}

	/**
	 * Detects the official WordPress/mcp-adapter plugin across supported versions.
	 *
	 * Delegates to the projection adapter so detection has one implementation:
	 * the settings screen reports the same answer this diagnostic does.
	 *
	 * @return bool
	 */
	private static function mcp_adapter_active(): bool {
		return McpServerProvider::adapter_active();
	}

	/**
	 * Probes which Abilities API capabilities this WordPress actually exposes.
	 *
	 * `abilities_api` alone cannot tell 7.0 from 7.1, so "the feature is
	 * missing" and "the API is missing" used to read identically here. Every
	 * entry below is probed from the running code rather than compared against
	 * `get_bloginfo( 'version' )`, because a version string is a claim and a
	 * reflected signature is an observation — and because a site can run a
	 * patched or partially loaded core.
	 *
	 * Reported capabilities are limited to the ones this plugin's own code
	 * depends on and can actually test for. `wp_ability_invoked`, `meta.public`
	 * and the execution filters are deliberately absent: an action that has not
	 * fired and a meta key read only during registration leave nothing to
	 * observe, and a probe that guessed from the version string would be the one
	 * thing this report exists to avoid. REST input coercion is likewise absent —
	 * core performs it, this plugin never calls it, and there is nothing an
	 * operator could act on.
	 *
	 * @return array<string, bool>
	 */
	private static function abilities_api_features(): array {
		$declarative_filtering = false;
		if ( function_exists( 'wp_get_abilities' ) ) {
			try {
				$declarative_filtering = ( new ReflectionFunction( 'wp_get_abilities' ) )->getNumberOfParameters() > 0;
			} catch ( ReflectionException ) {
				$declarative_filtering = false;
			}
		}

		return array(
			'declarative_filtering' => $declarative_filtering,
		);
	}

	/**
	 * Reads the WordPress version this plugin requires.
	 *
	 * Taken from the plugin header so the requirement has exactly one source of
	 * truth; a constant here would be a second copy to drift from `readme.txt`.
	 *
	 * @return string
	 */
	private static function minimum_wordpress_version(): string {
		if ( ! defined( 'WPCB_FILE' ) ) {
			return 'unknown';
		}

		$headers = get_file_data( WPCB_FILE, array( 'requires_wp' => 'Requires at least' ) );
		$value   = $headers['requires_wp'] ?? '';

		return '' !== $value ? $value : 'unknown';
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
	 * Creates a stable forbidden result.
	 *
	 * @return WP_Error
	 */
	private static function forbidden(): WP_Error {
		return AbilityError::create( 'wpcb_forbidden', __( 'You are not allowed to read content through WP Content Bridge.', 'wp-content-bridge' ) );
	}

	/**
	 * Returns an opaque error without leaking internal details.
	 *
	 * @return WP_Error
	 */
	private static function internal_error(): WP_Error {
		return AbilityError::create( 'wpcb_internal_error', __( 'The content operation could not be completed.', 'wp-content-bridge' ) );
	}
}

<?php
/**
 * MCP projection of this plugin's own abilities.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Adapter\Mcp;

use IsuDev\WPContentBridge\Adapter\Abilities\AbilityCategory;
use IsuDev\WPContentBridge\Infrastructure\WordPress\Installer;
use WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler;
use WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler;
use WP\MCP\Transport\HttpTransport;

/**
 * Hands every registered WP Content Bridge ability to the site's MCP Adapter.
 *
 * ADR 0025. The tool set is discovered from the ability registry by category on
 * each request, never from a hand-maintained name list: the abilities already
 * gate themselves — a disabled feature area is not registered, so it cannot be
 * discovered — and any copy of that list drifts out of date the moment an
 * ability is added. That drift is silent, which is worse than an outage.
 *
 * This class is the plugin's only MCP-aware code. It never bundles, installs,
 * or initializes the Adapter: it answers `mcp_adapter_init`, which only fires
 * when the site has installed and activated the Adapter itself. Transport,
 * authentication, and OAuth remain external and out of scope (ADR 0005,
 * ADR 0010).
 *
 * Projection is not authorization. Handing an ability to the Adapter grants
 * nothing: execution still requires the ability's WPCB capability, the native
 * WordPress object capability, per-type policy, schema validation, and the
 * write safeguards.
 */
final class McpServerProvider {

	/**
	 * Server identifier, kept stable so the endpoint URL never moves.
	 *
	 * @var string
	 */
	public const SERVER_ID = 'wpcb-bridge';

	/**
	 * REST namespace of the projected endpoint.
	 *
	 * @var string
	 */
	public const REST_NAMESPACE = 'wpcb-mcp';

	/**
	 * REST route of the projected endpoint, giving `/wp-json/wpcb-mcp/mcp`.
	 *
	 * @var string
	 */
	public const REST_ROUTE = 'mcp';

	/**
	 * Filter narrowing the projected ability set.
	 *
	 * @var string
	 */
	public const ABILITIES_FILTER = 'wp_content_bridge_mcp_abilities';

	/**
	 * Registers the projection hook.
	 *
	 * Priority 20 leaves a site-owned server registration at the default
	 * priority in charge: an install still carrying the retired
	 * `wp-content-bridge-mcp-server` MU-plugin keeps whatever profile it
	 * configured, and `create_server()` below then declines rather than
	 * registering `SERVER_ID` twice.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'mcp_adapter_init', array( $this, 'create_server' ), 20 );
	}

	/**
	 * Creates the MCP server for this plugin's registered abilities.
	 *
	 * The parameter is deliberately typed `object` rather than
	 * `WP\MCP\Core\McpAdapter`: the Adapter is not a dependency of this plugin,
	 * so its classes exist only on installs that added it. The positional
	 * argument list below matches the Adapter's stable v0.5.0
	 * `create_server()` signature; `method_exists()` keeps an install running
	 * an Adapter without that method from fataling, but a future upstream
	 * signature change is a real break and is meant to surface as one.
	 *
	 * @param object $adapter Adapter instance supplied by `mcp_adapter_init`.
	 * @return void
	 */
	public function create_server( object $adapter ): void {
		if (
			! class_exists( HttpTransport::class )
			|| ! class_exists( ErrorLogMcpErrorHandler::class )
			|| ! class_exists( NullMcpObservabilityHandler::class )
			|| ! method_exists( $adapter, 'create_server' )
		) {
			return;
		}

		// Best-effort duplicate guard for installs that still register the
		// retired MU-plugin server under the same identifier.
		if ( method_exists( $adapter, 'get_server' ) && null !== $adapter->get_server( self::SERVER_ID ) ) {
			return;
		}

		$abilities = self::abilities();

		if ( array() === $abilities ) {
			return;
		}

		$adapter->create_server(
			self::SERVER_ID,
			self::REST_NAMESPACE,
			self::REST_ROUTE,
			__( 'WP Content Bridge', 'wp-content-bridge' ),
			__( 'Capability-gated access to registered WP Content Bridge abilities.', 'wp-content-bridge' ),
			defined( 'WPCB_VERSION' ) ? WPCB_VERSION : '0.0.0',
			array( HttpTransport::class ),
			ErrorLogMcpErrorHandler::class,
			NullMcpObservabilityHandler::class,
			$abilities
		);
	}

	/**
	 * Whether the site projects its abilities as MCP tools.
	 *
	 * Absent means enabled; see `Installer::MCP_SERVER_ENABLED_OPTION`.
	 *
	 * @return bool
	 */
	public static function is_enabled(): bool {
		return (bool) get_option( Installer::MCP_SERVER_ENABLED_OPTION, true );
	}

	/**
	 * Reports what this plugin projects as MCP tools right now.
	 *
	 * `get-diagnostics` returns this because "the tool is missing from my
	 * client" and "the ability is not registered" were indistinguishable from
	 * the outside, and a stale hand-written projection list looked exactly like
	 * a disabled feature area. The reported names come from the same discovery
	 * the projection itself uses, so the two cannot disagree.
	 *
	 * @return array{enabled: bool, endpoint: string|null, projected_abilities: list<string>}
	 */
	public static function projection_status(): array {
		$enabled = self::is_enabled();
		$active  = $enabled && self::adapter_active();

		return array(
			'enabled'             => $enabled,
			'endpoint'            => $active ? rest_url( self::REST_NAMESPACE . '/' . self::REST_ROUTE ) : null,
			'projected_abilities' => $enabled ? self::abilities() : array(),
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
	public static function adapter_active(): bool {
		return defined( 'WP_MCP_ADAPTER_VERSION' )
			|| function_exists( 'wp_register_mcp_server' )
			|| class_exists( '\WP\MCP\Core\McpAdapter' )
			|| has_action( 'mcp_adapter_init' );
	}

	/**
	 * Returns the abilities to project, discovered by category.
	 *
	 * @return list<string>
	 */
	public static function abilities(): array {
		$discovered = self::discover();

		/**
		 * Filters the projected WP Content Bridge ability set.
		 *
		 * A site may narrow the projection to fewer abilities. Names outside
		 * the discovered set are dropped rather than honored: the projection
		 * can never reach an ability this plugin did not register, so an
		 * unrelated plugin's tools cannot enter this server through a filter.
		 *
		 * @param list<string> $discovered Registered WP Content Bridge ability names.
		 */
		$filtered = apply_filters( self::ABILITIES_FILTER, $discovered );

		return self::narrow( $discovered, $filtered );
	}

	/**
	 * Reads the registered abilities belonging to this plugin's category.
	 *
	 * WordPress 7.1 filters declaratively, so the category is a query rather
	 * than a convention (ADR 0027 makes 7.1 the minimum). The explicit
	 * comparison afterwards is **not** a migration shim and must stay:
	 * arguments to a userland PHP function are silently ignored, so on any
	 * WordPress that does not implement the filter this same call returns every
	 * registered ability on the site — including other plugins'. `narrow()`
	 * cannot catch that, because the discovered set is what widens, and the
	 * failure mode is a projection that hands a client tools this plugin never
	 * wrote. One `===` is cheap insurance against a silent, over-wide public
	 * surface.
	 *
	 * @return list<string>
	 */
	private static function discover(): array {
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			return array();
		}

		$names = array();

		foreach ( wp_get_abilities( array( 'category' => AbilityCategory::SLUG ) ) as $ability ) {
			if ( AbilityCategory::SLUG === $ability->get_category() ) {
				$names[] = $ability->get_name();
			}
		}

		sort( $names );

		return $names;
	}

	/**
	 * Intersects a filtered value with the discovered set, preserving its order.
	 *
	 * A non-array filter return is ignored rather than treated as an empty
	 * projection, so a misbehaving filter degrades to the full discovered set
	 * instead of silently emptying the server.
	 *
	 * @param array $discovered Registered ability names.
	 * @param mixed $filtered   Value returned by the filter.
	 * @return list<string>
	 * @phpstan-param list<string> $discovered
	 */
	private static function narrow( array $discovered, mixed $filtered ): array {
		if ( ! is_array( $filtered ) ) {
			return $discovered;
		}

		$kept = array();

		foreach ( $filtered as $name ) {
			if ( is_string( $name ) && in_array( $name, $discovered, true ) && ! in_array( $name, $kept, true ) ) {
				$kept[] = $name;
			}
		}

		return $kept;
	}
}

<?php
/**
 * Redirection (John Godley) provider adapter.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Infrastructure\Redirection;

use IsuDev\WPContentBridge\Application\Redirect\RedirectProvider;
use IsuDev\WPContentBridge\Application\Redirect\RedirectProviderUnavailable;
use IsuDev\WPContentBridge\Domain\Redirect\RedirectProviderStatus;
use IsuDev\WPContentBridge\Domain\Redirect\RedirectRule;
use IsuDev\WPContentBridge\Domain\Redirect\RedirectSourcePath;
use IsuDev\WPContentBridge\Domain\Redirect\RedirectStatusCode;
use IsuDev\WPContentBridge\Domain\Redirect\RedirectTargetUrl;
use WP_REST_Request;

/**
 * Calls the Redirection plugin's own `redirection/v1` REST routes through an
 * internal `rest_do_request()` dispatch — no HTTP loopback, no nonce — scoped
 * to WPCB's own capability rather than the plugin's `manage_options` default
 * (ADR 0026 s2).
 *
 * Reconciled against a live Redirection 5.9.0 install (2026-08-14) by reading
 * its actual source rather than only its public documentation, which
 * disagreed with it in three ways this class now follows instead:
 * - the read shape uses `enabled` (bool), never the documented `status`
 *   string;
 * - `POST /redirect` returns the *list* response (`{items, total}`), not the
 *   created item alone, so the created row is found by exact `url` match in
 *   `items`;
 * - the `url` search filter is a `LIKE '%…%'` substring match server-side, so
 *   an exact match is re-checked client-side rather than trusted from the
 *   first result.
 *
 * `group_id` on create is hardcoded to `1`, the "Redirections" group every
 * install's database installer creates by default. WPCB does not manage
 * groups; if a site's administrator renames or removes that group, `create()`
 * fails with Redirection's own "Invalid group" error rather than silently
 * writing into an unintended group. See ADR 0026 for the rest of this
 * adapter's boundary decisions.
 */
final class RedirectionProvider implements RedirectProvider {

	private const REST_NAMESPACE = 'redirection/v1';

	/**
	 * Creates the adapter for one WordPress site.
	 *
	 * @param string $site_url Canonical site URL, for target normalization.
	 */
	public function __construct( private readonly string $site_url ) {
	}

	/**
	 * Available only once the plugin's own REST namespace is registered —
	 * this does not depend on an internal class or constant that Redirection
	 * itself does not document.
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		if ( ! function_exists( 'rest_get_server' ) ) {
			return false;
		}

		// get_namespaces() returns a plain list of namespace strings, not an
		// associative array keyed by namespace — array_key_exists() against it
		// always returns false and silently reported this provider as absent.
		return in_array( self::REST_NAMESPACE, rest_get_server()->get_namespaces(), true );
	}

	/**
	 * Returns safe provider identity and normalized capabilities.
	 *
	 * @return RedirectProviderStatus
	 */
	public function status(): RedirectProviderStatus {
		$available = $this->is_available();
		$version   = null;
		// Redirection has no version constant; its header `Version:` is only
		// reachable through the `REDIRECTION_FILE` constant it always defines.
		if ( $available && defined( 'REDIRECTION_FILE' ) ) {
			$file = constant( 'REDIRECTION_FILE' );
			if ( is_string( $file ) && is_readable( $file ) ) {
				$data    = get_file_data( $file, array( 'Version' => 'Version' ) );
				$version = '' !== $data['Version'] ? $data['Version'] : null;
			}
		}

		return new RedirectProviderStatus( 'redirection', $version, $available, array( 'search', 'create' ) );
	}

	/**
	 * Returns the enabled rule for an exact source path, or null when none exists.
	 *
	 * @param RedirectSourcePath $source Exact source path.
	 * @return RedirectRule|null
	 * @throws RedirectProviderUnavailable When Redirection is not active.
	 */
	public function search( RedirectSourcePath $source ): ?RedirectRule {
		if ( ! $this->is_available() ) {
			throw new RedirectProviderUnavailable( 'Redirection is not active.' );
		}

		$normalized = new RedirectSourcePath( self::normalize_trailing_slash( $source->value(), $this->uses_trailing_slashes() ) );
		$status     = $this->status();

		return $this->with_scoped_capability(
			array( 'redirection_cap_redirect_manage' ),
			function () use ( $normalized, $status ): ?RedirectRule {
				$request = new WP_REST_Request( 'GET', '/' . self::REST_NAMESPACE . '/redirect' );
				foreach ( self::build_search_query( $normalized ) as $key => $value ) {
					$request->set_param( $key, $value );
				}
				$response = rest_do_request( $request );
				$match    = self::find_exact_match( self::response_items( $response->get_data() ), $normalized->value() );

				return null === $match ? null : self::map_item_to_rule( $match, $status, $this->site_url );
			}
		);
	}

	/**
	 * Creates a new redirect rule and returns it with its assigned identity.
	 *
	 * @param RedirectRule $candidate Rule with a null `id`.
	 * @return RedirectRule
	 * @throws RedirectProviderUnavailable When Redirection is not active.
	 */
	public function create( RedirectRule $candidate ): RedirectRule {
		if ( ! $this->is_available() ) {
			throw new RedirectProviderUnavailable( 'Redirection is not active.' );
		}

		// Normalize the same way search() does — a source stored exactly as
		// the caller typed it would silently mismatch every later search()
		// once the site's own trailing-slash convention is applied there.
		$source    = new RedirectSourcePath( self::normalize_trailing_slash( $candidate->source->value(), $this->uses_trailing_slashes() ) );
		$candidate = new RedirectRule( $candidate->id, $source, $candidate->status, $candidate->target, $candidate->enabled, $candidate->provider );
		$status    = $this->status();

		return $this->with_scoped_capability(
			array( 'redirection_cap_redirect_add' ),
			function () use ( $candidate, $status ): RedirectRule {
				$request = new WP_REST_Request( 'POST', '/' . self::REST_NAMESPACE . '/redirect' );
				foreach ( self::build_create_payload( $candidate ) as $key => $value ) {
					$request->set_param( $key, $value );
				}
				$response = rest_do_request( $request );
				if ( $response->get_status() >= 400 ) {
					$data    = $response->get_data();
					$message = is_array( $data ) && is_string( $data['message'] ?? null ) ? $data['message'] : 'unknown error';
					throw new RedirectProviderUnavailable( 'Redirection rejected the create request: ' . esc_html( $message ) );
				}

				// POST /redirect returns the same {items, total} list shape as
				// GET /redirect, not the created item alone — find it by its
				// exact source path, which create() guarantees is unique per
				// the collision check the caller already ran.
				$match = self::find_exact_match( self::response_items( $response->get_data() ), $candidate->source->value() );
				if ( null === $match ) {
					throw new RedirectProviderUnavailable( 'Redirection did not return the created redirect.' );
				}

				return self::map_item_to_rule( $match, $status, $this->site_url );
			}
		);
	}

	/**
	 * Registers the scoped `redirection_role`/`redirection_capability_check`
	 * filters for the duration of one call and always removes them
	 * afterwards, so they never widen access for an unrelated Redirection
	 * request dispatched later in the same PHP process (ADR 0026 s2).
	 *
	 * @template T
	 * @param array    $allowed_permissions Permission names this call may satisfy with WPCB's capability.
	 * @param callable $call                 The scoped REST dispatch.
	 * @phpstan-param list<string> $allowed_permissions
	 * @phpstan-param callable(): T $call
	 * @phpstan-return T
	 */
	private function with_scoped_capability( array $allowed_permissions, callable $call ): mixed {
		$role_filter       = static fn (): string => 'wpcb_manage_redirects';
		$capability_filter = self::capability_filter( 'wpcb_manage_redirects', $allowed_permissions );

		add_filter( 'redirection_role', $role_filter );
		add_filter( 'redirection_capability_check', $capability_filter, 10, 2 );

		try {
			return $call();
		} finally {
			remove_filter( 'redirection_role', $role_filter );
			remove_filter( 'redirection_capability_check', $capability_filter, 10 );
		}
	}

	/**
	 * Whether the site's permalink structure ends in a trailing slash.
	 *
	 * @return bool
	 */
	private function uses_trailing_slashes(): bool {
		$structure = get_option( 'permalink_structure' );

		return is_string( $structure ) && str_ends_with( $structure, '/' );
	}

	/**
	 * Canonicalizes a path's trailing slash to match the site's permalink
	 * structure, so the same logical URL is never treated as two different
	 * sources depending on how a caller happened to type it (ADR 0026 s5).
	 *
	 * @param string $path              Candidate path.
	 * @param bool   $trailing_slashed  Whether the site uses trailing slashes.
	 * @return string
	 */
	public static function normalize_trailing_slash( string $path, bool $trailing_slashed ): string {
		if ( '/' === $path ) {
			return $path;
		}

		$without_slash = rtrim( $path, '/' );

		return $trailing_slashed ? $without_slash . '/' : $without_slash;
	}

	/**
	 * Narrows an untrusted REST response value to a string-keyed array, so a
	 * malformed or unexpected payload shape fails closed before it ever
	 * reaches {@see self::map_item_to_rule()}.
	 *
	 * @param mixed $value Untrusted REST response value.
	 * @return array<string, mixed>
	 * @throws RedirectProviderUnavailable When the value is not a string-keyed array.
	 */
	private static function assert_response_item( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			throw new RedirectProviderUnavailable( 'Redirection returned a malformed response.' );
		}

		$item = array();
		foreach ( $value as $key => $item_value ) {
			if ( ! is_string( $key ) ) {
				throw new RedirectProviderUnavailable( 'Redirection returned a malformed response.' );
			}
			$item[ $key ] = $item_value;
		}

		return $item;
	}

	/**
	 * Extracts the `items` list from a `GET`/`POST /redirect` response body.
	 * Both routes return the same `{items, total}` list shape — a create
	 * response is never the created item alone.
	 *
	 * @param mixed $data Untrusted REST response data.
	 * @return array<int, array<string, mixed>>
	 */
	private static function response_items( mixed $data ): array {
		if ( ! is_array( $data ) || ! is_array( $data['items'] ?? null ) ) {
			return array();
		}

		$items = array();
		foreach ( $data['items'] as $item ) {
			$items[] = self::assert_response_item( $item );
		}

		return $items;
	}

	/**
	 * Finds the item whose `url` exactly equals the given source. Redirection's
	 * own `filterBy[url]` REST filter is a `LIKE '%…%'` substring match, so a
	 * server-side result set can legitimately contain non-matching rows; only
	 * an exact match is ever treated as "the" rule for a source.
	 *
	 * @param array<int, array<string, mixed>> $items  Response items.
	 * @param string                           $source Exact source path.
	 * @return array<string, mixed>|null
	 */
	public static function find_exact_match( array $items, string $source ): ?array {
		foreach ( $items as $item ) {
			if ( ( $item['url'] ?? null ) === $source ) {
				return $item;
			}
		}

		return null;
	}

	/**
	 * Builds the `redirection_capability_check` filter callback. Returns the
	 * WPCB capability only for the permission names this call actually needs,
	 * so an unrelated Redirection feature (groups, logs, 404s, admin) keeps
	 * requiring its own default rather than being silently opened up.
	 *
	 * @param string $wpcb_capability      Capability WPCB already verified before this call.
	 * @param array  $allowed_permissions  Permission names this call may satisfy.
	 * @phpstan-param list<string> $allowed_permissions
	 * @return callable(string, string): string
	 */
	public static function capability_filter( string $wpcb_capability, array $allowed_permissions ): callable {
		return static function ( string $capability, string $permission_name ) use ( $wpcb_capability, $allowed_permissions ): string {
			return in_array( $permission_name, $allowed_permissions, true ) ? $wpcb_capability : $capability;
		};
	}

	/**
	 * The "Redirections" group Redirection's own database installer creates
	 * by default on every install. See the class docblock for why this is a
	 * fixed value rather than a discovered one.
	 */
	private const DEFAULT_GROUP_ID = 1;

	/**
	 * Builds a search query for one source path. `filterBy[url]` is a
	 * substring match server-side (see {@see self::find_exact_match()}), and
	 * `per_page` below Redirection's own schema minimum of 5 is rejected by
	 * REST arg validation, so this asks for more than one result on purpose.
	 *
	 * @param RedirectSourcePath $source Exact source path.
	 * @return array<string, mixed>
	 */
	public static function build_search_query( RedirectSourcePath $source ): array {
		return array(
			'filterBy' => array( 'url' => $source->value() ),
			'per_page' => 5,
		);
	}

	/**
	 * Maps a provider-neutral candidate to Redirection's REST create payload.
	 *
	 * @param RedirectRule $candidate Candidate rule.
	 * @return array<string, mixed>
	 */
	public static function build_create_payload( RedirectRule $candidate ): array {
		$payload = array(
			'url'        => $candidate->source->value(),
			'match_type' => 'url',
			'regex'      => false,
			'status'     => $candidate->enabled ? 'enabled' : 'disabled',
			'group_id'   => self::DEFAULT_GROUP_ID,
		);

		if ( RedirectStatusCode::GONE === $candidate->status ) {
			$payload['action_type'] = 'error';
			$payload['action_code'] = RedirectStatusCode::GONE->value;

			return $payload;
		}

		$payload['action_type'] = 'url';
		$payload['action_code'] = $candidate->status->value;
		$payload['action_data'] = array( 'url' => $candidate->target?->value() );

		return $payload;
	}

	/**
	 * Maps one Redirection REST response item back to a provider-neutral rule.
	 *
	 * @param array<string, mixed>   $item     Response item.
	 * @param RedirectProviderStatus $status   Provider identity to attach.
	 * @param string                 $site_url Canonical site URL, for target normalization.
	 * @return RedirectRule
	 * @throws RedirectProviderUnavailable When the response item is malformed.
	 */
	public static function map_item_to_rule( array $item, RedirectProviderStatus $status, string $site_url ): RedirectRule {
		$id          = $item['id'] ?? null;
		$url         = $item['url'] ?? null;
		$action_code = $item['action_code'] ?? null;
		// Red_Item::to_json() reports `enabled` as a bool; it never returns a
		// `status` string despite that being an accepted *write* field.
		$enabled = $item['enabled'] ?? null;
		if ( ! ( is_int( $id ) || is_string( $id ) ) || ! is_string( $url ) || ! is_int( $action_code ) || ! is_bool( $enabled ) ) {
			throw new RedirectProviderUnavailable( 'Redirection returned a malformed redirect item.' );
		}

		$status_code = RedirectStatusCode::from( $action_code );
		$target      = null;
		if ( RedirectStatusCode::GONE !== $status_code ) {
			$action_data = $item['action_data'] ?? null;
			$target_url  = is_array( $action_data ) && is_string( $action_data['url'] ?? null ) ? $action_data['url'] : '';
			$target      = new RedirectTargetUrl( $site_url, $target_url );
		}

		return new RedirectRule(
			(string) $id,
			new RedirectSourcePath( $url ),
			$status_code,
			$target,
			$enabled,
			$status
		);
	}
}

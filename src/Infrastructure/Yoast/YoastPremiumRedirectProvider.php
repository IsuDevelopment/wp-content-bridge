<?php
/**
 * Yoast SEO Premium redirect provider adapter.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Infrastructure\Yoast;

use InvalidArgumentException;
use IsuDev\WPContentBridge\Application\Redirect\RedirectProvider;
use IsuDev\WPContentBridge\Application\Redirect\RedirectProviderForbidden;
use IsuDev\WPContentBridge\Application\Redirect\RedirectProviderUnavailable;
use IsuDev\WPContentBridge\Application\Redirect\RedirectRuleNotRepresentable;
use IsuDev\WPContentBridge\Domain\Redirect\RedirectProviderStatus;
use IsuDev\WPContentBridge\Domain\Redirect\RedirectRule;
use IsuDev\WPContentBridge\Domain\Redirect\RedirectSourcePath;
use IsuDev\WPContentBridge\Domain\Redirect\RedirectStatusCode;
use IsuDev\WPContentBridge\Domain\Redirect\RedirectTargetUrl;

/**
 * Calls Yoast SEO Premium's redirect manager in-process.
 *
 * Written from the source of Premium 28.0 (ADR 0026's amendment, 2026-09-01),
 * which corrected the ADR's original "Yoast has no callable API" finding.
 * Five facts from that reading shape this class:
 *
 * 1. `WPSEO_Redirect_Manager` and friends are Composer-classmap-autoloaded and
 *    carry no `is_admin()` guard, so they are callable from a REST, cron or
 *    CLI request. Only `WPSEO_Redirect_Page`/`_Ajax` are admin-gated, and this
 *    adapter never touches them.
 * 2. **The manager performs no capability check.** Calling it in-process makes
 *    this plugin the only gate, so `assert_authorized()` requires Yoast's own
 *    `wpseo_manage_redirects` in addition to bridge authority.
 * 3. Redirects live in three options: `wpseo-premium-redirects-base` is
 *    canonical, while `-export-plain`/`-export-regex` are derived caches that
 *    the front-end matcher actually reads. Writing the canonical option
 *    directly would create a rule that never fires, so every write goes
 *    through `create_redirect()`, which calls `save_redirects()` itself.
 * 4. `WPSEO_Redirect_Validator` issues a live outbound `wp_remote_head()`
 *    against the target. This adapter never calls it: a redirect write must
 *    not silently make an HTTP request to a third party. The provider-neutral
 *    guard already enforces this plugin's own invariants.
 * 5. Plain origins are stored **without leading or trailing slashes**
 *    (`trim( $url, '/' )`), unlike Redirection which stores `/old-page`. The
 *    same logical source therefore has two different textual forms across the
 *    two backends, and translating between them is this adapter's job — see
 *    {@see self::to_provider_origin()}.
 */
final class YoastPremiumRedirectProvider implements RedirectProvider {

	private const MANAGER_CLASS  = 'WPSEO_Redirect_Manager';
	private const REDIRECT_CLASS = 'WPSEO_Redirect';
	private const FORMATS_CLASS  = 'WPSEO_Redirect_Formats';

	/**
	 * Native Yoast capability required in addition to bridge authority.
	 */
	private const NATIVE_CAPABILITY = 'wpseo_manage_redirects';

	/**
	 * Creates the adapter for one WordPress site.
	 *
	 * @param string $site_url Canonical site URL, for target normalization.
	 */
	public function __construct( private readonly string $site_url ) {
	}

	/**
	 * Available only when Premium is loaded and the exact surface this adapter
	 * calls is present.
	 *
	 * The method probe is the compatibility gate ADR 0026 s3 asked for: this
	 * API is undocumented, so a rename in a future Premium release must make
	 * the provider report itself absent rather than fatal at call time.
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		if ( ! defined( 'WPSEO_PREMIUM_VERSION' ) ) {
			return false;
		}
		if ( ! class_exists( self::MANAGER_CLASS ) || ! class_exists( self::REDIRECT_CLASS ) || ! class_exists( self::FORMATS_CLASS ) ) {
			return false;
		}

		$required = array(
			self::MANAGER_CLASS  => array( 'get_redirect', 'create_redirect', 'update_redirect', 'delete_redirects', 'save_redirects' ),
			self::REDIRECT_CLASS => array( 'get_origin', 'get_target', 'get_type', 'get_format' ),
		);

		foreach ( $required as $class => $methods ) {
			foreach ( $methods as $method ) {
				if ( ! method_exists( $class, $method ) ) {
					return false;
				}
			}
		}

		return true;
	}

	/**
	 * Returns safe provider identity and normalized capabilities.
	 *
	 * @return RedirectProviderStatus
	 */
	public function status(): RedirectProviderStatus {
		$available = $this->is_available();
		$version   = null;

		if ( $available ) {
			$constant = constant( 'WPSEO_PREMIUM_VERSION' );
			$version  = is_string( $constant ) && '' !== $constant ? $constant : null;
		}

		return new RedirectProviderStatus( 'yoast-premium', $version, $available, array( 'search', 'create', 'update', 'delete' ) );
	}

	/**
	 * Returns the rule for an exact source path, or null when none exists.
	 *
	 * @param RedirectSourcePath $source Exact source path.
	 * @return RedirectRule|null
	 * @throws RedirectProviderUnavailable When Yoast Premium is not active.
	 * @throws RedirectProviderForbidden When the caller lacks the native capability.
	 * @throws RedirectRuleNotRepresentable When a rule exists but falls outside the neutral contract.
	 */
	public function search( RedirectSourcePath $source ): ?RedirectRule {
		$this->assert_available();
		$this->assert_authorized();

		$found = self::call( $this->manager(), 'get_redirect', array( self::to_provider_origin( $source->value() ) ) );
		if ( ! is_object( $found ) ) {
			// The manager returns `false`, not null, when nothing matches.
			return null;
		}

		return $this->map_to_rule( $found );
	}

	/**
	 * Creates a rule and returns it as the provider stored it.
	 *
	 * Delegates to `assert_available()` and `assert_authorized()` first, so a
	 * caller also sees `RedirectProviderUnavailable` and
	 * `RedirectProviderForbidden` from those, and to `map_to_rule()`, which
	 * raises `RedirectRuleNotRepresentable` for a rule outside the neutral
	 * contract.
	 *
	 * @param RedirectRule $candidate Rule with a null `id`.
	 * @return RedirectRule
	 * @throws RedirectProviderUnavailable When the write did not persist.
	 */
	public function create( RedirectRule $candidate ): RedirectRule {
		$this->assert_available();
		$this->assert_authorized();

		$origin  = self::to_provider_origin( $candidate->source->value() );
		$manager = $this->manager();

		$redirect_class = self::REDIRECT_CLASS;
		$formats_class  = self::FORMATS_CLASS;
		$redirect       = new $redirect_class(
			$origin,
			RedirectStatusCode::GONE === $candidate->status ? '' : (string) $candidate->target?->value(),
			$candidate->status->value,
			constant( $formats_class . '::PLAIN' )
		);

		// `create_redirect()` calls `save_redirects()` itself, which rewrites
		// both derived export options. It returns false when the origin is
		// already present, which the guard should have caught first.
		if ( true !== self::call( $manager, 'create_redirect', array( $redirect ) ) ) {
			throw new RedirectProviderUnavailable( 'Yoast SEO Premium refused the redirect create request.' );
		}

		$stored = self::call( $manager, 'get_redirect', array( $origin ) );
		if ( ! is_object( $stored ) ) {
			throw new RedirectProviderUnavailable( 'Yoast SEO Premium did not return the created redirect.' );
		}

		return $this->map_to_rule( $stored );
	}

	/**
	 * Replaces the target and status of the rule for an exact source path.
	 *
	 * @param RedirectSourcePath $source      Source path of the rule to change.
	 * @param RedirectRule       $replacement Desired end state.
	 * @return RedirectRule
	 * @throws RedirectProviderUnavailable When no such rule exists or the write did not persist.
	 */
	public function update( RedirectSourcePath $source, RedirectRule $replacement ): RedirectRule {
		$this->assert_available();
		$this->assert_authorized();

		$origin  = self::to_provider_origin( $source->value() );
		$manager = $this->manager();
		$current = self::call( $manager, 'get_redirect', array( $origin ) );

		if ( ! is_object( $current ) ) {
			throw new RedirectProviderUnavailable( 'Yoast SEO Premium holds no redirect for this source path.' );
		}

		$redirect_class = self::REDIRECT_CLASS;
		$formats_class  = self::FORMATS_CLASS;
		$updated        = new $redirect_class(
			$origin,
			RedirectStatusCode::GONE === $replacement->status ? '' : (string) $replacement->target?->value(),
			$replacement->status->value,
			constant( $formats_class . '::PLAIN' )
		);

		// `update_redirect()` calls `save_redirects()` itself, so the derived
		// export options the front end reads are regenerated with it.
		if ( true !== self::call( $manager, 'update_redirect', array( $current, $updated ) ) ) {
			throw new RedirectProviderUnavailable( 'Yoast SEO Premium refused the redirect update request.' );
		}

		$stored = self::call( $manager, 'get_redirect', array( $origin ) );
		if ( ! is_object( $stored ) ) {
			throw new RedirectProviderUnavailable( 'Yoast SEO Premium did not return the updated redirect.' );
		}

		return $this->map_to_rule( $stored );
	}

	/**
	 * Removes the rule for an exact source path.
	 *
	 * @param RedirectSourcePath $source Source path of the rule to remove.
	 * @return void
	 * @throws RedirectProviderUnavailable When no such rule exists or the removal did not persist.
	 */
	public function delete( RedirectSourcePath $source ): void {
		$this->assert_available();
		$this->assert_authorized();

		$origin  = self::to_provider_origin( $source->value() );
		$manager = $this->manager();
		$current = self::call( $manager, 'get_redirect', array( $origin ) );

		if ( ! is_object( $current ) ) {
			throw new RedirectProviderUnavailable( 'Yoast SEO Premium holds no redirect for this source path.' );
		}

		self::call( $manager, 'delete_redirects', array( array( $current ) ) );

		// Read back rather than trusting the return value: the manager reports
		// whether *any* deletion happened, not whether this one did.
		if ( is_object( self::call( $manager, 'get_redirect', array( $origin ) ) ) ) {
			throw new RedirectProviderUnavailable( 'Yoast SEO Premium did not remove the redirect.' );
		}
	}

	/**
	 * Builds a plain-format redirect manager.
	 *
	 * @return object
	 */
	private function manager(): object {
		$manager_class = self::MANAGER_CLASS;
		$formats_class = self::FORMATS_CLASS;

		return new $manager_class( constant( $formats_class . '::PLAIN' ) );
	}

	/**
	 * Fails closed when Premium's surface is absent.
	 *
	 * @return void
	 * @throws RedirectProviderUnavailable When the provider is unavailable.
	 */
	private function assert_available(): void {
		if ( ! $this->is_available() ) {
			throw new RedirectProviderUnavailable( 'Yoast SEO Premium redirects are not available.' );
		}
	}

	/**
	 * Requires Yoast's own capability, which Yoast itself never checks.
	 *
	 * This is not the bridge's authorization gate — that belongs to the
	 * Ability's `permission_callback`, as it does for every other write in
	 * this plugin. It is the check the roadmap requires *in addition* to
	 * bridge authority, and it exists here because `WPSEO_Redirect_Manager`
	 * performs none: called in-process, nothing else would stop a principal
	 * from gaining redirect authority the site never granted it in Yoast's
	 * own terms.
	 *
	 * @return void
	 * @throws RedirectProviderForbidden When the native capability is missing.
	 */
	private function assert_authorized(): void {
		if ( ! current_user_can( self::NATIVE_CAPABILITY ) ) {
			throw new RedirectProviderForbidden( 'Yoast SEO Premium redirects require the wpseo_manage_redirects capability.' );
		}
	}

	/**
	 * Converts a neutral source path to Yoast's stored origin form.
	 *
	 * Yoast trims both slashes from a plain relative origin, so `/old-page`
	 * is stored as `old-page`. Passing the neutral form would still match on
	 * read, because `origin_is()` re-sanitizes its argument — but it would not
	 * match on write, so the conversion happens once, here, for both.
	 *
	 * @param string $path Neutral source path.
	 * @return string
	 */
	public static function to_provider_origin( string $path ): string {
		return '/' === $path ? '/' : trim( $path, '/' );
	}

	/**
	 * Converts a stored Yoast origin or target back to a neutral path.
	 *
	 * An absolute URL is returned untouched. Yoast keeps the scheme on an
	 * off-site target, and prepending a slash to it would manufacture the
	 * same-site path `/https://elsewhere.example/x` — which the neutral
	 * target validator would then accept as a local path. The off-site rule
	 * has to stay recognisably off-site so it can be refused.
	 *
	 * @param string $value Stored value.
	 * @return string
	 */
	public static function to_neutral_path( string $value ): string {
		if ( '' === $value ) {
			return '/';
		}

		if ( str_contains( $value, '://' ) || str_starts_with( $value, '//' ) ) {
			return $value;
		}

		return str_starts_with( $value, '/' ) ? $value : '/' . $value;
	}

	/**
	 * Calls one method on an undocumented Yoast object.
	 *
	 * Every value crossing this boundary is validated by the caller rather
	 * than cast, because nothing in Premium promises these shapes: a future
	 * release could return a different type, and a cast would turn that into
	 * silently wrong data instead of a refusal.
	 *
	 * @param object $target Yoast object.
	 * @param string $method Method name.
	 * @param array  $args   Positional arguments.
	 * @phpstan-param list<mixed> $args
	 * @return mixed
	 */
	private static function call( object $target, string $method, array $args = array() ): mixed {
		/**
		 * The method's presence is asserted by `is_available()` before any
		 * call path reaches here.
		 *
		 * @var callable $callable
		 */
		$callable = array( $target, $method );

		return call_user_func_array( $callable, $args );
	}

	/**
	 * Reads a string property from an undocumented Yoast object.
	 *
	 * @param object $target Yoast object.
	 * @param string $method Getter name.
	 * @return string
	 * @throws RedirectRuleNotRepresentable When the value is not a string.
	 */
	private static function read_string( object $target, string $method ): string {
		$value = self::call( $target, $method );
		if ( ! is_string( $value ) ) {
			throw new RedirectRuleNotRepresentable( 'Yoast SEO Premium returned an unexpected redirect shape.' );
		}

		return $value;
	}

	/**
	 * Maps a stored Yoast redirect onto the provider-neutral rule.
	 *
	 * @param object $redirect Yoast redirect object.
	 * @return RedirectRule
	 * @throws RedirectRuleNotRepresentable When the rule is outside the neutral contract.
	 */
	private function map_to_rule( object $redirect ): RedirectRule {
		$formats_class = self::FORMATS_CLASS;
		if ( constant( $formats_class . '::PLAIN' ) !== self::call( $redirect, 'get_format' ) ) {
			// A regex rule can share an exact origin string with a plain one.
			// Reporting "no rule" here would let the guard create a duplicate.
			throw new RedirectRuleNotRepresentable( 'Yoast SEO Premium holds a regex-format rule for this source.' );
		}

		$type = self::call( $redirect, 'get_type' );
		if ( ! is_int( $type ) ) {
			throw new RedirectRuleNotRepresentable( 'Yoast SEO Premium returned a non-integer redirect type.' );
		}

		$status = RedirectStatusCode::tryFrom( $type );
		if ( null === $status ) {
			// Yoast also supports 307 and 451, which P0 excludes.
			throw new RedirectRuleNotRepresentable( 'Yoast SEO Premium holds a rule with an HTTP status outside the neutral allowlist.' );
		}

		$source = new RedirectSourcePath( self::to_neutral_path( self::read_string( $redirect, 'get_origin' ) ) );

		if ( RedirectStatusCode::GONE === $status ) {
			return new RedirectRule( $source->value(), $source, $status, null, true, $this->status() );
		}

		$raw_target = self::read_string( $redirect, 'get_target' );

		try {
			$target = new RedirectTargetUrl( $this->site_url, self::to_neutral_path( $raw_target ) );
		} catch ( InvalidArgumentException ) {
			// Yoast permits off-site targets; the neutral contract does not.
			throw new RedirectRuleNotRepresentable( 'Yoast SEO Premium holds a rule whose target is outside this site.' );
		}

		// Yoast stores no per-rule enabled flag: a stored rule is always live.
		// The identity is the origin, because the option store assigns no id.
		return new RedirectRule( $source->value(), $source, $status, $target, true, $this->status() );
	}
}

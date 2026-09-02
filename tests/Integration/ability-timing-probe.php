<?php
/**
 * Times abilities in-process so a slow transport can be told from slow PHP.
 *
 * This is a diagnostic probe, not a verifier: it asserts nothing and always
 * exits zero. It exists because an 11-minute production schema session was
 * traced to four MCP calls that each returned HTTP 504, while the same
 * abilities measured 89-326 ms on the reference site. Those two facts cannot
 * both describe this plugin's PHP, so the question is which layer is slow --
 * and that has to be measured on the affected install, not inferred here.
 *
 * Run it on the affected site, then request the same abilities through the MCP
 * endpoint and compare. In-process fast plus MCP slow indicts the transport or
 * the host (OAuth MCP server, PHP-FPM or gateway limits, the database). Both
 * slow indicts the ability, and the per-ability numbers say which one.
 *
 * Usage, from the WordPress root:
 *
 *     wp eval "require '/path/to/tests/Integration/ability-timing-probe.php';"
 *     wp eval "\$_ENV['WPCB_PROBE_POST_ID']='123'; require '.../ability-timing-probe.php';"
 *
 * It reads only. It creates no fixtures, writes no options, and changes no
 * content, so it is safe on production -- the one reason it is a probe rather
 * than a verifier, which would need to write.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI probe output, not rendered HTML.

namespace IsuDev\WPContentBridge\Tests\Integration;

use Throwable;
use WP_Error;
use WP_Post;

/**
 * Times each read ability in-process and prints a comparable table.
 */
final class AbilityTimingProbe {

	private const RUNS = 3;

	/**
	 * Runs the probe and prints the measurements.
	 *
	 * @return void
	 */
	public function run(): void {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			echo "The Abilities API is unavailable; this needs WordPress 7.1 or the Abilities API plugin.\n";
			return;
		}

		$post_id = $this->probe_post_id();
		if ( 0 === $post_id ) {
			echo "No readable published post was found. Set WPCB_PROBE_POST_ID to choose one.\n";
			return;
		}

		$this->assume_administrator();

		$user = wp_get_current_user();
		printf(
			"Probing post %d (%s) as user %d (%s)\n",
			$post_id,
			(string) get_post_type( $post_id ),
			get_current_user_id(),
			0 === get_current_user_id() ? 'anonymous - most abilities will refuse' : (string) $user->user_login
		);
		echo "Times are in-process PHP only: no HTTP, no MCP transport, no OAuth.\n\n";

		$url = get_permalink( $post_id );
		foreach ( $this->cases( $post_id, is_string( $url ) ? $url : '' ) as $label => $case ) {
			$this->measure( $label, (string) $case['ability'], $case['input'] );
		}

		echo "\nNext step, on this same install: request these abilities through the MCP\n";
		echo "endpoint and compare. A large gap is transport or host, not this plugin.\n";
	}

	/**
	 * Adopts an administrator when the runtime has no user.
	 *
	 * `wp eval` runs as user 0, and almost every ability then refuses in well
	 * under a millisecond. That looks like a fast install while measuring
	 * nothing, which is a worse outcome than not running at all. The probe only
	 * ever reads, so adopting an administrator changes no state; pass
	 * `wp --user=<id>` to measure a specific principal instead.
	 *
	 * @return void
	 */
	private function assume_administrator(): void {
		if ( 0 !== get_current_user_id() ) {
			return;
		}

		$administrators = get_users(
			array(
				'role'   => 'administrator',
				'number' => 1,
				'fields' => 'ID',
			)
		);
		if ( array() === $administrators ) {
			echo "No administrator exists to probe as; results will be refusals.\n";
			return;
		}

		wp_set_current_user( (int) $administrators[0] );
		echo "No user in this runtime, so the probe adopted an administrator (it only reads).\n";
	}

	/**
	 * Returns the ability calls that the reported slow session actually made.
	 *
	 * @param int    $post_id Target post ID.
	 * @param string $url     Target permalink.
	 * @return array<string, array{ability: string, input: array<string, mixed>}>
	 */
	private function cases( int $post_id, string $url ): array {
		$cases = array(
			'get-content (full)'       => array(
				'ability' => 'wp-content-bridge/get-content',
				'input'   => array(
					'post_id'         => $post_id,
					'representations' => array( 'raw', 'rendered', 'plain_text' ),
					'include'         => array( 'author', 'taxonomies', 'featured_media', 'revision' ),
				),
			),
			'get-content (plain_text)' => array(
				'ability' => 'wp-content-bridge/get-content',
				'input'   => array(
					'post_id'         => $post_id,
					'representations' => array( 'plain_text' ),
					'include'         => array(),
				),
			),
			'search-content'           => array(
				'ability' => 'wp-content-bridge/search-content',
				'input'   => array( 'per_page' => 10 ),
			),
			'get-custom-schema'        => array(
				'ability' => 'wp-content-bridge/get-custom-schema',
				'input'   => array( 'post_id' => $post_id ),
			),
			'get-diagnostics'          => array(
				'ability' => 'wp-content-bridge/get-diagnostics',
				'input'   => array(),
			),
			'get-block-tree'           => array(
				'ability' => 'wp-content-bridge/get-block-tree',
				'input'   => array( 'post_id' => $post_id ),
			),
		);

		if ( '' !== $url ) {
			/*
			 * Listed last and by URL because this is the one read measured
			 * genuinely slow: it fetches the site's own page over HTTP to read
			 * the rendered JSON-LD graph. On a host that blocks loopback
			 * requests it pays the full timeout before falling back.
			 */
			$cases['get-url-seo (loopback fetch)'] = array(
				'ability' => 'wp-content-bridge/get-url-seo',
				'input'   => array( 'url' => $url ),
			);
		}

		return $cases;
	}

	/**
	 * Times one ability across repeated runs and prints the result.
	 *
	 * @param string               $label   Human-readable case name.
	 * @param string               $ability Ability name.
	 * @param array<string, mixed> $input   Ability input.
	 * @return void
	 */
	private function measure( string $label, string $ability, array $input ): void {
		/*
		 * Membership is checked against the registered list rather than by
		 * calling wp_get_ability() on a possibly-absent name: core emits a
		 * _doing_it_wrong() notice for an unknown ability, which would bury the
		 * measurements in warnings on any install missing an optional surface.
		 */
		if ( ! in_array( $ability, self::registered_names(), true ) ) {
			printf( "%-30s not registered on this install\n", $label );
			return;
		}
		$registered = wp_get_ability( $ability );
		if ( null === $registered ) {
			printf( "%-30s not resolvable\n", $label );
			return;
		}

		$times = array();
		$bytes = 0;
		$note  = '';
		foreach ( range( 1, self::RUNS ) as $ignored ) {
			$started = microtime( true );
			try {
				/*
				 * An ability that registers no input schema rejects any input at
				 * all, including an empty array, so it must be called with no
				 * argument rather than with array().
				 */
				$result = array() === $input ? $registered->execute() : $registered->execute( $input );
			} catch ( Throwable $error ) {
				printf( "%-30s threw %s\n", $label, get_class( $error ) );
				return;
			}
			$times[] = ( microtime( true ) - $started ) * 1000;
			if ( $result instanceof WP_Error ) {
				$note  = 'refused: ' . (string) $result->get_error_code();
				$bytes = 0;
				continue;
			}
			$bytes = strlen( (string) wp_json_encode( $result ) );
		}

		printf(
			"%-30s first %7.1f ms   warm %7.1f ms   %7d bytes   %s\n",
			$label,
			$times[0],
			min( array_slice( $times, 1 ) ),
			$bytes,
			$note
		);
	}

	/**
	 * Returns every registered ability name once per request.
	 *
	 * @return list<string>
	 */
	private static function registered_names(): array {
		static $names = null;
		if ( is_array( $names ) ) {
			return $names;
		}

		$names = array();
		foreach ( wp_get_abilities() as $ability ) {
			$names[] = is_object( $ability ) && method_exists( $ability, 'get_name' )
				? (string) $ability->get_name()
				: '';
		}

		return $names;
	}

	/**
	 * Resolves the post to probe: the environment override, else any published post.
	 *
	 * @return int
	 */
	private function probe_post_id(): int {
		$requested = getenv( 'WPCB_PROBE_POST_ID' );
		if ( is_string( $requested ) && ctype_digit( $requested ) && 0 < (int) $requested ) {
			return get_post( (int) $requested ) instanceof WP_Post ? (int) $requested : 0;
		}

		$posts = get_posts(
			array(
				'numberposts' => 1,
				'post_status' => 'publish',
				'fields'      => 'ids',
			)
		);

		return array() === $posts ? 0 : (int) $posts[0];
	}
}

( new AbilityTimingProbe() )->run();

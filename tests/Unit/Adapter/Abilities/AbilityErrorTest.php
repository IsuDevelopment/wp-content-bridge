<?php
/**
 * Ability error status contract tests.
 *
 * @package IsuDev\WPContentBridge\Tests
 */

declare(strict_types=1);

// phpcs:disable WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- this test reads repository source files from disk to discover the error vocabulary; wp_remote_get() is for HTTP and WordPress is not loaded in the unit suite.

namespace IsuDev\WPContentBridge\Tests\Unit\Adapter\Abilities;

use IsuDev\WPContentBridge\Adapter\Abilities\AbilityError;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/wp-error-class-stub.php';

/**
 * Locks the public error code to HTTP status mapping, and — more importantly —
 * discovers the vocabulary from the source so a new error code cannot silently
 * inherit HTTP 500 the way all 86 of them did before this mapping existed.
 */
final class AbilityErrorTest extends TestCase {

	/**
	 * Every code an ability can return carries a deliberate status.
	 */
	public function test_every_error_code_in_the_source_is_mapped(): void {
		$statuses   = AbilityError::statuses();
		$discovered = self::discover_error_codes();

		self::assertNotEmpty( $discovered, 'The vocabulary scan found no error codes, so it is not testing anything.' );

		$unmapped = array_values( array_diff( $discovered, array_keys( $statuses ) ) );
		self::assertSame(
			array(),
			$unmapped,
			'These error codes have no HTTP status and would answer 500: ' . implode( ', ', $unmapped )
		);
	}

	/**
	 * The mapping carries no code the source cannot produce.
	 *
	 * A stale entry is harmless at runtime but misleads the next reader about
	 * what this plugin can answer, so it is a failure here.
	 */
	public function test_mapping_contains_no_unreachable_code(): void {
		$discovered = self::discover_error_codes();
		$stale      = array_values( array_diff( array_keys( AbilityError::statuses() ), $discovered ) );

		self::assertSame(
			array(),
			$stale,
			'These mapped codes are not produced anywhere in src/: ' . implode( ', ', $stale )
		);
	}

	/**
	 * Client faults, denials, missing objects, conflicts and bounds each answer
	 * their own status, which is the entire point of the change.
	 */
	public function test_status_classes_are_distinct(): void {
		self::assertSame( 400, AbilityError::status_for( 'wpcb_invalid_input' ) );
		self::assertSame( 403, AbilityError::status_for( 'wpcb_forbidden' ) );
		self::assertSame( 404, AbilityError::status_for( 'wpcb_content_unavailable' ) );
		self::assertSame( 409, AbilityError::status_for( 'wpcb_conflict' ) );
		self::assertSame( 413, AbilityError::status_for( 'wpcb_content_too_large' ) );
		self::assertSame( 501, AbilityError::status_for( 'wpcb_trash_unavailable' ) );
		self::assertSame( 500, AbilityError::status_for( 'wpcb_internal_error' ) );
	}

	/**
	 * An unmapped code answers 500 rather than blaming the client.
	 */
	public function test_unmapped_code_answers_500(): void {
		self::assertSame( 500, AbilityError::status_for( 'wpcb_not_a_real_code' ) );
	}

	/**
	 * Extra error data survives, and cannot override the mapped status.
	 */
	public function test_additional_data_cannot_override_the_status(): void {
		$error = AbilityError::create(
			'wpcb_invalid_custom_schema',
			'Invalid.',
			array(
				'validation' => array( 'detail' ),
				'status'     => 200,
			)
		);

		$data = $error->get_error_data();
		self::assertIsArray( $data );
		self::assertSame( 400, $data['status'] );
		self::assertSame( array( 'detail' ), $data['validation'] );
		self::assertSame( 'wpcb_invalid_custom_schema', $error->get_error_code() );
	}

	/**
	 * Collects every public error code the source can return: the literals
	 * handed to `AbilityError::create()` in the ability adapters, plus the codes
	 * returned by the application layer's `error_code()` methods, which the
	 * adapters pass through unchanged.
	 *
	 * @return list<string>
	 */
	private static function discover_error_codes(): array {
		$root  = dirname( __DIR__, 4 );
		$codes = array();

		foreach ( self::php_files( $root . '/src/Adapter/Abilities' ) as $file ) {
			$contents = (string) file_get_contents( $file );
			if ( preg_match_all( "/AbilityError::create\(\s*'([a-z0-9_]+)'/", $contents, $matches ) ) {
				$codes = array_merge( $codes, $matches[1] );
			}
		}

		foreach ( self::php_files( $root . '/src/Application' ) as $file ) {
			$contents = (string) file_get_contents( $file );
			if ( ! str_contains( $contents, 'function error_code' ) ) {
				continue;
			}
			if ( preg_match_all( "/return '(wpcb_[a-z0-9_]+)';/", $contents, $matches ) ) {
				$codes = array_merge( $codes, $matches[1] );
			}
		}

		$codes = array_values( array_unique( $codes ) );
		sort( $codes );

		return $codes;
	}

	/**
	 * Lists PHP files under a directory, recursively.
	 *
	 * @param string $directory Absolute directory path.
	 * @return list<string>
	 */
	private static function php_files( string $directory ): array {
		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $directory ) );
		$files    = array();
		foreach ( $iterator as $file ) {
			if ( $file instanceof \SplFileInfo && 'php' === $file->getExtension() ) {
				$files[] = $file->getPathname();
			}
		}

		return $files;
	}
}

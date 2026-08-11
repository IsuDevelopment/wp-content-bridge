<?php
/**
 * Legacy llms.txt artifact archiver tests.
 *
 * @package IsuDev\WPContentBridge\Tests
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Tests\Unit\Infrastructure\WordPress;

// phpcs:disable WordPress.WP.AlternativeFunctions -- These unit tests own and clean one random temporary directory; WordPress is intentionally not loaded.

use IsuDev\WPContentBridge\Application\Llms\LlmsOwnershipAdoptionProblem;
use IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressLlmsLegacyArtifactArchiver;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the closed target list, timestamped names, symlink rejection, and
 * best-effort rollback after a partial multi-target failure.
 */
final class WordPressLlmsLegacyArtifactArchiverTest extends TestCase {

	/**
	 * Isolated temporary root owned by this test.
	 *
	 * @var string
	 */
	private string $root;

	/**
	 * Creates one unique empty root.
	 */
	protected function setUp(): void {
		$this->root = sys_get_temp_dir() . '/wpcb-llms-archiver-' . bin2hex( random_bytes( 8 ) );
		self::assertTrue( mkdir( $this->root ) );
	}

	/**
	 * Removes only the exact temporary root this test created.
	 */
	protected function tearDown(): void {
		$entries = glob( $this->root . '/*' );
		if ( is_array( $entries ) ) {
			foreach ( $entries as $entry ) {
				if ( is_dir( $entry ) && ! is_link( $entry ) ) {
					$children = glob( $entry . '/*' );
					if ( is_array( $children ) ) {
						foreach ( $children as $child ) {
							unlink( $child );
						}
					}
					rmdir( $entry );
				} else {
					unlink( $entry );
				}
			}
		}
		rmdir( $this->root );
	}

	/**
	 * Every known target moves under the same suffix; unrelated files remain.
	 */
	public function test_archives_only_known_targets_under_one_timestamp(): void {
		file_put_contents( $this->root . '/llms.txt', 'index' );
		file_put_contents( $this->root . '/llms-full.txt', 'full' );
		mkdir( $this->root . '/llms-docs' );
		file_put_contents( $this->root . '/llms-docs/page.md', 'page' );
		file_put_contents( $this->root . '/unrelated.txt', 'keep' );

		$archiver = $this->archiver();
		$result   = $archiver->archive();

		self::assertSame(
			array(
				'llms.txt.backup_20260811_120000',
				'llms-full.txt.backup_20260811_120000',
				'llms-docs.backup_20260811_120000',
			),
			$result
		);
		self::assertFileDoesNotExist( $this->root . '/llms.txt' );
		self::assertFileExists( $this->root . '/llms.txt.backup_20260811_120000' );
		self::assertSame( 'page', file_get_contents( $this->root . '/llms-docs.backup_20260811_120000/page.md' ) );
		self::assertFileExists( $this->root . '/unrelated.txt' );
	}

	/**
	 * A symlink causes a fail-closed result before any target moves.
	 */
	public function test_rejects_symlink_without_moving_other_targets(): void {
		file_put_contents( $this->root . '/llms.txt', 'index' );
		file_put_contents( $this->root . '/target.txt', 'target' );
		symlink( $this->root . '/target.txt', $this->root . '/llms-full.txt' );

		try {
			$this->archiver()->archive();
			self::fail( 'A symlink must be rejected.' );
		} catch ( LlmsOwnershipAdoptionProblem $error ) {
			self::assertSame( 'unsafe_legacy_artifact', $error->error_code );
		}

		self::assertFileExists( $this->root . '/llms.txt' );
		self::assertTrue( is_link( $this->root . '/llms-full.txt' ) );
	}

	/**
	 * A later rename failure restores targets already moved in this attempt.
	 */
	public function test_rolls_back_first_rename_when_second_fails(): void {
		file_put_contents( $this->root . '/llms.txt', 'index' );
		file_put_contents( $this->root . '/llms-full.txt', 'full' );
		$calls = 0;

		$archiver = new WordPressLlmsLegacyArtifactArchiver(
			fn (): string => $this->root,
			static fn (): string => '20260811_120000',
			static function ( string $source, string $destination ) use ( &$calls ): bool {
				++$calls;
				if ( 2 === $calls ) {
					return false;
				}

				return rename( $source, $destination );
			}
		);

		try {
			$archiver->archive();
			self::fail( 'The second rename must fail.' );
		} catch ( LlmsOwnershipAdoptionProblem $error ) {
			self::assertSame( 'archive_failed', $error->error_code );
		}

		self::assertSame( 3, $calls );
		self::assertFileExists( $this->root . '/llms.txt' );
		self::assertFileExists( $this->root . '/llms-full.txt' );
		self::assertFileDoesNotExist( $this->root . '/llms.txt.backup_20260811_120000' );
	}

	/**
	 * Builds an archiver with deterministic root and timestamp inputs.
	 */
	private function archiver(): WordPressLlmsLegacyArtifactArchiver {
		return new WordPressLlmsLegacyArtifactArchiver(
			fn (): string => $this->root,
			static fn (): string => '20260811_120000'
		);
	}
}

<?php
/**
 * WordPress legacy llms.txt artifact archiver.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Infrastructure\WordPress;

use Closure;
use IsuDev\WPContentBridge\Application\Llms\LlmsLegacyArtifactArchiver;
use IsuDev\WPContentBridge\Application\Llms\LlmsOwnershipAdoptionProblem;

/**
 * Atomically renames only the three known legacy generator targets.
 *
 * No path is accepted from a caller. Symlinks and unexpected object types are
 * rejected, destinations are never overwritten, and a partial multi-target
 * failure is rolled back best-effort before the operation reports failure.
 */
final readonly class WordPressLlmsLegacyArtifactArchiver implements LlmsLegacyArtifactArchiver {

	private const TARGETS = array( 'llms.txt', 'llms-full.txt', 'llms-docs' );

	/**
	 * Creates the adapter.
	 *
	 * @param Closure|null $web_root_resolver Optional test override returning a trailing-slashed root.
	 * @param Closure|null $timestamp_reader  Optional test override returning a UTC timestamp suffix.
	 * @param Closure|null $renamer           Optional test override for rename operations.
	 * @phpstan-param (Closure(): string)|null $web_root_resolver
	 * @phpstan-param (Closure(): string)|null $timestamp_reader
	 * @phpstan-param (Closure(string, string): bool)|null $renamer
	 */
	public function __construct(
		private ?Closure $web_root_resolver = null,
		private ?Closure $timestamp_reader = null,
		private ?Closure $renamer = null,
	) {
	}

	/**
	 * Archives every known artifact under one collision-free timestamp suffix.
	 *
	 * @return array<int, string>
	 * @throws LlmsOwnershipAdoptionProblem When readiness, target safety, or a rename fails.
	 */
	public function archive(): array {
		$root = $this->web_root();
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Exact web-root targets require an atomic same-filesystem rename; WP_Filesystem transports cannot guarantee it.
		if ( '' === $root || ! is_dir( $root ) || ! is_writable( $root ) ) {
			throw new LlmsOwnershipAdoptionProblem( 'web_root_not_writable', 'The site web root is not writable by WordPress.' );
		}

		$sources = array();
		foreach ( self::TARGETS as $basename ) {
			$source = $root . $basename;
			if ( ! file_exists( $source ) && ! is_link( $source ) ) {
				continue;
			}
			if ( is_link( $source ) || ( 'llms-docs' === $basename ? ! is_dir( $source ) : ! is_file( $source ) ) ) {
				throw new LlmsOwnershipAdoptionProblem( 'unsafe_legacy_artifact', 'A legacy llms.txt target is a symlink or has an unexpected filesystem type.' );
			}
			$sources[ $basename ] = $source;
		}

		if ( array() === $sources ) {
			throw new LlmsOwnershipAdoptionProblem( 'legacy_artifacts_missing', 'No known legacy llms.txt artifacts were found to archive.' );
		}

		$suffix = '.backup_' . $this->timestamp();
		foreach ( array_keys( $sources ) as $basename ) {
			if ( file_exists( $root . $basename . $suffix ) || is_link( $root . $basename . $suffix ) ) {
				throw new LlmsOwnershipAdoptionProblem( 'backup_collision', 'A backup with the generated timestamp already exists.' );
			}
		}

		$moved = array();
		foreach ( $sources as $basename => $source ) {
			$destination = $root . $basename . $suffix;
			if ( ! $this->rename( $source, $destination ) ) {
				$this->rollback( $moved );
				throw new LlmsOwnershipAdoptionProblem( 'archive_failed', 'A legacy llms.txt artifact could not be archived.' );
			}
			$moved[ $source ] = $destination;
		}

		return array_map(
			static fn ( string $basename ): string => $basename . $suffix,
			array_keys( $sources )
		);
	}

	/**
	 * Restores already-moved targets after a later target failed.
	 *
	 * @param array<string, string> $moved Source-to-backup paths.
	 * @return void
	 */
	private function rollback( array $moved ): void {
		foreach ( array_reverse( $moved, true ) as $source => $destination ) {
			if ( ! file_exists( $source ) && ( file_exists( $destination ) || is_link( $destination ) ) ) {
				$this->rename( $destination, $source );
			}
		}
	}

	/**
	 * Resolves the web root.
	 *
	 * @return string
	 */
	private function web_root(): string {
		$root = null !== $this->web_root_resolver
			? ( $this->web_root_resolver )()
			: WordPressLlmsWebRoot::resolve();

		return '' === $root ? '' : rtrim( $root, '/\\' ) . '/';
	}

	/**
	 * Returns the bounded UTC backup suffix timestamp.
	 *
	 * @return string
	 * @throws LlmsOwnershipAdoptionProblem When the injected timestamp is not the closed expected format.
	 */
	private function timestamp(): string {
		$timestamp = null !== $this->timestamp_reader
			? ( $this->timestamp_reader )()
			: gmdate( 'Ymd_His' );

		if ( 1 !== preg_match( '/^\d{8}_\d{6}$/', $timestamp ) ) {
			throw new LlmsOwnershipAdoptionProblem( 'invalid_backup_timestamp', 'The backup timestamp could not be generated safely.' );
		}

		return $timestamp;
	}

	/**
	 * Performs one rename operation.
	 *
	 * @param string $source      Existing exact target.
	 * @param string $destination New exact backup target.
	 * @return bool
	 */
	private function rename( string $source, string $destination ): bool {
		if ( null !== $this->renamer ) {
			return ( $this->renamer )( $source, $destination );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Atomic same-filesystem migration of exact internal targets; no caller supplies either path.
		return rename( $source, $destination );
	}
}

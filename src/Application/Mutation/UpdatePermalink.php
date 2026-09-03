<?php
/**
 * Update-permalink use case.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Application\Mutation;

use InvalidArgumentException;
use IsuDev\WPContentBridge\Application\Content\ContentUnavailable;
use IsuDev\WPContentBridge\Application\ContentAccess\ContentAccessManager;
use IsuDev\WPContentBridge\Domain\ContentAccess\ContentOperation;
use IsuDev\WPContentBridge\Domain\Mutation\MutationResult;
use IsuDev\WPContentBridge\Domain\Mutation\PermalinkMutationResult;
use IsuDev\WPContentBridge\Domain\Mutation\PermalinkUpdate;
use Throwable;

/**
 * Orchestrates a policy- and version-tested slug change.
 */
final readonly class UpdatePermalink {

	public const ABILITY = 'wp-content-bridge/update-permalink';

	/**
	 * Creates the use case.
	 *
	 * @param ContentAccessManager      $access     Per-post-type write policy.
	 * @param ContentMutationRepository $repository Post lookup/version/re-read port.
	 * @param PermalinkRepository       $permalinks Slug read/write port.
	 * @param SlugNormalizer            $slugs      Slug normalization port.
	 * @param AuditLog                  $audit      Append-only audit sink.
	 * @param UrlCacheInvalidator       $urls       URL-scoped cache invalidation port (ADR 0032).
	 */
	public function __construct(
		private ContentAccessManager $access,
		private ContentMutationRepository $repository,
		private PermalinkRepository $permalinks,
		private SlugNormalizer $slugs,
		private AuditLog $audit,
		private UrlCacheInvalidator $urls,
	) {}

	/**
	 * Executes the slug change and records exactly one redacted audit event.
	 *
	 * The write port raises `PermalinkUnavailable` when the slug is taken; that
	 * propagates after the audit row is recorded.
	 *
	 * @param array<string, mixed> $raw_input Normalized Ability input.
	 * @param int                  $user_id   Acting principal.
	 * @return PermalinkMutationResult
	 * @throws InvalidArgumentException When the request is malformed or the slug normalizes away.
	 * @throws ContentUnavailable When the target is absent.
	 * @throws MutationForbidden When policy denies permalink writes for the type.
	 * @throws MutationConflict When the version token is stale.
	 * @throws MutationWriteFailed When the write, its confirmation, or the post re-read fails.
	 * @throws Throwable Re-thrown availability failures.
	 */
	public function execute( array $raw_input, int $user_id ): PermalinkMutationResult {
		$post_id          = null;
		$post_type        = null;
		$expected_version = null;

		try {
			$update           = PermalinkUpdate::from_input( $raw_input );
			$post_id          = $update->post_id;
			$expected_version = $update->expected_version->to_string();

			$post_type = $this->repository->post_type( $update->post_id );
			$before    = $this->permalinks->current( $update->post_id );
			if ( null === $post_type || null === $before ) {
				throw new ContentUnavailable( 'Content is unavailable.' );
			}
			if ( ! $this->access->allows( $post_type, ContentOperation::UPDATE_PERMALINK ) ) {
				throw new MutationForbidden( 'Permalink updates are not permitted for this type.' );
			}

			$current = $this->repository->current_version( $update->post_id );
			if ( null === $current ) {
				throw new ContentUnavailable( 'Content is unavailable.' );
			}
			if ( ! $current->equals( $update->expected_version ) ) {
				throw new MutationConflict( 'The submitted version token is stale.' );
			}

			/*
			 * Normalized before the write and compared after, so the caller is
			 * never told a slug was stored when WordPress stored a different
			 * one. A slug that normalizes to nothing is invalid input, not an
			 * availability problem.
			 */
			$slug = $this->slugs->normalize( $update->requested_slug );
			if ( null === $slug ) {
				throw new InvalidArgumentException( 'The slug contains no characters usable in a URL.' );
			}

			$after = $this->permalinks->apply( $update, $slug );
			$base  = $this->repository->result_for( $update->post_id );
			if ( null === $base ) {
				throw new MutationWriteFailed( 'The updated post could not be re-read.' );
			}

			$mutation = new MutationResult(
				$base->post_id,
				$base->post_type,
				$base->status,
				$base->version,
				$before['slug'] === $after['slug'] ? array() : $update->changed_fields(),
				false
			);

			/*
			 * ADR 0032: the old URL is invalidated here, by the write that
			 * knows it, rather than on `wpcb_mutation` - that event is
			 * redacted to changed field names, and putting URLs on it would
			 * make the audit record a carrier of content values. Skipped
			 * entirely when the URL did not move, so a no-op rename does not
			 * dispatch a purge.
			 */
			$channels = $before['url'] === $after['url']
				? array()
				: $this->urls->purge( array( $before['url'], $after['url'] ) );

			$result = new PermalinkMutationResult( $mutation, $before, $after, $channels );
		} catch ( Throwable $error ) {
			[ $outcome, $code ] = $this->classify( $error );
			$this->audit->record(
				new AuditEvent(
					$user_id,
					self::ABILITY,
					$post_id,
					$post_type,
					array(),
					$expected_version,
					null,
					$outcome,
					$code
				)
			);

			throw $error;
		}

		$this->audit->record(
			new AuditEvent(
				$user_id,
				self::ABILITY,
				$result->mutation->post_id,
				$result->mutation->post_type,
				$result->mutation->changed_fields,
				$expected_version,
				$result->mutation->version->to_string(),
				'success',
				null
			)
		);

		return $result;
	}

	/**
	 * Classifies a failure for the stable audit vocabulary.
	 *
	 * @param Throwable $error Failure that ended the attempt.
	 * @return array{0: string, 1: string}
	 */
	private function classify( Throwable $error ): array {
		if ( $error instanceof InvalidArgumentException ) {
			return array( 'invalid', 'wpcb_invalid_input' );
		}
		if ( $error instanceof ContentUnavailable ) {
			return array( 'invalid', 'wpcb_content_unavailable' );
		}
		if ( $error instanceof MutationForbidden ) {
			return array( 'denied', 'wpcb_forbidden' );
		}
		if ( $error instanceof MutationConflict ) {
			return array( 'conflict', 'wpcb_conflict' );
		}
		if ( $error instanceof PermalinkUnavailable ) {
			return array( 'invalid', $error->error_code() );
		}

		return array( 'failure', 'wpcb_write_failed' );
	}
}

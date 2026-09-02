<?php
/**
 * Create one redirect in a named provider.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Application\Redirect;

use InvalidArgumentException;
use IsuDev\WPContentBridge\Application\Mutation\AuditEvent;
use IsuDev\WPContentBridge\Application\Mutation\AuditLog;
use IsuDev\WPContentBridge\Domain\Redirect\RedirectRule;
use IsuDev\WPContentBridge\Domain\Redirect\RedirectSourcePath;
use IsuDev\WPContentBridge\Domain\Redirect\RedirectStatusCode;
use IsuDev\WPContentBridge\Domain\Redirect\RedirectTargetUrl;
use Throwable;

/**
 * Writes one redirect to the provider the caller named, after the
 * provider-neutral guard has cleared the candidate against **every** available
 * provider (ADR 0026 s4/s5, amended).
 *
 * The provider is required input, never inferred. On a two-plugin site the
 * choice decides which engine's rule actually fires, so guessing it would make
 * the result unpredictable in exactly the case where it matters most.
 */
final readonly class CreateRedirect {

	public const ABILITY = 'wp-content-bridge/create-redirect';

	/**
	 * Creates the use case.
	 *
	 * @param RedirectProviderRegistry $registry   Provider registry.
	 * @param RedirectCandidateGuard   $guard      Provider-neutral safety invariants.
	 * @param PublishedPermalinkLookup $permalinks Live-content shadow lookup.
	 * @param AuditLog                 $audit      Redacted audit sink.
	 * @param string                   $site_url   Canonical site URL.
	 */
	public function __construct(
		private RedirectProviderRegistry $registry,
		private RedirectCandidateGuard $guard,
		private PublishedPermalinkLookup $permalinks,
		private AuditLog $audit,
		private string $site_url,
	) {
	}

	/**
	 * Creates one redirect.
	 *
	 * Every failure is audited and re-thrown unchanged, so the adapter maps
	 * one vocabulary: `InvalidArgumentException` for a malformed candidate,
	 * `RedirectSourceRejected` for a failed safety invariant,
	 * `RedirectProviderForbidden` for the backend's own capability,
	 * `RedirectRuleNotRepresentable` for an existing rule outside this
	 * contract, and `RedirectProviderUnavailable` for a provider that cannot
	 * write.
	 *
	 * @param array<string, mixed> $input   Validated ability input.
	 * @param int                  $user_id Acting principal.
	 * @return array<string, mixed>
	 * @throws Throwable Re-thrown validation, provider, or audit failure.
	 */
	public function execute( array $input, int $user_id ): array {
		$slug = is_string( $input['provider'] ?? null ) ? $input['provider'] : '';

		try {
			$candidate = $this->candidate( $input, $slug );
			$provider  = $this->registry->select( $slug );

			// The guard runs against every available provider, not just the
			// named one: a source already claimed by the other plugin is a
			// collision even though this write would "succeed".
			$this->guard->assert_creatable( $candidate, $this->registry->lookup(), $this->permalinks );

			$created = $provider->create( $candidate );
		} catch ( Throwable $error ) {
			$this->audit->record(
				new AuditEvent(
					$user_id,
					self::ABILITY,
					null,
					'redirect',
					array(),
					null,
					null,
					self::outcome_for( $error ),
					self::code_for( $error )
				)
			);

			throw $error;
		}

		$this->audit->record(
			new AuditEvent(
				$user_id,
				self::ABILITY,
				null,
				'redirect',
				// Field names only, never values — a source path is content
				// the caller supplied, and the audit table stores shapes.
				array( 'source', 'target', 'status', 'provider' ),
				null,
				null,
				'success',
				null
			)
		);

		return $created->to_array();
	}

	/**
	 * Builds the candidate rule from validated input.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @param string               $slug  Named provider slug.
	 * @return RedirectRule
	 * @throws InvalidArgumentException When any field is invalid.
	 */
	private function candidate( array $input, string $slug ): RedirectRule {
		if ( '' === $slug ) {
			throw new InvalidArgumentException( 'A redirect provider must be named explicitly.' );
		}

		$raw_status = $input['status'] ?? RedirectStatusCode::PERMANENT->value;
		$status     = RedirectStatusCode::tryFrom( is_numeric( $raw_status ) ? (int) $raw_status : 0 );
		if ( null === $status ) {
			throw new InvalidArgumentException( 'Redirect status is not one of the supported codes.' );
		}

		$source     = new RedirectSourcePath( is_string( $input['source'] ?? null ) ? $input['source'] : '' );
		$raw_target = $input['target'] ?? null;

		if ( RedirectStatusCode::GONE === $status ) {
			if ( null !== $raw_target && '' !== $raw_target ) {
				throw new InvalidArgumentException( 'A Gone redirect must not carry a target.' );
			}

			$target = null;
		} else {
			if ( ! is_string( $raw_target ) || '' === $raw_target ) {
				throw new InvalidArgumentException( 'A redirect requires a target unless its status is 410.' );
			}

			$target = new RedirectTargetUrl( $this->site_url, $raw_target );
		}

		// The candidate carries the *named* provider's status so a rejection
		// reports the backend the caller addressed, even when the write never
		// reaches it.
		return new RedirectRule( null, $source, $status, $target, true, $this->registry->status_for( $slug ) );
	}

	/**
	 * Classifies one failure for redacted audit storage.
	 *
	 * @param Throwable $error Failure.
	 * @return string
	 */
	private static function outcome_for( Throwable $error ): string {
		return match ( true ) {
			$error instanceof RedirectSourceRejected,
			$error instanceof InvalidArgumentException => 'invalid',
			$error instanceof RedirectProviderForbidden => 'denied',
			default => 'failure',
		};
	}

	/**
	 * Returns the stable error code for one failure.
	 *
	 * @param Throwable $error Failure.
	 * @return string
	 */
	private static function code_for( Throwable $error ): string {
		return match ( true ) {
			$error instanceof RedirectSourceRejected => 'wpcb_redirect_source_rejected',
			$error instanceof InvalidArgumentException => 'wpcb_invalid_input',
			$error instanceof RedirectProviderForbidden => 'wpcb_forbidden',
			$error instanceof RedirectRuleNotRepresentable => 'wpcb_redirect_rule_not_representable',
			$error instanceof RedirectProviderUnavailable => 'wpcb_redirect_provider_unavailable',
			default => 'wpcb_internal_error',
		};
	}
}

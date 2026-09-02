<?php
/**
 * Change an existing redirect in a named provider.
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
 * Replaces the target and status of the rule answering one source path, in the
 * provider the caller names.
 *
 * The source is the identity and is never changed here. Moving a rule to a
 * different source is a delete plus a create, and it has to be, because the
 * new source needs the full candidate guard — collision, live content,
 * reserved paths — that an update deliberately skips.
 */
final readonly class UpdateRedirect {

	public const ABILITY = 'wp-content-bridge/update-redirect';

	/**
	 * Creates the use case.
	 *
	 * @param RedirectProviderRegistry $registry Provider registry.
	 * @param RedirectCandidateGuard   $guard    Provider-neutral safety invariants.
	 * @param AuditLog                 $audit    Redacted audit sink.
	 * @param string                   $site_url Canonical site URL.
	 */
	public function __construct(
		private RedirectProviderRegistry $registry,
		private RedirectCandidateGuard $guard,
		private AuditLog $audit,
		private string $site_url,
	) {
	}

	/**
	 * Updates one redirect.
	 *
	 * Failures are audited and re-thrown unchanged, so the adapter maps one
	 * vocabulary: `InvalidArgumentException` for malformed input,
	 * `RedirectSourceRejected` for a target that would loop,
	 * `RedirectProviderForbidden` for the backend's own capability,
	 * `RedirectRuleNotRepresentable` for a stored rule outside this contract,
	 * and `RedirectProviderUnavailable` when the provider cannot write or
	 * holds no such rule.
	 *
	 * @param array<string, mixed> $input   Validated ability input.
	 * @param int                  $user_id Acting principal.
	 * @return array<string, mixed>
	 * @throws Throwable Re-thrown validation, provider, or audit failure.
	 */
	public function execute( array $input, int $user_id ): array {
		$slug = is_string( $input['provider'] ?? null ) ? $input['provider'] : '';

		try {
			$replacement = $this->replacement( $input, $slug );
			$provider    = $this->registry->select( $slug );

			$this->guard->assert_updatable( $replacement, $this->registry->lookup() );

			$updated = $provider->update( $replacement->source, $replacement );
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
					RedirectAuditOutcome::for_error( $error ),
					RedirectAuditOutcome::code_for( $error )
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
				array( 'target', 'status', 'provider' ),
				null,
				null,
				'success',
				null
			)
		);

		return $updated->to_array();
	}

	/**
	 * Builds the desired end state from validated input.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @param string               $slug  Named provider slug.
	 * @return RedirectRule
	 * @throws InvalidArgumentException When any field is invalid.
	 */
	private function replacement( array $input, string $slug ): RedirectRule {
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

		return new RedirectRule( null, $source, $status, $target, true, $this->registry->status_for( $slug ) );
	}
}

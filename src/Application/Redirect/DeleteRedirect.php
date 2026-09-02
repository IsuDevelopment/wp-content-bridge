<?php
/**
 * Remove an existing redirect from a named provider.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Application\Redirect;

use InvalidArgumentException;
use IsuDev\WPContentBridge\Application\Mutation\AuditEvent;
use IsuDev\WPContentBridge\Application\Mutation\AuditLog;
use IsuDev\WPContentBridge\Domain\Redirect\RedirectSourcePath;
use Throwable;

/**
 * Removes the rule answering one source path from the provider the caller
 * names.
 *
 * Removal, not disabling. Yoast Premium stores no per-rule enabled flag, so a
 * rule it holds is always live and a "disable" operation could not mean the
 * same thing in both backends; one operation that means the same everywhere is
 * worth more than two that quietly differ.
 *
 * This is genuinely destructive under ADR 0028: the rule's target and status
 * are configuration the caller did not supply, and removing it is not
 * reversible from this plugin.
 */
final readonly class DeleteRedirect {

	public const ABILITY = 'wp-content-bridge/delete-redirect';

	/**
	 * Creates the use case.
	 *
	 * @param RedirectProviderRegistry $registry Provider registry.
	 * @param AuditLog                 $audit    Redacted audit sink.
	 */
	public function __construct(
		private RedirectProviderRegistry $registry,
		private AuditLog $audit,
	) {
	}

	/**
	 * Removes one redirect.
	 *
	 * @param array<string, mixed> $input   Validated ability input.
	 * @param int                  $user_id Acting principal.
	 * @return array<string, mixed>
	 * @throws InvalidArgumentException When no provider is named or the source is malformed.
	 * @throws Throwable Re-thrown provider or audit failure.
	 */
	public function execute( array $input, int $user_id ): array {
		$slug = is_string( $input['provider'] ?? null ) ? $input['provider'] : '';

		try {
			if ( '' === $slug ) {
				throw new InvalidArgumentException( 'A redirect provider must be named explicitly.' );
			}

			$source   = new RedirectSourcePath( is_string( $input['source'] ?? null ) ? $input['source'] : '' );
			$provider = $this->registry->select( $slug );

			// The adapters confirm removal by reading back rather than trusting
			// a provider's "rows touched" answer, so a successful return here
			// means the rule is actually gone.
			$provider->delete( $source );
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
				array( 'source', 'provider' ),
				null,
				null,
				'success',
				null
			)
		);

		return array(
			'deleted'  => true,
			'source'   => $source->value(),
			'provider' => $this->registry->status_for( $slug )->to_array(),
		);
	}
}

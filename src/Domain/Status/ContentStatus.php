<?php
/**
 * Fixed content status vocabulary for controlled transitions.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Domain\Status;

/**
 * The only statuses a transition may name.
 *
 * This is the single source of truth ADR 0024 requires: every later task in
 * this slice (`get-status-transitions`, `transition-content-status`, the
 * settings screen) must resolve statuses through this enum rather than
 * re-declaring the list, so the vocabulary cannot drift between them.
 *
 * `trash`, `auto-draft`, `inherit`, and any status a theme or plugin
 * registers are deliberately absent. `PHP`'s `enum::tryFrom()` already
 * rejects anything outside this set at the point untrusted input is parsed
 * ({@see StatusTransition::from_strings()}), so no separate allowlist check
 * is needed anywhere a `ContentStatus` is required.
 */
enum ContentStatus: string {

	case DRAFT   = 'draft';
	case PENDING = 'pending';
	case PRIVATE = 'private';
	case PUBLISH = 'publish';
	case FUTURE  = 'future';
}

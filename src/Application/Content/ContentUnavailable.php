<?php
/**
 * Non-enumerating content access failure.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Application\Content;

use RuntimeException;

/**
 * Used for missing, policy-disabled, and unreadable objects alike.
 */
final class ContentUnavailable extends RuntimeException {
}

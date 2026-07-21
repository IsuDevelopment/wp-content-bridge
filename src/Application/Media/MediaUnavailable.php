<?php
/**
 * Non-enumerating media read failure.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Application\Media;

use RuntimeException;

/**
 * Missing, disabled, and unreadable media share one failure.
 */
final class MediaUnavailable extends RuntimeException {
}

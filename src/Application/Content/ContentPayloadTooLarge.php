<?php
/**
 * Content payload size failure.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Application\Content;

use RuntimeException;

/**
 * Raised when selected representations exceed the documented hard limit.
 */
final class ContentPayloadTooLarge extends RuntimeException {
}

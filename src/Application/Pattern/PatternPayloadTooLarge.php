<?php
/**
 * Oversized pattern-content response.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Application\Pattern;

use RuntimeException;

/**
 * Signals that complete markup cannot be returned within the public limit.
 */
final class PatternPayloadTooLarge extends RuntimeException {
}

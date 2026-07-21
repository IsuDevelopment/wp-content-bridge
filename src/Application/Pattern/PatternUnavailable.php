<?php
/**
 * Block-pattern access failure.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Application\Pattern;

use RuntimeException;

/**
 * Non-enumerating feature or native-access denial.
 */
final class PatternUnavailable extends RuntimeException {
}

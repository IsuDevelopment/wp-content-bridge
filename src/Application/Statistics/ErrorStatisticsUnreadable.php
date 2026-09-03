<?php
/**
 * Raised when a statistics backend cannot be read at all.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Application\Statistics;

use RuntimeException;

/**
 * Reserved for a backend that is present, vouched for, permitted - and still
 * failed, such as a database error mid-query.
 *
 * The four states ADR 0030 s2 defines (`unavailable`, `disabled`,
 * `forbidden`, `measured`) are *results*, not exceptions, precisely so a
 * caller can tell them apart. Throwing for any of them would collapse them
 * into one failure at the ability boundary, which is the defect the ADR
 * exists to prevent.
 */
final class ErrorStatisticsUnreadable extends RuntimeException {
}

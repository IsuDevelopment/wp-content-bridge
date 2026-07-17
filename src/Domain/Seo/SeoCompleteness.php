<?php
/**
 * SEO document completeness.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Domain\Seo;

/**
 * Describes whether a provider could populate the requested SEO document.
 */
enum SeoCompleteness: string {
	case COMPLETE    = 'complete';
	case PARTIAL     = 'partial';
	case UNAVAILABLE = 'unavailable';
}

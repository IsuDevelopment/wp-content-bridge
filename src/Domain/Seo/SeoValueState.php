<?php
/**
 * Normalized SEO value state.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Domain\Seo;

/**
 * Explains how a normalized SEO value was obtained.
 */
enum SeoValueState: string {
	case EXPLICIT    = 'explicit';
	case INHERITED   = 'inherited';
	case GENERATED   = 'generated';
	case UNSUPPORTED = 'unsupported';
	case UNAVAILABLE = 'unavailable';
}

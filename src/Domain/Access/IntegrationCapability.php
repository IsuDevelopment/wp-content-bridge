<?php
/**
 * Integration capability vocabulary.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Domain\Access;

/**
 * Plugin-level capabilities that may be assigned to an integration principal.
 */
enum IntegrationCapability: string {

	case READ_CONTENT    = 'wpcb_read_content';
	case EDIT_CONTENT    = 'wpcb_edit_content';
	case MANAGE_SEO      = 'wpcb_manage_seo';
	case PUBLISH_CONTENT = 'wpcb_publish_content';
}

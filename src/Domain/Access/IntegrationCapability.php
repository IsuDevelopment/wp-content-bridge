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

	case READ_CONTENT     = 'wpcb_read_content';
	case READ_MEDIA       = 'wpcb_read_media';
	case READ_PATTERNS    = 'wpcb_read_patterns';
	case EDIT_CONTENT     = 'wpcb_edit_content';
	case MANAGE_SEO       = 'wpcb_manage_seo';
	case PUBLISH_CONTENT  = 'wpcb_publish_content';
	case DELETE_CONTENT   = 'wpcb_delete_content';
	case MANAGE_LLMS      = 'wpcb_manage_llms';
	case MANAGE_REDIRECTS = 'wpcb_manage_redirects';
	case UPLOAD_MEDIA     = 'wpcb_upload_media';

	/**
	 * Reading aggregate site error statistics is separate authority from
	 * managing redirects (ADR 0030 s5). Redirection's own permission model
	 * already separates the two, so honouring the separation costs nothing
	 * and makes the useful grant expressible: diagnose which redirect is
	 * missing, without authority to change routing.
	 */
	case READ_ERROR_STATISTICS = 'wpcb_read_error_statistics';
}

<?php
/**
 * Content access operation vocabulary.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Domain\ContentAccess;

/**
 * Stable operation keys used by settings and future abilities.
 */
enum ContentOperation: string {

	case READ              = 'get_content';
	case SEARCH            = 'search_content';
	case CREATE            = 'create_draft';
	case UPDATE            = 'update_content';
	case UPDATE_SEO        = 'update_seo';
	case TRANSITION_STATUS = 'transition_content_status';
	case TRASH             = 'trash_content';

	/**
	 * Returns operations that must also be enabled.
	 *
	 * Native WordPress capabilities are checked separately at execution time.
	 *
	 * @return list<self>
	 */
	public function prerequisites(): array {
		return match ( $this ) {
			self::READ => array(),
			self::SEARCH,
			self::CREATE,
			self::UPDATE,
			self::UPDATE_SEO,
			self::TRANSITION_STATUS,
			self::TRASH => array( self::READ ),
		};
	}
}

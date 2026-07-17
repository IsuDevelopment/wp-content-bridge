<?php
/**
 * Content-type catalog port.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Application\ContentAccess;

use IsuDev\WPContentBridge\Domain\ContentAccess\ContentTypeDefinition;

/**
 * Supplies content types eligible for policy configuration.
 */
interface ContentTypeCatalog {

	/**
	 * Lists content types that may be configured.
	 *
	 * @return list<ContentTypeDefinition>
	 */
	public function list_eligible(): array;
}

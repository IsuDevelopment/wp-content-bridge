<?php
/**
 * Port for reading the post identity a schema write depends on.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Application\Mutation;

use IsuDev\WPContentBridge\Domain\Mutation\SchemaTarget;

/**
 * Deliberately narrow: a schema read must not pull the content pipeline in.
 */
interface SchemaTargetReader {

	/**
	 * Reads the identity projection, or null when the post is absent.
	 *
	 * @param int $post_id Post ID.
	 * @return SchemaTarget|null
	 */
	public function read( int $post_id ): ?SchemaTarget;
}

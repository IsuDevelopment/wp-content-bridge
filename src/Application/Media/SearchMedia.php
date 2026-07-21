<?php
/**
 * Search-media use case.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Application\Media;

use IsuDev\WPContentBridge\Domain\Media\MediaQuery;
use IsuDev\WPContentBridge\Domain\Media\MediaSearchResult;

/**
 * Enforces media policy before repository access.
 */
final readonly class SearchMedia {

	/**
	 * Creates the use case.
	 *
	 * @param MediaAccessManager $access     Media policy.
	 * @param MediaRepository    $repository Media reader.
	 */
	public function __construct(
		private MediaAccessManager $access,
		private MediaRepository $repository,
	) {
	}

	/**
	 * Searches after enforcing the master policy.
	 *
	 * @param MediaQuery $query Search criteria.
	 * @return MediaSearchResult
	 * @throws MediaUnavailable When reads are disabled.
	 */
	public function execute( MediaQuery $query ): MediaSearchResult {
		$this->access->require_read();

		return $this->repository->search( $query );
	}
}

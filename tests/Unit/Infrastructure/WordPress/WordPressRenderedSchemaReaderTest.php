<?php
/**
 * Rendered schema reader tests.
 *
 * @package IsuDev\WPContentBridge\Tests
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Tests\Unit\Infrastructure\WordPress;

use Closure;
use IsuDev\WPContentBridge\Domain\Seo\RenderedGraph;
use IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressRenderedSchemaReader;
use PHPUnit\Framework\TestCase;

/**
 * Ensures the reader is same-origin bounded and parses only JSON-LD graphs.
 */
final class WordPressRenderedSchemaReaderTest extends TestCase {

	/**
	 * Encodes a JSON-LD document for a test fixture.
	 *
	 * @param array<string, mixed> $document JSON-LD document.
	 * @return string
	 */
	private function encode( array $document ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- WordPress helpers are unavailable in unit tests.
		return (string) json_encode( $document );
	}

	/**
	 * Wraps rendered HTML into a fetcher that returns a 200 response.
	 *
	 * @param string $html Page markup.
	 * @return Closure
	 * @phpstan-return Closure(): array{code: int, body: string}
	 */
	private function fetcher_returning( string $html ): Closure {
		return static fn (): array => array(
			'code' => 200,
			'body' => $html,
		);
	}

	/**
	 * Wraps a single JSON-LD document in a script tag.
	 *
	 * @param string $json Encoded JSON-LD document.
	 * @return string
	 */
	private function page_with_ld_json( string $json ): string {
		return '<html><head><title>x</title>'
			. '<script type="application/ld+json">' . $json . '</script>'
			. '</head><body>ignored</body></html>';
	}

	/**
	 * A same-origin page's JSON-LD @graph is parsed into bounded nodes.
	 */
	public function test_parses_graph_from_same_origin_page(): void {
		$json = $this->encode(
			array(
				'@context' => 'https://schema.org',
				'@graph'   => array(
					array(
						'@type'              => array( 'Organization', 'Place', 'Dentist' ),
						'@id'                => 'https://example.com/locations/warsaw/#local-branch-organization',
						'name'               => 'Warsaw Branch',
						'parentOrganization' => array( '@id' => 'https://example.com/#organization' ),
					),
					array(
						'@type' => 'Organization',
						'@id'   => 'https://example.com/#organization',
						'name'  => 'Example Group',
					),
				),
			)
		);

		$reader = new WordPressRenderedSchemaReader(
			'https://example.com',
			$this->fetcher_returning( $this->page_with_ld_json( $json ) )
		);

		$result = $reader->graph_for_url( 'https://example.com/locations/warsaw/' );
		$nodes  = $result->nodes;

		self::assertSame( RenderedGraph::CAPTURED, $result->outcome );
		self::assertNull( $result->diagnosis() );
		self::assertSame( 200, $result->status_code );
		self::assertCount( 2, $nodes );
		self::assertSame( 'Warsaw Branch', $nodes[0]['name'] );
		self::assertSame(
			array( '@id' => 'https://example.com/#organization' ),
			$nodes[0]['parentOrganization']
		);
	}

	/**
	 * A cross-origin URL is rejected before any fetch is attempted.
	 */
	public function test_rejects_cross_origin_url_without_fetching(): void {
		$called  = false;
		$fetcher = static function () use ( &$called ): array {
			$called = true;

			return array(
				'code' => 200,
				'body' => '',
			);
		};
		$reader  = new WordPressRenderedSchemaReader( 'https://example.com', $fetcher );

		$result = $reader->graph_for_url( 'https://evil.example.net/locations/warsaw/' );

		self::assertSame( array(), $result->nodes );
		self::assertSame( RenderedGraph::NOT_SAME_ORIGIN, $result->outcome );
		self::assertFalse( $called, 'A cross-origin URL must never be fetched.' );
	}

	/**
	 * A non-200 response yields no graph.
	 */
	public function test_non_200_response_returns_no_graph(): void {
		$reader = new WordPressRenderedSchemaReader(
			'https://example.com',
			static fn (): array => array(
				'code' => 404,
				'body' => 'not found',
			)
		);

		$result = $reader->graph_for_url( 'https://example.com/missing/' );

		self::assertSame( array(), $result->nodes );
		self::assertSame( RenderedGraph::HTTP_ERROR, $result->outcome );
		self::assertSame( 404, $result->status_code );
		self::assertStringContainsString( 'HTTP 404', (string) $result->diagnosis() );
	}

	/**
	 * A refused request is reported as a transport failure, not as a page
	 * without schema. These need opposite responses from an operator, and a
	 * blocked loopback request is the common production case.
	 */
	public function test_refused_request_is_distinguished_from_a_page_without_schema(): void {
		$refused = new WordPressRenderedSchemaReader( 'https://example.com', static fn (): ?array => null );
		$empty   = new WordPressRenderedSchemaReader(
			'https://example.com',
			$this->fetcher_returning( '<p>No structured data here.</p>' )
		);

		$refused_result = $refused->graph_for_url( 'https://example.com/' );
		$empty_result   = $empty->graph_for_url( 'https://example.com/' );

		self::assertSame( array(), $refused_result->nodes );
		self::assertSame( array(), $empty_result->nodes );
		self::assertNotSame( $refused_result->outcome, $empty_result->outcome );
		self::assertSame( RenderedGraph::REQUEST_FAILED, $refused_result->outcome );
		self::assertSame( RenderedGraph::EMPTY_GRAPH, $empty_result->outcome );
		self::assertStringContainsString( 'could not fetch its own page', (string) $refused_result->diagnosis() );
		self::assertStringContainsString( 'emits no JSON-LD', (string) $empty_result->diagnosis() );
	}

	/**
	 * Malformed JSON-LD blocks are skipped and valid ones still parse.
	 */
	public function test_skips_malformed_json_blocks(): void {
		$html   = '<script type="application/ld+json">{ not valid json ,,}</script>'
			. '<script type="application/ld+json">'
			. $this->encode(
				array(
					'@type' => 'LocalBusiness',
					'@id'   => 'https://example.com/#local',
					'name'  => 'Standalone',
				)
			)
			. '</script>';
		$reader = new WordPressRenderedSchemaReader( 'https://example.com', $this->fetcher_returning( $html ) );

		$nodes = $reader->graph_for_url( 'https://example.com/' )->nodes;

		self::assertCount( 1, $nodes );
		self::assertSame( 'Standalone', $nodes[0]['name'] );
	}

	/**
	 * Node output is capped even when the page contains a very large graph.
	 */
	public function test_caps_node_count(): void {
		$graph = array();
		for ( $i = 0; $i < 500; $i++ ) {
			$graph[] = array(
				'@type' => 'Thing',
				'@id'   => 'https://example.com/#n' . $i,
			);
		}
		$json   = $this->encode( array( '@graph' => $graph ) );
		$reader = new WordPressRenderedSchemaReader( 'https://example.com', $this->fetcher_returning( $this->page_with_ld_json( $json ) ) );

		self::assertLessThanOrEqual( 200, count( $reader->graph_for_url( 'https://example.com/' )->nodes ) );
	}
}

<?php
/**
 * Ability JSON Schema fixtures.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Adapter\Abilities;

/**
 * Centralizes public schemas so registrations remain readable.
 */
final class AbilitySchemas {

	/**
	 * Returns the search input schema.
	 *
	 * @return array<string, mixed>
	 */
	public static function search_input(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'query'            => array(
					'description' => 'Optional full-text search phrase.',
					'type'        => 'string',
					'maxLength'   => 500,
					'default'     => '',
				),
				'post_types'       => array_merge( array( 'description' => 'Enabled public REST post types to search.' ), self::string_array( 20 ) ),
				'statuses'         => array_merge( array( 'description' => 'WordPress post statuses to search, subject to object permissions.' ), self::string_array( 20, array( 'publish' ) ) ),
				'author_ids'       => array_merge( array( 'description' => 'Optional author user IDs.' ), self::integer_array( 100 ) ),
				'taxonomy'         => array(
					'description' => 'Taxonomy filters combined with AND semantics.',
					'type'        => 'array',
					'maxItems'    => 10,
					'uniqueItems' => true,
					'items'       => array(
						'type'                 => 'object',
						'required'             => array( 'taxonomy', 'term_ids' ),
						'properties'           => array(
							'taxonomy' => array(
								'description' => 'Registered public REST taxonomy slug.',
								'type'        => 'string',
								'minLength'   => 1,
								'maxLength'   => 32,
								'pattern'     => '^[a-z0-9_-]+$',
							),
							'term_ids' => array(
								'description' => 'Term IDs matched within the taxonomy.',
								'type'        => 'array',
								'minItems'    => 1,
								'maxItems'    => 100,
								'uniqueItems' => true,
								'items'       => array(
									'type'    => 'integer',
									'minimum' => 1,
								),
							),
						),
						'additionalProperties' => false,
					),
					'default'     => array(),
				),
				'published_after'  => array(
					'description' => 'Only content published at or after this RFC 3339 timestamp.',
					'type'        => 'string',
					'format'      => 'date-time',
				),
				'published_before' => array(
					'description' => 'Only content published at or before this RFC 3339 timestamp.',
					'type'        => 'string',
					'format'      => 'date-time',
				),
				'modified_after'   => array(
					'description' => 'Only content modified at or after this RFC 3339 timestamp.',
					'type'        => 'string',
					'format'      => 'date-time',
				),
				'modified_before'  => array(
					'description' => 'Only content modified at or before this RFC 3339 timestamp.',
					'type'        => 'string',
					'format'      => 'date-time',
				),
				'page'             => array(
					'description' => 'One-based result page.',
					'type'        => 'integer',
					'minimum'     => 1,
					'default'     => 1,
				),
				'per_page'         => array(
					'description' => 'Maximum items returned per page.',
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 100,
					'default'     => 20,
				),
				'order_by'         => array(
					'description' => 'Result ordering field.',
					'type'        => 'string',
					'enum'        => array( 'relevance', 'date', 'modified', 'title', 'id' ),
					'default'     => 'relevance',
				),
				'order'            => array(
					'description' => 'Ascending or descending result direction.',
					'type'        => 'string',
					'enum'        => array( 'asc', 'desc' ),
					'default'     => 'desc',
				),
			),
			'additionalProperties' => false,
			'default'              => (object) array(),
		);
	}

	/**
	 * Returns the search output schema.
	 *
	 * @return array<string, mixed>
	 */
	public static function search_output(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'schema_version', 'items', 'pagination', 'provenance' ),
			'properties'           => array(
				'schema_version' => array( 'type' => 'string' ),
				'items'          => array(
					'type'  => 'array',
					'items' => self::summary(),
				),
				'pagination'     => array(
					'type'                 => 'object',
					'required'             => array( 'page', 'per_page', 'total_items', 'total_pages', 'total_is_exact', 'has_more', 'candidate_scan_limit' ),
					'properties'           => array(
						'page'                 => array( 'type' => 'integer' ),
						'per_page'             => array( 'type' => 'integer' ),
						'total_items'          => array( 'type' => 'integer' ),
						'total_pages'          => array( 'type' => 'integer' ),
						'total_is_exact'       => array( 'type' => 'boolean' ),
						'has_more'             => array( 'type' => 'boolean' ),
						'candidate_scan_limit' => array( 'type' => 'integer' ),
					),
					'additionalProperties' => false,
				),
				'provenance'     => self::provenance(),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Returns the detail input schema.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_input(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'post_id' ),
			'properties'           => array(
				'post_id'         => array(
					'description' => 'WordPress content object ID.',
					'type'        => 'integer',
					'minimum'     => 1,
				),
				'representations' => array(
					'description' => 'Content representations to return within the shared 2 MiB limit.',
					'type'        => 'array',
					'uniqueItems' => true,
					'maxItems'    => 3,
					'items'       => array(
						'type' => 'string',
						'enum' => array( 'raw', 'rendered', 'plain_text' ),
					),
					'default'     => array( 'raw', 'plain_text' ),
				),
				'include'         => array(
					'description' => 'Optional bounded relationships to include.',
					'type'        => 'array',
					'uniqueItems' => true,
					'maxItems'    => 4,
					'items'       => array(
						'type' => 'string',
						'enum' => array( 'author', 'taxonomies', 'featured_media', 'revision' ),
					),
					'default'     => array(),
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Returns the detail output schema.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_output(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'schema_version', 'content', 'representations', 'relationships', 'concurrency_token', 'payload', 'provenance', 'warnings' ),
			'properties'           => array(
				'schema_version'    => array( 'type' => 'string' ),
				'content'           => self::summary(),
				'representations'   => array(
					'type'                 => 'object',
					'additionalProperties' => array( 'type' => 'string' ),
				),
				'relationships'     => array(
					'type'                 => 'object',
					'additionalProperties' => true,
				),
				'concurrency_token' => array( 'type' => array( 'string', 'null' ) ),
				'version_token'     => array(
					'description' => 'Opaque optimistic-concurrency token to pass to update-content.',
					'type'        => array( 'string', 'null' ),
				),
				'payload'           => array(
					'type'                 => 'object',
					'required'             => array( 'representation_bytes', 'total_representation_bytes' ),
					'properties'           => array(
						'representation_bytes'       => array(
							'type'                 => 'object',
							'additionalProperties' => array(
								'type'    => 'integer',
								'minimum' => 0,
							),
						),
						'total_representation_bytes' => array(
							'type'    => 'integer',
							'minimum' => 0,
						),
					),
					'additionalProperties' => false,
				),
				'provenance'        => self::provenance(),
				'warnings'          => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Returns the get-block-tree input schema.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_block_tree_input(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'post_id' ),
			'properties'           => array(
				'post_id'       => array(
					'description' => 'WordPress content object ID.',
					'type'        => 'integer',
					'minimum'     => 1,
				),
				'max_depth'     => array(
					'description' => 'Maximum node depth to return, counted from the returned root (1 returns only that node, or only top-level nodes when path is omitted). Omit for unbounded depth, still subject to the 500-node cap.',
					'type'        => 'integer',
					'minimum'     => 1,
				),
				'path'          => array(
					'description' => 'Zero-based indices into successive innerBlocks arrays identifying a subtree root; returns that node and its descendants instead of the whole document. parse_blocks() emits block_name: null freeform nodes for whitespace between blocks, and these occupy real indices that must be counted when building a path. Omit to read from the top of the document.',
					'type'        => 'array',
					'minItems'    => 1,
					'maxItems'    => 20,
					'items'       => array(
						'type'    => 'integer',
						'minimum' => 0,
					),
				),
				'include_attrs' => array(
					'description' => 'Whether to include each node\'s raw attrs. Defaults to false: attrs are omitted entirely (this is the contract, not a size omission, so attrs_omitted is never set in that case). When true, per-node attrs are still bounded by the 512-byte encoded limit, above which attrs_omitted is set instead.',
					'type'        => 'boolean',
					'default'     => false,
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Returns the get-block-tree output schema.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_block_tree_output(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'schema_version', 'post_id', 'post_type', 'version_token', 'nodes', 'truncated', 'provenance' ),
			'properties'           => array(
				'schema_version' => array( 'type' => 'string' ),
				'post_id'        => array( 'type' => 'integer' ),
				'post_type'      => array( 'type' => 'string' ),
				'version_token'  => array(
					'description' => 'Optimistic-concurrency token to pass to update-block or preview-update-block.',
					'type'        => 'string',
				),
				'nodes'          => array(
					'description' => 'Flat, document-ordered nodes, each carrying its own explicit path rather than nesting.',
					'type'        => 'array',
					'maxItems'    => 500,
					'items'       => self::block_tree_node(),
				),
				'truncated'      => array(
					'description' => 'True when the 500-node cap stopped traversal before every node was returned.',
					'type'        => 'boolean',
				),
				'provenance'     => self::provenance(),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Returns the bounded media search input schema.
	 *
	 * @return array<string, mixed>
	 */
	public static function media_search_input(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'id'       => array(
					'description' => 'Optional exact WordPress attachment ID. Mutually exclusive with url, filename, and query.',
					'type'        => 'integer',
					'minimum'     => 1,
				),
				'url'      => array(
					'description' => 'Optional exact same-site original attachment URL. Mutually exclusive with other selectors.',
					'type'        => 'string',
					'format'      => 'uri',
					'maxLength'   => 2048,
				),
				'filename' => array(
					'description' => 'Optional exact attachment basename, including extension. Mutually exclusive with other selectors.',
					'type'        => 'string',
					'minLength'   => 1,
					'maxLength'   => 255,
					'pattern'     => '^[^/\\\\]+$',
				),
				'query'    => array(
					'description' => 'Optional WordPress attachment title, caption, or description search. Mutually exclusive with exact selectors.',
					'type'        => 'string',
					'maxLength'   => 500,
					'default'     => '',
				),
				'page'     => array(
					'type'    => 'integer',
					'minimum' => 1,
					'default' => 1,
				),
				'per_page' => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 100,
					'default' => 20,
				),
			),
			'additionalProperties' => false,
			'default'              => (object) array(),
		);
	}

	/**
	 * Returns the deterministic media-by-ID input schema.
	 *
	 * @return array<string, mixed>
	 */
	public static function media_by_id_input(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'id' ),
			'properties'           => array(
				'id' => array(
					'description' => 'WordPress attachment ID.',
					'type'        => 'integer',
					'minimum'     => 1,
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Returns the media search object-envelope schema.
	 *
	 * @return array<string, mixed>
	 */
	public static function media_search_output(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'schema_version', 'items', 'pagination', 'provenance' ),
			'properties'           => array(
				'schema_version' => array( 'type' => 'string' ),
				'items'          => array(
					'type'     => 'array',
					'maxItems' => 100,
					'items'    => self::media_item(),
				),
				'pagination'     => array(
					'type'                 => 'object',
					'required'             => array( 'page', 'per_page', 'total_items', 'total_pages', 'total_is_exact', 'has_more', 'candidate_scan_limit' ),
					'properties'           => array(
						'page'                 => array( 'type' => 'integer' ),
						'per_page'             => array( 'type' => 'integer' ),
						'total_items'          => array( 'type' => 'integer' ),
						'total_pages'          => array( 'type' => 'integer' ),
						'total_is_exact'       => array( 'type' => 'boolean' ),
						'has_more'             => array( 'type' => 'boolean' ),
						'candidate_scan_limit' => array( 'type' => 'integer' ),
					),
					'additionalProperties' => false,
				),
				'provenance'     => self::provenance(),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Returns the one-media object-envelope schema.
	 *
	 * @return array<string, mixed>
	 */
	public static function media_by_id_output(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'schema_version', 'item', 'provenance' ),
			'properties'           => array(
				'schema_version' => array( 'type' => 'string' ),
				'item'           => self::media_item(),
				'provenance'     => self::provenance(),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Returns the bounded block-pattern listing input schema.
	 *
	 * @return array<string, mixed>
	 */
	public static function pattern_list_input(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'query'           => array(
					'description' => 'Optional text search across pattern name, title, description, and keywords.',
					'type'        => 'string',
					'maxLength'   => 200,
					'default'     => '',
				),
				'namespace'       => array(
					'description' => 'Optional exact pattern namespace before the slash.',
					'type'        => 'string',
					'minLength'   => 1,
					'maxLength'   => 100,
					'pattern'     => '^[A-Za-z0-9][A-Za-z0-9._-]*$',
				),
				'category'        => array(
					'description' => 'Optional exact registered pattern category slug.',
					'type'        => 'string',
					'minLength'   => 1,
					'maxLength'   => 100,
					'pattern'     => '^[A-Za-z0-9][A-Za-z0-9._-]*$',
				),
				'post_type'       => array(
					'description' => 'Optional exact post type; global patterns and patterns supporting this type match.',
					'type'        => 'string',
					'minLength'   => 1,
					'maxLength'   => 20,
					'pattern'     => '^[A-Za-z0-9][A-Za-z0-9._-]*$',
				),
				'include_content' => array(
					'description' => 'Whether to include complete block markup within the combined response limit.',
					'type'        => 'boolean',
					'default'     => false,
				),
				'page'            => array(
					'type'    => 'integer',
					'minimum' => 1,
					'default' => 1,
				),
				'per_page'        => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 50,
					'default' => 20,
				),
			),
			'additionalProperties' => false,
			'default'              => (object) array(),
		);
	}

	/**
	 * Returns the strict block-pattern result envelope schema.
	 *
	 * @return array<string, mixed>
	 */
	public static function pattern_list_output(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'schema_version', 'items', 'pagination', 'limits', 'provenance' ),
			'properties'           => array(
				'schema_version' => array( 'type' => 'string' ),
				'items'          => array(
					'type'     => 'array',
					'maxItems' => 50,
					'items'    => self::pattern_item(),
				),
				'pagination'     => array(
					'type'                 => 'object',
					'required'             => array( 'page', 'per_page', 'total_items', 'total_pages', 'total_is_exact', 'has_more', 'candidate_scan_limit' ),
					'properties'           => array(
						'page'                 => array( 'type' => 'integer' ),
						'per_page'             => array( 'type' => 'integer' ),
						'total_items'          => array( 'type' => 'integer' ),
						'total_pages'          => array( 'type' => 'integer' ),
						'total_is_exact'       => array( 'type' => 'boolean' ),
						'has_more'             => array( 'type' => 'boolean' ),
						'candidate_scan_limit' => array( 'type' => 'integer' ),
					),
					'additionalProperties' => false,
				),
				'limits'         => array(
					'type'                 => 'object',
					'required'             => array( 'content_response_bytes' ),
					'properties'           => array(
						'content_response_bytes' => array( 'type' => 'integer' ),
					),
					'additionalProperties' => false,
				),
				'provenance'     => self::provenance(),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Returns the bounded editorial-context selector schema.
	 *
	 * @return array<string, mixed>
	 */
	public static function editorial_context_input(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'sections'           => array(
					'description' => 'Context sections to return. Omit to request every bounded section.',
					'type'        => 'array',
					'minItems'    => 1,
					'maxItems'    => 6,
					'uniqueItems' => true,
					'items'       => array(
						'type' => 'string',
						'enum' => array( 'post_types', 'taxonomies', 'terms', 'authors', 'recent_content', 'local_businesses' ),
					),
					'default'     => array( 'post_types', 'taxonomies', 'terms', 'authors', 'recent_content', 'local_businesses' ),
				),
				'post_types'         => array_merge( array( 'description' => 'Optional policy-enabled content types. All enabled searchable types are used when omitted.' ), self::string_array( 20, array() ) ),
				'taxonomies'         => array_merge( array( 'description' => 'Optional public taxonomy slugs assigned to at least one effective content type.' ), self::string_array( 20, array() ) ),
				'recent_limit'       => array(
					'description' => 'Maximum published readable content summaries and therefore observed authors.',
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 50,
					'default'     => 20,
				),
				'terms_per_taxonomy' => array(
					'description' => 'Maximum vocabulary terms returned for each selected taxonomy.',
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 100,
					'default'     => 50,
				),
			),
			'additionalProperties' => false,
			'default'              => (object) array(),
		);
	}

	/**
	 * Returns the strict editorial-context response schema.
	 *
	 * @return array<string, mixed>
	 */
	public static function editorial_context_output(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'schema_version', 'sections', 'context', 'bounds', 'provenance', 'warnings' ),
			'properties'           => array(
				'schema_version' => array( 'type' => 'string' ),
				'sections'       => self::string_array( 6 ),
				'context'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'post_types'       => array(
							'type'     => 'array',
							'maxItems' => 20,
							'items'    => self::editorial_post_type(),
						),
						'taxonomies'       => array(
							'type'     => 'array',
							'maxItems' => 20,
							'items'    => self::editorial_taxonomy(),
						),
						'terms'            => array(
							'type'     => 'array',
							'maxItems' => 20,
							'items'    => self::editorial_terms(),
						),
						'authors'          => array(
							'type'     => 'array',
							'maxItems' => 50,
							'items'    => array(
								'type'                 => 'object',
								'required'             => array( 'id', 'display_name' ),
								'properties'           => array(
									'id'           => array( 'type' => 'integer' ),
									'display_name' => array( 'type' => 'string' ),
								),
								'additionalProperties' => false,
							),
						),
						'recent_content'   => array(
							'type'     => 'array',
							'maxItems' => 50,
							'items'    => self::summary(),
						),
						'local_businesses' => self::editorial_local_businesses(),
					),
					'additionalProperties' => false,
				),
				'bounds'         => array(
					'type'                 => 'object',
					'required'             => array( 'recent_content_limit', 'taxonomy_limit', 'terms_per_taxonomy' ),
					'properties'           => array(
						'recent_content_limit' => array( 'type' => 'integer' ),
						'taxonomy_limit'       => array( 'type' => 'integer' ),
						'terms_per_taxonomy'   => array( 'type' => 'integer' ),
					),
					'additionalProperties' => false,
				),
				'provenance'     => self::provenance(),
				'warnings'       => array(
					'type'     => 'array',
					'maxItems' => 20,
					'items'    => array(
						'type'      => 'string',
						'maxLength' => 500,
					),
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Returns the SEO selector input schema.
	 *
	 * @return array<string, mixed>
	 */
	public static function seo_input(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'post_id' => array(
					'description' => 'Readable WordPress content object ID.',
					'type'        => 'integer',
					'minimum'     => 1,
				),
				'url'     => array(
					'description' => 'Absolute URL on the current WordPress site origin.',
					'type'        => 'string',
					'format'      => 'uri',
					'maxLength'   => 2048,
				),
			),
			'oneOf'                => array(
				array( 'required' => array( 'post_id' ) ),
				array( 'required' => array( 'url' ) ),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Returns the normalized SEO output schema.
	 *
	 * @return array<string, mixed>
	 */
	public static function seo_output(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'schema_version', 'configured', 'resolved', 'analysis', 'schema_graph', 'provenance', 'warnings' ),
			'properties'           => array(
				'schema_version' => array( 'type' => 'string' ),
				'configured'     => self::seo_field_section( array( 'title', 'description', 'focus_keyphrases', 'keyphrase_details', 'keyphrase_synonyms', 'related_keyphrases', 'canonical', 'robots', 'social', 'schema_types', 'cornerstone' ) ),
				'resolved'       => self::seo_field_section( array( 'title', 'description', 'canonical', 'robots', 'open_graph', 'twitter', 'other_public_meta', 'local_businesses' ) ),
				'analysis'       => self::seo_field_section( array( 'seo', 'readability', 'inclusive_language' ) ),
				'schema_graph'   => array(
					'type'     => 'array',
					'maxItems' => 200,
					'items'    => array(
						'type'                 => 'object',
						'additionalProperties' => true,
					),
				),
				'provenance'     => array(
					'type'                 => 'object',
					'required'             => array( 'provider', 'normalization_schema_version', 'completeness' ),
					'properties'           => array(
						'provider'                     => self::provider_status(),
						'normalization_schema_version' => array( 'type' => 'string' ),
						'completeness'                 => array(
							'type' => 'string',
							'enum' => array( 'complete', 'partial', 'unavailable' ),
						),
					),
					'additionalProperties' => false,
				),
				'warnings'       => array(
					'type'     => 'array',
					'maxItems' => 50,
					'items'    => array(
						'type'      => 'string',
						'maxLength' => 500,
					),
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Returns the diagnostics output schema.
	 *
	 * @return array<string, mixed>
	 */
	public static function diagnostics_output(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'schema_version', 'plugin_version', 'wordpress_version', 'abilities_api', 'mcp_adapter', 'mcp_projection', 'max_content_representation_bytes', 'seo_provider', 'readable_post_types' ),
			'properties'           => array(
				'schema_version'                   => array( 'type' => 'string' ),
				'plugin_version'                   => array( 'type' => 'string' ),
				'wordpress_version'                => array( 'type' => 'string' ),
				'abilities_api'                    => array( 'type' => 'boolean' ),
				'mcp_adapter'                      => array( 'type' => 'boolean' ),
				'mcp_projection'                   => array(
					'description'          => 'What this plugin currently projects as MCP tools, so a missing tool can be told from a missing ability.',
					'type'                 => 'object',
					'required'             => array( 'enabled', 'endpoint', 'projected_abilities' ),
					'properties'           => array(
						'enabled'             => array(
							'description' => 'Whether the projection switch is on. False means the plugin registers no MCP server, whatever the adapter does.',
							'type'        => 'boolean',
						),
						'endpoint'            => array(
							'description' => 'REST route of the projected server, or null when the adapter is absent or projection is off.',
							'type'        => array( 'string', 'null' ),
						),
						'projected_abilities' => array_merge(
							array( 'description' => 'Ability names discovered for projection in this request. Compare against the abilities you expected to see.' ),
							self::string_array( 200, array() )
						),
					),
					'additionalProperties' => false,
				),
				'max_content_representation_bytes' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'seo_provider'                     => self::provider_status(),
				'readable_post_types'              => self::string_array( 100 ),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Returns the create-draft input schema.
	 *
	 * @return array<string, mixed>
	 */
	public static function create_draft_input(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'post_type', 'title' ),
			'properties'           => array(
				'post_type'       => array(
					'description' => 'Target post type slug.',
					'type'        => 'string',
					'pattern'     => '^[a-z0-9_-]{1,20}$',
				),
				'title'           => array(
					'description' => 'Post title.',
					'type'        => 'string',
					'minLength'   => 1,
					'maxLength'   => 500,
				),
				'block_markup'    => array(
					'description' => 'Gutenberg block markup for the post body.',
					'type'        => 'string',
					'maxLength'   => 500000,
					'default'     => '',
				),
				'excerpt'         => array(
					'description' => 'Optional excerpt.',
					'type'        => array( 'string', 'null' ),
					'maxLength'   => 2000,
				),
				'taxonomies'      => self::taxonomy_assignment_schema(),
				'idempotency_key' => array(
					'description' => 'Optional client key to make creation idempotent for 24h.',
					'type'        => array( 'string', 'null' ),
					'pattern'     => '^[A-Za-z0-9_.\\-]{1,128}$',
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Returns the update-content input schema.
	 *
	 * @return array<string, mixed>
	 */
	public static function update_content_input(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'post_id', 'version_token' ),
			'properties'           => array(
				'post_id'       => array(
					'description' => 'Target post ID.',
					'type'        => 'integer',
					'minimum'     => 1,
				),
				'version_token' => array(
					'description' => 'Optimistic-concurrency token from get-content.',
					'type'        => 'string',
					'minLength'   => 18,
					'maxLength'   => 191,
				),
				'title'         => array(
					'description' => 'Replacement title.',
					'type'        => array( 'string', 'null' ),
					'minLength'   => 1,
					'maxLength'   => 500,
				),
				'block_markup'  => array(
					'description' => 'Replacement block markup.',
					'type'        => array( 'string', 'null' ),
					'maxLength'   => 500000,
				),
				'excerpt'       => array(
					'description' => 'Replacement excerpt.',
					'type'        => array( 'string', 'null' ),
					'maxLength'   => 2000,
				),
				'taxonomies'    => self::taxonomy_assignment_schema(),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Returns the create-draft output schema.
	 *
	 * @return array<string, mixed>
	 */
	public static function create_draft_output(): array {
		return self::mutation_output();
	}

	/**
	 * Returns the update-content output schema.
	 *
	 * @return array<string, mixed>
	 */
	public static function update_content_output(): array {
		return self::mutation_output();
	}

	/**
	 * Returns the preview-update-content input contract.
	 *
	 * The preview intentionally shares the exact update input so the result can
	 * be applied without changing semantic intent or validation rules.
	 *
	 * @return array<string, mixed>
	 */
	public static function preview_content_input(): array {
		return self::update_content_input();
	}

	/**
	 * Returns the preview-update-content output contract.
	 *
	 * @return array<string, mixed>
	 */
	public static function preview_content_output(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'schema_version', 'writes_performed', 'post_id', 'post_type', 'version_token', 'changed_fields', 'current_content', 'preview_content', 'preview_taxonomies', 'warnings', 'provenance' ),
			'properties'           => array(
				'schema_version'     => array( 'type' => 'string' ),
				'writes_performed'   => array( 'type' => 'boolean' ),
				'post_id'            => array( 'type' => 'integer' ),
				'post_type'          => array( 'type' => 'string' ),
				'version_token'      => array( 'type' => 'string' ),
				'changed_fields'     => array(
					'type'        => 'array',
					'uniqueItems' => true,
					'maxItems'    => 4,
					'items'       => array(
						'type' => 'string',
						'enum' => array( 'title', 'content', 'excerpt', 'taxonomies' ),
					),
				),
				'current_content'    => self::content_snapshot_schema(),
				'preview_content'    => self::content_snapshot_schema(),
				'preview_taxonomies' => array(
					'type'     => 'array',
					'maxItems' => 50,
					'items'    => array(
						'type'                 => 'object',
						'required'             => array( 'taxonomy', 'term_ids' ),
						'properties'           => array(
							'taxonomy' => array( 'type' => 'string' ),
							'term_ids' => array(
								'type'     => 'array',
								'maxItems' => 100,
								'items'    => array( 'type' => 'integer' ),
							),
						),
						'additionalProperties' => false,
					),
				),
				'warnings'           => self::preview_warnings_schema( 20 ),
				'provenance'         => self::provenance(),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Returns the update-block input schema, shared verbatim by
	 * preview-update-block per ADR 0021.
	 *
	 * @return array<string, mixed>
	 */
	public static function update_block_input(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'post_id', 'version_token', 'path', 'expected_block_name', 'block_markup' ),
			'properties'           => array(
				'post_id'             => array(
					'description' => 'Target post ID.',
					'type'        => 'integer',
					'minimum'     => 1,
				),
				'version_token'       => array(
					'description' => 'Optimistic-concurrency token from get-block-tree or get-content.',
					'type'        => 'string',
					'minLength'   => 18,
					'maxLength'   => 191,
				),
				'path'                => array(
					'description' => 'Zero-based indices into successive innerBlocks arrays identifying the block subtree to replace, as returned by get-block-tree. parse_blocks() emits block_name: null freeform nodes for whitespace between blocks, and these occupy real indices that must be counted when building a path.',
					'type'        => 'array',
					'minItems'    => 1,
					'maxItems'    => 20,
					'items'       => array(
						'type'    => 'integer',
						'minimum' => 0,
					),
				),
				'expected_block_name' => array(
					'description' => 'Registered block name asserted to exist at path, or null to assert a freeform node. A matching version_token proves the document did not change; it does not prove path points at the intended block, so this fact is asserted separately and the request fails closed with wpcb_block_mismatch when it differs.',
					'type'        => array( 'string', 'null' ),
					'minLength'   => 1,
					'maxLength'   => 200,
				),
				'block_markup'        => array(
					'description' => 'Replacement block markup for the subtree at path. Empty string deletes the subtree.',
					'type'        => 'string',
					'maxLength'   => 500000,
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Returns the update-block output schema.
	 *
	 * @return array<string, mixed>
	 */
	public static function update_block_output(): array {
		return self::mutation_output();
	}

	/**
	 * Returns the preview-update-block input contract.
	 *
	 * The preview intentionally shares the exact update input so the result can
	 * be applied without changing semantic intent or validation rules.
	 *
	 * @return array<string, mixed>
	 */
	public static function preview_update_block_input(): array {
		return self::update_block_input();
	}

	/**
	 * Returns the preview-update-block output contract.
	 *
	 * @return array<string, mixed>
	 */
	public static function preview_update_block_output(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'schema_version', 'writes_performed', 'post_id', 'post_type', 'version_token', 'changed_fields', 'current_content', 'preview_content', 'provenance' ),
			'properties'           => array(
				'schema_version'   => array( 'type' => 'string' ),
				'writes_performed' => array( 'type' => 'boolean' ),
				'post_id'          => array( 'type' => 'integer' ),
				'post_type'        => array( 'type' => 'string' ),
				'version_token'    => array( 'type' => 'string' ),
				'changed_fields'   => array(
					'type'  => 'array',
					'items' => array(
						'type' => 'string',
						'enum' => array( 'content' ),
					),
				),
				'current_content'  => array(
					'description' => 'Current whole post_content.',
					'type'        => 'string',
				),
				'preview_content'  => array(
					'description' => 'Prospective whole post_content after the parse/splice/serialize round trip.',
					'type'        => 'string',
				),
				'provenance'       => self::provenance(),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Returns the update-block-attributes input schema.
	 *
	 * @return array<string, mixed>
	 */
	public static function update_block_attributes_input(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'post_id', 'version_token', 'path', 'expected_block_name', 'attributes' ),
			'properties'           => array(
				'post_id'             => array(
					'description' => 'Target post ID.',
					'type'        => 'integer',
					'minimum'     => 1,
				),
				'version_token'       => array(
					'description' => 'Optimistic-concurrency token from get-block-tree or get-content.',
					'type'        => 'string',
					'minLength'   => 18,
					'maxLength'   => 191,
				),
				'path'                => array(
					'description' => 'Zero-based indices into successive innerBlocks arrays identifying the block whose attrs to merge into, as returned by get-block-tree. parse_blocks() emits block_name: null freeform nodes for whitespace between blocks, and these occupy real indices that must be counted when building a path.',
					'type'        => 'array',
					'minItems'    => 1,
					'maxItems'    => 20,
					'items'       => array(
						'type'    => 'integer',
						'minimum' => 0,
					),
				),
				'expected_block_name' => array(
					'description' => 'Registered block name asserted to exist at path. A matching version_token proves the document did not change; it does not prove path points at the intended block, so this fact is asserted separately and the request fails closed with wpcb_block_mismatch when it differs. null asserts a freeform node, but a freeform node has no attributes to merge into, so the request fails closed with wpcb_block_mismatch in that case too.',
					'type'        => array( 'string', 'null' ),
					'minLength'   => 1,
					'maxLength'   => 200,
				),
				'attributes'          => array(
					'description'   => 'Shallow merge into the block\'s existing attrs, applied by WordPress via serialize_blocks() so the caller never hand-writes delimiter JSON. Keys absent here are left untouched. A key present with value null is removed from attrs. A key present with any other JSON value is added or overwritten. Bounded to 50 top-level keys and a 100000-byte canonical JSON encoding.',
					'type'          => 'object',
					'maxProperties' => 50,
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Returns the update-block-attributes output schema.
	 *
	 * @return array<string, mixed>
	 */
	public static function update_block_attributes_output(): array {
		return self::mutation_output();
	}

	/**
	 * Returns the update-seo input schema.
	 *
	 * @return array<string, mixed>
	 */
	public static function update_seo_input(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'post_id', 'version_token' ),
			'properties'           => array(
				'post_id'             => array(
					'description' => 'Target post ID.',
					'type'        => 'integer',
					'minimum'     => 1,
				),
				'version_token'       => array(
					'description' => 'Optimistic-concurrency token from get-content.',
					'type'        => 'string',
					'minLength'   => 18,
					'maxLength'   => 191,
				),
				'seo_title'           => array(
					'description' => 'Yoast SEO title override.',
					'type'        => array( 'string', 'null' ),
					'maxLength'   => 500,
				),
				'meta_description'    => array(
					'description' => 'Yoast meta description override.',
					'type'        => array( 'string', 'null' ),
					'maxLength'   => 320,
				),
				'focus_keyphrase'     => array(
					'description' => 'Yoast focus keyphrase override.',
					'type'        => array( 'string', 'null' ),
					'maxLength'   => 200,
				),
				'keyphrase_synonyms'  => array(
					'description' => 'Yoast Premium synonyms for the primary keyphrase. Empty array clears; null leaves unchanged.',
					'type'        => array( 'array', 'null' ),
					'maxItems'    => 20,
					'uniqueItems' => true,
					'items'       => array(
						'type'      => 'string',
						'minLength' => 1,
						'maxLength' => 191,
						'pattern'   => '^[^,]+$',
					),
				),
				'related_keyphrases'  => array(
					'description' => 'Yoast Premium related keyphrases. Empty array clears; null leaves unchanged.',
					'type'        => array( 'array', 'null' ),
					'maxItems'    => 20,
					'uniqueItems' => true,
					'items'       => array(
						'type'      => 'string',
						'minLength' => 1,
						'maxLength' => 191,
					),
				),
				'canonical'           => array(
					'description' => 'Yoast canonical URL override.',
					'type'        => array( 'string', 'null' ),
					'maxLength'   => 2048,
				),
				'robots_index'        => array(
					'description' => 'True forces index; false forces noindex.',
					'type'        => array( 'boolean', 'null' ),
				),
				'robots_follow'       => array(
					'description' => 'True forces follow; false forces nofollow.',
					'type'        => array( 'boolean', 'null' ),
				),
				'robots_noarchive'    => array(
					'description' => 'True adds noarchive; false removes it; null leaves it unchanged.',
					'type'        => array( 'boolean', 'null' ),
				),
				'robots_noimageindex' => array(
					'description' => 'True adds noimageindex; false removes it; null leaves it unchanged.',
					'type'        => array( 'boolean', 'null' ),
				),
				'robots_nosnippet'    => array(
					'description' => 'True adds nosnippet; false removes it; null leaves it unchanged.',
					'type'        => array( 'boolean', 'null' ),
				),
				'og_title'            => array(
					'description' => 'Yoast Open Graph title override.',
					'type'        => array( 'string', 'null' ),
					'maxLength'   => 500,
				),
				'og_description'      => array(
					'description' => 'Yoast Open Graph description override.',
					'type'        => array( 'string', 'null' ),
					'maxLength'   => 500,
				),
				'og_image_id'         => array(
					'description' => 'Readable WordPress image attachment ID; zero clears the Open Graph image override.',
					'type'        => array( 'integer', 'null' ),
					'minimum'     => 0,
				),
				'twitter_title'       => array(
					'description' => 'Yoast Twitter title override.',
					'type'        => array( 'string', 'null' ),
					'maxLength'   => 500,
				),
				'twitter_description' => array(
					'description' => 'Yoast Twitter description override.',
					'type'        => array( 'string', 'null' ),
					'maxLength'   => 500,
				),
				'twitter_image_id'    => array(
					'description' => 'Readable WordPress image attachment ID; zero clears the Twitter image override.',
					'type'        => array( 'integer', 'null' ),
					'minimum'     => 0,
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Returns the update-seo output schema.
	 *
	 * @return array<string, mixed>
	 */
	public static function update_seo_output(): array {
		$schema                                = self::mutation_output();
		$schema['required'][]                  = 'effective_seo';
		$schema['properties']['effective_seo'] = self::seo_output();

		return $schema;
	}

	/**
	 * Returns the preview-update-seo input contract.
	 *
	 * The preview intentionally shares the exact update input so the result can
	 * be applied without changing semantic intent or validation rules.
	 *
	 * @return array<string, mixed>
	 */
	public static function preview_seo_input(): array {
		return self::update_seo_input();
	}

	/**
	 * Returns the preview-update-seo output contract.
	 *
	 * `current_seo` is the same full resolved shape as `get-url-seo` and
	 * `update-seo`'s `effective_seo`, since it already exists and is already
	 * public. `preview_seo` is deliberately narrower: only the prospective
	 * *configured* allowlisted field values, because the resolved public output
	 * does not exist until the change is actually rendered.
	 *
	 * @return array<string, mixed>
	 */
	public static function preview_seo_output(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'schema_version', 'writes_performed', 'post_id', 'post_type', 'version_token', 'changed_fields', 'current_seo', 'preview_seo', 'warnings', 'provenance' ),
			'properties'           => array(
				'schema_version'   => array( 'type' => 'string' ),
				'writes_performed' => array( 'type' => 'boolean' ),
				'post_id'          => array( 'type' => 'integer' ),
				'post_type'        => array( 'type' => 'string' ),
				'version_token'    => array( 'type' => 'string' ),
				'changed_fields'   => array(
					'type'        => 'array',
					'uniqueItems' => true,
					'maxItems'    => 17,
					'items'       => array(
						'type' => 'string',
						'enum' => array(
							'seo_title',
							'meta_description',
							'focus_keyphrase',
							'keyphrase_synonyms',
							'related_keyphrases',
							'canonical',
							'robots_index',
							'robots_follow',
							'robots_noarchive',
							'robots_noimageindex',
							'robots_nosnippet',
							'og_title',
							'og_description',
							'og_image_id',
							'twitter_title',
							'twitter_description',
							'twitter_image_id',
						),
					),
				),
				'current_seo'      => self::seo_output(),
				'preview_seo'      => self::seo_preview_fields_schema(),
				'warnings'         => self::preview_warnings_schema( 17 ),
				'provenance'       => self::provenance(),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Returns the update-Service-schema input contract.
	 *
	 * Omitted fields remain unchanged. Empty strings and arrays explicitly clear
	 * their corresponding configured values.
	 *
	 * @return array<string, mixed>
	 */
	public static function update_service_schema_input(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'post_id', 'version_token' ),
			'properties'           => array(
				'post_id'       => array(
					'description' => 'Target post ID.',
					'type'        => 'integer',
					'minimum'     => 1,
				),
				'version_token' => array(
					'description' => 'Optimistic-concurrency token from get-content.',
					'type'        => 'string',
					'minLength'   => 18,
					'maxLength'   => 191,
				),
				'enabled'       => array(
					'description' => 'Whether the page emits the configured Service entity.',
					'type'        => 'boolean',
				),
				'name'          => array(
					'description' => 'Service name. Empty string clears the override.',
					'type'        => 'string',
					'maxLength'   => 191,
				),
				'service_type'  => array(
					'description' => 'Human-readable service category. Empty string clears.',
					'type'        => 'string',
					'maxLength'   => 191,
				),
				'description'   => array(
					'description' => 'Service description consistent with visible page content. Empty string clears.',
					'type'        => 'string',
					'maxLength'   => 2000,
				),
				'areas'         => array(
					'description' => 'Typed areaServed entries. Empty array clears all areas.',
					'type'        => 'array',
					'maxItems'    => 100,
					'uniqueItems' => true,
					'items'       => array(
						'type'                 => 'object',
						'required'             => array( 'type', 'name' ),
						'properties'           => array(
							'type' => array(
								'type' => 'string',
								'enum' => array( 'City', 'AdministrativeArea', 'Country' ),
							),
							'name' => array(
								'type'      => 'string',
								'minLength' => 1,
								'maxLength' => 191,
							),
						),
						'additionalProperties' => false,
					),
				),
				'brands'        => array(
					'description' => 'Brand names used by the service. Empty array clears all brands.',
					'type'        => 'array',
					'maxItems'    => 50,
					'uniqueItems' => true,
					'items'       => array(
						'type'      => 'string',
						'minLength' => 1,
						'maxLength' => 191,
						'pattern'   => '^[^,\\r\\n]+$',
					),
				),
				'catalog_name'  => array(
					'description' => 'OfferCatalog name. Empty string clears the catalog name.',
					'type'        => 'string',
					'maxLength'   => 191,
				),
				'offers'        => array(
					'description' => 'OfferCatalog items matching visible services. Empty array clears all offers.',
					'type'        => 'array',
					'maxItems'    => 20,
					'uniqueItems' => true,
					'items'       => array(
						'type'                 => 'object',
						'required'             => array( 'name' ),
						'properties'           => array(
							'name'        => array(
								'type'      => 'string',
								'minLength' => 1,
								'maxLength' => 191,
							),
							'description' => array(
								'type'      => 'string',
								'maxLength' => 1000,
							),
						),
						'additionalProperties' => false,
					),
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Returns the get-Service-schema input contract.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_service_schema_input(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'post_id' ),
			'properties'           => array(
				'post_id' => array(
					'description' => 'Target post ID.',
					'type'        => 'integer',
					'minimum'     => 1,
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Returns the get-Service-schema output contract.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_service_schema_output(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'schema_version', 'post_id', 'post_type', 'version_token', 'service_schema', 'provenance' ),
			'properties'           => array(
				'schema_version' => array( 'type' => 'string' ),
				'post_id'        => array( 'type' => 'integer' ),
				'post_type'      => array( 'type' => 'string' ),
				'version_token'  => array( 'type' => 'string' ),
				'service_schema' => self::service_schema_configuration(),
				'provenance'     => self::provenance(),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Returns the preview-Service-schema input contract.
	 *
	 * The preview intentionally shares the exact update input so the result can
	 * be applied without changing semantic intent or validation rules.
	 *
	 * @return array<string, mixed>
	 */
	public static function preview_service_schema_input(): array {
		return self::update_service_schema_input();
	}

	/**
	 * Returns the preview-Service-schema output contract.
	 *
	 * @return array<string, mixed>
	 */
	public static function preview_service_schema_output(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'schema_version', 'writes_performed', 'post_id', 'post_type', 'version_token', 'changed_fields', 'current_service_schema', 'preview_service_schema', 'provenance' ),
			'properties'           => array(
				'schema_version'         => array( 'type' => 'string' ),
				'writes_performed'       => array( 'type' => 'boolean' ),
				'post_id'                => array( 'type' => 'integer' ),
				'post_type'              => array( 'type' => 'string' ),
				'version_token'          => array( 'type' => 'string' ),
				'changed_fields'         => array(
					'type'        => 'array',
					'uniqueItems' => true,
					'maxItems'    => 8,
					'items'       => array(
						'type' => 'string',
						'enum' => array( 'enabled', 'name', 'service_type', 'description', 'areas', 'brands', 'catalog_name', 'offers' ),
					),
				),
				'current_service_schema' => self::service_schema_configuration(),
				'preview_service_schema' => self::service_schema_configuration(),
				'provenance'             => self::provenance(),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Returns the update-Service-schema output contract.
	 *
	 * @return array<string, mixed>
	 */
	public static function update_service_schema_output(): array {
		$schema               = self::mutation_output();
		$schema['required'][] = 'effective_service_schema';
		$schema['properties']['effective_service_schema'] = self::service_schema_configuration();

		return $schema;
	}

	/**
	 * Returns the update-Custom-schema input contract.
	 *
	 * Omitted fields remain unchanged. Empty source clears the editable JSON.
	 * The provider decides whether an invalid source may be saved while disabled.
	 *
	 * @return array<string, mixed>
	 */
	public static function update_custom_schema_input(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'post_id', 'version_token' ),
			'properties'           => array(
				'post_id'       => array(
					'description' => 'Target post ID.',
					'type'        => 'integer',
					'minimum'     => 1,
				),
				'version_token' => array(
					'description' => 'Optimistic-concurrency token from get-custom-schema or get-content.',
					'type'        => 'string',
					'minLength'   => 18,
					'maxLength'   => 191,
				),
				'enabled'       => array(
					'description' => 'Whether Schema Extended may merge valid custom nodes into the Yoast graph.',
					'type'        => 'boolean',
				),
				'source'        => array(
					'description' => 'Bounded JSON containing one Schema.org object, a node array, or an @graph wrapper. Empty string clears the source.',
					'type'        => 'string',
					'maxLength'   => 100000,
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Returns the get-Custom-schema input contract.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_custom_schema_input(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'post_id' ),
			'properties'           => array(
				'post_id' => array(
					'description' => 'Target post ID.',
					'type'        => 'integer',
					'minimum'     => 1,
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Returns the get-Custom-schema output contract.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_custom_schema_output(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'schema_version', 'post_id', 'post_type', 'version_token', 'custom_schema', 'provenance' ),
			'properties'           => array(
				'schema_version' => array( 'type' => 'string' ),
				'post_id'        => array( 'type' => 'integer' ),
				'post_type'      => array( 'type' => 'string' ),
				'version_token'  => array( 'type' => 'string' ),
				'custom_schema'  => self::custom_schema_configuration(),
				'provenance'     => self::provenance(),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Returns the preview-Custom-schema input contract.
	 *
	 * @return array<string, mixed>
	 */
	public static function preview_custom_schema_input(): array {
		return self::update_custom_schema_input();
	}

	/**
	 * Returns the preview-Custom-schema output contract.
	 *
	 * @return array<string, mixed>
	 */
	public static function preview_custom_schema_output(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'schema_version', 'writes_performed', 'post_id', 'post_type', 'version_token', 'changed_fields', 'current_custom_schema', 'preview_custom_schema', 'provenance' ),
			'properties'           => array(
				'schema_version'        => array( 'type' => 'string' ),
				'writes_performed'      => array( 'type' => 'boolean' ),
				'post_id'               => array( 'type' => 'integer' ),
				'post_type'             => array( 'type' => 'string' ),
				'version_token'         => array( 'type' => 'string' ),
				'changed_fields'        => array(
					'type'        => 'array',
					'uniqueItems' => true,
					'maxItems'    => 2,
					'items'       => array(
						'type' => 'string',
						'enum' => array( 'enabled', 'source' ),
					),
				),
				'current_custom_schema' => self::custom_schema_configuration(),
				'preview_custom_schema' => self::custom_schema_configuration(),
				'provenance'            => self::provenance(),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Returns the update-Custom-schema output contract.
	 *
	 * @return array<string, mixed>
	 */
	public static function update_custom_schema_output(): array {
		$schema               = self::mutation_output();
		$schema['required'][] = 'effective_custom_schema';
		$schema['properties']['effective_custom_schema'] = self::custom_schema_configuration();

		return $schema;
	}

	/**
	 * Returns the reversible trash input schema.
	 *
	 * @return array<string, mixed>
	 */
	public static function trash_content_input(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'post_id', 'version_token' ),
			'properties'           => array(
				'post_id'       => array(
					'description' => 'Target post ID.',
					'type'        => 'integer',
					'minimum'     => 1,
				),
				'version_token' => array(
					'description' => 'Optimistic-concurrency token from get-content.',
					'type'        => 'string',
					'minLength'   => 18,
					'maxLength'   => 191,
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Returns the reversible trash result schema.
	 *
	 * @return array<string, mixed>
	 */
	public static function trash_content_output(): array {
		return self::mutation_output();
	}

	/**
	 * Returns the restore-trashed-content input schema. Identical shape to
	 * the trash input — same post ID plus concurrency token.
	 *
	 * @return array<string, mixed>
	 */
	public static function restore_trashed_content_input(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'post_id', 'version_token' ),
			'properties'           => array(
				'post_id'       => array(
					'description' => 'Target post ID currently in trash.',
					'type'        => 'integer',
					'minimum'     => 1,
				),
				'version_token' => array(
					'description' => 'Optimistic-concurrency token from get-content.',
					'type'        => 'string',
					'minLength'   => 18,
					'maxLength'   => 191,
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Returns the restore-trashed-content result schema. The resulting
	 * `status` is always `draft`, `pending`, or `private` — never `publish`
	 * or `future`.
	 *
	 * @return array<string, mixed>
	 */
	public static function restore_trashed_content_output(): array {
		return self::mutation_output();
	}

	/**
	 * Returns the get-status-transitions input schema.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_status_transitions_input(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'post_id' ),
			'properties'           => array(
				'post_id' => array(
					'description' => 'WordPress content object ID.',
					'type'        => 'integer',
					'minimum'     => 1,
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Returns the get-status-transitions output schema.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_status_transitions_output(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'schema_version', 'post_id', 'post_type', 'current_status', 'version_token', 'targets', 'scheduling', 'provenance' ),
			'properties'           => array(
				'schema_version' => array( 'type' => 'string' ),
				'post_id'        => array( 'type' => 'integer' ),
				'post_type'      => array( 'type' => 'string' ),
				'current_status' => array(
					'type' => 'string',
					'enum' => array( 'draft', 'pending', 'private', 'publish', 'future' ),
				),
				'version_token'  => array( 'type' => 'string' ),
				'targets'        => array(
					'type'     => 'array',
					'maxItems' => 20,
					'items'    => self::status_transition_target(),
				),
				'scheduling'     => array(
					'type'                 => 'object',
					'required'             => array( 'site_timezone', 'utc_offset_seconds', 'scheduled_publication_can_run' ),
					'properties'           => array(
						'site_timezone'                 => array( 'type' => 'string' ),
						'utc_offset_seconds'            => array( 'type' => 'integer' ),
						'scheduled_publication_can_run' => array( 'type' => 'boolean' ),
					),
					'additionalProperties' => false,
				),
				'provenance'     => self::provenance(),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Returns one permitted-target descriptor for get-status-transitions.
	 *
	 * @return array<string, mixed>
	 */
	private static function status_transition_target(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'target_status', 'requires_publish_at', 'requires_publish_gates', 'gates' ),
			'properties'           => array(
				'target_status'          => array(
					'type' => 'string',
					'enum' => array( 'draft', 'pending', 'private', 'publish', 'future' ),
				),
				'requires_publish_at'    => array( 'type' => 'boolean' ),
				'requires_publish_gates' => array( 'type' => 'boolean' ),
				'gates'                  => array(
					'type'                 => 'object',
					'required'             => array( 'publish_enabled', 'publish_capability', 'native_publish_post' ),
					'properties'           => array(
						'publish_enabled'     => array( 'type' => 'boolean' ),
						'publish_capability'  => array( 'type' => 'boolean' ),
						'native_publish_post' => array( 'type' => 'boolean' ),
					),
					'additionalProperties' => false,
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Returns the transition-content-status input schema.
	 *
	 * `publish_at` is deliberately not RFC 3339 `format: date-time`: that
	 * format requires a UTC offset or `Z`, and `publish_at` is defined to be
	 * site-local with no offset of its own — the pattern below is the wire
	 * shape {@see \IsuDev\WPContentBridge\Domain\Status\PublishAt} parses.
	 *
	 * @return array<string, mixed>
	 */
	public static function transition_content_status_input(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'post_id', 'version_token', 'target_status' ),
			'properties'           => array(
				'post_id'       => array(
					'description' => 'Target post ID.',
					'type'        => 'integer',
					'minimum'     => 1,
				),
				'version_token' => array(
					'description' => 'Optimistic-concurrency token from get-content.',
					'type'        => 'string',
					'minLength'   => 18,
					'maxLength'   => 191,
				),
				'target_status' => array(
					'description' => "Status to transition to; must be permitted from the object's current status by the configured transition graph.",
					'type'        => 'string',
					'enum'        => array( 'draft', 'pending', 'private', 'publish', 'future' ),
				),
				'publish_at'    => array(
					'description' => 'Site-local date-time at which to schedule publication, in the form YYYY-MM-DDTHH:MM:SS with no UTC offset. Required when target_status is future; rejected for every other target_status.',
					'type'        => 'string',
					'pattern'     => '^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$',
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Returns the transition-content-status output schema.
	 *
	 * @return array<string, mixed>
	 */
	public static function transition_content_status_output(): array {
		$schema                             = self::mutation_output();
		$schema['properties']['publish_at'] = array(
			'description'          => 'Present only when the stored status is future: the scheduled instant, in both site-local and UTC forms.',
			'type'                 => 'object',
			'required'             => array( 'local', 'utc' ),
			'properties'           => array(
				'local' => array( 'type' => 'string' ),
				'utc'   => array( 'type' => 'string' ),
			),
			'additionalProperties' => false,
		);

		return $schema;
	}

	/**
	 * Returns the strict effective Service configuration document.
	 *
	 * @return array<string, mixed>
	 */
	private static function service_schema_configuration(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'schema_version', 'enabled', 'name', 'service_type', 'description', 'areas', 'brands', 'catalog_name', 'offers', 'provider' ),
			'properties'           => array(
				'schema_version' => array( 'type' => 'string' ),
				'enabled'        => array( 'type' => 'boolean' ),
				'name'           => array( 'type' => 'string' ),
				'service_type'   => array( 'type' => 'string' ),
				'description'    => array( 'type' => 'string' ),
				'areas'          => array(
					'type'     => 'array',
					'maxItems' => 100,
					'items'    => array(
						'type'                 => 'object',
						'required'             => array( 'type', 'name' ),
						'properties'           => array(
							'type' => array( 'type' => 'string' ),
							'name' => array( 'type' => 'string' ),
						),
						'additionalProperties' => false,
					),
				),
				'brands'         => array(
					'type'     => 'array',
					'maxItems' => 50,
					'items'    => array( 'type' => 'string' ),
				),
				'catalog_name'   => array( 'type' => 'string' ),
				'offers'         => array(
					'type'     => 'array',
					'maxItems' => 20,
					'items'    => array(
						'type'                 => 'object',
						'required'             => array( 'name', 'description' ),
						'properties'           => array(
							'name'        => array( 'type' => 'string' ),
							'description' => array( 'type' => 'string' ),
						),
						'additionalProperties' => false,
					),
				),
				'provider'       => array(
					'type'                 => 'object',
					'required'             => array( 'name', 'version' ),
					'properties'           => array(
						'name'    => array( 'type' => 'string' ),
						'version' => array( 'type' => 'string' ),
					),
					'additionalProperties' => false,
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Returns the bounded Custom Schema configuration document.
	 *
	 * Schema.org node properties are intentionally open because they are the
	 * validated data payload, not caller-selected WordPress fields. The source,
	 * node count, parser depth, and encoded result remain provider-bounded.
	 *
	 * @return array<string, mixed>
	 */
	private static function custom_schema_configuration(): array {
		$diagnostic = array(
			'type'                 => 'object',
			'required'             => array( 'code', 'message' ),
			'properties'           => array(
				'code'    => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => 191,
				),
				'message' => array(
					'type'      => 'string',
					'maxLength' => 2000,
				),
			),
			'additionalProperties' => false,
		);

		return array(
			'type'                 => 'object',
			'required'             => array( 'contract_version', 'enabled', 'source', 'save_allowed', 'render_eligible', 'validation', 'provider' ),
			'properties'           => array(
				'contract_version' => array(
					'type' => 'string',
					'enum' => array( '1.0' ),
				),
				'enabled'          => array( 'type' => 'boolean' ),
				'source'           => array(
					'type'      => 'string',
					'maxLength' => 100000,
				),
				'save_allowed'     => array(
					'description' => 'False only when invalid source is proposed while rendering is enabled.',
					'type'        => 'boolean',
				),
				'render_eligible'  => array(
					'description' => 'True only when the configuration is enabled and structurally valid.',
					'type'        => 'boolean',
				),
				'validation'       => array(
					'type'                 => 'object',
					'required'             => array( 'valid', 'context_resolved', 'nodes', 'errors', 'warnings' ),
					'properties'           => array(
						'valid'            => array( 'type' => 'boolean' ),
						'context_resolved' => array(
							'description' => 'False for source validation; use get-url-seo after saving to inspect the resolved complete graph.',
							'type'        => 'boolean',
						),
						'nodes'            => array(
							'type'     => 'array',
							'maxItems' => 20,
							'items'    => array(
								'type'                 => 'object',
								'additionalProperties' => true,
							),
						),
						'errors'           => array(
							'type'     => 'array',
							'maxItems' => 50,
							'items'    => $diagnostic,
						),
						'warnings'         => array(
							'type'     => 'array',
							'maxItems' => 50,
							'items'    => $diagnostic,
						),
					),
					'additionalProperties' => false,
				),
				'provider'         => array(
					'type'                 => 'object',
					'required'             => array( 'name', 'version' ),
					'properties'           => array(
						'name'    => array( 'type' => 'string' ),
						'version' => array( 'type' => 'string' ),
					),
					'additionalProperties' => false,
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Returns the compact content schema.
	 *
	 * @return array<string, mixed>
	 */
	private static function summary(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'id', 'post_type', 'status', 'title', 'slug', 'url', 'excerpt', 'author_id', 'published_at', 'modified_at', 'featured_image_id', 'featured_image_url', 'untrusted' ),
			'properties'           => array(
				'id'                 => array( 'type' => 'integer' ),
				'post_type'          => array( 'type' => 'string' ),
				'status'             => array( 'type' => 'string' ),
				'title'              => array( 'type' => 'string' ),
				'slug'               => array( 'type' => 'string' ),
				'url'                => array( 'type' => array( 'string', 'null' ) ),
				'excerpt'            => array( 'type' => 'string' ),
				'author_id'          => array( 'type' => 'integer' ),
				'published_at'       => array( 'type' => array( 'string', 'null' ) ),
				'modified_at'        => array( 'type' => 'string' ),
				'featured_image_id'  => array( 'type' => array( 'integer', 'null' ) ),
				'featured_image_url' => array( 'type' => array( 'string', 'null' ) ),
				'untrusted'          => array( 'type' => 'boolean' ),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Returns the flat block-tree node schema.
	 *
	 * @return array<string, mixed>
	 */
	private static function block_tree_node(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'path', 'block_name', 'inner_blocks', 'text', 'text_source' ),
			'properties'           => array(
				'path'          => array(
					'description' => 'Zero-based indices into successive innerBlocks arrays; pass straight back to update-block or preview-update-block.',
					'type'        => 'array',
					'minItems'    => 1,
					'items'       => array(
						'type'    => 'integer',
						'minimum' => 0,
					),
				),
				'block_name'    => array(
					'description' => 'Registered block name, or null for the freeform whitespace nodes parse_blocks() emits between blocks. These occupy real indices in the array a write mutates and are never omitted.',
					'type'        => array( 'string', 'null' ),
				),
				'inner_blocks'  => array(
					'description' => 'Immediate child count.',
					'type'        => 'integer',
					'minimum'     => 0,
				),
				'text'          => array(
					'description' => 'Bounded plain-text preview, at most 120 characters; null when empty. Tries the node\'s own innerHTML first; when that is empty, falls back to its prose-bearing string attributes (whitespace-containing values at least 3 characters long), concatenated in attribute-name order. See text_source for which was used.',
					'type'        => array( 'string', 'null' ),
				),
				'text_source'   => array(
					'description' => 'Where text came from: inner_html or attrs; null when text is null. Editing a block whose text_source is attrs means changing an attribute value, not the block\'s inner markup.',
					'type'        => array( 'string', 'null' ),
					'enum'        => array( 'inner_html', 'attrs', null ),
				),
				'attrs'         => array(
					'description' => 'Raw block attributes. Present only when include_attrs was true on the request, the attributes are non-empty, and the encoded form is within the 512-byte bound.',
					'type'        => 'object',
				),
				'attrs_omitted' => array(
					'description' => 'True when include_attrs was true but attrs was withheld for exceeding the 512-byte encoded bound. Never set when include_attrs is false, since omission is then the request\'s own contract, not a size omission.',
					'type'        => 'boolean',
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Returns the strict normalized media item schema.
	 *
	 * @return array<string, mixed>
	 */
	private static function media_item(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'id', 'title', 'filename', 'url', 'alt_text', 'caption', 'description', 'mime_type' ),
			'properties'           => array(
				'id'          => array( 'type' => 'integer' ),
				'title'       => array( 'type' => 'string' ),
				'filename'    => array( 'type' => 'string' ),
				'url'         => array( 'type' => 'string' ),
				'alt_text'    => array( 'type' => 'string' ),
				'caption'     => array( 'type' => 'string' ),
				'description' => array( 'type' => 'string' ),
				'mime_type'   => array( 'type' => 'string' ),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Returns one allowlisted block-pattern item schema.
	 *
	 * @return array<string, mixed>
	 */
	private static function pattern_item(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'name', 'namespace', 'title', 'description', 'source', 'viewport_width', 'inserter', 'categories', 'keywords', 'block_types', 'post_types', 'template_types', 'content', 'content_bytes', 'untrusted' ),
			'properties'           => array(
				'name'           => array(
					'type'      => 'string',
					'maxLength' => 200,
				),
				'namespace'      => array(
					'type'      => 'string',
					'maxLength' => 100,
				),
				'title'          => array(
					'type'      => 'string',
					'maxLength' => 1000,
				),
				'description'    => array(
					'type'      => 'string',
					'maxLength' => 1000,
				),
				'source'         => array(
					'type'      => array( 'string', 'null' ),
					'maxLength' => 100,
				),
				'viewport_width' => array(
					'type'    => array( 'integer', 'null' ),
					'minimum' => 0,
				),
				'inserter'       => array( 'type' => 'boolean' ),
				'categories'     => self::pattern_string_array(),
				'keywords'       => self::pattern_string_array(),
				'block_types'    => self::pattern_string_array(),
				'post_types'     => self::pattern_string_array(),
				'template_types' => self::pattern_string_array(),
				'content'        => array(
					'type'      => array( 'string', 'null' ),
					'maxLength' => 2097152,
				),
				'content_bytes'  => array(
					'type'    => 'integer',
					'minimum' => 0,
					'maximum' => 2097152,
				),
				'untrusted'      => array( 'type' => 'boolean' ),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Returns the strict bounded string-list schema used by pattern metadata.
	 *
	 * @return array<string, mixed>
	 */
	private static function pattern_string_array(): array {
		return array(
			'type'        => 'array',
			'uniqueItems' => true,
			'maxItems'    => 20,
			'items'       => array(
				'type'      => 'string',
				'maxLength' => 200,
			),
		);
	}

	/**
	 * Returns one editorial content-type descriptor schema.
	 *
	 * @return array<string, mixed>
	 */
	private static function editorial_post_type(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'name', 'label', 'public', 'show_in_rest', 'built_in' ),
			'properties'           => array(
				'name'         => array( 'type' => 'string' ),
				'label'        => array( 'type' => 'string' ),
				'public'       => array( 'type' => 'boolean' ),
				'show_in_rest' => array( 'type' => 'boolean' ),
				'built_in'     => array( 'type' => 'boolean' ),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Returns one editorial taxonomy descriptor schema.
	 *
	 * @return array<string, mixed>
	 */
	private static function editorial_taxonomy(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'name', 'label', 'hierarchical', 'object_types' ),
			'properties'           => array(
				'name'         => array( 'type' => 'string' ),
				'label'        => array( 'type' => 'string' ),
				'hierarchical' => array( 'type' => 'boolean' ),
				'object_types' => self::string_array( 20 ),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Returns one bounded taxonomy vocabulary schema.
	 *
	 * @return array<string, mixed>
	 */
	private static function editorial_terms(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'taxonomy', 'items', 'truncated' ),
			'properties'           => array(
				'taxonomy'  => array( 'type' => 'string' ),
				'items'     => array(
					'type'     => 'array',
					'maxItems' => 100,
					'items'    => array(
						'type'                 => 'object',
						'required'             => array( 'id', 'name', 'slug', 'parent' ),
						'properties'           => array(
							'id'     => array( 'type' => 'integer' ),
							'name'   => array( 'type' => 'string' ),
							'slug'   => array( 'type' => 'string' ),
							'parent' => array( 'type' => 'integer' ),
						),
						'additionalProperties' => false,
					),
				),
				'truncated' => array( 'type' => 'boolean' ),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Returns the normalized Local section schema.
	 *
	 * @return array<string, mixed>
	 */
	private static function editorial_local_businesses(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'state', 'source', 'reason', 'provider', 'items' ),
			'properties'           => array(
				'state'    => array(
					'type' => 'string',
					'enum' => array( 'explicit', 'inherited', 'generated', 'unsupported', 'unavailable' ),
				),
				'source'   => array( 'type' => 'string' ),
				'reason'   => array( 'type' => array( 'string', 'null' ) ),
				'provider' => self::provider_status(),
				'items'    => array(
					'type'     => 'array',
					'maxItems' => 50,
					'items'    => self::editorial_local_entity(),
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Returns the recursive allowlist shape emitted by LocalSchemaProjector.
	 *
	 * @return array<string, mixed>
	 */
	private static function editorial_local_entity(): array {
		$reference = self::editorial_nested_object( array( '@id', '@type', 'url', 'contentUrl', 'name' ) );

		return array(
			'type'                 => 'object',
			'properties'           => array(
				'@id'                       => array( 'type' => 'string' ),
				'@type'                     => array( 'type' => array( 'string', 'array' ) ),
				'name'                      => array( 'type' => 'string' ),
				'url'                       => array( 'type' => 'string' ),
				'email'                     => array( 'type' => 'string' ),
				'faxNumber'                 => array( 'type' => 'string' ),
				'priceRange'                => array( 'type' => 'string' ),
				'currenciesAccepted'        => array( 'type' => 'string' ),
				'paymentAccepted'           => array( 'type' => 'string' ),
				'vatID'                     => array( 'type' => 'string' ),
				'taxID'                     => array( 'type' => 'string' ),
				'telephone'                 => array( 'type' => array( 'string', 'integer', 'number', 'boolean', 'array' ) ),
				'areaServed'                => array( 'type' => array( 'string', 'integer', 'number', 'boolean', 'array' ) ),
				'address'                   => self::editorial_nested_object( array( '@id', '@type', 'streetAddress', 'addressLocality', 'addressRegion', 'postalCode', 'postOfficeBoxNumber', 'addressCountry' ) ),
				'geo'                       => self::editorial_nested_object( array( '@id', '@type', 'latitude', 'longitude' ) ),
				'openingHoursSpecification' => array(
					'type'     => 'array',
					'maxItems' => 100,
					'items'    => self::editorial_nested_object( array( '@id', '@type', 'dayOfWeek', 'opens', 'closes', 'validFrom', 'validThrough' ) ),
				),
				'image'                     => $reference,
				'logo'                      => $reference,
				'branchOf'                  => $reference,

				/*
				 * Yoast Local emits `parentOrganization`, not schema.org's
				 * `branchOf`, to link a branch to its parent — see ADR 0009 and
				 * `docs/verification/YOAST_PREMIUM_LOCAL.md`. `LocalSchemaProjector`
				 * has always allowlisted it; this schema never listed it, and
				 * with `additionalProperties: false` that made the whole
				 * `get-editorial-context` response fail output validation on any
				 * site where a branch actually has a parent configured. The
				 * multi-location case ADR 0009 exists for was therefore the one
				 * case the ability could not answer. Found 2026-08-08 by
				 * `local-multilocation-runtime-verification.sh` once the fixture
				 * site gained a parent organization.
				 */
				'parentOrganization'        => $reference,
				'mainEntityOfPage'          => $reference,
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Returns a strict nested public-Schema object.
	 *
	 * @param array $keys Allowed property names.
	 * @return array<string, mixed>
	 * @phpstan-param list<string> $keys
	 */
	private static function editorial_nested_object( array $keys ): array {
		$properties = array();
		foreach ( $keys as $key ) {
			$properties[ $key ] = in_array( $key, array( '@type', 'dayOfWeek' ), true )
				? array( 'type' => array( 'string', 'array' ) )
				: array( 'type' => array( 'string', 'integer', 'number', 'boolean' ) );
		}

		return array(
			'type'                 => 'object',
			'properties'           => $properties,
			'additionalProperties' => false,
		);
	}

	/**
	 * Returns the provenance schema.
	 *
	 * @return array<string, mixed>
	 */
	private static function provenance(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'source', 'untrusted' ),
			'properties'           => array(
				'source'    => array( 'type' => 'string' ),
				'untrusted' => array( 'type' => 'boolean' ),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Returns the shared current/preview content-field snapshot schema used by
	 * preview-update-content.
	 *
	 * @return array<string, mixed>
	 */
	private static function content_snapshot_schema(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'title', 'block_markup', 'excerpt' ),
			'properties'           => array(
				'title'        => array( 'type' => 'string' ),
				'block_markup' => array( 'type' => 'string' ),
				'excerpt'      => array( 'type' => 'string' ),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Returns the bounded prospective-configured-fields schema used by
	 * preview-update-seo. Deliberately narrower than `seo_output()`: only the
	 * present allowlisted fields, never a claim about resolved public output.
	 *
	 * @return array<string, mixed>
	 */
	private static function seo_preview_fields_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'seo_title'           => array( 'type' => 'string' ),
				'meta_description'    => array( 'type' => 'string' ),
				'focus_keyphrase'     => array( 'type' => 'string' ),
				'keyphrase_synonyms'  => array(
					'type'     => 'array',
					'maxItems' => 20,
					'items'    => array( 'type' => 'string' ),
				),
				'related_keyphrases'  => array(
					'type'     => 'array',
					'maxItems' => 20,
					'items'    => array( 'type' => 'string' ),
				),
				'canonical'           => array( 'type' => 'string' ),
				'robots_index'        => array( 'type' => 'boolean' ),
				'robots_follow'       => array( 'type' => 'boolean' ),
				'robots_noarchive'    => array( 'type' => 'boolean' ),
				'robots_noimageindex' => array( 'type' => 'boolean' ),
				'robots_nosnippet'    => array( 'type' => 'boolean' ),
				'og_title'            => array( 'type' => 'string' ),
				'og_description'      => array( 'type' => 'string' ),
				'og_image_id'         => array( 'type' => 'integer' ),
				'twitter_title'       => array( 'type' => 'string' ),
				'twitter_description' => array( 'type' => 'string' ),
				'twitter_image_id'    => array( 'type' => 'integer' ),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Returns the shared bounded machine-readable preview-warning list schema.
	 *
	 * @param int $maximum Maximum item count.
	 * @return array<string, mixed>
	 */
	private static function preview_warnings_schema( int $maximum ): array {
		return array(
			'type'     => 'array',
			'maxItems' => $maximum,
			'items'    => array(
				'type'                 => 'object',
				'required'             => array( 'code', 'field', 'message' ),
				'properties'           => array(
					'code'    => array(
						'type'      => 'string',
						'maxLength' => 64,
					),
					'field'   => array(
						'type'      => 'string',
						'maxLength' => 64,
					),
					'message' => array(
						'type'      => 'string',
						'maxLength' => 500,
					),
				),
				'additionalProperties' => false,
			),
		);
	}

	/**
	 * Returns the shared create-draft/update-content output schema.
	 *
	 * @return array<string, mixed>
	 * @phpstan-return array{type: string, required: array<int, string>, properties: array<string, mixed>, additionalProperties: bool}
	 */
	private static function mutation_output(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'schema_version', 'post_id', 'post_type', 'status', 'version_token', 'changed_fields', 'created', 'provenance' ),
			'properties'           => array(
				'schema_version' => array( 'type' => 'string' ),
				'post_id'        => array( 'type' => 'integer' ),
				'post_type'      => array( 'type' => 'string' ),
				'status'         => array( 'type' => 'string' ),
				'version_token'  => array( 'type' => 'string' ),
				'changed_fields' => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				'created'        => array( 'type' => 'boolean' ),
				'provenance'     => self::provenance(),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Returns the shared bounded taxonomy-assignment schema.
	 *
	 * @return array<string, mixed>
	 */
	private static function taxonomy_assignment_schema(): array {
		return array(
			'description' => 'Optional taxonomy assignments (replace mode).',
			'type'        => array( 'array', 'null' ),
			'maxItems'    => 50,
			'items'       => array(
				'type'                 => 'object',
				'required'             => array( 'taxonomy', 'term_ids' ),
				'properties'           => array(
					'taxonomy' => array(
						'description' => 'Registered public REST taxonomy slug to assign terms in.',
						'type'        => 'string',
						'pattern'     => '^[a-z0-9_-]{1,32}$',
					),
					'term_ids' => array(
						'description' => 'Existing term IDs to assign, replacing the current terms of this taxonomy.',
						'type'        => 'array',
						'minItems'    => 1,
						'maxItems'    => 100,
						'items'       => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
					),
				),
				'additionalProperties' => false,
			),
		);
	}

	/**
	 * Returns one strict section of normalized SEO fields.
	 *
	 * @param array $names Allowed normalized names.
	 * @return array<string, mixed>
	 * @phpstan-param list<string> $names
	 */
	private static function seo_field_section( array $names ): array {
		$properties = array();
		foreach ( $names as $name ) {
			$properties[ $name ] = self::seo_field();
		}

		return array(
			'type'                 => 'object',
			'properties'           => $properties,
			'additionalProperties' => false,
		);
	}

	/**
	 * Returns the normalized field envelope schema.
	 *
	 * @return array<string, mixed>
	 */
	private static function seo_field(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'value', 'state', 'source', 'reason' ),
			'properties'           => array(
				'value'  => array( 'type' => array( 'string', 'integer', 'number', 'boolean', 'array', 'object', 'null' ) ),
				'state'  => array(
					'type' => 'string',
					'enum' => array( 'explicit', 'inherited', 'generated', 'unsupported', 'unavailable' ),
				),
				'source' => array( 'type' => 'string' ),
				'reason' => array( 'type' => array( 'string', 'null' ) ),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Returns safe provider status schema.
	 *
	 * @return array<string, mixed>
	 */
	private static function provider_status(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'provider', 'version', 'detected', 'modules', 'module_versions', 'capabilities' ),
			'properties'           => array(
				'provider'        => array( 'type' => 'string' ),
				'version'         => array( 'type' => array( 'string', 'null' ) ),
				'detected'        => array( 'type' => 'boolean' ),
				'modules'         => self::string_array( 50 ),
				'module_versions' => array(
					'type'                 => 'object',
					'maxProperties'        => 50,
					'additionalProperties' => array( 'type' => 'string' ),
				),
				'capabilities'    => self::string_array( 50 ),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Returns a bounded string-array schema.
	 *
	 * @param int        $maximum       Maximum item count.
	 * @param array|null $default_value Optional default.
	 * @return array<string, mixed>
	 * @phpstan-param list<string>|null $default_value
	 */
	private static function string_array( int $maximum, ?array $default_value = null ): array {
		$schema = array(
			'type'        => 'array',
			'uniqueItems' => true,
			'maxItems'    => $maximum,
			'items'       => array( 'type' => 'string' ),
		);
		if ( null !== $default_value ) {
			$schema['default'] = $default_value;
		}

		return $schema;
	}

	/**
	 * Returns a bounded positive-integer-array schema.
	 *
	 * @param int $maximum Maximum item count.
	 * @return array<string, mixed>
	 */
	private static function integer_array( int $maximum ): array {
		return array(
			'type'        => 'array',
			'uniqueItems' => true,
			'maxItems'    => $maximum,
			'items'       => array(
				'type'    => 'integer',
				'minimum' => 1,
			),
		);
	}

	/**
	 * Returns the get-llms-txt input contract. No field here requires the
	 * publication flag; that is the point of this ability.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_llms_txt_input(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'verify_public_endpoint' => array(
					'description' => 'When true, additionally performs a bounded same-site GET of the public /llms.txt path to confirm what actually serves it, including before a configuration has been saved.',
					'type'        => 'boolean',
					'default'     => false,
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Returns the get-llms-txt output contract.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_llms_txt_output(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'schema_version', 'config', 'artifact', 'ownership', 'version_token', 'provenance' ),
			'properties'           => array(
				'schema_version' => array( 'type' => 'string' ),
				'config'         => self::nullable_object( self::llms_config_schema() ),
				'artifact'       => self::nullable_object( self::llms_artifact_summary_schema() ),
				'ownership'      => self::llms_ownership_schema(),
				'version_token'  => array( 'type' => 'string' ),
				'provenance'     => self::provenance(),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Returns the update-llms-txt input contract. preview-update-llms-txt
	 * shares it exactly, so a validated preview result can be resubmitted to
	 * update-llms-txt unchanged. Every field except `version_token`,
	 * `introduction`, and `curated_links` must be supplied: the stored
	 * configuration is always replaced whole, never merged.
	 *
	 * @return array<string, mixed>
	 */
	public static function update_llms_txt_input(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'version_token', 'site_title', 'site_summary', 'enabled_post_types', 'sections', 'group_by_section', 'show_excerpts', 'excerpt_length', 'max_items_per_section' ),
			'properties'           => array(
				'version_token'         => array(
					'description' => 'Optimistic-concurrency token from get-llms-txt.',
					'type'        => 'string',
					'minLength'   => 18,
					'maxLength'   => 191,
				),
				'site_title'            => array(
					'description' => 'Document "# " title.',
					'type'        => 'string',
					'minLength'   => 1,
					'maxLength'   => 200,
				),
				'site_summary'          => array(
					'description' => 'Document "> " one-sentence summary.',
					'type'        => 'string',
					'minLength'   => 1,
					'maxLength'   => 300,
				),
				'introduction'          => array(
					'description' => 'Optional introduction paragraph. Omitted or empty clears it.',
					'type'        => array( 'string', 'null' ),
					'maxLength'   => 2000,
				),
				'enabled_post_types'    => array(
					'description' => 'Post types eligible for selection.',
					'type'        => 'array',
					'minItems'    => 1,
					'maxItems'    => 50,
					'uniqueItems' => true,
					'items'       => array(
						'type'    => 'string',
						'pattern' => '^[a-z0-9_-]{1,20}$',
					),
				),
				'sections'              => array(
					'description' => 'Ordered section key/label pairs.',
					'type'        => 'array',
					'minItems'    => 1,
					'maxItems'    => 20,
					'items'       => array(
						'type'                 => 'object',
						'required'             => array( 'key', 'label' ),
						'properties'           => array(
							'key'   => array(
								'type'    => 'string',
								'pattern' => '^[a-z0-9_-]{1,64}$',
							),
							'label' => array(
								'type'      => 'string',
								'minLength' => 1,
								'maxLength' => 100,
							),
						),
						'additionalProperties' => false,
					),
				),
				'group_by_section'      => array(
					'description' => 'Whether entries group by their own section, or collapse into one.',
					'type'        => 'boolean',
				),
				'show_excerpts'         => array(
					'description' => 'Whether excerpts are ever emitted.',
					'type'        => 'boolean',
				),
				'excerpt_length'        => array(
					'description' => 'Configured excerpt character limit.',
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 200,
				),
				'max_items_per_section' => array(
					'description' => 'Configured per-section item limit.',
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 100,
				),
				'curated_links'         => array(
					'description' => 'Optional same-site curated links. Omitted or empty clears them.',
					'type'        => 'array',
					'maxItems'    => 200,
					'items'       => array(
						'type'                 => 'object',
						'required'             => array( 'title', 'url' ),
						'properties'           => array(
							'title'   => array(
								'type'      => 'string',
								'minLength' => 1,
								'maxLength' => 200,
							),
							'url'     => array(
								'description' => 'Canonical same-site absolute URL.',
								'type'        => 'string',
							),
							'section' => array(
								'description' => 'Configured section key this link belongs to, or null to use the default section.',
								'type'        => array( 'string', 'null' ),
							),
						),
						'additionalProperties' => false,
					),
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Returns the preview-update-llms-txt input contract.
	 *
	 * @return array<string, mixed>
	 */
	public static function preview_update_llms_txt_input(): array {
		return self::update_llms_txt_input();
	}

	/**
	 * Returns the preview-update-llms-txt output contract.
	 *
	 * @return array<string, mixed>
	 */
	public static function preview_update_llms_txt_output(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'schema_version', 'writes_performed', 'version_token', 'current_config', 'current_artifact', 'prospective_config', 'prospective_artifact', 'diff', 'provenance' ),
			'properties'           => array(
				'schema_version'       => array( 'type' => 'string' ),
				'writes_performed'     => array( 'type' => 'boolean' ),
				'version_token'        => array( 'type' => 'string' ),
				'current_config'       => self::nullable_object( self::llms_config_schema() ),
				'current_artifact'     => self::nullable_object( self::llms_artifact_summary_schema() ),
				'prospective_config'   => self::llms_config_schema(),
				'prospective_artifact' => self::llms_artifact_summary_schema(),
				'diff'                 => array(
					'type'                 => 'object',
					'required'             => array( 'added_sections', 'removed_sections', 'changed_sections' ),
					'properties'           => array(
						'added_sections'   => self::string_array( 20 ),
						'removed_sections' => self::string_array( 20 ),
						'changed_sections' => self::string_array( 20 ),
					),
					'additionalProperties' => false,
				),
				'provenance'           => self::provenance(),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Returns the update-llms-txt output contract.
	 *
	 * @return array<string, mixed>
	 */
	public static function update_llms_txt_output(): array {
		return self::llms_mutation_output();
	}

	/**
	 * Returns the regenerate-llms-txt input contract. Accepts no fields:
	 * regeneration always rebuilds from the already-stored configuration and
	 * live site content, never from caller-supplied paths or content.
	 *
	 * @return array<string, mixed>
	 */
	public static function regenerate_llms_txt_input(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(),
			'additionalProperties' => false,
		);
	}

	/**
	 * Returns the regenerate-llms-txt output contract.
	 *
	 * @return array<string, mixed>
	 */
	public static function regenerate_llms_txt_output(): array {
		return self::llms_mutation_output();
	}

	/**
	 * Returns the shared update/regenerate-llms-txt output shape.
	 *
	 * @return array<string, mixed>
	 */
	private static function llms_mutation_output(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'schema_version', 'version_token', 'config', 'artifact', 'changed_fields', 'ownership', 'provenance' ),
			'properties'           => array(
				'schema_version' => array( 'type' => 'string' ),
				'version_token'  => array( 'type' => 'string' ),
				'config'         => self::llms_config_schema(),
				'artifact'       => self::llms_artifact_summary_schema(),
				'changed_fields' => self::string_array( 20 ),
				'ownership'      => self::llms_ownership_schema(),
				'provenance'     => self::provenance(),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Returns the effective llms.txt configuration document, matching
	 * `LlmsConfig::to_array()`.
	 *
	 * @return array<string, mixed>
	 */
	private static function llms_config_schema(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'site_url', 'site_title', 'site_summary', 'introduction', 'enabled_post_types', 'sections', 'group_by_section', 'show_excerpts', 'excerpt_length', 'max_items_per_section', 'curated_links' ),
			'properties'           => array(
				'site_url'              => array( 'type' => 'string' ),
				'site_title'            => array( 'type' => 'string' ),
				'site_summary'          => array( 'type' => 'string' ),
				'introduction'          => array( 'type' => array( 'string', 'null' ) ),
				'enabled_post_types'    => self::string_array( 50 ),
				'sections'              => array(
					'type'     => 'array',
					'maxItems' => 20,
					'items'    => array(
						'type'                 => 'object',
						'required'             => array( 'key', 'label' ),
						'properties'           => array(
							'key'   => array( 'type' => 'string' ),
							'label' => array( 'type' => 'string' ),
						),
						'additionalProperties' => false,
					),
				),
				'group_by_section'      => array( 'type' => 'boolean' ),
				'show_excerpts'         => array( 'type' => 'boolean' ),
				'excerpt_length'        => array( 'type' => 'integer' ),
				'max_items_per_section' => array( 'type' => 'integer' ),
				'curated_links'         => array(
					'type'     => 'array',
					'maxItems' => 200,
					'items'    => array(
						'type'                 => 'object',
						'required'             => array( 'title', 'url', 'section' ),
						'properties'           => array(
							'title'   => array( 'type' => 'string' ),
							'url'     => array( 'type' => 'string' ),
							'section' => array( 'type' => array( 'string', 'null' ) ),
						),
						'additionalProperties' => false,
					),
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Returns the artifact summary document — every field of
	 * `LlmsArtifact::to_array()` except `content`, matching
	 * `LlmsArtifact::to_summary_array()`.
	 *
	 * @return array<string, mixed>
	 */
	private static function llms_artifact_summary_schema(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'content_hash', 'generated_at', 'byte_count', 'link_count', 'warnings' ),
			'properties'           => array(
				'content_hash' => array( 'type' => 'string' ),
				'generated_at' => array( 'type' => 'string' ),
				'byte_count'   => array( 'type' => 'integer' ),
				'link_count'   => array( 'type' => 'integer' ),
				'warnings'     => self::string_array( 50 ),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Returns the ownership-state document, matching
	 * `LlmsOwnershipState::to_array()`. Never contains a filesystem path.
	 *
	 * @return array<string, mixed>
	 */
	private static function llms_ownership_schema(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'owner', 'physical_artifact_exists', 'legacy_full_artifact_exists', 'legacy_docs_directory_exists', 'yoast_llms_txt_enabled', 'bridge_publication_enabled', 'bridge_route_routable', 'public_verification', 'conflict', 'administrator_action' ),
			'properties'           => array(
				'owner'                        => array(
					'type' => 'string',
					'enum' => array( 'bridge', 'yoast', 'third_party', 'none' ),
				),
				'physical_artifact_exists'     => array( 'type' => 'boolean' ),
				'legacy_full_artifact_exists'  => array( 'type' => 'boolean' ),
				'legacy_docs_directory_exists' => array( 'type' => 'boolean' ),
				'yoast_llms_txt_enabled'       => array( 'type' => 'boolean' ),
				'bridge_publication_enabled'   => array( 'type' => 'boolean' ),
				'bridge_route_routable'        => array( 'type' => 'boolean' ),
				'public_verification'          => array(
					'type' => 'string',
					'enum' => array( 'served_by_bridge', 'served_by_other', 'not_found', 'unknown' ),
				),
				'conflict'                     => array(
					'type' => array( 'string', 'null' ),
					'enum' => array( 'yoast_llms_txt_enabled', 'physical_artifact_present', 'bridge_route_unroutable', null ),
				),
				'administrator_action'         => array( 'type' => 'string' ),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Widens an object schema to also accept `null`.
	 *
	 * @param array $schema Object schema to widen.
	 * @return array<string, mixed>
	 * @phpstan-param array<string, mixed> $schema
	 */
	private static function nullable_object( array $schema ): array {
		$schema['type'] = array( 'object', 'null' );

		return $schema;
	}
}

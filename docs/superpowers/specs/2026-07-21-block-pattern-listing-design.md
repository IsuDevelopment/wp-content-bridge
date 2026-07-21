# Block-pattern listing design

## Goal

Expose the site's registered Gutenberg block patterns as a deterministic,
bounded, read-only WordPress Ability that can safely inform draft creation.

## Public ability

ID: `wp-content-bridge/list-block-patterns`

Input:

- `query`: optional text search, maximum 200 bytes;
- `namespace`: optional exact namespace before `/`, maximum 100 bytes;
- `category`: optional exact category slug, maximum 100 bytes;
- `post_type`: optional registered post-type slug, maximum 20 bytes; global
  patterns and patterns explicitly supporting that type match;
- `include_content`: boolean, default `false`;
- `page`: positive integer, default `1`;
- `per_page`: integer `1..50`, default `20`.

Output is a schema-versioned object envelope with `items`, `pagination`,
`limits`, and `provenance`. Each item contains:

- `name`, `namespace`, `title`, `description`;
- `source` (nullable), `viewport_width` (nullable), and `inserter`;
- bounded `categories`, `keywords`, `block_types`, `post_types`, and
  `template_types` arrays;
- `content` (complete string or null), `content_bytes`, and `untrusted: true`.

Content is null unless requested. Combined content on one response page is
capped at 2 MiB. Over-limit content returns
`wpcb_pattern_content_too_large`; markup is never truncated.

## Authorization and exposure

- option: `wpcb_pattern_reads_enabled`, false by default;
- plugin capability: `wpcb_read_patterns`;
- native gate: core-compatible editor access (`edit_posts` or a mapped
  `edit_posts` capability for a REST-visible post type);
- ability is not registered while disabled;
- REST and MCP-public metadata may advertise the registered domain ability,
  but the site MCP allowlist remains unchanged until explicitly updated.

## Application boundary

`ListBlockPatterns` consumes a `BlockPatternCatalog` and a
`BlockPatternAccess` port. The WordPress adapters own registry access and native
capability mapping. The Ability adapter owns input normalization and `WP_Error`
mapping. No application or domain class depends on REST, MCP, or WordPress.

## Runtime behavior

The WordPress catalog reads the current registry only. It does not call
`_load_remote_block_patterns()`, `_load_remote_featured_patterns()`, or any
other remote loader. It allowlists output fields, drops `filePath`, sorts by
name, filters before pagination, and scans at most 1,000 candidates.

## Verification

- domain validation, filtering semantics, serialization, and payload limits;
- disabled-feature and native-denial ordering before catalog reads;
- strict schemas and annotations;
- Settings API registration, capability migration, ability registration,
  deterministic pagination, metadata-only default, content opt-in, filesystem
  path leakage rejection, anonymous/subscriber denial, and editor success on
  Kormas local.

# Media P0 design

## Goal

Provide deterministic, bounded, read-only media discovery without treating
attachments as ordinary content or exposing attachment internals.

## Public contract

`wp-content-bridge/get-media` returns an object envelope containing
`schema_version`, `items`, `pagination`, and `provenance`. Optional selectors
are mutually exclusive: `id`, `url`, `filename`, or `query`. With no selector,
the ability returns a bounded newest-first page. Filename and URL selectors are
exact; URL matching is limited to the current site.

`wp-content-bridge/get-media-by-id` accepts one positive attachment ID and
returns `schema_version`, one `item`, and `provenance`.

Every item contains exactly: `id`, `title`, `filename`, `url`, `alt_text`,
`caption`, `description`, and `mime_type`. Stored values are untrusted data.

Content summaries always contain `featured_image_id` and
`featured_image_url`. They are either both populated from one authorized
attachment or both null.

## Architecture

- Domain: immutable `MediaQuery`, `MediaItem`, and `MediaSearchResult`.
- Application: `MediaRepository`, `MediaAccessManager`, `SearchMedia`, and
  `GetMediaById`.
- Infrastructure: `WordPressMediaRepository` using public WordPress APIs and
  per-object native authorization.
- Adapter: `MediaAbilities`, strict schemas, stable `WP_Error` mapping, and a
  Settings API master switch.

The MCP adapter remains an external projection. It is not initialized or
configured by this feature.

## Security and bounds

- master option off by default;
- dedicated `wpcb_read_media` capability;
- native `read_post` checked before an item enters totals or output;
- 100 items per page and 1,000 inspected candidates maximum;
- no arbitrary attachment meta, disk path, options, SQL, or remote fetch;
- URL selector rejects credentials, fragments, non-HTTP schemes, and a
  different effective origin;
- denial and absence are non-enumerating;
- media writes/uploads remain out of scope.

## Verification

Unit tests lock query validation, response envelopes, access ordering, schemas,
integration-capability allowlisting, and featured-image pair serialization. A
repeatable WordPress verifier covers registration flagging, capability denial,
ID/URL/filename lookup, native object denial, normalized fields, pagination,
and featured-image ID+URL output.

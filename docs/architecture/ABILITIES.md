# Ability catalog and contracts

Ability IDs are stable public API. This document is normative; executable JSON
Schemas are centralized in `src/Adapter/Abilities/AbilitySchemas.php` and locked
by unit and runtime contract verification.

## Category

`wp-content-bridge` — content, editorial, and SEO capabilities.

Every content ability first checks the per-post-type operation policy documented in `CONTENT_ACCESS.md`, then plugin and native WordPress capabilities. A policy switch does not register or enable an otherwise unavailable ability.

## MVP read abilities

### `wp-content-bridge/search-content`

Purpose: find content the authenticated principal may read.

The effective set is the intersection of requested types, configured `search_content` types, configured `get_content` types, and objects readable by the principal.

Inputs:

- `query?: string`
- `post_types?: string[]`
- `statuses?: string[]`
- `author_ids?: integer[]`
- `taxonomy?: [{ taxonomy, term_ids } ]`, maximum 10 filters and 100 positive,
  unique term IDs per filter. Each taxonomy may appear once, must be public or
  REST-visible, and must be assigned to every effective post type. Terms match
  with `IN` inside one filter; multiple taxonomy filters use `AND`. Descendants
  are not included implicitly.
- `published_after/before?: date-time`
- `modified_after/before?: date-time`
- `page?: integer` default 1
- `per_page?: integer` default 20, maximum 100
- `order_by?: relevance|date|modified|title|id`
- `order?: asc|desc`

Output: paginated compact summaries and query metadata. Private statuses are
allowed only when the principal can read those objects. Pagination is computed
after per-object authorization. `total_is_exact` tells the caller whether every
candidate was inspected, `has_more` reports a possible continuation, and
`candidate_scan_limit` currently reports the hard 1,000-candidate scan bound.
When the bound is reached, totals are safe lower bounds and never include known
unreadable objects.

Annotations: read-only, non-destructive, idempotent.

### `wp-content-bridge/get-content`

Purpose: retrieve one content object with selectable representations. Optional
normalized SEO is added in Milestone 2 without changing the content repository
into a Yoast-specific component.

The object's post type must allow `get_content`; disallowed and unreadable objects must not become an enumeration oracle.

Inputs:

- `post_id: integer`
- `representations?: [raw, rendered, plain_text]`
- `include?: [author, taxonomies, featured_media, revision]`

Output sections:

- identity/status/URL/dates;
- selected content representations;
- optional relationships;
- concurrency token derived from authoritative object version data;
- byte counts for each selected representation and their combined total;
- provenance and warnings.

The combined UTF-8 byte size of selected representations is capped at 2 MiB.
The object is rejected with `wpcb_content_too_large`; the plugin does not return
silent truncation that could corrupt Gutenberg source or mislead a model.

Annotations: read-only, non-destructive, idempotent.

### `wp-content-bridge/get-url-seo`

Purpose: retrieve normalized effective SEO for a URL or supported WordPress object, including archives and Local SEO locations.

Inputs use exactly one selector:

- `url`, or
- `post_id`, or
- a future typed term/archive selector.

Output:

- provider identity/version/modules and safe module versions;
- `configured` where an editable object exists;
- `configured.keyphrase_details` for tested Premium additional keyphrases;
- `resolved` public metadata, including allowlisted `local_businesses` when
  provider-emitted Schema contains Place/LocalBusiness data;
- `analysis` when available;
- full `schema_graph` subject to explicit size limits;
- completeness/provenance/warnings.

Annotations: read-only, non-destructive, idempotent.

### `wp-content-bridge/get-editorial-context`

Purpose: obtain bounded site vocabulary and content inventory needed to plan content.

Inputs select sections and bounds. Output may contain post types, taxonomies, terms, authors, recent-content summaries, and normalized public organization/location information. It must not generate a plan or call a model.

Inputs:

- `sections?: string[]` — defaults to all six supported sections:
  `post_types`, `taxonomies`, `terms`, `authors`, `recent_content`, and
  `local_businesses`; at least one and at most six;
- `post_types?: string[]` — exact optional selection, maximum 20;
- `taxonomies?: string[]` — exact optional selection, maximum 20;
- `recent_limit?: integer` — 1–50, default 20;
- `terms_per_taxonomy?: integer` — 1–100, default 50.

Semantics and security:

- an effective post type must allow both configured READ and SEARCH operations;
- unavailable explicitly requested types or taxonomies fail instead of silently
  returning a partial selection;
- recent inventory contains published objects only and reuses authorization-
  aware search before pagination;
- authors are derived only from those readable recent results and contain only
  `id` and `display_name`; this is not a WordPress-user enumeration endpoint;
- taxonomies must be public or REST-visible and assigned to an effective type;
- terms use editorial vocabulary semantics (`hide_empty=false`) and include an
  explicit per-taxonomy truncation flag;
- Local businesses come only through normalized provider-emitted public Schema,
  never Local options, licenses, raw indexables, or arbitrary metadata.

Output is a schema `1.0` envelope containing selected `sections`, `context`,
effective `bounds`, `provenance`, and bounded `warnings`.

Annotations: read-only, non-destructive, idempotent.

### `wp-content-bridge/get-block-tree`

Purpose: return one readable content object's Gutenberg structure as a flat,
path-addressed node list, without full block markup. Same sensitivity as
`get-content`, so the same gates: always registered, requires
`wpcb_read_content`, native `read_post`, and the post type's Read policy.

Inputs:

- `post_id: integer` (required).
- `max_depth?: integer` (minimum 1) — maximum node depth returned, counted
  from the returned root; omit for unbounded depth, still subject to the
  500-node cap.
- `path?: integer[]` (1–20 items, each ≥ 0) — zero-based indices into
  successive `innerBlocks` arrays identifying a subtree root; returns that
  node and its descendants instead of the whole document.
- `include_attrs?: boolean` (default `false`).

Each output node is `{ path, block_name, inner_blocks, text, text_source,
attrs?, attrs_omitted? }`. `path` and `block_name` are `list<int>` and
`string|null`; `block_name` is `null` for the freeform whitespace nodes
`parse_blocks()` emits between blocks — they occupy real indices in the array
`update-block` mutates and are always included, never skipped, and
`expected_block_name: null` legitimately targets one. `text` tries the node's
own `innerHTML` first (`wp_strip_all_tags()`, trimmed, at most 120 characters);
when that is empty it falls back to the node's own prose-bearing string
attributes (whitespace-containing values at least 3 characters long,
concatenated in attribute-name order). `text_source` records which:
`inner_html`, `attrs`, or `null` when `text` is `null` — a block whose
`text_source` is `attrs` is edited by changing an attribute, not by replacing
markup.

`attrs` are opt-in via `include_attrs` (default `false`); when `false` they
are omitted entirely and `attrs_omitted` is never set, since absence is then
the request's own contract, not a size omission. When `include_attrs` is
`true`, one node's `attrs` is still withheld with `attrs_omitted: true` above
a 512-byte encoded bound, so one pathological block cannot dominate the
response.

Output: `schema_version`, `post_id`, `post_type`, `version_token` (to pass to
`update-block`, `preview-update-block`, or `update-block-attributes`),
`nodes` (at most 500), `truncated` (`true` when the node cap stopped
traversal before every node was returned), and `provenance`.

Annotations: read-only, non-destructive, idempotent.

### `wp-content-bridge/get-diagnostics`

Purpose: report safe compatibility status for support and client setup.

It reports versions and availability, not secrets, database details, paths, usernames, or raw configuration.

Annotations: read-only, non-destructive, idempotent.

## Media read abilities

Media abilities are registered only when `wpcb_media_reads_enabled` is true.
Both require `wpcb_read_media` and native `read_post` for every attachment.
Attachments remain outside the content-type matrix (ADR 0011).

### `wp-content-bridge/get-media`

Purpose: search the authorized media library without guessing attachment IDs.

Inputs accept at most one selector: exact positive `id`, exact same-site
original `url`, exact `filename`, or bounded text `query`; `page` defaults to 1
and `per_page` defaults to 20 with a maximum of 100. With no selector, newest
attachments are returned. Candidate inspection is capped at 1,000 and native
authorization is applied before pagination.

Output is an object envelope containing `schema_version`, `items`, `pagination`,
and `provenance`. Each item contains exactly `id`, `title`, `filename`, `url`,
`alt_text`, `caption`, `description`, and `mime_type`.

Annotations: read-only, non-destructive, idempotent.

### `wp-content-bridge/get-media-by-id`

Purpose: retrieve one exact authorized attachment. Missing and denied objects
both return `wpcb_media_unavailable`.

Input: positive integer `id`. Output: an object envelope with
`schema_version`, one normalized `item`, and `provenance`.

Annotations: read-only, non-destructive, idempotent.

`search-content` and `get-content` summaries add the required nullable pair
`featured_image_id` and `featured_image_url`; both are populated together only
when the attachment is readable. The optional
`relationships.featured_media` projection remains available.

## Block-pattern read ability

`wp-content-bridge/list-block-patterns` is registered only when
`wpcb_pattern_reads_enabled` is true. It requires the dedicated
`wpcb_read_patterns` capability and native editor access equivalent to the core
block-pattern REST controller (`edit_posts`, or the mapped `edit_posts`
capability for at least one REST-visible post type). Patterns remain outside the
content-type matrix (ADR 0013).

Inputs:

- optional text `query`, maximum 200 bytes;
- optional exact `namespace`, `category`, and `post_type` filters;
- `include_content`, default false;
- `page`, default 1, and `per_page`, default 20, maximum 50.

The output is a strict schema `1.0` object envelope with `items`, `pagination`,
`limits`, and `provenance`. Items expose only namespaced identity, plain-text
title/description, source, viewport/inserter metadata, bounded category,
keyword, block-type, post-type and template-type arrays, optional complete
content, its byte count, and `untrusted: true`. Filesystem paths and unknown
registry properties are never returned.

Content is omitted by default. When requested, the combined page markup is
capped at 2 MiB and fails atomically with
`wpcb_pattern_content_too_large`; valid Gutenberg markup is never truncated.
Candidate scanning is capped at 1,000 and inexact totals are explicit. The
ability reads only the current registry and never invokes WordPress.org remote
pattern loaders.

Annotations: read-only, non-destructive, idempotent.

## Write abilities

`create-draft`, `update-content`, `update-block`, `update-block-attributes`,
`update-seo`, `update-service-schema`, `trash-content`, and
`restore-trashed-content` are **implemented and reachable** writes.
Service-schema read and preview are separate read-only intents under the same
registration gate.
The first five writes are
registered when
`get_option( Installer::WRITES_ENABLED_OPTION )` (`wpcb_writes_enabled`) is
truthy. All Service-schema intents additionally require the compatible standalone
IsuDev Schema Extended public API to be loaded, while `trash-content` and
`restore-trashed-content` additionally require `wpcb_trash_enabled` —
restoration is part of the trash feature, not a separate flag. An ability
that is not registered is invisible to Abilities discovery and to any MCP
projection. Every write additionally requires its plugin capability
(`wpcb_edit_content`, `wpcb_manage_seo`, or `wpcb_delete_content`), the native
WordPress type/object capability, and a
per-post-type policy (`ContentAccessManager`) — three independently-enforced
gates. None can set post status to `publish`, `future`, or `pending`.

Stable error codes shared by `create-draft`/`update-content`:
`wpcb_invalid_input`, `wpcb_conflict`, `wpcb_invalid_blocks`, `wpcb_forbidden`,
`wpcb_content_unavailable`, `wpcb_write_failed`, `wpcb_internal_error`.
`update-seo` shares the same set except `wpcb_invalid_blocks`, and adds
`wpcb_seo_field_unsupported` (a field outside the writable allowlist rejects
the whole request).
`update-block` and `preview-update-block` share that same set and add
`wpcb_block_path_not_found` (no block exists at `path`) and
`wpcb_block_mismatch` (the block at `path` is not `expected_block_name`).
`update-block-attributes` uses the same two block-addressing codes but never
`wpcb_invalid_blocks`, since it never accepts raw block markup; a freeform
node targeted by `path` — which has no `attrs` to merge into — is also
reported as `wpcb_block_mismatch`.
`update-service-schema` uses the SEO gate and adds
`wpcb_service_schema_unavailable` when the optional provider or target post type
is unsupported.
`update-custom-schema` uses the same SEO gate and adds
`wpcb_custom_schema_unavailable` for an absent, incompatible, or unsupported
provider and `wpcb_invalid_custom_schema` with bounded diagnostics when enabled
JSON fails provider validation.
`restore-trashed-content` shares `trash-content`'s error-code set except
`wpcb_invalid_blocks`, and reuses `wpcb_invalid_state`/`wpcb_trash_unavailable`
for a non-`trash` source state and disabled trash retention, respectively.

**MCP projection:** the current reference profile contains all 25 implemented
ability IDs and intersects that closed allowlist
with the abilities registered in the current request. Feature flags therefore
still remove disabled operations from discovery, and Service/Custom Schema
Abilities disappear when their Schema Extended contracts are not loaded. Projection does not grant
authority: the official Adapter principal and the separate ChatGPT-facing
miniOrange grant must each be configured explicitly; see
`docs/setup/MCP_ADAPTER.md` and `docs/setup/CHATGPT_CONNECTOR.md`.

### `wp-content-bridge/create-draft`

Creates a new post/page/registered custom post type. Always writes status
`draft` — there is no status input field, so it can never publish as a side
effect.

Inputs:

- `post_type: string` (required) — target post type slug.
- `title: string` (required, 1–500 chars).
- `block_markup?: string` — Gutenberg block markup for the body; validated by
  a registered-block-type + parse round-trip check (`wpcb_invalid_blocks` on
  failure); default empty.
- `excerpt?: string|null`.
- `taxonomies?: [{ taxonomy, term_ids }]|null` — bounded assignment (mirrors
  `search-content`'s taxonomy-filter shape).
- `idempotency_key?: string|null` — when supplied, a repeated call with the
  same key (per acting user, 24h TTL) replays the same result instead of
  creating a second post; `created` is `false` on replay.

Output (`schema_version, post_id, post_type, status, version_token,
changed_fields, created, provenance`): `status` is always `draft`;
`version_token` is the optimistic-concurrency token to pass to
`update-content`; `changed_fields` lists field names only, never values.

Annotations: `readonly: false`, `destructive: false`, `idempotent: false` (the
annotation is conservative — idempotent replay is functionally supported via
`idempotency_key` but not asserted in the ability's own metadata).

### `wp-content-bridge/update-content`

Updates title/content/excerpt/taxonomies of one existing post. Never includes
`post_status` in its write — status is untouched by this ability under any
input.

Inputs:

- `post_id: integer` (required).
- `version_token: string` (required) — from a prior `get-content` call's
  `version_token` field; a stale token is rejected with `wpcb_conflict` and
  the post is never overwritten.
- `title?`, `block_markup?`, `excerpt?: string|null` — any subset may be
  supplied; `block_markup` is validated the same way as `create-draft`.
- `taxonomies?: [{ taxonomy, term_ids }]|null`.

Output: same shape as `create-draft`'s (`created` is always `false`).
`update-content` creates a WordPress revision on every successful write.

Annotations: `readonly: false`, `destructive: true`, `idempotent: false`.

### `wp-content-bridge/preview-update-content`

Accepts the exact `update-content` input contract, including required
`post_id` and current `version_token`. It checks the same per-post-type Update
policy, the same optimistic-concurrency token, and validates block markup with
the same `BlockMarkupValidator`, then returns bounded current/prospective
content and `writes_performed: false`. No post, meta, revision, audit, or
cache state is changed (ADR 0021).

Output (`schema_version, writes_performed, post_id, post_type, version_token,
changed_fields, current_content, preview_content, preview_taxonomies,
warnings, provenance`): `current_content`/`preview_content` each carry
`title`, `block_markup`, and `excerpt`. `block_markup` is round-tripped through
`parse_blocks()`/`serialize_blocks()` only — never through content filters
that could mutate what would actually be stored. `preview_taxonomies` lists
the prospective `{taxonomy, term_ids}` assignments, empty when taxonomies are
untouched. `warnings` is a bounded list of `{code, field, message}`, emitting
`content_replaced`/`content_deleted` when `block_markup` changes and
`taxonomies_replaced` when a taxonomy assignment is present (assignments
always replace a taxonomy's current terms).

Annotations: `readonly: true`, `destructive: false`, `idempotent: true`.

### `wp-content-bridge/update-seo`

Writes a fixed, version-tested Yoast editor-field allowlist on one existing post
through the active `SeoWriter` (`YoastSeoWriter`). Core fields require Yoast
Free 28.x; Premium keyphrase fields additionally require Premium 28.x. It never
touches post title/body/status and never publishes.

Inputs:

- `post_id: integer` (required).
- `version_token: string` (required) — from a prior `get-content` call's
  `version_token` field; a stale token is rejected with `wpcb_conflict` and
  no SEO meta is written.
- Any subset of: `seo_title?`, `meta_description?`, `focus_keyphrase?`,
  `keyphrase_synonyms?: string[]|null`, `related_keyphrases?: string[]|null`,
  `canonical?`, `og_title?`, `og_description?`, `twitter_title?`,
  `twitter_description?: string|null`, `robots_index?`, `robots_follow?`,
  `robots_noarchive?`, `robots_noimageindex?`, `robots_nosnippet?: bool|null`,
  and `og_image_id?`, `twitter_image_id?: integer|null`. Premium lists contain
  at most 20 unique, non-empty values of at
  most 191 characters; `[]` clears and null/omission leaves unchanged. A key
  outside this allowlist (e.g. Local-only `schema_type`) rejects the **whole** request with
  `wpcb_seo_field_unsupported` naming the offending keys — there is no
  field-level partial application.

Advanced robots booleans merge only their named directive with Yoast's current
`meta-robots-adv` value. A positive social image ID must be a readable WordPress
image attachment; `0` clears the override and null/omission leaves it unchanged.
The client cannot supply an image URL. Image validation completes before any
field is written, and failure is non-enumerating (`wpcb_seo_image_unavailable`;
ADR 0016).

Output: same envelope shape as `create-draft`/`update-content`
(`schema_version, post_id, post_type, status, version_token, changed_fields,
created, provenance`) plus `effective_seo` — the same resolved SEO shape as
`get-url-seo`, re-read via `YoastSeoProvider` immediately after the write so
callers can confirm what actually landed.

The re-read uses normalization schema 1.3. It retains the 1.2 Premium fields
and additionally returns the advanced robots flags and social image IDs in the
configured projection. Premium output includes
`configured.keyphrase_synonyms` plus `configured.related_keyphrases`. The
provider's raw positional JSON is never part of the Ability contract (ADR
0014).

Annotations: `readonly: false`, `destructive: true`, `idempotent: false`.

### `wp-content-bridge/preview-update-seo`

Accepts the exact `update-seo` input contract, including required `post_id`
and current `version_token`. It checks the same per-post-type Update SEO
policy, the same optimistic-concurrency token, the same allowlist (an
outside-allowlist key still fails with `wpcb_seo_field_unsupported`), and
normalizes each requested field exactly as `YoastSeoWriter::write()` sanitizes
it — including resolving social-image attachment IDs — but never calls
`WPSEO_Meta::set_value()`. Returns `writes_performed: false` (ADR 0021).

Output (`schema_version, writes_performed, post_id, post_type, version_token,
changed_fields, current_seo, preview_seo, warnings, provenance`):
`current_seo` is the same full resolved shape as `get-url-seo` and
`update-seo`'s `effective_seo`, since it already exists and is already public.
`preview_seo` is deliberately narrower — only the present, sanitized
allowlisted field values — because the resolved public output does not exist
until the change is actually rendered. `warnings` is a bounded list of
`{code, field, message}`, emitting `field_cleared` when a field is explicitly
set to an empty string.

Annotations: `readonly: true`, `destructive: false`, `idempotent: true`.

### `wp-content-bridge/update-block`

Replaces exactly one block subtree, addressed by tree `path`, leaving every
other block byte-identical by construction — the rest of the document is
never re-emitted by the caller and never re-parsed from its output (ADR
0022). Shares `update-content`'s gates exactly: `wpcb_writes_enabled`,
`wpcb_edit_content`, native `edit_post`, the post type's Update policy, and
optimistic concurrency.

Inputs:

- `post_id: integer` (required).
- `version_token: string` (required) — from a prior `get-block-tree` or
  `get-content` call; a stale token is rejected with `wpcb_conflict` before
  any mutation.
- `path: integer[]` (required, 1–20 items, each ≥ 0) — zero-based indices
  into successive `innerBlocks` arrays, as returned by `get-block-tree`.
  `parse_blocks()` emits `block_name: null` freeform nodes for whitespace
  between blocks, and these occupy real indices that must be counted when
  building a path.
- `expected_block_name: string|null` (**required**) — the registered block
  name asserted to exist at `path`, or `null` to assert a freeform node. A
  matching `version_token` proves the document did not change; it does not
  prove `path` points at the block the caller believes it does, so this fact
  is asserted separately and the request fails closed with
  `wpcb_block_mismatch` when it differs.
- `block_markup: string` (required, ≤ 500,000 bytes) — replacement markup for
  the subtree at `path`; an empty string deletes it.

Behaviour: resolve `path` against the current content, assert
`expected_block_name`, validate `block_markup` recursively (nested blocks
included), splice the parsed replacement into the tree, and
`serialize_blocks()` the whole tree through the existing
`ContentMutationRepository`. An out-of-range `path` fails with the
non-enumerating `wpcb_block_path_not_found`.

Output: the standard mutation envelope. `changed_fields` is always
`["content"]` — a block path is positional detail and must not enter the
audit row, which records field names only.

Annotations: `readonly: false`, `destructive: true`, `idempotent: false`.

### `wp-content-bridge/preview-update-block`

Accepts the exact `update-block` input contract and applies the same policy,
concurrency check, path resolution, `expected_block_name` assertion, and
block-markup validation, but never writes. Returns bounded current/prospective
`post_content` and `writes_performed: false`. It takes no `AuditLog`
dependency at all, so it cannot record a mutation row even by accident
(ADR 0021).

Output (`schema_version, writes_performed, post_id, post_type, version_token,
changed_fields, current_content, preview_content, provenance`):
`current_content`/`preview_content` are each the whole `post_content` — before
and after the parse/splice/serialize round trip — since that round trip can
itself change what would actually be stored, and block-type registration is
specific to the site.

Annotations: `readonly: true`, `destructive: false`, `idempotent: true`.

### `wp-content-bridge/update-block-attributes`

Shallow-merges a JSON object into one block's `attrs`, addressed by tree
`path`, leaving every other block — and every other field of the addressed
block — byte-identical by construction. `serialize_blocks()` performs the
JSON encoding, so the caller never hand-writes delimiter JSON, which is where
escaping mistakes live (ADR 0022). Shares `update-block`'s gates exactly.

Inputs are identical to `update-block` except `block_markup` is replaced by:

- `attributes: object` (required, ≤ 50 top-level keys, ≤ 100,000 bytes of
  canonical JSON encoding) — a shallow overlay onto the block's existing
  `attrs`. A key absent from `attributes` is left untouched; a key present
  with value `null` removes that key from `attrs`; any other value sets it.

`expected_block_name` is required exactly as for `update-block`. A freeform
node has no `attrs` to merge into, so the request fails closed with
`wpcb_block_mismatch` even when `expected_block_name: null` correctly
identifies it. There is no preview: `get-block-tree` with `include_attrs:
true` already returns the current attributes, the caller holds the new
values, and a documented shallow merge is something it can compute itself —
this is the same preview-justification test that cut three previews before
0.4.0.

Output: the standard mutation envelope; `changed_fields` is always
`["content"]`.

Error codes: `wpcb_invalid_input`, `wpcb_conflict`, `wpcb_forbidden`,
`wpcb_content_unavailable`, `wpcb_block_path_not_found`,
`wpcb_block_mismatch`, `wpcb_write_failed`, `wpcb_internal_error`. Never
`wpcb_invalid_blocks` — it never accepts raw block markup.

Annotations: `readonly: false`, `destructive: true`, `idempotent: false`.

### `wp-content-bridge/get-service-schema`

Reads the independently saved provider configuration for one target. It
requires `post_id`, `wpcb_manage_seo`, native `edit_post`, configured
`update_seo` policy, global writes, and the compatible provider. Output includes
the target identity, current `version_token`, strict `service_schema`, and
provenance. It performs no mutation.

Annotations: `readonly: true`, `destructive: false`, `idempotent: true`.

### `wp-content-bridge/preview-update-service-schema`

Accepts the exact `update-service-schema` input contract, including required
`post_id` and current `version_token`, and at least one mutable field. It checks
policy, provider support, and optimistic concurrency, then returns
`current_service_schema`, provider-sanitized `preview_service_schema`,
`changed_fields`, and `writes_performed: false`. No metadata,
audit row, revision, or cache state is changed.

Annotations: `readonly: true`, `destructive: false`, `idempotent: true`.

### `wp-content-bridge/update-service-schema`

Writes a provider-neutral, structured `Service` configuration through the
optional standalone IsuDev Schema Extended adapter. Registration occurs only
when `wpcb_writes_enabled` is true and the plugin's loaded public
`Meta_Fields` API passes compatibility checks. The Ability is therefore absent
from WordPress and MCP discovery when the dependency is inactive.

Inputs:

- `post_id: integer` and current `version_token: string` (required);
- `enabled?: boolean`;
- `name?`, `service_type?`, `catalog_name?: string` (maximum 191 characters);
- `description?: string` (maximum 2,000 characters);
- `areas?: [{type, name}]` (maximum 100), where `type` is exactly `City`,
  `AdministrativeArea`, or `Country`;
- `brands?: string[]` (maximum 50 unique values, 191 characters each);
- `offers?: [{name, description?}]` (maximum 20 unique names; descriptions are
  capped at 1,000 characters).

At least one mutable field is required. Omission leaves a field unchanged;
empty strings and empty arrays are explicit clear operations. The contract maps
to `Service`, `areaServed`, `brand`, and `hasOfferCatalog`; it never accepts raw
JSON-LD, caller-selected meta keys, Schema IDs, URLs, or provider storage
objects.

Execution requires `wpcb_manage_seo`, native `edit_post`, configured
`update_seo` policy for the post type, and optimistic concurrency. The provider
must also list the target post type as Service-capable. Values are normalized
before the first write. If WordPress rejects a later field, already-written
keys are restored best-effort from a pre-write snapshot. Success returns the
standard mutation envelope plus strict `effective_service_schema` values re-read
through the provider API. Audit records contain field names only, and the
normal post-scoped cache invalidation runs after success.

Annotations: `readonly: false`, `destructive: true`, `idempotent: false`.

### `wp-content-bridge/get-custom-schema`

Reads the Custom Schema configuration through Schema Extended's public
`Integration_API` contract. Input requires `post_id`. Output includes the
current content `version_token`, editable `source`, `enabled`, normalized
validation nodes and diagnostics, `save_allowed`, `render_eligible`, and
provider provenance. Structural source validation does not resolve page
placeholders or execute a speculative Yoast render, so
`validation.context_resolved` is false. That is not a failure signal, and it
can accompany `valid: true`.

Output also carries `target`: the post's `title`, `slug`, `url`, `status`,
`published_at`, `modified_at`, and authorized featured-image identity. These are
the fields a JSON-LD document is normally built from (`name`, `url`,
`datePublished`, `dateModified`, `image`), and returning them here is what makes
a single-page schema edit one call instead of a schema read plus a content read.
The permalink is `null` when WordPress cannot resolve one for the post's current
status, so a caller must not treat it as always present.

`target` deliberately omits the excerpt. Generating one renders the post's
blocks when no manual excerpt exists, which is the expensive read on this path,
and this projection exists to stay cheap. The merged Yoast graph is deliberately
omitted too: it requires the loopback front-end fetch that `get-url-seo`
performs, which is the one measured slow read in this plugin.

Annotations: `readonly: true`, `destructive: false`, `idempotent: true`.

### `wp-content-bridge/preview-update-custom-schema`

Accepts the exact `update-custom-schema` input contract: required `post_id` and
current `version_token`, plus at least one of `enabled` or `source`. Omitted
fields retain their current saved values. The preview checks policy and
optimistic concurrency, validates the prospective source through the provider,
and returns current plus prospective configurations with
`writes_performed: false`. Invalid source is reported rather than thrown so an
agent can repair it. No metadata, audit row, revision, or cache state is
changed.

Annotations: `readonly: true`, `destructive: false`, `idempotent: true`.

### `wp-content-bridge/update-custom-schema`

Writes only `enabled?: boolean` and `source?: string` through the optional
Schema Extended `Integration_API` contract version 1.0. Source is valid UTF-8,
contains no null bytes, and is limited to 100,000 bytes. Schema Extended owns
JSON parsing, a 20-node and depth limit, allowed placeholders, context and ID
rules, and final Yoast graph integration. Invalid source can be saved only when
disabled; enabled invalid JSON fails with `wpcb_invalid_custom_schema` and
bounded diagnostics.

Registration requires global writes and a compatible provider. Execution also
requires `wpcb_manage_seo`, native `edit_post`, configured `update_seo` policy,
and the current content `version_token`. The adapter never accepts meta keys,
provider method names, PHP callbacks, script markup, or an unbounded graph.
Audit stores only changed field names and never JSON source. Success returns the
standard mutation envelope plus `effective_custom_schema` re-read through the
provider. The authoritative complete graph is then available through the
existing `get-url-seo` Ability, avoiding a duplicate full-graph endpoint.

Annotations: `readonly: false`, `destructive: true`, `idempotent: false`.

### `wp-content-bridge/create-media`

Fetches one image from a remote URL and stores it in the media library. See
ADR 0031. Input requires `source_url` and `idempotency_key`; `title`,
`alt_text`, and `caption` are optional.

**SSRF.** The site makes an outbound request on the caller's instruction, so the
URL goes through `wp_safe_remote_get()`, which applies
`wp_http_validate_url()` — and core re-applies it to every redirect target. That
refuses loopback, `10/8`, `172.16/12`, `192.168/16`, `169.254/16` (the cloud
metadata range), `100.64/10`, multicast, the reserved space, embedded
credentials, and any port outside 80/443/8080. We use core's implementation
rather than a hand-rolled filter because core's is maintained and covers more.

Three residual gaps are recorded in the ADR rather than papered over: DNS
rebinding (validation resolves, then the request resolves again — not fixable
from userland PHP), same-host URLs skipping the IP checks by core's design, and
IPv6 not being range-checked (in practice refused, not waved through).

**File type comes from the bytes.** The URL, its extension, and the response
`Content-Type` are untrusted hints. `wp_check_filetype_and_ext()` sniffs the
downloaded file, and the allowlist is raster images only: JPEG, PNG, GIF, WebP,
AVIF. **No SVG** — it is an XML document that can carry script and would be
served from the site's own origin. The allowlist is not an input parameter, so a
caller cannot widen it. A valid image served under the wrong extension is stored
under the extension its bytes imply.

The byte ceiling (12 MiB) is checked against the declared `Content-Length` when
present *and* against the real body, because `Content-Length` is a claim.

**`idempotency_key` is required, not optional.** A repeated call with the same
key returns the attachment the first call created and performs no fetch. Unlike
a duplicate draft, a duplicate upload consumes storage, regenerates every
image size, and stays invisible until someone opens the media library — and the
transport has been observed returning 504 *after* doing the work. The key is
scoped per principal.

**It only creates the attachment.** It does not attach it to a post, set it as a
featured image, or insert it into content. Placement stays with
`update-featured-image` and its own policy, capability, and version-token
checks.

Every refusal stage returns the **same** public error. Telling a caller whether
a host resolved, answered, or answered with the wrong bytes is the
reconnaissance an SSRF attempt is after. The audit row never records the source
URL.

Registration requires media reads, `wpcb_writes_enabled`, and the separate
`wpcb_media_uploads_enabled` (off by default). Execution requires the new
`wpcb_upload_media` capability **and** native `upload_files`; the plugin
capability is deliberately not `wpcb_edit_content`, because a principal that may
edit text is not thereby one that may put files on the server.

Annotations: `readonly: false`, `destructive: false`, `idempotent: false`.
Not destructive because creating an attachment loses nothing (ADR 0028). Not
idempotent because the *operation* is not — annotating it otherwise would tell a
client that blind retries are safe, which is what the key exists to prevent.

Imported images keep their EXIF, including GPS where present. WordPress does not
strip it and neither do we; that would be a separate decision.

### `wp-content-bridge/update-featured-image`

Assigns an existing image attachment as one post's featured image, or removes
the current one. Input requires `post_id`, `version_token`, and `attachment_id`.

`attachment_id` is **required and nullable**, not optional. Removing a featured
image and leaving it alone are different intents, and an omitted key cannot
express the first without risking the second on a malformed request. `null`
means remove.

It never uploads, imports, or fetches anything. The attachment must already
exist, and the ability refuses any attachment that is not an image or that the
acting principal cannot read. That gate exists because WordPress does not
provide one: `set_post_thumbnail()` accepts any attachment ID — a PDF, a private
upload, or an ID that is not an attachment at all — and themes then render the
result in a public image slot.

An absent attachment, a non-image, and an unreadable one all return the **same**
refusal, so the response cannot be used to probe which attachment IDs exist or
which are private. The version token is checked *before* the attachment is
examined, for the same reason: a caller without a current token cannot probe at
all.

Registration needs three switches, not one: media reads, the content-writes
master switch, and `wpcb_media_writes_enabled`. Enabling content writes does not
imply consent to mutate media. Execution additionally requires
`wpcb_edit_content`, native `edit_post` on the target, and the per-post-type
`update_featured_image` policy.

Output is the standard mutation envelope plus `featured_image`: the attachment
in effect after the write, re-read from storage, or `null` when none is
assigned. Both writes are confirmed by re-reading rather than by trusting the
WordPress return value, because a filter on `update_post_metadata` can
short-circuit a write while the call still reports success. A repeated removal
succeeds rather than erroring, since `delete_post_thumbnail()` returns `false`
both when nothing was assigned and when a write genuinely failed.

A featured image is postmeta and the post row is untouched, so the version token
only moves because the token covers postmeta. A chained caller must use the
token the write returns.

Annotations: `readonly: false`, `destructive: true`, `idempotent: true`.
Destructive because an assignment replaces the previous image and a `null`
removes one, neither recoverable from the request.

### `wp-content-bridge/trash-content`

Moves one eligible content object to reversible WordPress trash. The ability is
registered only when both `wpcb_writes_enabled` and `wpcb_trash_enabled` are
true. It requires `wpcb_delete_content`, native `delete_post`, configured Read
and Trash policy for the post type, and a current optimistic-concurrency token.

Inputs:

- `post_id: integer` (required);
- `version_token: string` (required), from `get-content`.

The ability rejects `trash`, `auto-draft`, and `inherit` source states. It
fails with `wpcb_trash_unavailable` when WordPress trash retention is disabled,
so `wp_trash_post()` can never fall back to permanent deletion. Success returns
the standard mutation envelope with `status: trash` and `changed_fields:
["status"]`. WordPress revision saving, redacted audit, and post-scoped cache
invalidation run through the mutation infrastructure. Permanent deletion is not
included; see `restore-trashed-content` below for the reverse operation.

Annotations: `readonly: false`, `destructive: true`, `idempotent: false`.

### `wp-content-bridge/restore-trashed-content`

The mirror image of `trash-content` — same registration gate
(`wpcb_writes_enabled` and `wpcb_trash_enabled`), same capability shape
(`wpcb_delete_content` plus native `delete_post`), same per-post-type Trash
policy, same optimistic-concurrency and audit/cache-invalidation
infrastructure. It exists to undo `trash-content`, which shipped in 0.1.5 with
no reverse operation.

Inputs:

- `post_id: integer` (required), currently in `trash`;
- `version_token: string` (required), from `get-content`.

The ability requires the target's current status to be `trash`; any other
status is the non-enumerating `wpcb_invalid_state` failure. WordPress stores
the pre-trash status in `_wp_trash_meta_status`. The ability restores to that
recorded status only when it is one of `draft`, `pending`, or `private`; a
missing, unparseable, or `publish`/`future` recorded status all fall back to
`draft`. **Restoration can never reach `publish` or `future`** — republication
is a separate, still-unimplemented contract
(`transition-content-status`, `0.7.0`) gated behind the publication switch and
`wpcb_publish_content`, and this ability must never become a way around that
gate. The adapter sets the intended status explicitly through the
`wp_untrash_post_status` filter rather than relying on `wp_untrash_post()`'s
own default (which has changed across WordPress versions), and verifies the
effective status on re-read before returning it. Success returns the standard
mutation envelope with the resulting `status` (`draft`, `pending`, or
`private`) and `changed_fields: ["status"]`.

There is no preview intent. A caller already knows the one post ID it is
sending; the response reports a status it could not have derived or changed
the value of, which fails the roadmap's preview justification test (see "When
a preview Ability is justified" in `docs/plan/EDITORIAL_OPERATIONS_ROADMAP.md`).

Annotations: `readonly: false`, `destructive: false`, `idempotent: false`.

### `wp-content-bridge/transition-content-status` (planned)

Per ADR 0015, this replaces the never-released `publish-content` plan. It will
perform runtime-validated transitions from an explicit per-type transition
graph rather than accept an arbitrary registered WordPress status.

Inputs will include `post_id`, `version_token`, `target_status`, and
`publish_at` only for scheduling. Editorial transitions require
`wpcb_edit_content` and native `edit_post`; transitions to `publish` or `future`
additionally require `wpcb_publish_content`, native `publish_post`, and the
off-by-default `wpcb_publish_enabled` flag. Internal statuses and `trash` are
excluded. Draft creation and scheduling remain two separate calls.

## llms.txt abilities

Four abilities manage the published `/llms.txt` document (ADR 0023). All four
require the dedicated `wpcb_manage_llms` capability. `get-llms-txt` is always
registered; the three writes are withheld entirely while the off-by-default
`wpcb_llms_enabled` flag is false, so a disabled feature is not merely
unauthorized but absent.

Unlike every other ability here, these govern an **unauthenticated public
surface**. The document they produce is served to anyone, with no capability
check on the read path — see "The unauthenticated public surface (`/llms.txt`)"
in `docs/architecture/SECURITY.md`, which states plainly which of this
document's other guarantees do not apply there.

### `wp-content-bridge/get-llms-txt`

Returns the stored configuration, a summary of the current snapshot (content
hash, generation time, byte and link counts, warnings), the ownership state,
and a `version_token` for optimistic concurrency. Never returns a filesystem
path, including when reporting that another owner's physical file exists.
Ownership additionally reports legacy `llms-full.txt` / `llms-docs` presence
and whether the bridge rewrite route is routable. `verify_public_endpoint`
performs a bounded same-site check even before configuration exists.

Annotations: `readonly: true`, `destructive: false`, `idempotent: true`.

### `wp-content-bridge/preview-update-llms-txt`

Returns the document a given configuration would produce, plus a diff against
the stored one. Writes nothing and is deterministic for unchanged source.

Annotations: `readonly: true`, `destructive: false`, `idempotent: true`.

### `wp-content-bridge/update-llms-txt`

Replaces the configuration and regenerates the snapshot. Requires a
`version_token`; a stale token is rejected before any write. `site_url` is not
an input — it is derived from `home_url()` at the composition root, because a
caller-supplied origin would let a principal holding only `wpcb_manage_llms`
publish links to a foreign host inside a document written for LLM consumption.
The successful mutation result includes the ownership state re-read after the
write, so clients do not have to infer publication readiness from a stale
pre-write diagnostic.

Annotations: `readonly: false`, `destructive: true`, `idempotent: false`.
`destructive` changed from `false` in 0.8.4 under ADR 0028: the input is a
complete prospective configuration, so a field absent from the request is a
field removed. The HTTP method is unaffected — POST before and after.

### `wp-content-bridge/regenerate-llms-txt`

Rebuilds the snapshot from current content under the stored configuration.
Idempotent for unchanged source and configuration: an unchanged rebuild does
not churn the stored hash or generation time. This is also the manual escape
hatch for eligibility changes the debounced triggers do not observe, such as a
sitewide Yoast indexing setting.

Annotations: `readonly: false`, `destructive: false`, `idempotent: true`.

Filesystem ownership adoption is intentionally not an Ability. It is a local
wp-admin operation requiring native plugin-management authority; MCP principals
cannot rename or select files.

The wp-admin screen may also create the first conservative configuration and
snapshot from site-owned settings and the existing Read policy. That shortcut
calls the same `UpdateLlmsTxt` / `RegenerateLlmsTxt` application services but is
not registered as another Ability: it accepts no configuration input, does not
enable publication, and exists only to remove a circular operator prerequisite
from the local ownership workflow.

## Redirect abilities

Two abilities, both requiring `wpcb_manage_redirects` and the
`wpcb_redirects_enabled` switch. The write additionally requires the
`wpcb_writes_enabled` master switch, and the selected backend's own authority:
Redirection through its scoped permission filters, Yoast SEO Premium through
its native `wpseo_manage_redirects` — which the Yoast adapter has to check
itself, because Yoast's redirect manager checks no capability at all.

**The premise these two are shaped by:** a site can run Redirection *and*
Yoast Premium at the same time, and many do. When it does, **both engines
serve redirects** — Yoast's handler is gated on `! is_admin()` and
Redirection's module hooks the front end too — and whichever attaches first
wins. So a single "active provider" is a fiction for reads and for conflict
detection, even though a write must still land in exactly one backend.

### `wp-content-bridge/search-redirects`

Read. Input is one exact site-relative `source` path; there is deliberately no
`provider` parameter, because a read spans every available provider.

The result is **per provider**, not merged. Each entry carries the provider's
identity and version plus a `state`:

| State | Meaning |
|---|---|
| `claimed` | A readable rule exists in this provider. |
| `free` | This provider answered, and holds nothing for this path. |
| `not_representable` | A rule exists that this plugin's contract cannot express — a regex-format rule, an off-site target, or a status outside `301`/`302`/`410`. **The path is taken.** |
| `unavailable` | The provider could not answer. Also **not** "free". |

`held_by` lists the providers holding the path in any form a write must
respect, and `held_by_multiple` flags the routing hazard that neither plugin's
own screen shows. `configured_providers` lists every configured provider,
available or not, so "no provider is installed" stays distinguishable from
"no redirect exists".

Only `claimed` and `free` are safe to act on. One provider holding an
unreadable rule never blanks out another provider's readable answer.

### `wp-content-bridge/create-redirect`

Write. `provider` is **required and never inferred** — on a two-plugin site
that choice decides which engine's rule actually fires, so guessing it would
make the result unpredictable in exactly the case where it matters most. A
write addressed to an unavailable provider is refused, never redirected into
the other one.

Input: `provider`, `source`, `target` (required unless `status` is `410`, and
rejected when it is), and `status` from `301`/`302`/`410`, defaulting to `301`.
The result is the rule **as the provider stored it**, not as the caller asked
for it — the two differ, because Yoast stores a plain origin with both slashes
trimmed while Redirection keeps the leading slash.

Before anything is written, the provider-neutral guard clears the candidate
against **every** available provider:

- **Collision** — a source claimed by *any* available provider is refused, and
  the rejection names which one holds it. Checking only the addressed provider
  is unsound on a two-plugin site: the write would "succeed" while the other
  plugin's rule keeps firing.
- **Chain and loop bound** — the target is resolved through all providers' rules
  up to three hops. `/a → /b` in one plugin and `/b → /a` in the other is a
  loop neither plugin can see alone.
- **Live-content shadow** — a source that is currently served content is
  refused. This covers the site root and every public post-type archive
  explicitly, because `url_to_postid()` answers `0` for the root: relying on it
  alone let a redirect be created **on `/`**, found by a live probe before
  release. Term archives and other rewrite-driven routes are still not
  resolved; the reserved list and operator review are the defence there.
- **Reserved paths** — `wp-json/`, `wp-admin/`, `wp-content/`, `wp-includes/`,
  `feed/`, `wp-login.php`, `wp-cron.php`, `wp-signup.php`, `wp-activate.php`,
  `xmlrpc.php`, `wp-sitemap*`, `robots.txt`, and this plugin's own `llms.txt` /
  `llms-full.txt`.

Error codes: `wpcb_invalid_input`, `wpcb_forbidden`,
`wpcb_redirect_source_rejected`, `wpcb_redirect_rule_not_representable`,
`wpcb_redirect_provider_unavailable`, `wpcb_write_failed`.

Annotations: not `destructive` — it adds routing and loses no content or
configuration the caller did not supply (ADR 0028) — and not `idempotent`,
because a second identical call collides with the rule the first one created.

The audit row records field **names** only (`source`, `target`, `status`,
`provider`) with `object_type` `redirect` and no object ID, since a redirect is
not a post.

### `wp-content-bridge/update-redirect`

Write. Changes the `target` and `status` of the rule answering an existing
`source`, in the named `provider`. **The source is not changeable here**:
moving a rule to a different source needs the full candidate guard an update
deliberately skips, so that is a delete plus a create.

The guard is narrower than on create, and the narrowing is the point.
Collision and the live-content shadow are **not** re-checked: the rule already
exists at that source, so the source is by definition already claimed, and
re-running those checks would make every existing rule impossible to fix —
which matters most for the rules that need fixing. The cross-provider
chain/loop bound still applies to the new target.

Updating a source the named provider does not hold is an error, never silently
turned into a create.

### `wp-content-bridge/delete-redirect`

Write, and **`destructive`** under ADR 0028: the rule's target and status are
configuration the caller did not supply, and removal is not reversible from
this plugin.

Removal, not disabling. Yoast Premium stores no per-rule enabled flag, so a
rule it holds is always live and a "disable" operation could not mean the same
thing in both backends; one operation that means the same everywhere is worth
more than two that quietly differ.

Only the named provider is touched, even when both engines hold the same path —
a caller cleaning up one engine must not have the other's rule removed
underneath it. Removing a rule that is not there is an error, not a quiet
success: success would tell a caller the path is clear when another engine may
still hold it. Both adapters confirm removal by re-reading the provider rather
than trusting its "rows touched" answer, so `deleted` is only ever `true`.

## Status transition abilities

Two abilities implement ADR 0015's semantic status workflow, shaped by ADR 0024.
`create-draft` remains draft-only and `update-content` never accepts a status,
so these are the only way content changes state.

Authorization here is deliberately layered more heavily than elsewhere, because
publication is the one editorial act an automated principal should not acquire
by accident.

### `wp-content-bridge/get-status-transitions`

Always registered. Requires `wpcb_read_content`, native `edit_post`, and the
per-type Read policy; an object the caller cannot see gets the same
non-enumerating failure as every other read.

Returns the current status, the permitted target statuses from it under the
configured pair allowlist, and for each target which gates the caller currently
satisfies and whether `publish_at` is required. Also returns the site timezone
with its current UTC offset, and whether scheduled publication can actually run
on this site — a site with `DISABLE_WP_CRON` and no alternate runner can reach
`future` but will never publish it.

Per-target gate semantics are worth stating because they are easy to misread:
for a target that does not need the publication gates, `requires_publish_gates`
is `false` and the three gate booleans report `true` regardless of the real flag
value. They mean "does this gate block *this* target", not "what is this flag
set to". A client must read `requires_publish_gates` before interpreting them.

Annotations: `readonly: true`, `destructive: false`, `idempotent: true`.

### `wp-content-bridge/transition-content-status`

Registered only while `wpcb_writes_enabled` is true. Inputs are `post_id`,
`version_token`, `target_status`, and `publish_at` only when the target is
`future`.

Seven gates run before any write: the object is readable; the per-type
`transition_content_status` policy allows it; `wpcb_edit_content` and native
`edit_post` are held; the exact `(current, target)` pair is in the allowlist for
that post type; for `publish` and `future`, `wpcb_publish_enabled`,
`wpcb_publish_content` and native `publish_post` are all satisfied; the
`version_token` is current; and `publish_at` is present, parseable and strictly
in the future for `future`, absent otherwise.

`publish_at` is validated before the write rather than trusted to fail safely.
Measured on WordPress 7.0.2, `wp_update_post()` asked for `future` with a past
date stores `publish` — the content goes live, which is the opposite of what the
caller wanted.

The response reports the status read back from storage. Where WordPress stored
something other than the target, the ability fails and names what was stored.
That check detects but does not roll back; the seventh gate is what keeps the
path unreachable.

Annotations: `readonly: false`, `destructive: false`, `idempotent: false`.

## Versioning

- Additive optional fields may be a minor plugin release.
- New required inputs, removed fields, semantic changes, or renamed ability IDs require a major contract version/ability migration.
- Every response includes `schema_version` so clients can reject incompatible payloads.

The current SEO normalization schema is `1.3`. Schema 1.2 added
`module_versions`, detailed Premium keyphrases, and resolved Local businesses;
1.3 adds configured advanced robots flags and social attachment IDs. No raw
Yoast Premium/Local options or licensing state are exposed.

## Input typing over REST (WordPress 7.1)

WordPress 7.1 coerces run-endpoint input to the ability's declared input schema.
It is not opt-in. The contract below was **measured** on WordPress 7.1 against
these schemas, not read from a dev note, and it is pinned by
`tests/Integration/rest-input-coercion-verification.php`.

Core applies `rest_sanitize_value_from_schema()` as the `sanitize_callback` of
the run endpoint's `input` argument, and — this is the part that makes it safe —
**only when `validate_input()` already accepts the raw input**, falling back to
the raw input if sanitization surfaces any error
(`WP_REST_Abilities_V1_Run_Controller::coerce_input_to_schema()`). Consequences
for this plugin's contracts:

- **The domain contract is unchanged and still strict.** Coercion happens at the
  REST boundary. `WP_Ability::execute( array( 'post_id' => '123' ) )` called
  directly still fails with `wpcb_invalid_input`. Do not relax a schema or a use
  case on the assumption that input arrives coerced.
- **Over REST, a numeric string now reaches the use case as an integer**, so
  requests that previously failed inside our code now succeed. This widens what
  REST callers may send; it does not widen what they may *do*.
- **Every bound still holds.** `per_page=0`, `per_page=101`, `page=0`, and an
  over-length `author_ids` list are all still rejected as invalid input, because
  an uncoercible or out-of-bounds value never reaches the sanitizer.
- **`type: array` fields accept the comma-separated form.** `post_types=post,page`
  resolves to exactly the same result as `["post","page"]`, and a single value
  resolves to a one-element list.
- **`type: string` fields are never split.** A search phrase containing a comma
  arrives intact.
- **Nested objects get no magic.** A comma string offered where `taxonomy`
  expects objects is rejected.
- **Coercion is not authorization.** A principal without the capability is still
  refused (`403 rest_ability_cannot_execute`) on a perfectly coercible request.

## Error codes and HTTP status

Error codes are stable public API, and since 0.8.4 so is the HTTP status each
one answers with. Every `WP_Error` an ability returns is built by
`Adapter\Abilities\AbilityError::create()`, which attaches the status from one
central map; WordPress respects a status already present in the error data and
defaults to 500 only when none is set.

**This replaced a defect, found while verifying 7.1: all 86 error returns
answered HTTP 500.** A bad `post_types` value, an unknown `post_id`, and an
invalid selector were indistinguishable from a server fault, so agent clients
retried ordinary refusals as transient and monitoring read them as an outage.

| Status | Meaning | Codes |
|---|---|---|
| 400 | The request is wrong: malformed input, an unusable reference inside the input, or an address that does not resolve within the addressed object | `wpcb_invalid_input`, `wpcb_invalid_selector`, `wpcb_invalid_blocks`, `wpcb_invalid_custom_schema`, `wpcb_block_mismatch`, `wpcb_block_path_not_found`, `wpcb_seo_field_unsupported`, `wpcb_seo_image_unavailable`, `wpcb_redirect_source_rejected` |
| 403 | The principal is not permitted | `wpcb_forbidden` |
| 404 | The addressed object does not exist **or is not visible to this principal** | `wpcb_content_unavailable`, `wpcb_media_unavailable`, `wpcb_pattern_unavailable` |
| 409 | Stored state conflicts with the request; re-read and retry | `wpcb_conflict`, `wpcb_invalid_state`, `wpcb_redirect_rule_not_representable` |
| 413 | A declared payload bound would be exceeded | `wpcb_content_too_large`, `wpcb_pattern_content_too_large` |
| 501 | This install cannot implement the operation because an optional provider or a WordPress feature it needs is absent. Deliberately not 503: nothing is overloaded and retrying will not help | `wpcb_service_schema_unavailable`, `wpcb_custom_schema_unavailable`, `wpcb_seo_data_unavailable`, `wpcb_trash_unavailable`, `wpcb_redirect_provider_unavailable` |
| 500 | The plugin or WordPress failed | `wpcb_internal_error`, `wpcb_write_failed` |

A denial caught by `permission_callback` never reaches this map — WordPress
answers those `403 rest_ability_cannot_execute` itself.

The 404 row is a deliberate conflation: "does not exist" and "exists but is not
visible to you" answer identically, so a status code cannot be used to enumerate
content. See `SECURITY.md`.

An unmapped code answers 500 rather than guessing a 4xx that would blame the
client. `AbilityErrorTest` discovers the vocabulary from the source — the
literals passed to `AbilityError::create()` plus the application layer's
`error_code()` returns — and fails both when a code has no status and when the
map carries a code the source cannot produce. Adding an error code without a
status is therefore a failing test, not a silent 500.

### Versioning note

The status per code is part of the contract. Changing one is a client-visible
change: it alters what an HTTP consumer and an MCP client conclude about
whether to retry. Treat it like a schema change.

## WordPress 7.1 execution filters this plugin does not use

7.1 added filters around ability execution. They are all deliberately unused,
and this section exists so the next reader does not adopt one as an obvious
improvement. If a future change needs one, it needs an ADR that answers the
objection recorded here.

- **`wp_pre_execute_ability`** — a feature area whose flag is off is not
  registered at all (`src/Plugin.php`, `LlmsAbilities::publication_enabled()`),
  so it has no ability to short-circuit. A filter is the weaker gate: it leaves
  the ability discoverable and merely refuses it, which is exactly the "disabled
  feature advertises itself" shape this project has already rejected.
- **`wp_ability_permission_result`** — a filter that can flip an authorization
  decision is an invisible gate, and AGENTS.md mandates a `permission_callback`
  per ability precisely so authorization is readable at the registration site.
  The real asymmetry it appears to fix — `ContentAccessManager` being enforced
  inside use cases rather than in the permission layer, so a policy denial
  surfaces as an execution error — is fixed by a shared gate object usable from
  both layers, not by moving authorization into a global filter.
- **`wp_ability_normalize_input`** — input shaping belongs to the schema and the
  use case. A filter that mutates input before validation makes the published
  schema stop describing what actually ran, and the schema is public API.
- **`wp_ability_execute_result`** — output bounds, redaction, and provenance are
  built where the payload is assembled and asserted there. A late filter can
  reintroduce a leak past every test that pins a response shape.
- **`wp_ability_validate_input` / `wp_ability_validate_output`** — schemas are
  centralized in `AbilitySchemas` and contract-tested. A second validation
  channel splits one contract into two, and only one of them is documented.
- **`wp_ability_invoked`** — the one genuinely attractive hook, because a denial
  at `permission_callback` currently leaves no trace anywhere and reads are not
  audited at all. It is *not* rejected, only ungated: it needs its own ADR,
  because invocation telemetry must not share the mutation audit's table (which
  prunes at 5,000 rows, so read traffic would evict write history), must be off
  by default, and must record shapes rather than values. Task 7 of
  `docs/plan/WP_7_1_ABILITIES_ADOPTION_PLAN.md`.

## Registration metadata and exposure flags

Every ability's `meta` comes from `Adapter\Abilities\AbilityMeta` —
`read()`, `preview()`, or `write( $destructive, $idempotent )`. There are no
per-class copies of the shape.

Three exposure flags, all stated explicitly rather than inferred:

| Flag | Consumer |
|---|---|
| `show_in_rest` | WordPress core's REST listing. Kept explicit even though 7.1 would fall back to `public`, because `CLOSED_PROFILE` asserts exactly which abilities REST lists — an explicit value makes a future intentional divergence a one-line reviewable change instead of a side effect of a fallback. |
| `public` | WordPress 7.1's unified flag, resolved by core as `show_in_rest ?? public ?? false`. Declared so channels that read only `public` (AI Client function declarations, future adapters) see these abilities at all. |
| `mcp.public` | The MCP Adapter. **Not** core's `public`: different key, different nesting, different consumer. Do not consolidate the two. |

None of them authorizes anything. Exposure decides what a client may discover;
`permission_callback` plus the capability and policy gates decide what it may
execute (ADR 0025).

### What the safety annotations mean

Annotations are public API, and WordPress also derives the **expected HTTP
method** from them: `readonly` → GET, `destructive` **and** `idempotent`
together → DELETE, otherwise POST. Changing one can therefore move an endpoint's
method, not merely its advice. No ability in this plugin sets
`destructive && idempotent`, so every write is POST and every read is GET.

- **`readonly`** — the operation stores nothing. Previews are read-only: they
  compute a write's result and discard it.
- **`destructive`** — **the operation can lose content or configuration the
  client did not supply in the request** (ADR 0028). This is the definition, not
  a synonym for "writes": a whole-object replacement is destructive because a
  field absent from the request is a field removed, while creating a new object
  or moving one between states is not. Do not widen it to mean "risky" —
  publication is consequential and gated separately (ADR 0024), and it is not
  content loss.
- **`idempotent`** — replaying the same input has no additional effect.

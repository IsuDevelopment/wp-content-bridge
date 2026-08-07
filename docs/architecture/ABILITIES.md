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

`create-draft`, `update-content`, `update-seo`, `update-service-schema`,
`trash-content`, and `restore-trashed-content` are **implemented and
reachable** writes. Service-schema read and preview are separate read-only
intents under the same registration gate.
The first three writes are
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

**MCP projection:** the current reference profile contains all 21 implemented
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
`changed_fields`, and `dry_run: true`. No metadata, audit row, revision, or
cache state is changed.

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
`validation.context_resolved` is false.

Annotations: `readonly: true`, `destructive: false`, `idempotent: true`.

### `wp-content-bridge/preview-update-custom-schema`

Accepts the exact `update-custom-schema` input contract: required `post_id` and
current `version_token`, plus at least one of `enabled` or `source`. Omitted
fields retain their current saved values. The preview checks policy and
optimistic concurrency, validates the prospective source through the provider,
and returns current plus prospective configurations with `dry_run: true`.
Invalid source is reported rather than thrown so an agent can repair it. No
metadata, audit row, revision, or cache state is changed.

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
(`transition-content-status`, `0.6.0`) gated behind the publication switch and
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

## Versioning

- Additive optional fields may be a minor plugin release.
- New required inputs, removed fields, semantic changes, or renamed ability IDs require a major contract version/ability migration.
- Every response includes `schema_version` so clients can reject incompatible payloads.

The current SEO normalization schema is `1.3`. Schema 1.2 added
`module_versions`, detailed Premium keyphrases, and resolved Local businesses;
1.3 adds configured advanced robots flags and social attachment IDs. No raw
Yoast Premium/Local options or licensing state are exposed.

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

## Write abilities

`create-draft`, `update-content`, and `update-seo` are **implemented and
reachable** (Milestone 5 Plans 2–3), registered only when
`get_option( Installer::WRITES_ENABLED_OPTION )` (`wpcb_writes_enabled`) is
truthy — an ability that is not registered is invisible to Abilities
discovery and to any MCP projection. All three additionally require a plugin
capability (`wpcb_edit_content` for the first two, `wpcb_manage_seo` for
`update-seo`), the native WordPress type/object capability, and a
per-post-type policy (`ContentAccessManager`) — three independently-enforced
gates. None of the three can ever set post status to `publish`, `future`, or
`pending`.

Stable error codes shared by `create-draft`/`update-content`:
`wpcb_invalid_input`, `wpcb_conflict`, `wpcb_invalid_blocks`, `wpcb_forbidden`,
`wpcb_content_unavailable`, `wpcb_write_failed`, `wpcb_internal_error`.
`update-seo` shares the same set except `wpcb_invalid_blocks`, and adds
`wpcb_seo_field_unsupported` (a field outside the writable allowlist rejects
the whole request).

**Not yet visible to any MCP client:** the site-infrastructure MCP glue
(`wpcb-mcp-server.php` mu-plugin, and the ChatGPT-facing miniOrange OAuth
scope) still hardcode an explicit five-read-ability allowlist that has not
been updated to include any of the three write abilities — see
`docs/setup/MCP_ADAPTER.md`.

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

### `wp-content-bridge/update-seo`

Writes a fixed Yoast Free core-field SEO allowlist on one existing post
through the active `SeoWriter` (`YoastFreeSeoWriter`, version-gated to Yoast
Free 28.x exactly like the read-side `YoastSeoProvider`). It never touches
post title/body/status and never publishes.

Inputs:

- `post_id: integer` (required).
- `version_token: string` (required) — from a prior `get-content` call's
  `version_token` field; a stale token is rejected with `wpcb_conflict` and
  no SEO meta is written.
- Any subset of: `seo_title?`, `meta_description?`, `focus_keyphrase?`,
  `canonical?`, `og_title?`, `og_description?`, `twitter_title?`,
  `twitter_description?: string|null`, `robots_index?`, `robots_follow?:
  bool|null`. A key outside this allowlist (e.g. Premium/Local-only fields
  such as `schema_type`) rejects the **whole** request with
  `wpcb_seo_field_unsupported` naming the offending keys — there is no
  field-level partial application.

Output: same envelope shape as `create-draft`/`update-content`
(`schema_version, post_id, post_type, status, version_token, changed_fields,
created, provenance`) plus `effective_seo` — the same resolved SEO shape as
`get-url-seo`, re-read via `YoastSeoProvider` immediately after the write so
callers can confirm what actually landed.

Annotations: `readonly: false`, `destructive: true`, `idempotent: false`.

### `wp-content-bridge/publish-content` (planned — Plan 4)

Transitions a supported object to publish. Requires dedicated capability, feature flag, expected version, and approval-compatible request metadata. Disabled by default.

Annotations: not read-only, destructive, idempotent for an already-published unchanged object only if contract tests prove it; otherwise false.

## Versioning

- Additive optional fields may be a minor plugin release.
- New required inputs, removed fields, semantic changes, or renamed ability IDs require a major contract version/ability migration.
- Every response includes `schema_version` so clients can reject incompatible payloads.

The current SEO normalization schema is `1.1`. It adds `module_versions`,
`configured.keyphrase_details`, and `resolved.local_businesses`; no raw Yoast
Premium/Local options or licensing state are exposed.

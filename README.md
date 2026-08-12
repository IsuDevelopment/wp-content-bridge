# WP Content Bridge

WP Content Bridge is a standalone WordPress plugin that exposes controlled
content, editorial, media, block-pattern, and SEO operations through the
WordPress Abilities API.

It gives an authenticated integration a typed interface for:

- searching and reading WordPress content;
- reading configured and resolved SEO data;
- discovering editorial vocabulary and recent content;
- finding media by ID, URL, filename, or text;
- reading registered block patterns;
- creating drafts and updating Gutenberg content;
- reading a post's block structure and editing or removing one block, or
  merging attributes into it, by tree path without rewriting the whole
  document;
- updating an allowlisted set of Yoast SEO fields;
- configuring a structured Service entity, local service areas, brands, and an
  offer catalog when IsuDev Schema Extended is active;
- moving content to reversible WordPress trash;
- diagnosing whether the WordPress, Abilities API, MCP, and SEO layers are
  available.

The plugin does not bundle an AI model, authentication provider, or MCP server.
The official WordPress MCP Adapter can project selected abilities to MCP
clients, but Abilities remain the plugin's transport-neutral public API.

## Requirements

- WordPress 7.0 or newer
- PHP 8.2 or newer
- Composer dependencies included in the release package or installed locally
- Optional: Yoast SEO 28.x for normalized SEO reads and writes
- Optional: Yoast SEO Premium 28.x for keyphrase synonyms and related
  keyphrases
- Optional: Yoast Local SEO 15.x for public organization and location data
  resolved from the Schema graph
- Optional: IsuDev Schema Extended 0.2.x for structured Service entity writes
  (`PHP 8.4+` is required by that optional plugin)

## Automatic updates

Packaged installs use Plugin Update Checker to discover WP Content Bridge
releases from GitHub. Update checks initialize only in WordPress admin or cron,
and downloads use the packaged `wp-content-bridge.zip` GitHub release asset —
never GitHub's dependency-free source archive.

Self-updates are disabled automatically when the plugin directory contains
`.git`, protecting source checkouts from being overwritten. A site whose plugin
files are managed by Composer or deployment automation should also disable the
WordPress updater in `wp-config.php`:

```php
define( 'WPCB_DISABLE_SELF_UPDATES', true );
```

Site infrastructure may alternatively return `false` from
`wp_content_bridge_self_updates_enabled`. The release ZIP contains the
production updater dependency; a source checkout still requires Composer.

## Implemented abilities

All IDs use the `wp-content-bridge/` namespace. Every ability has a strict
output JSON Schema, abilities accepting input also have a strict input schema,
and every registration declares a permission callback, REST visibility, MCP
projection metadata, and `readonly`, `destructive`, and `idempotent` safety
annotations.

| Ability | Registration | WPCB capability | What it provides |
|---|---|---|---|
| `search-content` | Always | `wpcb_read_content` | Authorization-aware content search with content type, status, author, taxonomy, date, ordering, and pagination filters. |
| `get-content` | Always | `wpcb_read_content` | One content object with selected representations, relationships, featured-image ID and URL, and a concurrency token. |
| `get-block-tree` | Always | `wpcb_read_content` | One content object's Gutenberg structure as a flat, path-addressed node list, without full block markup. |
| `get-url-seo` | Always | `wpcb_read_content` | Normalized configured and resolved SEO for one readable post ID or same-origin URL. |
| `get-editorial-context` | Always | `wpcb_read_content` | Bounded post types, taxonomies, terms, authors, recent content, and public Local SEO entities. |
| `get-diagnostics` | Always | `wpcb_read_content` | Safe compatibility and availability information without secrets or server paths. |
| `get-media` | Media reads enabled | `wpcb_read_media` | Paginated media listing and exact ID, URL, filename, or text lookup. |
| `get-media-by-id` | Media reads enabled | `wpcb_read_media` | One exact, authorized attachment in a stable object envelope. |
| `list-block-patterns` | Pattern reads enabled | `wpcb_read_patterns` | Registered local block-pattern metadata and optional complete Gutenberg markup. |
| `create-draft` | Content writes enabled | `wpcb_edit_content` | Creates a draft with Gutenberg markup, excerpt, taxonomies, and optional idempotency. |
| `update-content` | Content writes enabled | `wpcb_edit_content` | Updates selected content fields with optimistic concurrency and a WordPress revision. |
| `preview-update-content` | Content writes enabled | `wpcb_edit_content` | Returns current and prospective title/content/excerpt/taxonomies for an update without writing. |
| `update-block` | Content writes enabled | `wpcb_edit_content` | Replaces exactly one block subtree, addressed by path, leaving every other block byte-identical; empty markup deletes it. |
| `preview-update-block` | Content writes enabled | `wpcb_edit_content` | Returns the whole `post_content` that `update-block` would store, without writing. |
| `update-block-attributes` | Content writes enabled | `wpcb_edit_content` | Shallow-merges a JSON object into one block's attrs, addressed by path, without hand-written delimiter JSON. |
| `update-seo` | Content writes enabled | `wpcb_manage_seo` | Updates supported Yoast fields and returns the effective SEO document after the write. |
| `preview-update-seo` | Content writes enabled | `wpcb_manage_seo` | Returns the current resolved SEO document and sanitized prospective field values without writing. |
| `get-service-schema` | Content writes enabled and Schema Extended active | `wpcb_manage_seo` | Reads the independently saved Service, areaServed, brand, and OfferCatalog configuration. |
| `preview-update-service-schema` | Content writes enabled and Schema Extended active | `wpcb_manage_seo` | Returns current and provider-sanitized prospective Service configuration without writing. |
| `update-service-schema` | Content writes enabled and Schema Extended active | `wpcb_manage_seo` | Updates a fixed Service, areaServed, brand, and OfferCatalog field set and returns the effective configuration. |
| `get-custom-schema` | Content writes enabled and Schema Extended 0.3+ active | `wpcb_manage_seo` | Reads bounded Custom Schema JSON and structural validation diagnostics. |
| `preview-update-custom-schema` | Content writes enabled and Schema Extended 0.3+ active | `wpcb_manage_seo` | Validates prospective Custom Schema without writing; reports save and render eligibility. |
| `update-custom-schema` | Content writes enabled and Schema Extended 0.3+ active | `wpcb_manage_seo` | Writes validated Custom Schema through the provider's public integration contract. |
| `trash-content` | Content writes and trash enabled | `wpcb_delete_content` | Moves an authorized object to reversible WordPress trash without exposing permanent deletion. |
| `restore-trashed-content` | Content writes and trash enabled | `wpcb_delete_content` | Restores a trashed object to its safe pre-trash status; never `publish` or `future`. |
| `get-llms-txt` | Always | `wpcb_manage_llms` | Reads the llms.txt configuration, snapshot summary, ownership state, and a concurrency token. Never returns a filesystem path. |
| `preview-update-llms-txt` | llms.txt enabled | `wpcb_manage_llms` | Returns the document a configuration would produce, and its diff against the stored one, without writing. |
| `update-llms-txt` | llms.txt enabled | `wpcb_manage_llms` | Replaces the configuration and regenerates the published snapshot under optimistic concurrency. |
| `regenerate-llms-txt` | llms.txt enabled | `wpcb_manage_llms` | Rebuilds the snapshot from current content; idempotent for unchanged source and configuration. |
| `get-status-transitions` | Always | `wpcb_read_content` | Reports one object's current status, the permitted target statuses under the configured allowlist, which gates the caller satisfies for each, and whether scheduled publication can actually run on this site. |
| `transition-content-status` | Content writes enabled | `wpcb_edit_content` (+ `wpcb_publish_content` for `publish`/`future`) | Moves one object between statuses along an administrator-configured pair, with optimistic concurrency; `publish_at` schedules and is validated before any write. |

A WPCB capability never grants access by itself. Operations also enforce the
configured policy and the matching native WordPress type or object capability.

## Ability reference

### `wp-content-bridge/search-content`

Finds content the current WordPress user is allowed to read.

Inputs include:

- free-text query;
- selected post types, statuses, and author IDs;
- up to 10 public or REST-visible taxonomy filters;
- published and modified date ranges;
- `relevance`, `date`, `modified`, `title`, or `id` ordering;
- pages of up to 100 results.

Authorization is applied before pagination. The ability scans at most 1,000
candidates and reports whether the returned total is exact. Results never
include objects already known to be unreadable.

### `wp-content-bridge/get-content`

Returns one authorized WordPress content object by positive `post_id`.

The caller can select:

- `raw`, `rendered`, and `plain_text` content representations;
- author, taxonomy, featured-media, and revision relationships.

The response includes identity, status, URL, publication and modification
dates, selected content, byte counts, provenance, warnings, and a
`version_token`. Content summaries expose both nullable `featured_image_id`
and `featured_image_url`, avoiding attachment-ID guessing.

Selected representations have a combined 2 MiB limit. Oversized content fails
explicitly instead of returning truncated Gutenberg markup.

### `wp-content-bridge/get-block-tree`

Returns one readable post's Gutenberg structure as a flat, path-addressed list
of nodes instead of its full markup. Same sensitivity as `get-content`: always
registered, and requires `wpcb_read_content`, native `read_post`, and the post
type's Read policy.

Each node carries its `path` (zero-based indices into successive
`innerBlocks` arrays), `block_name`, an `inner_blocks` child count, a bounded
`text` preview, and `text_source` (`inner_html`, `attrs`, or `null`) recording
where that preview came from. `parse_blocks()` emits `block_name: null`
freeform nodes for whitespace between blocks; these occupy real indices that a
later write mutates, so they are always included, never skipped.

Optional `path` returns a subtree instead of the whole document, and
`max_depth` bounds how deep it is walked. Raw `attrs` are opt-in via
`include_attrs` — omitted by default, since emitting them for a whole document
can produce a response larger than the content it replaces — and are
individually withheld (`attrs_omitted: true`) above a 512-byte encoded bound
per node. The response is capped at 500 nodes, with `truncated: true` when the
cap stops traversal early, and returns a `version_token` to pass to
`update-block`, `preview-update-block`, or `update-block-attributes`.

### `wp-content-bridge/get-url-seo`

Returns normalized SEO for exactly one readable `post_id` or same-origin URL.

The response separates:

- `configured` editor values;
- `resolved` public title, description, canonical, robots, Open Graph, Twitter,
  and Local business data;
- available SEO and readability analysis;
- a bounded Schema graph;
- provider identity, module versions, completeness, provenance, and warnings.

Yoast's public resolved output is preferred over database internals. Raw Yoast
options, indexables, licenses, and unrestricted `_yoast_*` metadata are never
returned.

### `wp-content-bridge/get-editorial-context`

Returns bounded site context an integration can use before planning or editing
content. It does not generate a content plan and does not call an AI model.

The caller can request any of six sections:

- post types;
- taxonomies;
- terms;
- observed authors;
- recent readable content;
- public Local SEO businesses and locations.

Post-type and taxonomy selections are limited to 20. Recent content is limited
to 50 items, and terms are limited to 100 per taxonomy. Authors are derived
from readable recent content and contain only ID and display name; this is not
a general WordPress user-enumeration endpoint.

### `wp-content-bridge/get-diagnostics`

Reports safe operational information needed to configure or troubleshoot an
integration, including:

- plugin and WordPress versions;
- Abilities API and MCP Adapter availability;
- active SEO provider and safe module availability;
- configured readable post types;
- the maximum content-representation payload size.

It does not return credentials, usernames, database details, filesystem paths,
private options, or license data.

### `wp-content-bridge/get-media`

Lists or searches attachments the current user may read. Media reads are
independently disabled by default.

The caller may use at most one selector:

- exact attachment ID;
- exact same-site original URL;
- exact filename;
- bounded text query.

With no selector, the newest authorized attachments are returned. Pages contain
up to 100 items and candidate scanning is capped at 1,000. Native `read_post`
authorization is applied before pagination.

Every media item contains exactly:

- `id`;
- `title`;
- `filename`;
- `url`;
- `alt_text`;
- `caption`;
- `description`;
- `mime_type`.

### `wp-content-bridge/get-media-by-id`

Returns one exact attachment by positive ID using the same normalized media
shape as `get-media`. Missing and unauthorized attachments intentionally use
the same unavailable response so the ability cannot be used as an enumeration
oracle.

### `wp-content-bridge/list-block-patterns`

Lists patterns already registered in the current WordPress request. Pattern
reads are independently disabled by default and require WPCB pattern access
plus native editor-level access.

Inputs support text, namespace, category, and post-type filters, pagination up
to 50 items, and optional `include_content`.

Metadata is returned by default. When content is requested, complete Gutenberg
markup is returned under a combined 2 MiB page limit; it is never silently
truncated. The ability excludes filesystem paths and unknown registry fields,
scans at most 1,000 candidates, and does not load remote WordPress.org patterns.

### `wp-content-bridge/create-draft`

Creates a `draft` in an allowed post type. There is no status input, so this
ability cannot publish or schedule content.

Inputs include:

- required post type and title;
- optional Gutenberg block markup and excerpt;
- optional bounded taxonomy assignments;
- optional per-user `idempotency_key` with a 24-hour lifetime.

Block markup is parsed and round-tripped against registered block types before
the write. Reusing an idempotency key returns the original result instead of
creating another post.

The response includes the new ID, type, `draft` status, changed field names,
creation state, provenance, and a `version_token` for later writes.

### `wp-content-bridge/update-content`

Updates one existing object's title, Gutenberg block markup, excerpt, or
taxonomy assignments. It never accepts or changes `post_status`.

The caller must provide the `post_id` and current `version_token` obtained from
`get-content`. A stale token returns `wpcb_conflict` without overwriting newer
work. Successful updates create a WordPress revision and return a new mutation
result with changed field names only.

### `wp-content-bridge/preview-update-content`

Accepts the exact `update-content` input contract and applies the same
policy, concurrency check, and block-markup validation, but never writes.
Returns current and prospective `title`/`block_markup`/`excerpt`, prospective
taxonomy assignments, `changed_fields`, and bounded machine-readable
`warnings` (`content_replaced`/`content_deleted` when block markup changes,
`taxonomies_replaced` when taxonomies are present). The response reports
`writes_performed: false`. See ADR 0021.

### `wp-content-bridge/update-block`

Replaces exactly one block subtree, addressed by the `path` returned by
`get-block-tree`, leaving every other block byte-identical by construction —
the rest of the document is never re-emitted by the caller and never
re-parsed from its output. Shares `update-content`'s gates exactly: content
writes enabled, `wpcb_edit_content`, native `edit_post`, the post type's
Update policy, and optimistic concurrency.

Inputs are `post_id`, `version_token`, `path`, `expected_block_name` — the
registered block name asserted to exist at `path`, or `null` to assert a
freeform node; **required**, not optional — and `block_markup` (an empty
string deletes the subtree). A matching `version_token` proves the document
did not change; it does not prove `path` points at the block the caller
believes it does, so `expected_block_name` is asserted separately and the
write fails closed with `wpcb_block_mismatch` when it differs. An
out-of-range `path` fails with `wpcb_block_path_not_found`; invalid
replacement markup fails with `wpcb_invalid_blocks`, checked recursively
including nested blocks; a stale token fails with `wpcb_conflict` before any
mutation.

Output is the standard mutation envelope; `changed_fields` is always
`["content"]` — the block path is positional detail and never enters the
audit row.

### `wp-content-bridge/preview-update-block`

Mirrors `update-block`'s exact input contract, policy, and concurrency check,
and reports what the whole `post_content` would become after the same
parse/splice/serialize round trip, without writing. Returns
`writes_performed: false`, `current_content`, and `preview_content`. It takes
no audit dependency at all, so it cannot record a mutation row even by
accident. See ADR 0021 and ADR 0022.

### `wp-content-bridge/update-block-attributes`

Shallow-merges a JSON object into one block's `attrs`, addressed by `path`,
leaving every other block — and every other field of the addressed block —
byte-identical. `serialize_blocks()` performs the JSON encoding, so the caller
never hand-writes delimiter JSON, which is where escaping mistakes live.

Shares `update-block`'s input contract except `block_markup` is replaced by
`attributes`, a JSON object bounded to 50 keys and a 100,000-byte canonical
encoding. A key absent from `attributes` is left untouched; a key present with
`null` is removed from `attrs`; any other value is set. `expected_block_name`
is required exactly as for `update-block`, and a freeform node — which has no
`attrs` to merge into — fails closed with `wpcb_block_mismatch` even when
`expected_block_name: null` correctly identifies it. There is no preview: the
caller already holds the current attributes from `get-block-tree` with
`include_attrs: true`, and a documented shallow merge is something it can
compute itself; see ADR 0022.

### `wp-content-bridge/update-seo`

Updates a fixed, version-tested allowlist of Yoast editor fields on one existing
post. It requires `post_id`, the current `version_token`, compatible Yoast, and
at least one supported field.

Supported fields are:

- SEO title, meta description, focus keyphrase, and canonical URL;
- robots index and follow settings;
- advanced robots noarchive, noimageindex, and nosnippet flags;
- Open Graph title and description;
- Open Graph image by readable WordPress attachment ID;
- Twitter title and description;
- Twitter image by readable WordPress attachment ID;
- Yoast Premium primary-keyphrase synonyms;
- Yoast Premium related keyphrases.

An unsupported field rejects the complete request; there is no partial
application and no generic post-meta write. After success, the plugin re-reads
SEO through the normalized provider and returns `effective_seo`, allowing the
caller to verify what actually took effect.

Advanced robots updates merge only the explicitly supplied flags. Social
images accept a positive image attachment ID, or `0` to clear the override;
caller-supplied URLs are rejected. All requested attachments are authorized and
resolved before the first SEO field is written.

### `wp-content-bridge/preview-update-seo`

Accepts the exact `update-seo` input contract, including the same allowlist
and the same optimistic-concurrency check, and normalizes each requested field
exactly as the write sanitizes it — including resolving social-image
attachment IDs — but never writes Yoast metadata. Returns `current_seo` (the
same full resolved shape as `get-url-seo`), `preview_seo` (only the present,
sanitized allowlisted field values — deliberately not a claim about resolved
public output, which does not exist until the change is rendered),
`changed_fields`, and bounded `warnings` (`field_cleared` when a field is set
to an empty string). The response reports `writes_performed: false`. See
ADR 0021.

### Service schema configuration

Configures the structured `Service` entity emitted by the optional standalone
IsuDev Schema Extended plugin. The Ability is not registered unless content
writes are enabled and a compatible plugin API is loaded. It requires
`wpcb_manage_seo`, native `edit_post`, the post type's Update SEO policy, and a
current `version_token` for preview and update.

`get-service-schema` requires `post_id` and returns the saved effective
configuration plus the current `version_token`. `preview-update-service-schema`
requires `post_id`, `version_token`, and at least one proposed field; it returns
the current and provider-sanitized prospective configurations with
`writes_performed: false`. It performs no metadata write, audit mutation, or
cache purge. `update-service-schema` remains the only mutating operation.

The input is a fixed normalized document: `enabled`, `name`, `service_type`,
`description`, typed `areas` (`City`, `AdministrativeArea`, or `Country`),
`brands`, `catalog_name`, and bounded `offers`. These map to Schema.org
`Service`, `areaServed`, `brand`, and `hasOfferCatalog`. Omission leaves a field
unchanged; an empty string or list clears that configured value. Arbitrary meta
keys and raw JSON-LD fragments are never accepted.

Success returns the standard mutation envelope plus
`effective_service_schema`, re-read through the provider's public metadata API.
The write is audited using field names only and triggers the normal post-scoped
cache invalidation.

### Custom Schema configuration

The optional Custom Schema workflow exposes three separate intents when global
writes are enabled and Schema Extended's compatible `Integration_API` contract
is active. `get-custom-schema` reads the saved `enabled` flag, editable JSON,
normalized nodes, and diagnostics. `preview-update-custom-schema` requires `post_id`,
the current `version_token`, and at least one of `enabled` or `source`; it
returns current and prospective configurations with `writes_performed: false`
and cannot write. `update-custom-schema` uses the same input and is the only
mutation.

JSON source is limited to 100,000 bytes. Schema Extended validates a single
Schema.org object, a node list, or an `@graph` wrapper with at most 20 nodes and
bounded nesting. It rejects malformed JSON, unsupported placeholders, nested
contexts, duplicate identifiers, and Yoast-owned identifiers at render time.
Invalid source may remain saved only while disabled; `save_allowed` and
`render_eligible` make that distinction explicit.

This is not a generic meta or arbitrary provider endpoint. The connector calls
only Schema Extended's public contract, enforces the Update SEO policy,
`wpcb_manage_seo`, native `edit_post`, optimistic concurrency, and redacted
audit, and never logs JSON source. After a successful update, call
`get-url-seo` with the same `post_id` to inspect the complete, context-resolved
Yoast graph; preview intentionally reports `context_resolved: false` because it
does not execute a speculative front-end render.

### `wp-content-bridge/trash-content`

Moves one current object to reversible WordPress trash. It requires:

- global content writes enabled;
- the separate destructive trash switch enabled;
- Read and Trash policy for the object's post type;
- `wpcb_delete_content`;
- native `delete_post` for the object;
- a current `version_token`.

The ability rejects `trash`, `auto-draft`, and `inherit` source states. It also
fails closed with `wpcb_trash_unavailable` when WordPress trash retention is
disabled, because `wp_trash_post()` could otherwise fall back to permanent
deletion. Permanent deletion is not exposed; trash restoration is a separate
ability below.

### `wp-content-bridge/restore-trashed-content`

Restores one currently-trashed object. It requires:

- global content writes enabled;
- the same separate trash switch `trash-content` requires;
- Read and Trash policy for the object's post type;
- `wpcb_delete_content`;
- native `delete_post` for the object;
- a current `version_token`.

The ability requires the target's current status to be `trash`; any other
status is the non-enumerating `wpcb_invalid_state` failure. WordPress records
the pre-trash status in `_wp_trash_meta_status`. The ability restores to that
recorded status only when it is `draft`, `pending`, or `private`; a missing,
unparseable, or `publish`/`future` recorded status all fall back to `draft`.
**It can never restore to `publish` or `future`** — that remains the separate,
still-unimplemented `transition-content-status` contract, gated behind the
publication switch and `wpcb_publish_content`. There is no preview intent: the
caller already holds the one input it sent and gets back one status it could
not have derived itself, which does not clear the bar for a preview Ability.

## Write safety and side effects

All implemented writes are disabled by default. Their shared behavior includes:

- strict input schemas and field allowlists;
- per-post-type Read plus operation policy;
- dedicated WPCB capabilities and native WordPress authorization;
- optimistic concurrency for existing objects;
- Gutenberg block validation where content is written;
- WordPress revisions for content updates and trash attempts;
- exactly one redacted audit event per mutation attempt that reaches the
  application service;
- audit rows containing field names, never content or SEO values;
- no implicit publication or scheduling;
- post-scoped cache invalidation after successful mutations.

Cache invalidation calls `clean_post_cache()` for the affected object. When
LiteSpeed Cache is active, the plugin also dispatches its public post-specific
purge hook. It never exposes caller-selected purge targets or performs a global
cache purge.

Stored content and SEO fields are returned as untrusted data. They are never
interpreted as agent instructions.

## Settings and integration access

The settings page is available under **Settings → WP Content Bridge** and
requires `wpcb_manage_settings`.

It provides:

- per-post-type policies for Read, Search, Create draft, Update content, Update
  SEO, Trash, and reserved status transitions;
- independent switches for media reads, block-pattern reads, content writes,
  and destructive trash access;
- assignment of a closed WPCB capability set to one existing integration user.

Unsaved `post` and `page` policies default to Read and Search. Other eligible
post types default to no access. Search and every mutation require Read; invalid
policy combinations are disabled during normalization.

The managed integration user must be an existing non-administrator with native
WordPress `read`. The settings interface can assign:

- `wpcb_read_content`;
- `wpcb_read_media`;
- `wpcb_read_patterns`;
- `wpcb_edit_content`;
- `wpcb_manage_seo`;
- `wpcb_publish_content` — reserved for future public or scheduled status
  transitions;
- `wpcb_delete_content`.

Saving replaces the managed user's exact WPCB capability set. Selecting a new
user revokes managed WPCB capabilities from the previous user without changing
unrelated WordPress capabilities. Integration-user management is disabled on
multisite.

## Status transitions

`create-draft` creates drafts and `update-content` never touches status, so
publication is impossible through either. Moving content between statuses is a
separate, semantic ability.

Transitions run against an **allowlist of ordered `from -> to` status pairs, per
post type**, which is empty until an administrator configures it. Upgrading the
plugin therefore adds no new write surface. Pairs rather than target statuses is
the point: it is what lets you permit unpublishing while withholding
publishing, which a list of allowed targets cannot express.

The five expressible statuses are `draft`, `pending`, `private`, `publish` and
`future`. `trash` is a separate ability with its own flag, and `auto-draft`,
`inherit` and plugin-defined statuses cannot be reached by configuration
mistake.

`publish` and `future` require three gates ordinary editorial transitions do
not: the off-by-default `wpcb_publish_enabled` flag, the `wpcb_publish_content`
capability, and native `publish_post`.

Scheduling takes `publish_at` in the site timezone and stores UTC. A past or
otherwise invalid value is refused **before** the write: asked for `future` with
a past date, WordPress stores `publish` and the content goes live immediately,
so degrading gracefully is not an option. Every response reports the status read
back from storage rather than the one requested.

Use `get-status-transitions` to discover what is permitted for a given object
and caller instead of guessing — it also reports whether the site can actually
run scheduled publication, which a site with `DISABLE_WP_CRON` and no alternate
runner cannot.

## The published `/llms.txt`

Off by default, and the only unauthenticated surface this plugin exposes.

While `wpcb_llms_enabled` is false no rewrite rule is registered at all, so
`/llms.txt` 404s exactly as any unknown URL does — a disabled feature is
indistinguishable from one that was never installed, rather than answering
`403` and advertising itself.

When enabled, the route serves a stored snapshot and does nothing else: one
option read, then those bytes. It never queries posts, calls an SEO provider,
generates a document, or writes anything, and a missing snapshot returns `404`
rather than being built on the request. Responses carry a strong `ETag` taken
from the document's own content hash, a `Last-Modified` from its generation
time, and a bounded `Cache-Control`; `If-None-Match` and `If-Modified-Since`
are answered with a bodyless `304`.

Draft, private, password-protected, `noindex`, and non-public-post-type content
never reaches the document. Regeneration is debounced and runs on cron, in
bounded batches on large sites, and replaces the snapshot in one step so a
reader mid-run gets the previous complete document rather than a partial one.
Un-publishing triggers regeneration, so content an author withdraws leaves the
document instead of lingering.

If Yoast's own llms.txt feature is active, or a physical `llms.txt` already
answers the path, that is reported as a blocking ownership conflict. For
artifacts left behind by a retired generator, an administrator can explicitly
complete the visible two-step workflow in **Settings -> WP Content Bridge**.
Step 1 creates a conservative initial configuration and snapshot from the core
site name, tagline, and public content types already allowed by Content Access
Read policy; it accepts no configuration fields and does not enable the public
route. Step 2 becomes available after the snapshot and route prerequisites are
ready, then renames only the known `llms.txt`, `llms-full.txt`, and `llms-docs`
targets to timestamped `.backup_*` names. It never deletes them, accepts no path
input, and is not exposed as an Ability or MCP tool. Both buttons remain visible
when unavailable and state why the next step is locked.

The bridge does not generate LLMagnet's proprietary `/llms-docs/` tree. The
current llms.txt v2 proposal instead recommends Markdown alternates beside
their canonical URLs. Adding those alternates would create a much wider public
surface and is intentionally deferred to its own architecture and security
decision.

## MCP integration

Install the official WordPress MCP Adapter and the endpoint exists — the plugin
projects its own abilities at `/wp-json/wpcb-mcp/mcp`, with no server code to
write and no list of ability names to maintain (ADR 0025). The Adapter is not
bundled, not a dependency, and never installed by this plugin; transport,
authentication, and OAuth remain external.

The tool set is discovered from the WordPress ability registry by category on
every request, so it is exactly what the plugin registered: enabling a feature
area in settings adds its abilities to discovery, and a disabled media, pattern,
write, Schema Extended, trash, llms.txt, or publication feature is absent
because it was never registered. `get-diagnostics` reports the projected set,
so a tool missing from a client can be told from an ability that does not exist.

Two optional controls narrow this: the `wpcb_mcp_server_enabled` switch turns the
endpoint off, and the `wp_content_bridge_mcp_abilities` filter removes
individual abilities. The filter can only subtract — it can never expose an
ability this plugin did not register.

An ability can still be registered and hidden from a particular client by that
client's own OAuth grant. Neither projection nor any allowlist grants authority
beyond the bound WordPress user's WPCB and native capabilities.

See [MCP Adapter setup](docs/setup/MCP_ADAPTER.md) and
[ChatGPT connector setup](docs/setup/CHATGPT_CONNECTOR.md).

## Planned and unsupported operations

`wp-content-bridge/transition-content-status` shipped in 0.7.0 and owns
editorial status transitions, publication, and scheduling. Public and scheduled
transitions additionally require the publication feature flag,
`wpcb_publish_content`, native `publish_post`, and an allowed pair from the
configured transition matrix. `create-draft` remains draft-only.

The current plugin does not provide abilities for:

- changing slugs or permalinks;
- changing author or publication date;
- permanent deletion or trash restoration;
- revision restoration;
- navigation-menu editing;
- redirects;
- media upload or metadata writes;
- featured-image assignment or removal.

## Installation from source

```bash
composer install --no-dev --optimize-autoloader
```

Activate **WP Content Bridge**, then configure its access policy under
**Settings → WP Content Bridge**. The production host must run PHP 8.2 or newer.

For development:

```bash
composer install
composer check
```

`composer check` is static: PHPCS, PHPStan, and unit tests. The gates that
matter — capabilities, per-post-type policy, optimistic concurrency, audit
redaction, cache invalidation, provider graph output — are behaviour, and are
covered by 18 runtime verifiers under `tests/Integration/` instead. Eleven need
only WordPress; the rest need licensed Yoast SEO or the private Schema Extended
plugin.

[Runtime verification](docs/setup/VERIFICATION.md) is the run book: the full
inventory, what each verifier proves, its hardest dependency, the commands, and
the date of the last complete green run. Run it before cutting a release.

Detailed contracts and architecture are documented in:

- [Abilities](docs/architecture/ABILITIES.md)
- [Content access](docs/architecture/CONTENT_ACCESS.md)
- [Security](docs/architecture/SECURITY.md)

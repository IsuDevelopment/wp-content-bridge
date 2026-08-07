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
| `get-url-seo` | Always | `wpcb_read_content` | Normalized configured and resolved SEO for one readable post ID or same-origin URL. |
| `get-editorial-context` | Always | `wpcb_read_content` | Bounded post types, taxonomies, terms, authors, recent content, and public Local SEO entities. |
| `get-diagnostics` | Always | `wpcb_read_content` | Safe compatibility and availability information without secrets or server paths. |
| `get-media` | Media reads enabled | `wpcb_read_media` | Paginated media listing and exact ID, URL, filename, or text lookup. |
| `get-media-by-id` | Media reads enabled | `wpcb_read_media` | One exact, authorized attachment in a stable object envelope. |
| `list-block-patterns` | Pattern reads enabled | `wpcb_read_patterns` | Registered local block-pattern metadata and optional complete Gutenberg markup. |
| `create-draft` | Content writes enabled | `wpcb_edit_content` | Creates a draft with Gutenberg markup, excerpt, taxonomies, and optional idempotency. |
| `update-content` | Content writes enabled | `wpcb_edit_content` | Updates selected content fields with optimistic concurrency and a WordPress revision. |
| `preview-update-content` | Content writes enabled | `wpcb_edit_content` | Returns current and prospective title/content/excerpt/taxonomies for an update without writing. |
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
`dry_run: true`. It performs no metadata write, audit mutation, or cache purge.
`update-service-schema` remains the only mutating operation.

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
returns current and prospective configurations with `dry_run: true` and cannot
write. `update-custom-schema` uses the same input and is the only mutation.

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

## MCP integration

The plugin registers domain abilities; it does not provide MCP transport or
authentication. Install the official WordPress MCP Adapter separately and
explicitly allow only the abilities required by a client.

The current source defines a closed 20-ability projection profile covering
every implemented ability. The reference site-level MCP server intersects
that profile with the abilities registered in the current request, so disabled
media, pattern, write, Schema Extended, and trash features remain absent from
discovery. Service and Custom Schema abilities additionally disappear when
their required Schema Extended public contract is inactive or incompatible.

An ability can be registered in WordPress but still hidden from a particular
MCP client by the Adapter or OAuth allowlist. Those projection allowlists never
grant authority beyond the bound WordPress user's WPCB and native capabilities.

See [MCP Adapter setup](docs/setup/MCP_ADAPTER.md) and
[ChatGPT connector setup](docs/setup/CHATGPT_CONNECTOR.md).

## Planned and unsupported operations

`wp-content-bridge/transition-content-status` is planned but not implemented.
It will own editorial status transitions, publication, and scheduling. Public
and scheduled transitions will additionally require the publication feature
flag, `wpcb_publish_content`, native `publish_post`, and an allowed transition
from the configured graph. `create-draft` will remain draft-only.

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

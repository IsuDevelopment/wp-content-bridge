# WP Content Bridge

WP Content Bridge exposes WordPress content, media, block patterns, editorial
context, and normalized SEO data through the WordPress Abilities API. It also
provides narrowly scoped draft, content, SEO, and reversible-trash mutations.

The plugin does not bundle an MCP server, authentication layer, or AI model.
The official WordPress MCP Adapter can expose selected abilities to MCP clients.

## Requirements

- WordPress 7.0 or newer
- PHP 8.2 or newer
- Composer dependencies included in a packaged release or installed locally
- Optional: Yoast SEO 28.x for SEO reads and writes
- Optional: Yoast SEO Premium 28.x for keyphrase synonyms and related
  keyphrases
- Optional: Yoast Local SEO 15.x for public business/location data resolved
  from Schema

## Abilities

All ability IDs use the `wp-content-bridge/` namespace. Every ability declares
strict JSON Schemas and is exposed to the WordPress REST projection.

| Ability | Availability | Required WPCB capability | Function |
|---|---|---|---|
| `search-content` | Always registered | `wpcb_read_content` | Searches configured post types with status, author, taxonomy, date, ordering, and pagination filters. Authorization is applied before pagination. |
| `get-content` | Always registered | `wpcb_read_content` | Returns one content object with selected raw, rendered, or plain-text representations, optional relationships, payload sizes, and a concurrency token. |
| `get-url-seo` | Always registered | `wpcb_read_content` | Returns normalized configured and resolved SEO for one readable post ID or same-origin URL. |
| `get-editorial-context` | Always registered | `wpcb_read_content` | Returns selected post types, taxonomies, terms, observed authors, recent readable content, and public Local SEO entities. |
| `get-diagnostics` | Always registered | `wpcb_read_content` | Returns safe plugin, WordPress, Abilities API, MCP Adapter, SEO-provider, payload-limit, and readable-post-type status. |
| `get-media` | `Media library reads` enabled | `wpcb_read_media` | Lists or searches authorized attachments by exact ID, same-site original URL, exact filename, or text query. |
| `get-media-by-id` | `Media library reads` enabled | `wpcb_read_media` | Returns one authorized attachment by exact ID. |
| `list-block-patterns` | `Registered block patterns` enabled | `wpcb_read_patterns` | Lists registered local block-pattern metadata and, optionally, complete block markup. |
| `create-draft` | `Content writes` enabled | `wpcb_edit_content` | Creates a draft with title, Gutenberg markup, excerpt, taxonomy assignments, and an optional idempotency key. It cannot publish. |
| `update-content` | `Content writes` enabled | `wpcb_edit_content` | Updates title, Gutenberg markup, excerpt, or taxonomy assignments using optimistic concurrency. It does not change post status. |
| `update-seo` | `Content writes` enabled | `wpcb_manage_seo` | Updates an allowlist of Yoast SEO fields using optimistic concurrency and returns the effective SEO result. |
| `trash-content` | `Content writes` and `Move content to trash` enabled | `wpcb_delete_content` | Moves one current, authorized object to reversible WordPress trash. It never performs permanent deletion. |

The required plugin capability is only one authorization gate. Content and SEO
object operations also enforce content-type policy and the corresponding native
WordPress type/object capability. Media additionally requires native
`read_post` permission for every attachment. Block patterns require native
editor-level access. Diagnostics require `wpcb_read_content` but do not access
individual content objects.

### Content search and retrieval

`search-content` supports:

- query text and selected post types/statuses/authors;
- up to 10 public or REST-visible taxonomy filters;
- published and modified date ranges;
- deterministic ordering and pages of up to 100 items.

Search inspects at most 1,000 candidates. The response states whether totals
are exact and never includes known unreadable objects in pagination totals.

`get-content` accepts a positive `post_id`, selected representations (`raw`,
`rendered`, `plain_text`), and optional `author`, `taxonomies`,
`featured_media`, and `revision` relationships. Selected representations share
a 2 MiB limit. Content summaries contain both `featured_image_id` and
`featured_image_url`; both are `null` when no readable featured image exists.
The returned `version_token` is required by update abilities.

### SEO

`get-url-seo` accepts exactly one `post_id` or same-origin `url`. Its normalized
response separates:

- configured editor values;
- resolved public title, description, canonical, robots, Open Graph, Twitter,
  and Local business data;
- available SEO/readability analysis;
- bounded Schema graph;
- provider provenance, completeness, and warnings.

`update-seo` accepts a `post_id`, current `version_token`, and any supported
subset of:

- SEO title, meta description, focus keyphrase, and canonical URL;
- robots index/follow;
- Open Graph title/description;
- Twitter title/description;
- Yoast Premium primary-keyphrase synonyms and related keyphrases.

Unsupported fields reject the complete request. The plugin never exposes or
writes arbitrary post meta, Yoast options, indexables tables, license data, or
raw Premium JSON.

### Media

Media results use object envelopes and contain exactly: attachment ID, title,
filename, original URL, ALT text, caption, description, and MIME type.
`get-media` returns up to 100 authorized items per page and scans at most 1,000
candidates. Missing and denied attachments use the same unavailable response.

Media upload, metadata updates, deletion, and featured-image assignment are not
implemented.

### Block patterns

`list-block-patterns` filters by text, namespace, category, or post type. It
returns metadata only by default and up to 50 items per page. With
`include_content: true`, complete block markup is returned under a combined
2 MiB limit. Pattern file paths and unknown registry properties are excluded;
remote WordPress.org patterns are not loaded by this ability.

### Writes

`create-draft`, `update-content`, `update-seo`, and `trash-content` are off by
default. Their shared safeguards are:

- a global write switch plus a matching per-post-type policy;
- dedicated WPCB and native WordPress capabilities;
- optimistic concurrency for mutations of existing objects;
- WordPress revisions for content updates and trash attempts;
- an audit record containing field names, not content or SEO values;
- no publication side effect;
- post-scoped cache invalidation after a successful mutation.

Cache invalidation calls `clean_post_cache()` for the changed object. If
LiteSpeed Cache is active, the plugin also dispatches its public post-specific
purge hook. It never performs a global cache purge.

`trash-content` additionally requires the per-type Trash policy, native
`delete_post`, and a current `version_token`. It refuses to execute when
WordPress trash retention is disabled, because WordPress would otherwise fall
back to permanent deletion. Already trashed, `auto-draft`, and `inherit`
objects are rejected. Restoration and permanent deletion are separate future
operations.

## Settings and access control

The settings page is at **Settings → WP Content Bridge** and requires
`wpcb_manage_settings`.

It provides:

- per-post-type policies for Read, Search, Create draft, Update content, Update
  SEO, Trash, and reserved status-transition access;
- independent master switches for media reads, block-pattern reads, content
  writes, and destructive trash access;
- assignment of the closed WPCB capability set to one existing integration
  user.

Unsaved `post` and `page` policies default to Read and Search. Other eligible
post types default to no access. Search and every write policy require Read;
invalid combinations are disabled during normalization.

The managed integration user must be an existing non-administrator with native
WordPress `read`. The interface can assign:

- `wpcb_read_content`
- `wpcb_read_media`
- `wpcb_read_patterns`
- `wpcb_edit_content`
- `wpcb_manage_seo`
- `wpcb_publish_content` (reserved for public/scheduled status transitions)
- `wpcb_delete_content`

Saving replaces that user's exact WPCB capability set. Selecting another user
revokes the managed WPCB capabilities from the previous user without changing
unrelated WordPress capabilities. Integration-user management is currently
disabled on multisite.

The status-transition policy, `wpcb_publish_content`, and the internal
publication flag are reserved for a future `transition-content-status`
ability. Publication and scheduling will require both native `publish_post`
and the extra publication gate. Configuring the reserved policy or capability
does not currently expose status changes.

## MCP integration

Abilities are the plugin's public application contract. MCP is an optional
projection. Install and configure the official WordPress MCP Adapter separately,
then explicitly allow the abilities required by the client. Adapter and OAuth
allowlists can further restrict access; they never grant authority that the
bound WordPress user does not have.

See [MCP Adapter setup](docs/setup/MCP_ADAPTER.md) and
[ChatGPT connector setup](docs/setup/CHATGPT_CONNECTOR.md).

## Not implemented

The current version does not provide abilities for publication or other status
transitions, slug/permalink changes, author/date changes, permanent deletion,
trash restoration, revision restoration, navigation-menu editing, redirects,
media writes/uploads, or featured-image assignment.

## Installation from source

```bash
composer install --no-dev --optimize-autoloader
```

Activate **WP Content Bridge** in WordPress, then configure access under
**Settings → WP Content Bridge**. The production host must use PHP 8.2 or newer.

For development:

```bash
composer install
composer check
```

Architecture and contracts are documented in
[Abilities](docs/architecture/ABILITIES.md),
[Content access](docs/architecture/CONTENT_ACCESS.md), and
[Security](docs/architecture/SECURITY.md).

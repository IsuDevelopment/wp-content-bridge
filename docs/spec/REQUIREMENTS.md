# Requirements

Requirement identifiers are stable references for code, tests, ADRs, and release notes.

## Functional requirements

### Content-type access configuration

- **FR-ACCESS-001:** Administrators can configure allowed operations independently for every eligible post type.
- **FR-ACCESS-002:** The stable operations are read, search, create draft,
  update content, update SEO, transition content status, and trash content.
- **FR-ACCESS-003:** Search and mutation policies require read policy for the same post type; invalid combinations normalize to disabled.
- **FR-ACCESS-004:** Enabling a configured operation never grants a WordPress role/object capability and never activates an unimplemented ability.
- **FR-ACCESS-005:** Post/page default to read/search; custom post types default to deny-all.
- **FR-ACCESS-006:** Internal WordPress content types and attachments are excluded unless a later explicit specification adds them.
- **FR-ACCESS-007:** An authorized administrator can select one existing,
  non-administrator WordPress user as the managed integration principal and
  assign an exact allowlisted set of operational WPCB capabilities without
  changing that user's native role or object capabilities.
- **FR-ACCESS-008:** Replacing the managed integration principal revokes only
  the WPCB operational capabilities managed by this surface from the previous
  principal. Multisite assignment remains unavailable until separately
  specified.

### Content discovery

- **FR-CONTENT-001:** Search readable posts across allowed post types.
- **FR-CONTENT-002:** Filter by post type, status, author, taxonomy, date range, modified range, and free text where WordPress supports it safely.
- **FR-CONTENT-003:** Enforce a server-side maximum page size and deterministic pagination.
- **FR-CONTENT-004:** Return stable IDs, status, title, slug, URL, excerpt, author, dates, and compact taxonomy/media summaries.
- **FR-CONTENT-005:** Never reveal an object the authenticated user cannot read.

### Content detail

- **FR-DETAIL-001:** Retrieve a content object by ID.
- **FR-DETAIL-002:** Return Gutenberg source (`raw`), rendered content, and normalized plain text as independently selectable representations.
- **FR-DETAIL-003:** Include revision/concurrency information required for a later safe update.
- **FR-DETAIL-004:** Include taxonomy terms, featured media, author summary, and public URL.
- **FR-MEDIA-001:** Expose media through a dedicated, disabled-by-default read
  policy rather than adding attachments to the general content-type catalog.
- **FR-MEDIA-002:** Support bounded media lookup by exact ID, exact same-site
  original URL, exact filename, and text query, with authorization before
  pagination.
- **FR-MEDIA-003:** Return stable object envelopes and an explicit media-field
  allowlist: ID, title, filename, URL, ALT, caption, description, and MIME type.
- **FR-MEDIA-004:** Content summaries return `featured_image_id` and
  `featured_image_url` together, or return both as null when unavailable.
- **FR-DETAIL-005:** Allow SEO inclusion without requiring a second call while keeping a standalone SEO ability for non-post URLs.

### SEO

- **FR-SEO-001:** Detect the active SEO provider without making it mandatory.
- **FR-SEO-002:** Return `configured`, `resolved`, `analysis`, `schema_graph`, and `provenance` sections.
- **FR-SEO-003:** Use Yoast's documented resolved-output APIs as the primary source.
- **FR-SEO-004:** Support a versioned allowlist for configured editor values not available through a public API.
- **FR-SEO-005:** Include Yoast Premium keyphrases/features only when detected and supported.
- **FR-SEO-006:** Represent Local SEO through effective public Schema and a normalized public business/location profile where available.
- **FR-SEO-007:** Never expose arbitrary Yoast meta/options, credentials, license data, internal caches, or tables.
- **FR-SEO-008:** Report partial/unavailable data with source and reason instead of silently fabricating defaults.
- **FR-SEO-009:** Preserve provider-native Schema objects without lossy flattening.

### Editorial context

- **FR-EDITORIAL-001:** List readable post types, taxonomies, categories/tags, and authors relevant to planning.
- **FR-EDITORIAL-002:** Provide bounded summaries of recent and existing content for topic inventory.
- **FR-EDITORIAL-003:** Return public organization/location context where the SEO provider exposes it safely.
- **FR-EDITORIAL-004:** Planning and generation remain client responsibilities in MVP.

### Writes (post-MVP)

- **FR-WRITE-001:** Create drafts independently from publishing.
- **FR-WRITE-002:** Update content only with object-level authorization and optimistic concurrency.
- **FR-WRITE-003:** Preserve WordPress revisions and return the resulting revision/object version.
- **FR-WRITE-004:** Update SEO through a narrow provider-supported field set.
- **FR-WRITE-005:** Transition status through a separate ability and explicit
  per-type transition graph. Public and scheduled targets additionally require
  a separate capability, feature flag, native publication permission, and
  approval-compatible contract.
- **FR-WRITE-006:** Emit an audit event for every attempted and completed mutation without logging secrets or full private content.
- **FR-WRITE-007:** When compatible Yoast Premium is active, update primary
  keyphrase synonyms and related keyphrases through bounded normalized lists;
  never expose the provider's positional JSON contract.
- **FR-WRITE-008:** Move content to reversible trash only through a separate,
  disabled-by-default ability requiring per-type policy, dedicated plugin
  capability, native deletion permission, optimistic concurrency, and audit.
- **FR-WRITE-009:** Trash must fail closed when WordPress would bypass trash
  and permanently delete. Restoration and permanent deletion are separate
  future operations.
- **FR-WRITE-010:** Update a structured Service entity through a bounded,
  provider-neutral field set covering typed service areas, brands, and an offer
  catalog; never accept arbitrary metadata or raw JSON-LD.
- **FR-WRITE-011:** Register the Service-schema write Ability only when global
  writes and a compatible loaded provider are both available. Execution reuses
  SEO capability, native object authorization, per-type Update SEO policy,
  optimistic concurrency, redacted audit, and post-write verification.

### Block patterns

- **FR-PATTERN-001:** The plugin shall expose a separately enabled,
  capability-gated read ability for registered block-pattern discovery.
- **FR-PATTERN-002:** Pattern discovery shall require native editor-level
  access equivalent to WordPress core and shall not trigger remote pattern
  downloads.
- **FR-PATTERN-003:** Pattern output shall use a strict object envelope,
  deterministic filtering/pagination, fixed field allowlists, bounded arrays,
  and no filesystem paths.
- **FR-PATTERN-004:** Pattern content shall be omitted by default and returned
  only when requested, complete and within a combined 2 MiB response limit.

### Diagnostics

- **FR-DIAG-001:** Report WordPress, PHP, plugin, Abilities API, MCP Adapter, SEO provider, and optional Agents API compatibility.
- **FR-DIAG-002:** Diagnostics must not expose detailed runtime secrets or unnecessary server internals.

## Non-functional requirements

- **NFR-SEC-001:** Every ability has a permission callback and explicit safety annotations.
- **NFR-SEC-002:** Authorization combines plugin capabilities with WordPress object capabilities.
- **NFR-SEC-002A:** Every content use case also enforces the configured post-type operation through the shared policy service.
- **NFR-SEC-003:** Content returned to agents is labeled as untrusted data.
- **NFR-SEC-004:** Remote transport authentication is delegated to WordPress/MCP Adapter; this plugin does not invent bearer tokens.
- **NFR-PERF-001:** All list operations are bounded and paginated.
- **NFR-PERF-002:** Heavy rendered/SEO representations are opt-in and avoid N+1 requests where possible.
- **NFR-COMPAT-001:** WordPress 7.0+ and PHP 8.2+.
- **NFR-COMPAT-002:** Core content features work without an SEO provider or MCP Adapter.
- **NFR-EXT-001:** Additional SEO providers implement a provider interface without changing ability IDs.
- **NFR-EXT-002:** Agents API remains an optional integration.
- **NFR-EXT-003:** Optional write providers are feature-detected through loaded
  public APIs and isolated behind application ports; plugin filesystem paths do
  not enter domain contracts.
- **NFR-OBS-001:** Errors use stable machine codes and include safe diagnostic data.
- **NFR-TEST-001:** Ability schemas, permissions, provider normalization, and mutation safety have automated contract coverage.
- **NFR-REL-001:** Releases are reproducible ZIPs containing production Composer dependencies.

## Assumptions

- External clients supply their own model and planning behavior in MVP.
- The official WordPress MCP Adapter handles MCP protocol and transport concerns.
- Yoast's resolved REST output is more stable than direct access to its internal indexables tables.
- Premium and Local SEO require licensed test environments or fixtures for verified compatibility.

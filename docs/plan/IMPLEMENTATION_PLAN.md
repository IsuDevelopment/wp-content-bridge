# Implementation plan

The plan uses gated milestones. A later milestone must not begin merely because the previous code exists; its acceptance criteria must pass.

## Milestone 0 — repository and specification scaffold

Status: complete.

Deliverables:

- standalone repository and plugin bootstrap;
- Composer autoloading and quality tooling;
- SDD, ADRs, AI-agent instructions;
- local symlink integration path;
- no production abilities yet.

Acceptance:

- plugin is detected and activates without warnings after Composer install;
- `composer check` passes for the scaffold;
- Kormas source/deploy files remain unchanged.

### Release update infrastructure — **code-complete 2026-08-03**

- production `yahnis-elsts/plugin-update-checker` dependency locked through
  Composer and included by the existing release workflow;
- admin/cron-only GitHub updater configured to use the packaged release asset;
- `.git` checkout protection plus constant/filter opt-outs for Composer- and
  deployment-managed sites;
- side-effect-free policy tests, Composer advisory gate, documentation, and ADR
  0018.

## Milestone 1 — read-only content core

### Milestone 1A — content access foundation

Status: complete.

Deliverables:

- stable content operation vocabulary and prerequisite rules;
- option-backed per-post-type matrix with safe defaults;
- eligible post-type catalog;
- Settings API admin table protected by `wpcb_manage_settings`;
- shared policy application service;
- ADR, access architecture, code map, agent procedure, and unit contract tests.

The write columns reserve policy only. No write ability or mutation service exists in this milestone.

### Milestone 1B — content read services and abilities

Status: complete. The DTOs, application services, WordPress repository,
capability migration, three read-only Ability registrations, bounded taxonomy
filters, authorization matrix, contract verification, and payload boundary have
all passed on local WordPress 7.0.1.

Completed work:

#### 1B.1 — bounded taxonomy search

Status: complete. Implemented and verified on local WordPress 7.0.1 using the
built-in `category` taxonomy. A valid post/category filter returned only the
assigned post; the same taxonomy across effective `post + page` types was
rejected before WP_Query.

- add a transport-neutral taxonomy filter value object;
- extend the `search-content` input schema with bounded filters;
- validate taxonomy existence and its assignment to every effective post type;
- use positive term IDs, `IN` matching per filter, and `AND` between filters;
- reject unsupported taxonomy/type combinations before executing WP_Query;
- add unit and local integration coverage.

#### 1B.2 — authorization matrix

Status: complete. The repeatable harness creates exact temporary users, an
ephemeral opted-in CPT, and published/draft/private fixtures, then removes them
and restores the prior policy in a `finally` block. It verifies returned IDs and
authorized pagination totals.

- create repeatable published, draft, and private fixtures for post, page, and
  one opted-in public CPT;
- exercise anonymous, subscriber, owning author, non-owning author, editor,
  administrator, and least-privilege integration principals;
- prove that configuration, plugin capability, and native object capability are
  independently required;
- verify that search totals do not reveal unreadable objects.

#### 1B.3 — ability contracts and payload limits

Status: complete. Runtime discovery and REST execution are repeatably verified.
Raw, rendered, and plain-text representations have byte metadata; their combined
size is capped at 2 MiB. The representative 500-block response measured 99,500
representation bytes and 103,898 encoded bytes.

- snapshot stable IDs, category, annotations, input/output schemas, and error
  codes for all three read abilities;
- exercise raw, rendered, and plain-text output for Gutenberg/block-heavy data;
- record representative byte sizes and define explicit truncation/error behavior
  before introducing a limit;
- rerun the local registration, anonymous-denial, disabled-CPT, missing-object,
  and output-schema smoke scenarios.

Milestone 1B was completed on 2026-07-17. Verification evidence is maintained in
`docs/verification/ABILITIES_VERIFICATION.md`.

Deliverables:

- domain DTOs for content identity, summary, detail, query, pagination, and provenance;
- WordPress content repository with object-level visibility filtering;
- policy enforcement through `ContentAccessManager` before query/detail access;
- application services for search and detail;
- ability category and registrations for `search-content`, `get-content`, and safe diagnostics;
- raw/rendered/plain-text representation selection;
- stable errors and schema fixtures.

Tests:

- published/draft/private access by administrator, editor, author, subscriber, and integration user;
- post/page/public CPT support;
- invalid IDs/types/statuses and pagination bounds;
- Gutenberg source integrity and plain-text normalization;
- no arbitrary post meta in output.

Exit gate:

- read contract and permission matrix pass;
- taxonomy filters cannot query unregistered or unrelated taxonomies;
- pagination totals cannot disclose unreadable objects;
- no write code exists;
- payload size is measured on representative long/block-heavy posts.

## Milestone 2 — provider-neutral SEO and Yoast Free

Status: **complete**. Milestone 2A delivered the neutral contract, registry,
selector, authorization, and diagnostics. Milestone 2B delivered the Yoast Free
28.x adapter, `get-url-seo`, runtime parity, partial-result, and leakage matrix.
Milestone 2C accepted composition over embedding in ADR 0008.

Deliverables:

- SEO provider interface, null provider, provider registry;
- normalized `SeoDocument` with configured/resolved/analysis/schema/provenance;
- Yoast Free adapter using documented output first;
- `get-url-seo` and optional SEO inclusion in `get-content`;
- same-site URL validation;
- provider diagnostics and partial-result warnings.

Tests:

- no SEO plugin;
- supported Yoast Free versions;
- inherited vs explicit values;
- posts, pages, terms/archives, homepage, 404/unindexed behavior;
- canonical/robots/social/Schema parity with Yoast output;
- unavailable indexables and reindex guidance.

Exit gate:

- content reads remain functional without Yoast;
- normalized resolved output matches tested public Yoast output;
- private option/meta leakage tests pass.

Implementation order inside this milestone:

1. **2A — complete:** neutral domain model, provider port/null/registry,
   same-origin selector, authorization port, diagnostics.
2. **2B — complete:** documented Yoast Surfaces read adapter, version-gated
   configured allowlist, `get-url-seo`, WordPress runtime/parity evidence.
3. **2C — complete:** clients compose `get-content` and `get-url-seo`; SEO is
   not silently embedded in the content envelope (ADR 0008).

## Milestone 3 — Yoast Premium, Local SEO, and editorial context

Status: **complete**. Milestones 3A, 3B, and 3C are complete, including the
licensed Local multiple-location runtime matrix (ADR 0009).

### 3A — module detection and neutral contracts

Status: complete.

- safe Premium/Local slug and public version reporting;
- normalization schema 1.2 fields for detailed keyphrases, Premium synonyms,
  related keyphrases, and public local
  businesses;
- strict schemas, bounds, provenance, and leakage tests.

### 3B — bounded editorial context

Status: complete and verified on WordPress 7.0.1.

- separate read-only `get-editorial-context` Ability implemented;
- requested bounded vocabulary/inventory sections only, with strict type and
  taxonomy selection and explicit truncation metadata;
- reuses `ContentAccessManager`, authorization-aware `SearchContent`, native
  visibility, and normalized SEO provider output;
- authors are derived only from readable recent results and expose only ID and
  display name;
- no LLM call or editorial-plan generation inside WordPress.

### 3C — licensed adapters

Status: complete and verified on Kormas for Yoast Free 28.0 + Premium 28.0 +
Local 15.8, in both single-location and multiple-location modes.

- Premium additional keyphrases: complete for the tested 28.x JSON envelope;
- Local public profile: complete from emitted Schema for the tested 15.x
  single-location fixture;
- multiple-location/branch projection: verified at runtime through rendered
  front-end schema capture (ADR 0009); the resolved meta surface alone omits
  Yoast's `parentOrganization` branch schema;
- Premium primary synonyms and related keyphrases: code-complete under the
  bounded 28.x contract in ADR 0014; live write verification pending.

Deliverables:

- feature detection for Premium and Local modules;
- licensed-version-tested additional keyphrase normalization where stable;
- Local SEO Schema preservation and normalized public location profile;
- `get-editorial-context` with bounded vocabulary/inventory sections;
- compatibility matrix and explicit limitations.

Tests:

- Yoast Free only;
- Free + Premium;
- Free + Local;
- Free + Premium + Local;
- single and multiple location configurations;
- primary/non-primary location Schema;
- no license/configuration secret exposure.

Exit gate:

- compatibility claims are backed by fixtures/manual environments;
- no direct indexables-table dependency;
- Schema limits and completeness reporting are verified.

The milestone is closed. The licensed single- and multiple-location runtime
matrices pass; multiple-location branch data is captured through the bounded
same-origin rendered-schema reader (ADR 0009).

## Milestone 4 — MCP client interoperability

**Phase 1 target is ChatGPT** (primary), read-only. Codex and Gemini CLI are
secondary/deferred — they are not blocking for this phase, since their
existing App-Password/local-STDIO paths are lower-risk and already covered by
the client-agnostic smoke suite. Writes (create/update/publish) remain out of
scope for Milestone 4 entirely; they are Milestones 5–7, each behind its own
threat model.

Approach: **Approach A** (ADR 0010) — MCP transport and OAuth are external,
configured layers this plugin never bundles or initializes. The official
`WordPress/mcp-adapter` projects the plugin's five read Abilities as MCP
tools; an external OAuth 2.1 layer fronts ChatGPT's connector requirements.
Only a layer that passes ADR 0010's six-criterion evaluation gate
(principal-bound, executes as that user, scope only reduces, ChatGPT-correct
OAuth, secret hygiene, read-only) is adopted.

Deliverables:

- ADR 0010 (MCP transport and OAuth are external, principal-bound layers);
- OAuth candidate evaluation against the ADR 0010 gate
  (`docs/research/OAUTH_CANDIDATES.md`);
- least-privilege bridge-reader fixture (`wpcb-bridge-reader`, capabilities
  `read` + `wpcb_read_content` only — `tests/Integration/bridge-reader-fixture.php`);
- official MCP Adapter installation/setup recipe (`docs/setup/MCP_ADAPTER.md`)
  projecting exactly the five read abilities;
- client-agnostic contract smoke suite for discovery, schema retrieval, and
  execution (`tests/Integration/mcp-smoke-verification.sh`);
- ChatGPT connector guide, self-test, and troubleshooting
  (`docs/setup/CHATGPT_CONNECTOR.md`);
- troubleshooting diagnostics for the live tunnel/OAuth setup (proxy-base
  shim, tunnel restarts, cached OAuth, `502`/`rest_no_route`).

Exit gate:

- ChatGPT completes the five-ability read scenario (discovery, schema
  retrieval, execution of `search-content`, `get-content`, `get-url-seo`,
  `get-editorial-context`, `get-diagnostics`) from a verified setup;
- every credential/grant is principal-bound — no ambient authority;
- no credential can grant more authority than its bound WordPress user;
- no credentials or secrets are committed or shown in logs;
- Codex/Gemini remain secondary/deferred for Phase 1 — their read scenario is
  exercised only via the client-agnostic smoke suite, not a manual walkthrough;
- writes stay globally blocked.

### Principal capability administration patch — **complete in 0.1.2**

- the settings page can bind one existing, dedicated non-administrator
  WordPress user as the managed integration principal;
- the administrator assigns only the closed operational WPCB capability
  allowlist; native WordPress roles/capabilities and connector grants remain
  separate;
- saves require a nonce, `wpcb_manage_settings`, `promote_users`, and
  per-target `edit_user`; native `read` is required before any WPCB grant;
- changing the managed principal revokes only managed WPCB capabilities from
  the previous account; multisite remains blocked pending an ADR;
- application contract tests and a repeatable WordPress runtime verifier cover
  validation, exact replacement, revocation, and unrelated-capability
  preservation.

## Milestone 5 — writes (drafts, SEO, trash, and controlled status workflow)

Status: **in progress (Plans 1–3 complete; trash code-complete; status workflow remains).** During brainstorming
(2026-07-20) the write scope originally spread across Milestones 5–7 was
folded into a single Milestone 5, executed as four sequential, independently
shippable plans. Public and scheduled transitions stay gated behind their own feature flag
and capability (the M7 guardrails, pulled forward). Architecture is
**Approach A**: a new `src/*/Mutation/` vertical slice mirroring the read
layers; the read surface is untouched except for one additive `version_token`
field on `get-content`. Plan 2 shipped the plugin's first live, reachable
write surface (`create-draft` + `update-content`), still off by default.

Design spec: `docs/superpowers/specs/2026-07-20-milestone-5-writes-design.md`.

### Plan 1 — writes foundation — **complete (merged `ab4805f`, 2026-07-20)**

Foundation only; no live write operation is wired yet. Delivered:

- `VersionToken` (Domain) optimistic-concurrency primitive
  (`modified_gmt` + short content hash; mismatch → `wpcb_conflict`);
- typed Application failures `MutationConflict` / `InvalidBlockMarkup` /
  `SeoFieldUnsupported` with stable codes;
- mutation ports `AuditEvent`, `AuditLog`, `BlockMarkupValidator`;
- `Installer` grants the three write capabilities to `administrator`,
  registers the two master feature flags `wpcb_writes_enabled` /
  `wpcb_publish_enabled` (both default **false**, non-autoloaded), and creates
  the capped `{prefix}wpcb_audit` table via `dbDelta`;
- `WordPressAuditLog` (Infrastructure): redacted row insert (field names only),
  prune to a bounded row count, and `do_action( 'wpcb_mutation', $event )`.

Reviewed clean (0 Critical/Important); `composer check` green
(85 tests / 211 assertions); the `wp eval` runtime verifier
(`tests/Integration/writes-foundation-verification.php`) passes.

### Plan 2 — `create-draft` + `update-content` — **complete (merged `28818ab`, 2026-07-21)**

Executed on branch `feat/m5-create-draft-update-content` via subagent-driven
development (13 tasks + final whole-branch review), then merged to `main` and
pushed. Plan:
`docs/superpowers/plans/2026-07-20-m5-create-draft-update-content.md`. Delivered:

- extended `phpcs.xml.dist` `WordPress.WP.Capabilities` `custom_capabilities`
  with `wpcb_edit_content`, `wpcb_manage_seo`, `wpcb_publish_content` (carried
  from the Plan 1 review);
- `TaxonomyAssignment` / `DraftInput` / `ContentUpdate` / `MutationResult`
  Domain DTOs;
- `ContentMutationRepository` / `IdempotencyStore` Application ports,
  `MutationForbidden` / `MutationWriteFailed` typed failures, and the
  `CreateDraft` / `UpdateContent` use cases — validate input, enforce
  per-post-type policy, validate block markup, perform the write, and record
  exactly one audit row per attempt;
- `PhpBlockMarkupValidator` (parse round-trip + registered-type check),
  `WordPressContentMutationRepository` (`wp_insert_post` / `wp_update_post` +
  revisions; never sets `publish`/`future`/`pending`), and
  `WordPressTransientIdempotencyStore` (per-user, 24h transient) in
  Infrastructure;
- the additive `version_token` field on `get-content` output (`ContentDetail`,
  `WordPressContentRepository::get()`, `AbilitySchemas::get_output()`) — the
  one permitted read-ability touch;
- `MutationAbilities` (Adapter) — registers both abilities, with capability +
  native-object permission callbacks and stable `WP_Error` mapping, only when
  `wpcb_writes_enabled` is on;
- `Plugin.php` wiring behind the flag, and the settings-page global "Enable
  content writes" checkbox;
- the runtime write verifier
  (`tests/Integration/writes-mutation-verification.php`): authorization
  matrix (plugin cap, native cap, and policy independently required), the
  no-publish invariant, stale-version-conflict rejection, revision creation,
  block round-trip, idempotent create, and audit redaction.

The final whole-branch review (opus) found and fixed one Important issue:
`CreateDraft`/`UpdateContent` shared a `try` block between the success-audit
call and the failure `catch`, so a throw from the audit sink itself (reachable
via the `wpcb_mutation` public action hook, now wired to the concrete
`WordPressAuditLog` in production) could record a second (failure) audit row
for an attempt that had already succeeded. Fixed (`bc47f8a`) by moving the
success-audit call outside the `try`/`catch` in both use cases, with two new
regression tests. `composer check` green on merged `main` (120 tests / 309
assertions, PHPCS 0, PHPStan 0).

**Expanded in the current source profile:** site infrastructure may expose a
closed list of all 13 implemented abilities through the official Adapter and
filters out any ability not registered under the current feature flags.
miniOrange OAuth grants remain a distinct per-principal runtime configuration;
see `docs/setup/MCP_ADAPTER.md` and `docs/setup/CHATGPT_CONNECTOR.md`.

### Plan 3 — `update-seo` — **base merged `796e932`; SEO extensions runtime-verified**

- `SeoUpdate` DTO; `SeoWriter` port + `YoastSeoWriter` (Yoast Free core
  allowlist plus the Premium 28.x normalized `keyphrase_synonyms` and
  `related_keyphrases` lists, re-read after write);
- `UpdateSeo` use case, ability, schema, and SEO write/re-read verifier.

ADR 0014 replaces the original blanket Premium-write exclusion for these two
fields only. Their installed Premium 28.0 storage shape is version-gated,
scores/synonyms for retained related phrases are preserved, raw provider JSON
and caller-supplied scores remain forbidden, and normalization schema 1.2
introduced both fields.

The implementation is merged to `main` and `composer check` passes. The
repeatable live Yoast verifier exists at
`tests/Integration/writes-seo-verification.php`; its Kormas local execution is
green on 2026-07-24 against Yoast Free 28.1, Premium 28.0, and Local 15.8.
ADR 0016 adds merged `noarchive`/`noimageindex`/`nosnippet` writes and
Open Graph/Twitter overrides by authorized WordPress image attachment ID. The
normalized output contract advances to schema 1.3.

### Plan 3b — `update-service-schema` — **code-complete 2026-08-03**

- provider-neutral `ServiceSchemaUpdate`, `ServiceSchemaWriter`, and
  `UpdateServiceSchema` application flow;
- optional `SchemaExtendedServiceSchemaWriter` adapter for the standalone
  plugin's loaded public `Meta_Fields` API, with no plugin-path or admin-helper
  dependency;
- conditional Ability registration only under global writes plus compatible
  provider availability;
- strict Service, typed-area, brand, and OfferCatalog schemas; no raw JSON-LD or
  arbitrary metadata surface;
- existing SEO capability, native object authorization, per-type Update SEO
  policy, optimistic concurrency, redacted audit, and post-scoped cache event;
- pre-normalization, fixed metadata constants, best-effort rollback of earlier
  keys after a later write failure, and effective post-write configuration;
- ADR 0017 plus unit, schema-contract, static-analysis, and coding-standard
  coverage.

Static verification is green on 2026-08-03: PHPCS, PHPStan at maximum level,
and PHPUnit 214 tests / 532 assertions. A disposable WordPress runtime verifier
with the standalone provider active remains the release gate for graph-level
`Service`/`areaServed`/`hasOfferCatalog` parity.

### Plan 4a — `list-block-patterns`

Status: **code-complete for 0.1.3; Kormas local runtime verification pending.**
The verifier was retried on 2026-07-21, but WordPress could not connect to the
stopped Local database, so no fixture mutation ran.

- read-only `BlockPatternCatalog`/`BlockPatternAccess` ports,
  `WordPressBlockPatternCatalog`/`WordPressBlockPatternAccess`,
  `PatternAccessManager`, `ListBlockPatterns`, and `PatternAbilities`;
- dedicated off-by-default `wpcb_pattern_reads_enabled` option and
  `wpcb_read_patterns` capability exposed in settings;
- metadata-only default, optional complete markup capped at 2 MiB, 50-item
  pages, 1,000-candidate scan, deterministic filters/order, no filesystem
  fields, and no remote pattern loading;
- ADR 0013, strict schemas/unit coverage, and disposable runtime fixture.

### Plan 4b — `trash-content`

Status: **released in 0.1.5; repeatable Kormas WordPress runtime verification
remains pending while the Local database is stopped.**
The Kormas verifier was attempted on 2026-07-21, but Local's database socket
was unavailable, so WordPress did not bootstrap and no fixture mutation ran.

- separate `wpcb_trash_enabled` flag, `wpcb_delete_content` capability, and
  per-type `trash_content` policy;
- `TrashInput`, `MutationTarget`, `ContentTrashRepository`, `TrashContent`,
  `WordPressContentTrashRepository`, and `TrashAbilities`;
- optimistic concurrency, native `delete_post`, revision attempt, redacted
  audit, and post-scoped cache invalidation;
- fail-closed behavior when WordPress trash retention is disabled, preventing
  fallback to permanent deletion;
- strict schema/unit coverage and disposable WordPress verifier.

### Plan 4c — `transition-content-status`

ADR 0015 replaces the never-released `publish-content` plan with a controlled
status-workflow ability:

- explicit administrator-configured transition graph per content type;
- `post_id`, `version_token`, `target_status`, and `publish_at` only for
  scheduling;
- editorial transitions require `wpcb_edit_content` + native `edit_post`;
- `publish` and `future` additionally require `wpcb_publish_enabled`,
  `wpcb_publish_content`, native `publish_post`, and approval-compatible audit;
- internal statuses and `trash` are excluded; trash remains Plan 4b.

Cross-cutting tests (across the four plans):

- per-object authorization;
- stale version conflicts;
- revision creation and rollback feasibility;
- taxonomy/media authorization;
- sanitization preserving valid blocks;
- repeated/idempotent request behavior;
- mutation audit redaction.

Exit gate:

- no create/update path can publish; public or scheduled status is reachable
  only through the separately-gated `transition-content-status`;
- conflicts never overwrite newer edits;
- writes are invisible over MCP unless their master flag is enabled;
- security review signs off before beta.

> Milestones 6 and 7 below are retained for historical traceability of the
> original gate criteria; their deliverables are executed inside Milestone 5
> Plans 3 and 4 respectively.

## Milestone 6 — SEO writes

Deliverables:

- provider write-capability discovery;
- allowlisted normalized `update-seo` service/ability;
- provider reindex/refresh and post-write verification;
- field-level result reporting.

Exit gate:

- no direct write to provider-derived/indexables tables;
- unsupported fields fail explicitly;
- effective SEO is re-read after mutation;
- Premium/Local writes beyond the two Premium keyphrase fields in ADR 0014
  remain out until separately specified.

## Milestone 7 — controlled status transitions

Deliverables:

- disabled-by-default feature flag;
- dedicated publish capability;
- separate `transition-content-status` ability with an explicit transition graph;
- approval-compatible request/result contract;
- scheduled-content date/time policy and audit trail;
- `trash` explicitly excluded from the status-transition vocabulary.

Exit gate:

- explicit human approval flow has been demonstrated;
- integration user cannot publish unless separately granted;
- no create/update ability can bypass publication controls.

## Milestone 8 — optional Agents API integration

Deliverables only after an ADR reassessment:

- optional dependency feature detection;
- embedded editorial agent registration;
- bounded workflows for audit/plan/draft/approval;
- memory, transcript, consent, capability-ceiling, and budget configuration;
- no change to the base abilities contract.

Agents API is not required for external-client usage.

## Future backlog — redirect management

Add bounded redirect-management abilities in a separate, post-MVP phase.
Before planning implementation, compare a dedicated redirects plugin with
Yoast Premium redirects as the storage/execution backend.

The public application contract must remain provider-neutral. A selected
backend should be implemented behind a redirect-provider port so that Yoast or
a dedicated plugin can be replaced without changing stable ability IDs or
schemas. The evaluation must cover documented API stability, supported redirect
types, source-path normalization, redirect-chain and loop detection, duplicate
or conflicting rules, authorization, optimistic concurrency, audit events,
post-write verification, import/export portability, and behavior when the
provider is unavailable. Redirect writes require their own threat-model update,
capability, disabled-by-default feature flag, schemas, and contract/runtime
tests; they must never expose arbitrary rewrite rules or direct database access.

## Media abilities and featured-image identity

Status: **P0 code-complete for 0.1.3; Kormas local runtime verification
pending.** ADR 0011 and
`docs/superpowers/specs/2026-07-21-media-p0-design.md` define the accepted
boundary. The third-party plugin comparison is recorded in
`docs/research/ENABLE_ABILITIES_FOR_MCP_COMPARISON.md`.

Design a dedicated, bounded media vertical slice rather than treating
attachments as generic posts or exposing arbitrary post-meta writes. Use the
publicly available implementation and documentation of the third-party
**Enable Abilities for MCP** plugin as comparison material. Reuse sound contract
patterns, not provider-specific internals.

### P0 — eliminate media identity and schema ambiguity — **code complete**

- Add a stable `get-media` search ability whose output is an object envelope
  (for example `items`, `pagination`, and `provenance`), never a raw top-level
  array that an adapter may interpret as the wrong schema type.
- Add a separate `get-media-by-id` ability for deterministic retrieval of one
  authorized attachment; absence and denial must use the same non-enumerating
  public error shape.
- Support explicit search selectors for exact positive attachment ID, exact
  same-site full URL, and normalized filename. Define exact-versus-partial
  filename behavior, ambiguity handling, bounds, and deterministic ordering;
  never fetch a caller-supplied remote URL.
- Return one normalized media record containing at least: attachment `id`,
  title, filename, canonical attachment/file URL, ALT text, caption,
  description, and MIME type. Add dimensions, file size, generated sizes, dates,
  and provenance only after explicit bounds and leakage review.
- Make featured-image identity unambiguous in content reads by returning both
  `featured_image_id` and `featured_image_url` together. Today WP Content Bridge
  already returns `relationships.featured_media.id`, `url`, and `alt_text` when
  the caller explicitly includes `featured_media`; the future contract must
  decide whether the ID+URL pair becomes an additive content-summary field or a
  default relationship so agents cannot accidentally substitute a URL or a
  different attachment ID. Update schemas and contract tests in the same
  change.

### P1 — safe media mutation and upload

- Add a semantic `update-media` ability with an explicit allowlist for title,
  ALT text, caption, and description. Do not route this through a generic
  `update-post-meta` ability and never expose arbitrary attachment metadata.
- Require a version token, `wpcb_edit_media` (or a separately accepted
  capability mapping), native `edit_post` for the attachment, a media feature
  flag/policy, revision or rollback strategy where WordPress supports it, and a
  redacted audit event.
- Add featured-image assignment/removal as a separate content-media operation.
  Validate that the attachment exists, is readable/usable by the principal, is
  an allowed image MIME type, and belongs to the current site; return the final
  ID+URL pair after the write.
- Add upload as a separate ability requiring `upload_files`, MIME allowlists,
  file-size/dimension limits, sanitized filenames, bounded metadata, attachment
  ownership checks, and post-write read-back. Remote import remains a distinct
  SSRF-reviewed design and must not be implied by upload support.
- Reuse the post-scoped mutation invalidation boundary for future media and
  featured-image writes (ADR 0012); do not add provider calls to their use cases.

Delivered behind the off-by-default `wpcb_media_reads_enabled` policy with the
dedicated `wpcb_read_media` capability and native `read_post` filtering. The
public contracts are `wp-content-bridge/get-media` and
`wp-content-bridge/get-media-by-id`; content summaries now include the required
nullable `featured_image_id` + `featured_image_url` pair. Static/unit quality is
green (155 tests / 380 assertions). Run
`tests/Integration/media-read-verification.php` on Kormas local before release.

## Cross-cutting cache invalidation after agent mutations

Status: **baseline code-complete for 0.1.3; Kormas local runtime verification
pending.** ADR 0012 owns the accepted boundary. Kormas local has LiteSpeed Cache
installed, and its public `litespeed_purge_post` hook was verified in the local
plugin source.

Ensure that every successful AI-initiated mutation becomes visible through the
site's active cache stack without coupling application services to a concrete
page-cache, object-cache, hosting-cache, or CDN plugin.

- The delivered WordPress infrastructure subscriber consumes successful,
  pre-redacted `wpcb_mutation` events and invalidates the authoritative post ID.
  It calls `clean_post_cache()` and dispatches `litespeed_purge_post` only when
  that hook has an active listener. This closes the metadata-only SEO-write gap
  without coupling application services to LiteSpeed.
- Integrations use public hooks only; never delete cache files, mutate cache
  tables directly, or accept arbitrary action names/cache keys from an MCP
  caller.
- Build a bounded invalidation plan from authoritative mutation results, not
  caller-supplied URLs. Depending on the operation it may contain the exact
  canonical object URL, both old and new URLs after a slug/permalink change,
  relevant archive/home/feed/sitemap dependencies, attachment URLs, and
  provider-generated SEO/schema output. Every target must be same-site unless a
  separately configured CDN adapter owns it.
- Invalidation runs only after the write and successful audit event. A purge
  failure does not roll back or misreport a committed mutation; it emits the
  redacted `wp_content_bridge_cache_invalidation_failed` infrastructure event.
- Emit redacted audit/observability data containing provider identity, target
  count, outcome, and safe error code, never cache credentials, filesystem
  paths, raw provider configuration, or secret CDN tokens.
- Make repeated invalidation idempotent, coalesce duplicate targets, cap the
  number of URLs/objects per mutation, and prevent an integration account from
  triggering an unrestricted full-site purge. A manual full purge, if ever
  supported, must be a separate administrator-only operation and is not an MCP
  content ability.
- Future provider expansion may expose safe diagnostics and an additive
  `cache_invalidation` result only after its public contract is specified.
  Provider-specific failures must never break content reads or rewrite a
  committed mutation result.

The delivered subscriber applies to `create-draft`, `update-content`, and
`update-seo`. Apply the same event contract to publication/status
transitions, slug changes, redirect writes, menu changes, revision restoration,
featured-image/media changes, and deletions. Before implementation, research
the public invalidation APIs and automatic-hook behavior of the cache plugins
actually targeted by the compatibility matrix. Add no provider claim without a
fixture or manual runtime test.

Delivered runtime coverage: successful post-scoped WordPress/LiteSpeed
invalidation, no purge for unsuccessful events, and contained adapter failure.
Future mutation types still require old+new URL handling after slug changes,
bounded target deduplication, unsupported-provider behavior, and explicit
dependency coverage where one post affects archives or other public URLs.

## Future backlog — extended editorial operations

Add the following editorial capabilities after the current write milestone.
They must remain separate semantic abilities over shared application services;
do not expand `update-content` into a generic action dispatcher.

- **Navigation menu editing:** support both classic menus and the block-based
  Navigation model through a normalized menu contract. Require explicit menu
  selection, bounded item trees, cycle validation, theme/location awareness,
  native capabilities, optimistic concurrency, revisions where WordPress
  supports them, and mutation audit events.
- **Revision restoration:** list bounded revision metadata and restore one
  explicitly selected revision. Restoration must create/preserve a recoverable
  revision, require `edit_post`, reject stale targets, and never silently change
  publication state, author, slug, or publication date.
- **Slug and permalink changes:** expose a deliberate slug update with collision
  checks, resulting canonical URL, and post-write verification. Define whether
  an old-URL redirect is suggested or created through the future redirect
  provider; never create a redirect implicitly without an explicit policy.
- **Post-status transitions:** add `transition-content-status` with an explicit
  finite-state transition graph
  for editorial states such as draft, pending review, scheduled, published, and
  private where supported. Keep publication/scheduling behind the dedicated
  publish feature flag and capability; do not add a free-form `post_status`
  field to `update-content`.
- **Trash and permanent deletion:** `trash-content` is delivered as a reversible,
  separately gated operation. Permanent deletion must remain a distinct ability with stronger
  authorization, explicit confirmation/approval semantics, conflict checks,
  attachment/reference impact reporting, and audit events.
- **Author and publication-date changes:** allow assignment only to an eligible,
  explicitly selected WordPress user and enforce native `edit_others_posts` or
  equivalent type capabilities. Date changes must distinguish authored date,
  immediate publication, and scheduling, validate timezone handling, and reuse
  publication gates instead of bypassing them.
- **Featured image and media management:** follow the dedicated media backlog
  above; keep retrieval, metadata updates, featured-image assignment, upload,
  and any remote import as separate semantic operations.

Before implementation, update the content-operation policy vocabulary and
dependencies, threat model, capability map, Ability schemas, audit taxonomy,
and runtime authorization matrix for each operation. Media/attachments and
navigation entities require dedicated access-policy decisions because they are
intentionally outside the current eligible content-type catalog.

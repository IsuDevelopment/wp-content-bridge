# Project status

**Released version: 0.4.0** (`v0.4.0` = `52cb2a2`, on `origin`). Static quality
is green at 247 tests / 648 assertions. Runtime verification is no longer the
open gate: the environment was restored on 2026-08-07 and fifteen verifiers
pass. Work in progress is `0.4.5`, a consolidation release —
see "Next action" and `docs/plan/RELEASE_0_4_5_PLAN.md`.

Entries below are dated and historical; read them as a log, not as current
state. Where a dated entry names a version that later moved, the "Release
numbering" table in `docs/plan/EDITORIAL_OPERATIONS_ROADMAP.md` is
authoritative.

## Current phase

**0.4.5 task 1 (`restore-trashed-content`) is code-complete on 2026-08-07.**
It is the mirror image of `TrashContent`/`TrashAbilities`: registered only
under the existing `wpcb_writes_enabled` + `wpcb_trash_enabled` gate (no new
flag), requires `wpcb_delete_content` plus native `delete_post`, the same
per-post-type Trash policy, and a current `version_token`; it requires the
target's current status to be exactly `trash` (the inverse of `TrashContent`'s
check), the non-enumerating failure for any other status. `RestoreInput`
(Domain) mirrors `TrashInput`; the existing `MutationResult` DTO is reused
rather than duplicated, since it already carries the resulting `status` the
contract requires. `ContentTrashRepository` gained an additive `untrash()`
method; `WordPressContentTrashRepository::untrash()` computes the safe restore
status from `_wp_trash_meta_status` (`draft`/`pending`/`private` only, `draft`
otherwise — a `publish`/`future` recorded status, or missing/unparseable meta,
all fall back to `draft`), forces that exact value through the
`wp_untrash_post_status` filter rather than trusting `wp_untrash_post()`'s own
default (documented as version-dependent), and verifies the effective status on
re-read before returning. No preview intent — it fails the roadmap's preview
justification test. The closed MCP profile grows to 21 entries (from 20).
`RestoreTrashedContentAbilities` is a new adapter file (not folded into
`TrashAbilities`) so the shipped `trash-content-verification.php` needed no
changes. New runtime verifier
`tests/Integration/restore-trashed-content-verification.php` covers
registration/annotations/schema strictness, the two-gate authorization matrix,
trash-to-draft restore with redacted audit, a `publish` pre-trash fixture
landing on `draft` (never `publish`/`future`), a stale-token conflict rejected
before any mutation, and per-type policy denial. The consuming site's
MU-plugin projection package (`isudev/wp-content-bridge-mcp-server`, separate
repository) still needs the new ID and a version bump — that is the user's
action, not part of this change. Tasks 2–8 of `docs/plan/RELEASE_0_4_5_PLAN.md`
remain outstanding and are explicitly out of scope for this change.

**0.4.5 task 2 (unify the preview response flag) is code-complete on
2026-08-07.** `ServiceSchemaPreviewResult` and `CustomSchemaPreviewResult`
(Domain) now emit `writes_performed: false` additively, alongside the existing
`dry_run: true` — neither field was removed. `AbilitySchemas::preview_service_schema_output()`
and `AbilitySchemas::preview_custom_schema_output()` add `writes_performed` as
a required boolean property (both schemas keep `additionalProperties: false`,
so the property definition was mandatory, not optional). All four preview
Abilities (`preview-update-content`, `preview-update-seo`,
`preview-update-service-schema`, `preview-update-custom-schema`) now share one
`writes_performed` read path. `docs/architecture/ABILITIES.md` documents
`dry_run` as deprecated on both remaining abilities, removal scheduled for
`0.5.0`. ADR 0019 and ADR 0020 each gained a second "Amended 2026-08-07" note
recording the addition without altering the original decisions. Existing unit
assertions covering the preview output shape
(`AbilitySchemasTest`, `ServiceSchemaReadPreviewTest`,
`CustomSchemaUseCasesTest`) were extended in place — no new test file. Static
quality: 247 tests / 648 assertions, PHPCS/PHPStan/PHPUnit all green.
`tests/Integration/schema-service-verification.php` and
`tests/Integration/schema-custom-verification.php` both `PASS` against the
LocalWP environment. Tasks 3–8 of `docs/plan/RELEASE_0_4_5_PLAN.md` remain
outstanding and are explicitly out of scope for this change.

**The post-0.3 editorial operations roadmap was accepted and extended on
2026-08-03.** It is recorded in
`docs/plan/EDITORIAL_OPERATIONS_ROADMAP.md`.

> **Superseded 2026-08-07 — version numbers only.** The scope described below
> stands; its release numbering does not. 0.4.0 shipped the previews alone,
> llms.txt moved to 0.5.0, status transitions to 0.6.0, and every later slice
> up one. 0.4.5 was inserted as a consolidation release. Three planned preview
> Abilities were also cut. See the "Release numbering" and "When a preview
> Ability is justified" sections of the roadmap.

Version 0.4.0 now contains two
sequential sub-slices: content/SEO preview followed by native `llms.txt` read,
preview, configuration, generation, and virtual
publication informed by the installed LLMagnet 3.4.3 implementation. LLMagnet
is research material only and will be removable with no runtime adapter,
classes, options, files, Abilities, cron, or licensing dependency in the
bridge. A physical artifact left by LLMagnet is a fail-closed ownership
conflict; migration/deactivation/deletion remains an explicit administrator
deployment step. Yoast SEO's own `llms.txt` toggle and physical-file ownership
are now an additional fail-closed conflict gate. The first release detects and
guides an administrator to disable Yoast manually; it never writes the raw
`wpseo` option. Optional automated handoff requires a separately accepted,
version-tested Yoast write contract plus administrator-only preview and
confirmation. The existing 0.5 status-transition target remains next. The
0.8 redirect slice now requires deterministic Yoast Premium-first selection
with Redirection fallback and never dual-writes. The targeted Gutenberg slice
distinguishes server structural validation from true editor-side `save()`
semantic validation. All slices retain separate read/preview/write intents,
least-privilege capabilities, optimistic concurrency, redacted audit, and
runtime/MCP release gates. No implementation has started.

**Slice 1A (content and SEO preview) is code-complete and runtime-verified for
0.4.0 on 2026-08-07.** `wp-content-bridge/preview-update-content` and
`wp-content-bridge/preview-update-seo` mirror `update-content` and
`update-seo` exactly, per ADR 0021: same validated input DTO, per-post-type
policy, optimistic-concurrency check, and (for content) block-markup
validation; neither takes an `AuditLog` dependency at all, so neither can
record a mutation audit row. Content preview round-trips block markup through
a new `BlockMarkupValidator::normalize()` method (`parse_blocks()`/
`serialize_blocks()` only, never content filters) and reads the post's current
title/content/excerpt through a new, additive `ContentSnapshotRepository`
port that `WordPressContentMutationRepository` also implements. SEO preview
normalizes every requested field exactly as `YoastSeoWriter::write()`
sanitizes it (including resolving social-image attachment IDs) through a new,
additive `SeoPreviewProvider` port that `YoastSeoWriter` also implements, but
never calls `WPSEO_Meta::set_value()`. Both new ports are additive to
existing interfaces, so no existing implementer or test double changed shape.
Preview responses report `writes_performed: false` — deliberately not
`dry_run`, which the roadmap reserves for the forbidden mode of a destructive
Ability. Both previews register only inside `MutationAbilities`, alongside the
writes they mirror, so they share `wpcb_writes_enabled` and the identical
capability/native-object permission callback automatically. The closed MCP
profile grew to 20 potential abilities. `composer check` is green: PHPCS 0
errors, PHPStan max-level 0 errors, PHPUnit 242 tests / 626 assertions. The new
`tests/Integration/preview-verification.php` passes against Yoast Free 28.2 on
Kormas local, proving repeated previews are deterministic and cause no
post/meta/revision/audit change, that a preview followed by the matching
write produces exactly the previewed state, and that stale tokens are
rejected before any mutation. `abilities-runtime-verification.php` (the
closed-profile guard) also passes. The consuming site's MU-plugin projection
package `isudev/wp-content-bridge-mcp-server` still needs a version bump to
include the two new IDs — that is the user's action in a different
repository, not part of this change.

**Bounded Custom Schema MCP support is code-complete for connector 0.3.0 on
2026-08-03.** Conditional `get-custom-schema`, `preview-update-custom-schema`, and
`update-custom-schema` Abilities use only Schema Extended 0.3.0's public
`Integration_API` contract version 1.0. Input is limited to `enabled` and a
100,000-byte JSON source; provider output is normalized to at most 20 nodes and
strict diagnostics. Preview is read-only and reports save/render eligibility.
Update reuses SEO policy, native authorization, optimistic concurrency,
redacted audit, and post-scoped cache invalidation. Full resolved graph checks
remain on the existing `get-url-seo` Ability. ADR 0020 records the bounded JSON
exception. The closed MCP profile now has 18 potential entries. Full
`composer check` is green: PHPCS, maximum-level PHPStan, and PHPUnit 234 tests /
596 assertions. Live WordPress registration and Yoast graph-merge checks remain
the release gate.

**Service schema read-before-write was code-complete on 2026-08-03.** Separate
`get-service-schema` and `preview-update-service-schema` Abilities now complement the
existing update. Get returns the independently saved provider configuration and
current token. Preview consumes the exact update input, policy, provider,
optimistic-concurrency, and sanitization paths but performs no metadata write,
mutation audit, or cache invalidation. Both are truthfully annotated read-only.
The raw MCP smoke test now inspects each targeted tool's own required-field
declaration, covering `post_id` and `version_token`. ADR 0019 records why dry
run is a separate semantic intent. The profile at that milestone had 15 potential
entries. PHPCS and maximum-level PHPStan are clean; PHPUnit passes 223 tests /
560 assertions. The Kormas source profile is updated to 0.2.1. Runtime
registration verification was attempted, but Local's database socket was not
available, so WordPress could not bootstrap.

The reported optional `post_id`/`version_token` display was traced outside the
bridge: miniOrange Secure MCP Server 1.3.1 removes the nested Ability `required`
list in `wrap_input_schema()`. WordPress.org 1.4.2 trunk still contains the same
code. The bridge source and contract tests already require the fields, and the
official-Adapter smoke test now asserts the raw MCP descriptor. No generated
third-party plugin file was patched; the OAuth projection remains an upstream
compatibility issue.

**Conditional structured Service writes are code-complete on 2026-08-03.**
`wp-content-bridge/update-service-schema` is registered only when global writes
are enabled and the standalone IsuDev Schema Extended plugin marker plus
compatible public `Meta_Fields` API are loaded. The provider-neutral contract
covers bounded Service name/type/description, typed service areas, brands, and
OfferCatalog entries; it never exposes arbitrary post meta or raw JSON-LD. The
write reuses `wpcb_manage_seo`, native `edit_post`, per-type Update SEO policy,
optimistic concurrency, redacted audit, and post-scoped cache invalidation.
The provider adapter pre-normalizes values, maps fixed metadata constants,
restores earlier keys best-effort after a later write failure, and returns an
effective configuration re-read. ADR 0017 records the optional provider
boundary. PHPCS and PHPStan are clean; PHPUnit passes 214 tests / 532
assertions. WordPress runtime graph verification with the standalone provider
active remains a release check. The profile at that milestone had 15 potential
entries and continues to intersect them with currently registered abilities.

**The critical `update-seo` gap is closed and runtime-verified on 2026-07-24.**
ADR 0016 extends the existing ability with independently merged
`robots_noarchive`, `robots_noimageindex`, and `robots_nosnippet` flags plus
`og_image_id` and `twitter_image_id`. Social overrides accept only readable
WordPress image attachment IDs; URLs are resolved internally, both Yoast ID and
URL values are written, `0` clears the pair, and all images are validated
before the first field write. Configured SEO re-reads expose the new flags and
attachment IDs under normalization schema 1.3. The complete unit suite is 200
tests / 493 assertions, PHPStan is clean, and the Kormas runtime write verifier
passes against Yoast Free 28.1, Premium 28.0, and Local 15.8, including merge,
clear, re-read, invalid-image atomicity, concurrency, authorization, and audit
coverage. The verifier now snapshots and restores local WPCB policy/options.
The managed `wpcb-bridge-reader` principal (user 87) was changed from
Subscriber to Editor while retaining its seven explicit WPCB capabilities.

**Version 0.2.0 MCP exposure is statically complete on 2026-07-21.** The plugin version,
readme changelog, official Adapter setup, and configurable MCP discovery smoke
profile now describe a closed profile of all 12 implemented abilities. Kormas
owns the official Adapter projection in a version-controlled Composer MU-plugin
that intersects this profile with abilities registered under current feature
flags. This does not alter authorization. The miniOrange OAuth endpoint has
separate principal-to-ability grants that still require explicit runtime update
and verification after the stopped Kormas Local database is available.
The plugin passes the PHP 8.2 release gate: PHPCS clean, PHPStan 0 errors, and
PHPUnit 197 tests / 474 assertions. The Kormas package passes PHP syntax and
PHPCS, Composer resolves it as version 0.2.0, and the obsolete local root MCP
file was removed to prevent duplicate server registration. Live discovery was
attempted but WordPress could not connect to the stopped database.

**The root README ability catalog was expanded on 2026-07-21.** It now gives a
standalone, factual description of every implemented ability, including its
registration gate, WPCB capability, main inputs and outputs, bounds, native
authorization, mutation safeguards, cache behavior, settings, MCP boundary,
and unsupported operations. Planned `transition-content-status` remains
clearly separated from the 12 abilities released in version 0.1.5 and projected
by the version 0.2.0 site profile.

**Version 0.1.5 trash slice and status-boundary decision are complete on
2026-07-21.** ADR 0015 replaces the never-released `publish-content` plan with
the future `transition-content-status` ability: an explicit transition graph,
with public/scheduled transitions additionally gated by the publication flag,
`wpcb_publish_content`, and native `publish_post`. Trash is deliberately a
separate `wp-content-bridge/trash-content` intent. It is off by default behind
the writes flag, its own trash flag, per-type read + trash policy,
`wpcb_delete_content`, native `delete_post`, optimistic concurrency, redacted
audit, and post-scoped cache invalidation. It fails closed when WordPress would
skip reversible trash, and rejects `trash`, `auto-draft`, and `inherit` source
states. Permanent deletion and restore are not exposed. The root README now
catalogs all 12 implemented abilities, and the settings surface includes all
seven managed capabilities plus the new policy and destructive switch. Full
`composer check` is green on the minimum supported PHP 8.2.30: PHPCS and
PHPStan clean, PHPUnit 197 tests / 474 assertions. Anonymous readonly test
fixtures that accidentally required PHP 8.3 were made PHP 8.2-compatible. The
Kormas runtime verifier was attempted but the Local
database remains stopped, so no fixture mutation ran.

Milestone 5 (writes) — **in progress.** Planned as four sequential plans; it
has since grown to nine (1, 2, 3, 3b, 3c, 3d, 4a, 4b, 4c).
**Plan 1 (writes foundation) is complete and merged** to `main` (merge commit
`ab4805f`). **Plan 2 (`create-draft` + `update-content`) is complete and
merged** to `main` (merge commit `28818ab`, pushed to origin). The plugin now
has its **first live, reachable write surface** — `wp-content-bridge/create-draft`
and `wp-content-bridge/update-content` — still **off by default** behind
`wpcb_writes_enabled` (an administrator must enable the flag, and per-post-type
Create/Update policy, before either ability is reachable at all; see
`docs/architecture/ABILITIES.md` for the delivered contract). 13 tasks executed
via subagent-driven development plus a final whole-branch review (opus) that
found and fixed one Important issue: `CreateDraft`/`UpdateContent` recorded a
second (failure) audit row if the audit sink itself threw after a success row
was already committed — fixed by moving the success-audit call outside the
`try`/`catch` (commit `bc47f8a`), with regression tests. `composer check` green
on merged `main` (120 tests / 309 assertions, PHPCS 0, PHPStan 0); the runtime
write verifier (`tests/Integration/writes-mutation-verification.php`) passes
the full authorization matrix, no-publish invariant, stale-version conflict,
revision-on-update, block round-trip, idempotent create, and audit-redaction
checks. The 0.2.0 site-infrastructure projection closes the official Adapter
discovery gap with an explicit 12-ability, registered-only profile. The
ChatGPT-facing miniOrange OAuth grants remain a separate pending runtime
configuration.

**Plan 3 (`update-seo`) base is merged** to `main` (merge commit `796e932`).
The current 0.1.3 worktree extends its fixed Yoast Free core-field allowlist
with normalized `keyphrase_synonyms` and `related_keyphrases` for compatible
Yoast Premium 28.x (ADR 0014), advances normalized SEO output to schema 1.2,
and extends the repeatable verifier. The current `composer check` baseline is
185 tests / 443
assertions. The verifier was retried on 2026-07-21, but WordPress could not
connect to the stopped Local database; no fixture mutation ran.

**Media P0 and post-scoped cache invalidation for version 0.1.3 are
code-complete in the current worktree.** Media adds
off-by-default `get-media` and `get-media-by-id` abilities, the dedicated
`wpcb_read_media` capability, native per-attachment authorization, deterministic
ID/same-site URL/filename lookup, strict object envelopes, normalized media
fields, and required nullable `featured_image_id` + `featured_image_url` content
summary fields. ADR 0011 owns the separate media policy. The current combined
worktree passes `composer check` (185 tests / 443 assertions); Kormas runtime
verification is pending only because the Local site/database is stopped.
Successful mutations now clear the
affected WordPress post cache and, when active, dispatch LiteSpeed Cache's
public post-scoped purge hook. Cache failures are contained after commit (ADR
0012); its Kormas runtime verifier is pending for the same reason.

**Plan 4a (`list-block-patterns`) is code-complete in the current 0.1.3
worktree.** The ability is independently off by default, requires
`wpcb_read_patterns` plus WordPress editor-level access, returns metadata by
default, and exposes optional complete markup under a 2 MiB bound. It uses the
current registry without remote loading and never exposes pattern filesystem
paths (ADR 0013). Static/unit quality is green (185 tests / 443 assertions).
The verifier was retried on 2026-07-21 and reached WordPress, but the stopped
Local database socket prevented bootstrap; no fixture mutation ran.

Milestone 4 Phase 1 — **complete** (ChatGPT-primary, read-only, Approach A per
ADR 0010). ChatGPT completed the five-ability read scenario live through the
official MCP Adapter (App-Password endpoint, `/wp-json/wpcb-mcp/mcp`) fronted
by an external OAuth 2.1 layer (miniOrange Secure MCP Connector,
`/wp-json/mosmcp/v1/mcp`), DCR + PKCE, token principal-bound to a WordPress
user. Codex/Gemini remain secondary/deferred, covered only by the
client-agnostic smoke suite. A still-open M4 thread (independent of writes):
staging stabilization with a real TLS certificate (the local run used a
cloudflared quick tunnel) plus a strict least-privilege re-consent.

Milestone 3 — complete. Premium/Local reads, bounded editorial context, and
the licensed Local multiple-location runtime matrix (primary + branch) are all
verified.

## Completed

- Version 0.1.2 adds single-site integration-principal capability management to
  the plugin settings page. An administrator can bind one existing dedicated
  non-administrator user and assign the closed operational WPCB capability
  allowlist; nonce, `wpcb_manage_settings`, `promote_users`, per-target
  `edit_user`, native `read`, prior-principal revocation, and multisite denial
  are enforced. `composer check` is green (141 tests / 351 assertions, PHPCS 0,
  PHPStan 0). The repeatable WordPress runtime verifier was added but could not
  be executed in this environment because the local WordPress database runtime
  was not running (`Error establishing a database connection`).
- Media P0 code-complete for 0.1.3: dedicated off-by-default policy and
  `wpcb_read_media`; strict object-envelope `get-media`; deterministic
  `get-media-by-id`; exact ID/same-site URL/filename lookup; normalized ID,
  title, filename, URL, ALT, caption, description, and MIME; and required
  `featured_image_id` + `featured_image_url` content-summary fields. ADR 0011,
  runtime verifier, settings UI, capability migration, contract tests, and the
  verified third-party comparison are included. `composer check` passes (155
  tests / 380 assertions); Kormas runtime remains pending while Local is stopped.
- Implemented post-scoped cache invalidation after every successful bridge
  mutation. The WordPress infrastructure subscriber clears the exact post's
  object cache and uses LiteSpeed Cache's public `litespeed_purge_post` hook
  only when present. It forbids caller-selected targets and full purges,
  contains provider exceptions after commit, and exposes bounded success/failure
  lifecycle hooks (ADR 0012).
- Implemented Plan 4a `list-block-patterns`: dedicated settings flag and
  integration capability, core-compatible native editor gate, transport-neutral
  access/catalog ports, deterministic filters/pagination, metadata-only default,
  optional bounded markup, strict schemas, unit coverage, and a disposable
  Kormas runtime verifier (ADR 0013).
- Captured provider-neutral redirect management as a future backlog item,
  including the required evaluation of Yoast Premium redirects versus a
  dedicated redirects plugin and the security gates for any write ability.
- Captured extended editorial operations in the future backlog: navigation
  menus, revision restoration, slug/permalink changes, explicit post-status
  transitions, trash/permanent deletion, author/date changes, and featured
  image/media upload management with separate authorization and safety gates.
- Fixed the GitHub release workflow to build with the project's minimum
  supported PHP version (8.2), so production-only Composer installation honors
  the root platform constraint without changing the lock file.
- Standalone repository scaffold.
- Composer autoloading and quality-tool configuration.
- Minimal activatable WordPress plugin bootstrap.
- Canonical AI-agent instructions.
- Initial SDD, ADRs, implementation plan, security model, and test strategy.
- Composer dependencies installed; PHPCS 80/80 files and maximum-level PHPStan
  pass; PHPUnit currently passes 74 tests with 195 assertions.
- Local Kormas integration is active through the ignored runtime symlink `public/content/plugins/wp-content-bridge`.
- The plugin is activated locally and its PSR-4 bootstrap has been verified.
- Domain-level per-post-type operation policy and dependencies.
- Option-backed content access repository and eligible post-type catalog.
- Settings API matrix protected by `wpcb_manage_settings`.
- ADR 0006, content access architecture, code map, and agent feature procedure.
- Unit policy contract coverage.
- Local WordPress verification: capability migration, eligible-type defaults, Settings API registration, and admin menu registration.
- Transport-neutral content query/summary/detail/result DTOs and repository port.
- WordPress content repository with native `read_post` enforcement.
- Read-only `search-content`, `get-content`, and safe diagnostics Abilities.
- Dedicated `wpcb_read_content` capability for administrators.
- LLMagnet 3.4.3 architectural comparison and principal-bound credential ADR.
- Local WordPress 7.0.1 verification: all three abilities register and validate;
  administrator search/detail/diagnostics succeed, anonymous access fails,
  disabled `realizacja` search fails, missing detail is non-enumerating, and
  raw/plain-text/relationship/concurrency output was exercised.
- Milestone 1B.1 bounded taxonomy filters: immutable filter value object,
  taxonomy discovery port, public/REST WordPress taxonomy adapter, strict
  Ability schema, all-effective-types validation, and bounded `AND`/`IN`
  WP_Query mapping.
- Local taxonomy verification: category term 1 returned post 1 for an explicit
  post search; using category across effective post and page types returned
  `wpcb_invalid_input` before querying.
- Repeatable WordPress authorization matrix covering anonymous, subscriber,
  owning/non-owning authors, editor, administrator, and least-privilege
  integration principals across published, draft, private, page, and opted-in
  CPT fixtures.
- Search authorization is applied before pagination; unreadable objects cannot
  leak through totals. Candidate scans are capped at 1,000 and disclose whether
  totals are exact.
- Detail responses report per-representation byte sizes and reject selected
  representations above 2 MiB with `wpcb_content_too_large`.
- Block-heavy runtime fixture verified 500 blocks: 99,500 representation bytes
  and 103,898 encoded response bytes.
- Runtime Abilities verification passes for discovery, strict schemas,
  annotations, anonymous denial, administrator execution, deterministic twin
  calls, REST discovery, and REST execution on WordPress 7.0.1.
- Milestone 1B exit gate is complete; evidence is in
  `docs/verification/ABILITIES_VERIFICATION.md`.
- Provider-neutral SEO domain model, explicit value states, provenance,
  completeness, bounded Schema graph, null provider, and provider registry.
- Strict same-origin SEO target validation and content-policy/native-object
  authorization before provider access.
- Read-only `wp-content-bridge/get-url-seo` Ability with an exclusive
  `post_id`/`url` selector and stable non-enumerating errors.
- Yoast Free 28.x adapter: documented Surfaces output for resolved metadata and
  Schema, plus a narrow version-gated configured post-meta allowlist.
- Safe SEO-provider status is included in diagnostics.
- Five-Ability runtime verification passes on WordPress 7.0.1, including strict
  schemas, anonymous denial, deterministic twin execution, stable SEO errors,
  REST discovery, and REST execution.
- The authorization matrix now covers SEO reads for author/editor/integration
  principals, policy-disabled objects, non-enumerating denial, and arbitrary
  post-meta leakage.
- Real-HTTP URL verification passes with a disposable least-privilege
  Application Password principal: post URLs canonicalize after authorization,
  external origins fail, and unavailable home/archive indexables return bounded
  explicit warnings.
- Yoast public-head parity passes for title, description, canonical, robots,
  Open Graph, Twitter, and normalized Schema.
- Disposable configured-value verification passes for explicit/inherited
  states, partial output without an indexable, and arbitrary-meta isolation.
- Milestone 2C keeps content and SEO as composable abilities (ADR 0008), so
  provider failure cannot break authoritative content reads.
- Milestone 2 exit gate is complete.
- Safe Yoast Premium 28.x and Local SEO 15.x module/version detection without
  exposing license or update state.
- Premium additional focus keyphrases normalized into bounded
  `keyphrase_details` with primary/additional roles and optional public scores;
  the backward-compatible `focus_keyphrases` list is retained.
- Local public business/location profiles derived only from Yoast's emitted
  Schema through recursive allowlists and bounded reference resolution.
- Single-location Kormas runtime verification passes with Yoast Free 28.0,
  Premium 28.0, and Local 15.8. A pure multi-location Schema contract test also
  passes, but a real multi-location fixture has not yet been exercised.
- SEO normalization schema 1.2, module-version diagnostics, Premium/Local leak
  tests, and updated Ability schemas.
- Bounded `wp-content-bridge/get-editorial-context` Ability with selectable
  `post_types`, `taxonomies`, `terms`, `authors`, `recent_content`, and
  `local_businesses` sections.
- Editorial context requires both configured READ and SEARCH access, reuses
  authorization-aware published-content search, exposes only authors observed
  in readable results, and obtains Local entities only from the normalized SEO
  provider contract.
- Editorial selection bounds: 20 post types, 20 taxonomies, 50 recent content
  items, 100 terms per taxonomy, and strict rejection of unavailable requested
  types/taxonomies.
- Editorial runtime verification passes for discovery, schema validation,
  policy denial, role/object authorization, deterministic twin execution,
  real HTTPS execution, Local public-profile projection, and sensitive-data
  leakage guards.
- Rendered-schema capture for Local multiple-location profiles: a bounded,
  same-origin `RenderedSchemaReader` port and WordPress adapter feed the
  `LocalSchemaProjector` from the target's public front-end JSON-LD, because the
  Yoast resolved meta surface omits branch (`parentOrganization`) schema. Wired
  into `YoastSeoProvider` for `local_businesses` only, with a Meta-surface
  fallback and explicit degraded warning (ADR 0009).
- Licensed Local multiple-location runtime matrix verified on Kormas local
  (Yoast Free/Premium/Local 28.0/28.0/15.8): primary organization profile and a
  non-primary branch with `parentOrganization`, branch address, geo, and hours,
  through both `get-url-seo` and `get-editorial-context` over real HTTPS, with
  bounds and private-option (`local_api_key`/`googlemaps_api_key`) leakage
  rejection. The fixture snapshots and restores the exact prior Local
  configuration.
- Current quality baseline: PHPCS 72/72 files, PHPStan 0 errors, PHPUnit 68
  tests with 165 assertions; all WordPress and HTTP runtime verifiers pass,
  including the new multiple-location verifier.
- ADR 0010: MCP transport and OAuth are external, principal-bound layers
  (Approach A), with a six-criterion evaluation gate for any OAuth candidate.
- OAuth candidate evaluation against the ADR 0010 gate
  (`docs/research/OAUTH_CANDIDATES.md`).
- Least-privilege bridge-reader fixture (`wpcb-bridge-reader`, capabilities
  `read` + `wpcb_read_content` only).
- Official MCP Adapter (`WordPress/mcp-adapter` v0.5.0) installed as site
  infrastructure, projecting exactly the five read abilities at
  `/wp-json/wpcb-mcp/mcp` (`docs/setup/MCP_ADAPTER.md`).
- Client-agnostic MCP smoke suite (`tests/Integration/mcp-smoke-verification.sh`)
  passes: discovery, schema retrieval, and execution of all five abilities via
  a disposable Application Password.
- ChatGPT connected live via miniOrange Secure MCP Connector
  (`miniorange-secure-mcp-server` v1.3.0) at `/wp-json/mosmcp/v1/mcp`: RFC
  8414/9728 discovery, RFC 7591 DCR, PKCE S256; token confirmed
  principal-bound to a WordPress user. Setup, self-test, and troubleshooting
  are in `docs/setup/CHATGPT_CONNECTOR.md`.
- Two live-audit defects fixed on this branch: `get-url-seo` no longer leaks
  the server filesystem path via Open Graph images
  (`src/Infrastructure/Yoast/YoastSeoProvider.php`); `get-diagnostics` no
  longer false-negatives on `mcp_adapter` detection
  (`src/Adapter/Abilities/ContentAbilities.php`).
- Write exposure closed: no `mosmcp/*` write grants remain on the site; writes
  stay globally blocked pending Milestones 5–7.
- Current quality baseline after M4 Phase 1: PHPCS 0 errors, PHPStan 0
  errors, PHPUnit 74 tests with 195 assertions (see `composer check` output
  referenced in the Task 7 report).
- Milestone 5 Plan 1 (writes foundation), merged `ab4805f`: `VersionToken`
  optimistic-concurrency primitive (Domain); typed Application failures
  `MutationConflict`/`InvalidBlockMarkup`/`SeoFieldUnsupported`; mutation ports
  `AuditEvent`/`AuditLog`/`BlockMarkupValidator`; `Installer` (schema v4) grants
  `wpcb_edit_content`/`wpcb_manage_seo`/`wpcb_publish_content`, registers master
  flags `wpcb_writes_enabled`/`wpcb_publish_enabled` (both false, non-autoloaded),
  and creates the capped `{prefix}wpcb_audit` table via `dbDelta`; redacting
  `WordPressAuditLog` (field names only + `do_action('wpcb_mutation')`). No write
  ability is registered — abilities appear only when their master flag is on.
- Quality baseline after M5 Plan 1: PHPCS 0 errors, PHPStan 0 errors, PHPUnit
  85 tests with 211 assertions; `writes-foundation-verification.php` PASS.
- Milestone 5 Plan 2 (`create-draft` + `update-content`), merged `28818ab`: the
  plugin's first live write surface. `TaxonomyAssignment`/`DraftInput`/
  `ContentUpdate`/`MutationResult` DTOs (Domain); `ContentMutationRepository`/
  `IdempotencyStore` ports, `MutationForbidden`/`MutationWriteFailed` typed
  failures, `CreateDraft`/`UpdateContent` use cases (Application) — each
  validates input, enforces per-post-type policy, checks block markup,
  performs the write, and records exactly one audit row per attempt (fixed to
  hold even if the audit sink itself throws, `bc47f8a`); `PhpBlockMarkupValidator`,
  `WordPressContentMutationRepository` (`wp_insert_post`/`wp_update_post` +
  revisions, never sets `publish`/`future`/`pending`), and
  `WordPressTransientIdempotencyStore` (per-user 24h transient) (Infrastructure);
  `MutationAbilities` (Adapter) registers both abilities — with capability +
  native-object permission callbacks and stable `WP_Error` mapping — only when
  `wpcb_writes_enabled` is on. Additive `version_token` field added to
  `get-content` output (`ContentDetail`, `WordPressContentRepository::get()`,
  `AbilitySchemas::get_output()`) — the one permitted read-ability touch.
  `Plugin.php` wires the write services behind the flag; the settings page
  gained the global "Enable content writes" checkbox. Runtime write verifier
  (`tests/Integration/writes-mutation-verification.php`) proves the
  authorization matrix (plugin cap, native cap, and policy independently
  required), the no-publish invariant, stale-version-conflict rejection,
  revision creation, block round-trip (valid survives, unregistered block
  rejected), idempotent create, and audit redaction. Quality baseline: PHPCS 0
  errors, PHPStan 0 errors, PHPUnit 120 tests with 309 assertions.

## Not implemented

- Premium fields beyond the normalized synonyms/related-keyphrase contract in
  ADR 0014.
- Per-target Yoast analysis scores. Yoast's documented score Abilities return
  recent-post lists without stable post IDs, so they cannot safely be joined to
  a requested object.
- Codex/Gemini manual walkthrough (secondary/deferred for Phase 1; covered
  only by the client-agnostic smoke suite).
- Strict least-privilege re-consent as `wpcb-bridge-reader` (Task 6's live
  ChatGPT consent was done as admin `dev` for exploration; re-run on staging
  with a real certificate).
- Controlled status transitions (`transition-content-status`) are not
  implemented. Public and scheduled transitions are part of that future
  contract; the old `publish-content` plan is superseded by ADR 0015.
  `list-block-patterns`, `update-seo`, `create-draft`, and `update-content` are
  implemented; pattern runtime sign-off is pending.
- Media P1 writes are not implemented: `update-media`, upload, featured-image
  assignment/removal, and remote import remain separately gated future work.
- Runtime verification of the current official Adapter profile and explicit
  miniOrange grants for the intended principal. The OAuth grant is site
  configuration and must not use a wildcard or enable unrelated `mosmcp/*`
  tools.
- Restore-from-trash. `trash-content` shipped in 0.1.5 as reversible, but no
  ability undoes it; recovery requires wp-admin. **Decided 2026-08-07:**
  `restore-trashed-content` is built in 0.4.5, pulled forward out of roadmap
  Slice 3 because the destructive half is already live. It must never reach
  `publish` or `future`; see `docs/plan/RELEASE_0_4_5_PLAN.md` task 1.
- A reproducible verification environment. Runtime sign-off still depends on one
  machine's Local instance. Scheduled for 0.4.5 task 4, with an explicit split
  between verifiers that run anywhere and those that need the licensed Yoast
  and Schema Extended providers.
- Role-management UI beyond the capability grant.
- Agents API integration.

## Next action

Released state: **0.4.0**, tagged `v0.4.0` on `origin`. Versions 0.2.0 through
0.4.0 all shipped.

**Next release is `0.4.5`, a consolidation release.** It is not a roadmap
slice. Its executable plan is `docs/plan/RELEASE_0_4_5_PLAN.md` and it contains
seven tasks: `restore-trashed-content`, unifying the preview response flag,
`list-block-patterns` runtime sign-off, a reproducible verification
environment, retiring the inert `wpcb_public_base_url` option, the Milestone 5
security sign-off, and the release itself. Nothing in it is breaking.

Slice 1B (llms.txt) is `0.5.0`, not `0.4.0`. That split was decided on
2026-08-07: Slice 1A was small, low-risk, and verified, and holding it behind
the heaviest slice in the roadmap would have delayed it for nothing. The full
renumbering is in the "Release numbering" table of
`docs/plan/EDITORIAL_OPERATIONS_ROADMAP.md`.

**Three planned preview Abilities were cut on 2026-08-07**:
`preview-transition-content-status`, `preview-update-media`, and
`preview-update-featured-image`. The roadmap now carries an explicit test for
when a preview is justified — it must answer something the caller cannot get
from the matching `get-*` read plus the payload it already holds. Echoing back
a sanitized string the caller just sent is not a preview. The test also records
that of the two shipped in 0.4.0, `preview-update-content` clears it
comfortably (block round-trip plus replace-not-merge warnings) while
`preview-update-seo` is close to an echo and is kept for symmetry rather than
because it earns its keep.

### Historical note

Before 0.4.0 the released state was **0.3.0**, tagged `v0.3.0` (`be3b177`).

**Verification environment restored on 2026-08-07 and all eleven PHP runtime
verifiers pass** against WordPress 7.0.2, PHP 8.4, Yoast Free 28.2, and Premium
28.0. The 2026-07-21 blocker was environment configuration, not a stopped
database: `DB_HOST` pointed at a stale Local socket path and `WP_HOME` had lost
its domain. Details, including the four drifted verifiers repaired during the
run and the one real schema defect found, are in the "Runtime verification
backlog" section of `docs/plan/IMPLEMENTATION_PLAN.md`.

Fourteen runtime verifiers now pass, including two written on 2026-08-07 for
the Service and Custom Schema slices, one for the 0.4.0 previews, and the MCP
discovery smoke against the official Adapter.

The MCP projection gap and the miniOrange grants were both closed on
2026-08-07; see "MCP exposure and grants" below. Remaining, all scheduled into
`0.4.5` (`docs/plan/RELEASE_0_4_5_PLAN.md`):

1. `restore-trashed-content` — task 1. Closes the live asymmetry where a
   connector can trash but not untrash.
2. Unify the preview response flag — task 2. The 0.3.0 previews report
   `dry_run: true`, the 0.4.0 previews report `writes_performed: false`. One
   concept, two names, opposite polarity. Fixed additively; `dry_run` is
   removed in `0.5.0`.
3. `list-block-patterns` runtime sign-off — task 3. It is code-complete, in the
   closed profile, and in a live grant set, but has never been exercised.
4. Reproducible verification environment — task 4. `.wp-env.json` already
   exists in the app repo.
5. Retire the inert `wpcb_public_base_url` option — task 5.
6. Milestone 5 security sign-off — task 6.
7. Release packaging — task 7. Verified on 2026-08-07: 74 files under `docs/`
   and `.agents/` ship inside the production plugin ZIP, including the security
   model, known gaps, and notes about the consuming site's grants. The same
   task fixes the release trigger, which fires on any push touching the version
   line and on 2026-08-07 published a `v0.4.0` built from the rename commit
   alone, missing all of Slice 1A. That release was deleted and re-cut from
   `52cb2a2`; the current `v0.4.0` artifact is correct and was verified by
   listing the ZIP.

**Open manual step, not automatable from this repository:** the old root-owned
`cloudflared` service on the development machine still needs uninstalling. It
is a `sudo`-level operation outside the repo. The dev-only MU shim has already
been removed.

Slice 1B (llms.txt) is `0.5.0` and starts after 0.4.5. Slice 2
`transition-content-status` is `0.6.0`; it is not the next action and never
was 0.5.0 under the current numbering.

## MCP exposure and grants

Verified against the running site on 2026-08-07.

The official Adapter now projects all 18 registered abilities. The site's
projection package `isudev/wp-content-bridge-mcp-server` was one release behind
at 0.2.1 with 15 entries, so the three Custom Schema abilities from 0.3.0 were
registered in WordPress but unreachable over MCP. The package was bumped to
0.3.0 in the consuming site's repository and the smoke run confirms 18/18.

The separate miniOrange per-principal grants for `wpcb-bridge-reader` did not
match the documented least-privilege profile in either direction. They granted
`create-draft` — a write intent on a read-only principal — while missing
`search-content`, `get-content`, and `get-url-seo`. The layered defense held
(user 87 has no role and only `wpcb_read_content`, so the native `edit_posts`
gate would have refused the call), but the grant contradicted this file's own
claim that no write grants remained. The write grant was removed and the three
missing reads added; the principal now holds exactly the five documented reads
plus `list-block-patterns`.

**Decision 2026-08-07: `list-block-patterns` stays in the `wpcb-bridge-reader`
grant set and its review is deferred to 0.4.5.** It is a genuine read, it is
independently gated behind `wpcb_pattern_reads_enabled` and
`wpcb_read_patterns`, and Plan 4a has never had a runtime sign-off. Revisiting
it now would block the 0.4.0 release on an unrelated verification; 0.4.5 should
either complete that sign-off or drop the grant.

Treat the earlier "no `mosmcp/*` write grants remain" note as unverified
history: it was written without a live check. External MCP allowlists remain
site infrastructure and must be updated only when new abilities are
intentionally exposed to a specific principal.

## Guardrail

Do not start write abilities until Milestones 1–3 pass their security and contract acceptance criteria.

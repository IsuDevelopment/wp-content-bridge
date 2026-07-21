# Project status

## Current phase

Milestone 5 (writes) — **in progress.** Executed as four sequential plans.
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
checks. **Known gap:** the site-infrastructure MCP glue (`wpcb-mcp-server.php`
mu-plugin and the ChatGPT-facing miniOrange OAuth config) still hardcode an
explicit allowlist of only the five original read abilities — the two new write
abilities are reachable directly (PHP/Abilities API, REST via the Abilities
REST routes) but are **not yet visible to any MCP client** until that
site-infra allowlist is updated separately (outside this plugin repo). Next is
**Plan 3** (`update-seo`).

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
- SEO normalization schema 1.1, module-version diagnostics, Premium/Local leak
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

- Premium synonyms (their stable public/storage contract has not been proven).
- Per-target Yoast analysis scores. Yoast's documented score Abilities return
  recent-post lists without stable post IDs, so they cannot safely be joined to
  a requested object.
- Codex/Gemini manual walkthrough (secondary/deferred for Phase 1; covered
  only by the client-agnostic smoke suite).
- Strict least-privilege re-consent as `wpcb-bridge-reader` (Task 6's live
  ChatGPT consent was done as admin `dev` for exploration; re-run on staging
  with a real certificate).
- Live SEO writes (`update-seo`) and controlled publication
  (`publish-content`) and their abilities — M5 Plan 1 foundation exists (the
  `SeoFieldUnsupported` failure type and `wpcb_manage_seo`/
  `wpcb_publish_content` capabilities/flags) but no ability is registered yet.
  `list-block-patterns` is not built. (`create-draft`/`update-content` ARE now
  implemented and reachable, Plan 2; the `wpcb_writes_enabled` Settings-page
  checkbox IS built, Plan 2 — the still-missing toggle is `wpcb_publish_enabled`,
  which is Plan 4's job.)
- MCP exposure of the two new write abilities: the site-infrastructure MCP glue
  (`wpcb-mcp-server.php` mu-plugin and the ChatGPT-facing miniOrange OAuth
  scope) still hardcode an explicit five-read-ability allowlist and have not
  been updated to add `create-draft`/`update-content` — this is a site-config
  change outside the plugin repo, not a plugin-code gap.
- Role-management UI beyond the capability grant.
- Agents API integration.

## Next action

Milestone 5 **Plan 3** — `update-seo`: `SeoUpdate` DTO, `SeoWriter` port +
`YoastFreeSeoWriter` (Yoast Free core allowlist only, via Yoast's documented
write path, re-read after write), `UpdateSeo` use case, ability, schema, and a
SEO write/re-read runtime verifier. Write Plan 3 just-in-time (superpowers
`writing-plans`) and execute via subagent-driven development, mirroring Plans
1–2. Design spec: `docs/superpowers/specs/2026-07-20-milestone-5-writes-design.md`;
four-plan split: `docs/plan/IMPLEMENTATION_PLAN.md` (Milestone 5). Writes stay
behind the off-by-default master flags until each plan's exit gate passes.

Separately (not blocking Plan 3, but needed before any external MCP client can
exercise the two Plan 2 write abilities): update the site-infrastructure MCP
glue (`content/mu-plugins/wpcb-mcp-server.php` and the miniOrange
ChatGPT-facing OAuth scope) to add `wp-content-bridge/create-draft` and
`wp-content-bridge/update-content` to their currently read-only allowlists —
see `docs/setup/MCP_ADAPTER.md`.

Independent open thread (M4): stabilize on staging with a real TLS certificate
(replacing the local cloudflared quick tunnel) and re-run the strict
least-privilege `wpcb-bridge-reader` consent. Re-run commands:

```bash
WPCB_SITE_URL=https://kormas-isu.local \
WPCB_WP_ROOT="/Users/lukaszbiedron/Local Sites/kormas-isu/app/public" \
WPCB_MCP_PATH="/wp-json/wpcb-mcp/mcp" \
"/Users/lukaszbiedron/Other Projects/wp-content-bridge/tests/Integration/mcp-smoke-verification.sh"
```

ChatGPT connector self-test: see `docs/setup/CHATGPT_CONNECTOR.md`.

For cross-agent/session continuation, read `.continue-here.md` before making
changes. It contains verified commands, environment caveats, and decisions.

## Guardrail

Do not start write abilities until Milestones 1–3 pass their security and contract acceptance criteria.

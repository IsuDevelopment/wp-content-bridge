# Project status

## Current phase

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
- Runtime verification of the 0.2.0 official Adapter profile and explicit
  miniOrange grants for the intended principal. The OAuth grant is site
  configuration and must not use a wildcard or enable unrelated `mosmcp/*`
  tools.
- Role-management UI beyond the capability grant.
- Agents API integration.

## Next action

1. Run the remaining integration-access, media, cache, pattern, and trash
   runtime verifiers recorded in `.continue-here.md`; update-seo is green.
2. Delete the now-inert `wpcb_public_base_url` option and uninstall the old
   root-owned cloudflared service; the dev-only MU shim has already been removed.
3. Verify the complete 0.2.0 profile through the official Adapter, then update
   and verify the separate miniOrange grants for the intended principal.
4. Ship 0.2.0 after runtime sign-off.
5. Start Plan 4c `transition-content-status`; keep the explicit transition
   graph and the stronger public/scheduled gates defined by ADR 0015.

External MCP allowlists remain site infrastructure and must be updated only
when the new abilities are intentionally exposed to a specific principal.

## Guardrail

Do not start write abilities until Milestones 1–3 pass their security and contract acceptance criteria.

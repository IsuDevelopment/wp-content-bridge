# WP Content Bridge

WP Content Bridge is a standalone WordPress 7 plugin that exposes secure,
provider-neutral **content and SEO capabilities** through the WordPress
Abilities API. The official WordPress MCP Adapter can project those abilities to
ChatGPT, Codex, Gemini, and other MCP clients — so an agent can read (and, from
Milestone 5 on, safely write) your site's content and SEO without direct
database or REST access.

The repository is intentionally independent from any site project. During
development it is symlinked into a local WordPress install for integration
testing.

- **WordPress:** 7.0+ **PHP:** 8.2+
- **Standard:** WordPress Coding Standards (PHPCS), PHPStan max level, PHPUnit 11
- **Architecture:** DDD layers — Domain → Application → Infrastructure → Adapter

---

## What you can do with it today

### Read abilities (live, read-only)

Five private Abilities are registered. All require the `wpcb_read_content`
capability; object reads additionally enforce a per-post-type READ policy **and**
native WordPress object-access checks.

| Ability | What it does |
|---|---|
| `wp-content-bridge/search-content` | Authorization-aware content search with bounded taxonomy filters. Authorization is applied **before** pagination; candidate scans are capped at 1,000 and inexact totals are labelled, so unreadable objects never leak through counts. |
| `wp-content-bridge/get-content` | Single-object detail in raw / rendered / plain-text representations, with per-representation byte sizes and a combined 2 MiB cap (`wpcb_content_too_large`). |
| `wp-content-bridge/get-url-seo` | Provider-neutral SEO for a post ID or same-origin URL: title, description, canonical, robots, Open Graph, Twitter, and a bounded Schema graph. |
| `wp-content-bridge/get-editorial-context` | Bounded, context-only composition: selectable `post_types`, `taxonomies`, `terms`, `authors`, `recent_content`, `local_businesses`. Never calls an LLM and never generates a plan. |
| `wp-content-bridge/get-diagnostics` | Safe environment/provider status (no secrets, no license/update state). |

Content and SEO stay **composable, not embedded** (ADR 0008): an SEO-provider
failure can never break an authoritative content read.

### Media reads (0.1.3 — off by default)

Two dedicated read abilities are registered only after an administrator enables
the `wpcb_media_reads_enabled` setting. They require `wpcb_read_media` plus
native `read_post` permission for every returned attachment:

| Ability | What it does |
|---|---|
| `wp-content-bridge/get-media` | Returns a strict object envelope and bounded pagination. Supports exact attachment ID, exact same-site original URL, exact filename, or text search. |
| `wp-content-bridge/get-media-by-id` | Deterministically returns one authorized attachment without revealing whether a missing result was absent or denied. |

Every media item contains ID, title, filename, URL, ALT, caption, description,
and MIME type. Content summaries also return `featured_image_id` and
`featured_image_url` together, or both as null.

Successful bridge mutations also invalidate the affected WordPress post cache.
When LiteSpeed Cache is active, its public post-scoped purge hook is dispatched
for the same ID, including metadata-only SEO updates that may not trigger its
normal post lifecycle integration. WP Content Bridge never performs a global
cache flush (ADR 0012).

### Block-pattern reads (0.1.3 — off by default)

`wp-content-bridge/list-block-patterns` is registered only after enabling
`wpcb_pattern_reads_enabled`. It requires `wpcb_read_patterns` plus native
editor-level permission. The ability returns deterministic, bounded pattern
metadata by default and optional complete block markup under a combined 2 MiB
limit. It never exposes pattern file paths or triggers remote WordPress.org
pattern loading (ADR 0013).

### SEO (read)

A provider-neutral SEO model (`src/Domain/Seo`) with a Yoast adapter
(`src/Infrastructure/Yoast`) covering **Yoast Free / Premium / Local 28.x**:

- documented Yoast Surfaces output (resolved title/description/canonical/robots/
  social/Schema);
- a narrow, version-gated configured-meta allowlist (never arbitrary postmeta);
- Premium primary/additional keyphrases with optional public scores;
- Local single- and multiple-location public business profiles, including branch
  `parentOrganization` schema captured via a bounded same-origin rendered-schema
  fetch (ADR 0009).

No arbitrary postmeta, raw provider options, license/update state, secrets, or
Yoast indexables-table rows are ever returned.

### MCP client interoperability (Milestone 4 Phase 1, complete)

- The official `WordPress/mcp-adapter` projects exactly the five read abilities
  at `/wp-json/wpcb-mcp/mcp` (App-Password auth) — it is site infrastructure,
  **not** bundled in this plugin (Approach A, ADR 0010).
- An **external** OAuth 2.1 layer (miniOrange Secure MCP Connector) fronts
  ChatGPT's connector at `/wp-json/mosmcp/v1/mcp` (DCR + PKCE; token
  principal-bound to a WordPress user).
- ChatGPT has completed the five-ability read scenario live.
- Setup guides: `docs/setup/MCP_ADAPTER.md`, `docs/setup/CHATGPT_CONNECTOR.md`.

The plugin settings page includes **Integration user access** for a single-site
deployment. Enter an existing, dedicated non-administrator WordPress user's
login or email and assign only the required Content Bridge capabilities. The
user must already have native WordPress `read` through its role; object-level
permissions, content-type policy, feature flags, and connector grants remain
independent gates. Selecting a different managed user revokes the six managed
WPCB operational capabilities from the previous account.

### Write abilities (Milestone 5 Plans 2–3, complete — off by default)

Three write abilities are implemented and reachable once an administrator
turns on `wpcb_writes_enabled` (still off by default) and the relevant
per-post-type policy:

| Ability | What it does |
|---|---|
| `wp-content-bridge/create-draft` | Creates a new post/page/CPT, always as `draft` — no status input, so it can never publish as a side effect. Supports an idempotency key for safe replay. |
| `wp-content-bridge/update-content` | Updates title/content/excerpt/taxonomies on an existing post via optimistic concurrency (`version_token`); creates a WordPress revision on every write; never touches `post_status`. |
| `wp-content-bridge/update-seo` | Writes the version-tested Yoast Free core-field allowlist plus normalized Premium 28.x primary synonyms and related keyphrases, then re-reads `effective_seo`. Raw Premium JSON and fields outside the allowlist are rejected. |

Shared invariants across all three:

- `VersionToken` optimistic-concurrency primitive (mismatch → `wpcb_conflict`);
- capabilities `wpcb_edit_content` (create-draft/update-content) and
  `wpcb_manage_seo` (update-seo), plus native WordPress object capabilities and
  per-post-type policy (`ContentAccessManager`) — independently enforced gates;
- the `wpcb_writes_enabled` master flag and (separately) `wpcb_publish_enabled`
  for publishing — both **off by default**; abilities are not registered while
  their flag is off, so they are invisible over MCP;
- a capped `{prefix}wpcb_audit` table + `do_action( 'wpcb_mutation', … )` hook
  recording field **names** only (never content, SEO values, or secrets).

**Not yet visible to any MCP client:** the site-infrastructure MCP glue still
hardcodes an explicit five-read-ability allowlist that has not been updated to
include pattern/media reads or any of the three write abilities — see
`docs/setup/MCP_ADAPTER.md`.

`publish-content` (Plan 4) remains planned; see the roadmap below.

---

## Security model (at a glance)

- **Least privilege:** every ability is capability-gated; writes add per-operation
  capabilities on top of two master flags that are off until an administrator
  turns them on.
- **Publication is doubly gated:** `publish-content` needs its own
  `wpcb_publish_enabled` flag *and* the `wpcb_publish_content` capability; no
  create/update path can publish.
- **Optimistic concurrency:** writes carry a `version_token` read from
  `get-content`; a stale token is rejected with `wpcb_conflict` and never
  overwrites a newer edit.
- **Redacted audit:** the audit table stores who/which-ability/which-fields and
  outcome — never post content or secret values.
- **No ambient authority:** MCP/OAuth transport is external and principal-bound;
  a credential can never exceed its bound WordPress user's authority.

Full model: `docs/architecture/SECURITY.md`.

---

## Architecture

Strict DDD layering (enforced in review — see `docs/architecture/CODE_MAP.md`):

```
src/
  Domain/          pure PHP, never calls WordPress (ContentAccess, Content, Seo, Mutation)
  Application/     use cases + ports, never calls WordPress (…/Mutation ports live here)
  Infrastructure/  the ONLY layer allowed to call WordPress (WordPress/, Yoast/)
  Adapter/         maps I/O + WP_Error; Abilities registration
```

- **Domain / Application** are framework-free and unit-tested with fakes (PHPUnit).
- **Infrastructure** (WordPress/Yoast calls) is verified by runtime scripts in
  `tests/Integration/` run via `wp eval`.
- DTOs are `final readonly` with a factory that throws `InvalidArgumentException`
  on bad input.

---

## Development

```bash
composer install
composer check          # PHPCS + PHPStan (max) + PHPUnit
```

Individual tools:

```bash
vendor/bin/phpcs --report=summary
vendor/bin/phpstan analyse --memory-limit=512M --no-progress
vendor/bin/phpunit --colors=never
```

Runtime (WordPress-touching) verifiers run inside the symlinked Local install:

```bash
cd "/Users/lukaszbiedron/Local Sites/kormas-isu/app"
wp eval 'require "<repo>/tests/Integration/abilities-runtime-verification.php";'
wp eval 'require "<repo>/tests/Integration/authorization-matrix.php";'
wp eval 'require "<repo>/tests/Integration/writes-foundation-verification.php";'
wp eval 'require "<repo>/tests/Integration/writes-mutation-verification.php";'
wp eval 'require "<repo>/tests/Integration/writes-seo-verification.php";'
```

**Current baseline:** PHPCS clean · PHPStan 0 errors · PHPUnit 155 tests /
380 assertions. Media, integration-access, update-SEO, and cache runtime
verification on WordPress 7.0.2 is pending while Kormas Local is stopped.

Read [AGENTS.md](AGENTS.md) before making changes, and `.continue-here.md` for
the current continuation point.

---

## Roadmap

| Milestone | Status |
|---|---|
| M0 scaffold · M1 read core · M2 Yoast Free SEO · M3 Premium/Local + editorial | ✅ complete |
| M4 Phase 1 — ChatGPT MCP read interoperability | ✅ complete (staging TLS re-consent pending) |
| **M5 writes** — executed as 4 plans | 🚧 Plans 1–3 done |
| ↳ Plan 1 — writes foundation | ✅ complete |
| ↳ Plan 2 — `create-draft` + `update-content` | ✅ complete |
| ↳ Plan 3 — `update-seo` (Free + bounded Premium keyphrases) | 🧪 code complete; local runtime pending |
| Media P0 — deterministic reads + featured-image identity | 🧪 code complete; local runtime pending |
| Post-scoped cache invalidation (WordPress + optional LiteSpeed) | 🧪 code complete; local runtime pending |
| ↳ Plan 4a — `list-block-patterns` | 🧪 code complete; local runtime pending |
| ↳ Plan 4b — `publish-content` (separately gated) | planned after security review |
| M8 — optional Agents API integration | deferred (needs ADR reassessment) |

Details: [docs/plan/IMPLEMENTATION_PLAN.md](docs/plan/IMPLEMENTATION_PLAN.md).

---

## Product boundary

- The WordPress **Abilities API** defines the stable domain contracts.
- The **MCP Adapter** is an optional projection dependency, not bundled here.
- **Yoast SEO** is an optional SEO provider; content reads work without it.
- The **Agents API** is reserved for a later optional embedded-agent integration
  and is not required for external-client usage.

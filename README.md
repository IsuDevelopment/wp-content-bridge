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

### Write foundation (Milestone 5 Plan 1, complete — no live writes yet)

The scaffolding for safe writes is in place but **no write operation is wired or
reachable yet**. Present:

- `VersionToken` optimistic-concurrency primitive (mismatch → `wpcb_conflict`);
- capabilities `wpcb_edit_content`, `wpcb_manage_seo`, `wpcb_publish_content`
  granted to `administrator`;
- two master feature flags — `wpcb_writes_enabled` and `wpcb_publish_enabled` —
  both **off by default**; abilities are not registered while their flag is off,
  so they are invisible over MCP;
- a capped `{prefix}wpcb_audit` table + `do_action( 'wpcb_mutation', … )` hook
  recording field **names** only (never content or secrets).

See the roadmap below for what unlocks the actual write abilities.

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
cd "/Users/lukaszbiedron/Local Sites/kormas-isu/app/public"
wp eval 'require "<repo>/tests/Integration/abilities-runtime-verification.php";'
wp eval 'require "<repo>/tests/Integration/authorization-matrix.php";'
wp eval 'require "<repo>/tests/Integration/writes-foundation-verification.php";'
```

**Current baseline:** PHPCS clean · PHPStan 0 errors · PHPUnit 85 tests /
211 assertions · all runtime verifiers pass on WordPress 7.0.1.

Read [AGENTS.md](AGENTS.md) before making changes, and `.continue-here.md` for
the current continuation point.

---

## Roadmap

| Milestone | Status |
|---|---|
| M0 scaffold · M1 read core · M2 Yoast Free SEO · M3 Premium/Local + editorial | ✅ complete |
| M4 Phase 1 — ChatGPT MCP read interoperability | ✅ complete (staging TLS re-consent pending) |
| **M5 writes** — executed as 4 plans | 🚧 Plan 1 (foundation) done |
| ↳ Plan 2 — `create-draft` + `update-content` | ⏭ next |
| ↳ Plan 3 — `update-seo` (Yoast Free allowlist) | planned |
| ↳ Plan 4 — `publish-content` (gated) + `list-block-patterns` | planned |
| M8 — optional Agents API integration | deferred (needs ADR reassessment) |

Details: [docs/plan/IMPLEMENTATION_PLAN.md](docs/plan/IMPLEMENTATION_PLAN.md).

---

## Product boundary

- The WordPress **Abilities API** defines the stable domain contracts.
- The **MCP Adapter** is an optional projection dependency, not bundled here.
- **Yoast SEO** is an optional SEO provider; content reads work without it.
- The **Agents API** is reserved for a later optional embedded-agent integration
  and is not required for external-client usage.

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

Status: **in progress**. Milestones 3A and 3B and the tested portion of 3C are
complete. Real multi-location Local runtime verification remains.

### 3A — module detection and neutral contracts

Status: complete.

- safe Premium/Local slug and public version reporting;
- normalization schema 1.1 fields for detailed keyphrases and public local
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

Status: partially complete and verified on Kormas for Yoast Free 28.0 + Premium
28.0 + Local 15.8 in single-location mode.

- Premium additional keyphrases: complete for the tested 28.x JSON envelope;
- Local public profile: complete from emitted Schema for the tested 15.x
  single-location fixture;
- pure multi-location/branch Schema projection: covered by unit contract;
- Premium synonyms and real multi-location runtime matrix: pending.

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

The milestone is not yet closed because the licensed multi-location runtime
matrix remains.

## Milestone 4 — MCP client interoperability

Deliverables:

- official MCP Adapter installation/setup guide;
- local STDIO recipes for Codex and Gemini CLI;
- remote HTTPS/auth threat review and ChatGPT-compatible setup path;
- client contract smoke suite for discovery, schema retrieval, and execution;
- troubleshooting diagnostics.
- connection control-plane design informed by the local LLMagnet comparison;
- ADR-backed decision between official adapter/gateway authentication and a
  plugin-owned, principal-bound managed-key/OAuth implementation;
- if managed credentials are approved: one-time secret display, hashing,
  expiry/revocation, scope reduction, WordPress-user binding, rate limits, and
  activity event persistence.

Exit gate:

- at least Codex and Gemini complete the same read scenario;
- ChatGPT limitations/requirements are documented from an actual supported setup before claiming support;
- no credentials are committed or shown in logs.
- no credential can grant more authority than its bound WordPress user.

## Milestone 5 — safe draft mutations

Deliverables:

- plugin capabilities and role-management UI/CLI strategy;
- mutation DTOs/services;
- `create-draft` and `update-content` abilities;
- idempotency strategy for draft creation;
- optimistic concurrency, revisions, and audit events;
- writes disabled globally until configured.

Tests:

- per-object authorization;
- stale version conflicts;
- revision creation and rollback feasibility;
- taxonomy/media authorization;
- sanitization preserving valid blocks;
- repeated/idempotent request behavior;
- mutation audit redaction.

Exit gate:

- no path can publish;
- conflicts never overwrite newer edits;
- security review signs off before beta.

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
- Premium/Local writes remain out until separately specified.

## Milestone 7 — controlled publication

Deliverables:

- disabled-by-default feature flag;
- dedicated publish capability;
- separate `publish-content` ability;
- approval-compatible request/result contract;
- scheduled-content policy and audit trail.

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

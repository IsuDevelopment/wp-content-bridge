# Code map

This is the onboarding map for humans and AI agents. Update it whenever a directory gains a new responsibility or a use case crosses a new boundary.

## Runtime entry points

- `wp-content-bridge.php` — plugin header, Composer guard, activation hook, `plugins_loaded` bootstrap.
- `src/Plugin.php` — composition root; runs schema upgrades and wires the admin,
  application, WordPress repository, and Abilities adapters.

## Read request flow

```text
WordPress Ability
  -> plugin capability (`wpcb_read_content`)
  -> SearchContent or GetContent application service
  -> ContentAccessManager post-type operation policy
  -> ContentRepository port
  -> WordPressContentRepository
  -> native `read_post` / readable WP_Query filtering
  -> immutable result DTO
  -> Ability output schema validation
```

Rules for this flow:

- `ContentAbilities` owns WordPress callbacks and `WP_Error` mapping only.
- `SearchContent` and `GetContent` own use-case ordering and policy decisions.
- `WordPressContentRepository` owns WordPress querying, rendering, relationship
  loading, and native object checks.
- Domain DTOs do not call WordPress or know about MCP.
- MCP authentication/projection is outside this flow and cannot bypass it.

## Content access feature

- `src/Domain/ContentAccess/ContentOperation.php` — stable operation vocabulary and configuration dependencies.
- `src/Domain/ContentAccess/ContentTypePolicy.php` — immutable normalization and dependency enforcement for one row.
- `src/Domain/ContentAccess/ContentTypeDefinition.php` — transport-neutral post-type descriptor.
- `src/Application/ContentAccess/ContentAccessManager.php` — shared use-case policy service; future content abilities call this.
- `src/Application/ContentAccess/ContentAccessSettingsRepository.php` — storage port.
- `src/Application/ContentAccess/ContentTypeCatalog.php` — post-type discovery port.
- `src/Infrastructure/WordPress/WordPressContentAccessSettingsRepository.php` — `wp_options` adapter.
- `src/Infrastructure/WordPress/WordPressContentTypeCatalog.php` — registered post-type adapter and eligibility rules.
- `src/Infrastructure/WordPress/Installer.php` — versioned capability/option setup.
- `src/Adapter/Admin/ContentAccessSettingsPage.php` — Settings API and HTML adapter; contains no policy rules.
- `tests/Unit/Domain/ContentAccess/ContentTypePolicyTest.php` — dependency/default parsing contract.

## Read-only content feature

- `src/Domain/Content/` — immutable query and result DTOs; no WordPress calls.
- `src/Domain/Content/TaxonomyFilter.php` — bounded taxonomy/term selection
  invariant used by search.
- `src/Application/Content/ContentRepository.php` — content read port.
- `src/Application/Content/TaxonomyCatalog.php` — taxonomy eligibility and
  effective-type assignment port.
- `src/Application/Content/SearchContent.php` — effective-type intersection and search use case.
- `src/Application/Content/GetContent.php` — non-enumerating detail use case and
  2 MiB selected-representation limit.
- `src/Application/Content/ContentPayloadTooLarge.php` — typed application
  failure for an oversized detail response.
- `src/Infrastructure/WordPress/WordPressContentRepository.php` — WP_Query, representations, relationships, and native object authorization.
- `src/Infrastructure/WordPress/WordPressTaxonomyCatalog.php` — public/REST
  taxonomy discovery and all-effective-types assignment checks.
- `src/Adapter/Abilities/AbilitySchemas.php` — public input/output JSON Schemas.
- `src/Adapter/Abilities/ContentAbilities.php` — thin WordPress Abilities projection and stable error boundary.
- `tests/Unit/Domain/Content/ContentQueryTest.php` — defaults, normalization, bounds, and effective-type copy contract.
- `tests/Unit/Adapter/Abilities/AbilitySchemasTest.php` — strict public schema,
  taxonomy-bound, pagination-safety, and payload-metadata contract.
- `tests/Unit/Domain/Content/ContentDetailTest.php` — byte accounting contract.
- `tests/Integration/authorization-matrix.php` — isolated role/object fixtures,
  policy independence, search-total privacy, representation safety, and payload
  boundary verification.
- `tests/Integration/abilities-runtime-verification.php` — runtime inventory,
  schemas, annotations, permissions, deterministic execution, and REST checks.
- `tests/Integration/http-url-runtime-verification.sh` — disposable
  least-privilege Application Password principal and real-HTTP verification of
  post URL canonicalization, public `yoast_head_json` parity, homepage behavior,
  and external-origin rejection.
- `tests/Integration/yoast-configured-runtime-verification.php` — disposable
  post fixture for explicit/inherited values, partial results without an
  indexable, and arbitrary-meta leakage.

## Provider-neutral SEO feature

- `src/Domain/Seo/` — immutable target, field-state, provider-status,
  completeness, and bounded normalized-document contracts.
- `src/Application/Seo/SeoProvider.php` — optional provider port.
- `src/Application/Seo/NullSeoProvider.php` and `SeoProviderRegistry.php` —
  explicit no-provider behavior and ordered feature detection.
- `src/Application/Seo/SameSiteSeoTargetFactory.php` — exclusive post/URL
  selector and exact-origin URL validation.
- `src/Application/Seo/GetSeo.php` and `SeoTargetAccess.php` — authorization
  boundary followed by provider-neutral retrieval.
- `src/Infrastructure/WordPress/WordPressSeoTargetAccess.php` — content-policy
  plus native-object authorization for post-backed selectors; readable post
  URLs are canonicalized to post selectors after authorization so stale
  provider URL indexes cannot break content-object reads.
- `src/Infrastructure/Yoast/YoastSeoProvider.php` — documented resolved Yoast
  Surfaces output, safe Free/Premium/Local detection, and version-gated
  configured-meta allowlists; no options or indexables-table reads.
- `src/Infrastructure/Yoast/PremiumKeyphraseNormalizer.php` — pure bounded
  normalizer for tested Premium 28.x primary/additional-keyphrase data; strips
  unknown members and emits provider-neutral roles/scores.
- `src/Infrastructure/Yoast/LocalSchemaProjector.php` — recursive allowlist
  projection of public Place/LocalBusiness data from provider-emitted Schema,
  including bounded public references to address, geo, hours, and branch data
  (`parentOrganization` and schema.org `branchOf`).
- `src/Application/Seo/RenderedSchemaReader.php` — port for a same-origin
  public JSON-LD graph. Used for Local multiple-location branch data that the
  resolved meta surface omits.
- `src/Infrastructure/WordPress/WordPressRenderedSchemaReader.php` — bounded,
  same-origin, cached loopback adapter that fetches a page and returns its
  `application/ld+json` graph; TLS-verified by default with a
  `wpcb_seo_rendered_schema_sslverify` filter (ADR 0009). `YoastSeoProvider`
  uses it for `local_businesses` only, with a Meta-surface fallback.
- `src/Adapter/Abilities/SeoAbilities.php` — thin read-only
  `wp-content-bridge/get-url-seo` projection and stable error mapping.
- `stubs/yoast.stub.php` — static-analysis-only declarations for Yoast's global
  surface accessor; it is never loaded at runtime.
- `tests/Unit/Domain/Seo/` and `tests/Unit/Application/Seo/` — bounds, selector,
  registry, null-provider, and authorization-ordering contracts.
- `tests/Unit/Infrastructure/Yoast/` — Premium parser, Local public projection,
  nested secret rejection, and `parentOrganization`/`branchOf` fixtures.
- `tests/Unit/Infrastructure/WordPress/WordPressRenderedSchemaReaderTest.php` —
  same-origin guard, JSON-LD parsing, malformed-block skipping, and node bounds.
- `tests/Integration/local-multilocation-fixture.php` — licensed multiple-location
  setup/teardown (snapshot + exact restore of `wpseo_local`, `wpseo_titles`, and
  content-access policy; primary + branch location posts with injected private-option
  sentinels).
- `tests/Integration/local-multilocation-runtime-verification.sh` — real-HTTPS
  branch-identity, bounds, and leakage verification of `get-url-seo` and
  `get-editorial-context` in multiple-location mode.

## Editorial context feature

```text
get-editorial-context Ability
  -> wpcb_read_content capability
  -> EditorialContextQuery bounds and section selection
  -> GetEditorialContext
     -> ContentAccessManager (READ + SEARCH policy)
     -> SearchContent (published, per-object readable recent inventory)
     -> EditorialContextRepository (public/REST taxonomy vocabulary and
        display names for authors already observed in readable results)
     -> SeoProviderRegistry (normalized public Local businesses only)
  -> EditorialContext schema 1.0 envelope
```

- `src/Domain/Editorial/EditorialContextQuery.php` — immutable section/type/
  taxonomy selection and request bounds.
- `src/Domain/Editorial/EditorialContext.php` — bounded schema-versioned result,
  provenance, limits, and warnings.
- `src/Application/Editorial/EditorialContextRepository.php` — vocabulary and
  observed-author port.
- `src/Application/Editorial/GetEditorialContext.php` — orchestration and
  authorization ordering; contains no WordPress, REST, or MCP dependency.
- `src/Infrastructure/WordPress/WordPressEditorialContextRepository.php` — Core
  taxonomy/term APIs and safe observed-author labels; no user enumeration.
- `src/Adapter/Abilities/ContentAbilities.php` — thin Ability registration,
  REST scalar normalization, permission callback, and stable error mapping.
- `tests/Unit/Domain/Editorial/EditorialContextQueryTest.php` and
  `tests/Unit/Application/Editorial/GetEditorialContextTest.php` — bounds,
  section composition, authorization reuse, and leakage contracts.
- `tests/Integration/abilities-runtime-verification.php`,
  `tests/Integration/authorization-matrix.php`, and
  `tests/Integration/http-url-runtime-verification.sh` — runtime discovery,
  schema/annotation checks, policy and role matrix, HTTPS execution, Local
  projection, and leakage assertions.

## Write (mutation) feature

```text
create-draft / update-content Ability
  -> plugin capability (`wpcb_edit_content`) + native object capability
     (`create_posts`/`edit_posts` or `edit_post`) — MutationAbilities
     permission callback
  -> CreateDraft or UpdateContent application use case
     -> ContentAccessManager per-post-type CREATE/UPDATE operation policy
     -> BlockMarkupValidator (registered block-type + parse round-trip)
     -> ContentMutationRepository port
     -> WordPressContentMutationRepository
        -> wp_insert_post (always `draft`) / wp_update_post (status untouched)
        -> WordPress revisions
     -> AuditLog: exactly one redacted row per attempt (success or failure)
  -> MutationResult DTO -> Ability output schema validation
```

Rules for this flow:

- Registered only when `get_option( Installer::WRITES_ENABLED_OPTION )` is
  truthy (`Plugin.php`); an unregistered ability is invisible to Abilities
  discovery and to any MCP projection.
- `MutationAbilities` owns WordPress callbacks, `WP_Error` mapping, and
  capability gates only — no per-post-type policy decisions.
- `CreateDraft`/`UpdateContent` own use-case ordering, policy, idempotency
  (create only), block validation, and audit — and record the success-audit
  call only after the write has genuinely completed, so a throw from the
  audit sink itself is never misclassified as a write failure.
- `create-draft` never accepts or sets a status; the repository hardcodes
  `draft`. `update-content` never includes `post_status` in its write args.
- Domain DTOs (`TaxonomyAssignment`, `DraftInput`, `ContentUpdate`,
  `MutationResult`) do not call WordPress or know about MCP.
- `get-content`'s output additionally carries a `version_token` (built from
  `VersionToken::for_content()`) that `update-content` consumes for optimistic
  concurrency — the only touch to the read flow.

Files:

- `src/Domain/Mutation/TaxonomyAssignment.php` — validated taxonomy + term-ID
  write input.
- `src/Domain/Mutation/DraftInput.php` — validated new-post input; status is
  always draft (no status field exists).
- `src/Domain/Mutation/ContentUpdate.php` — validated existing-post update; no
  status field.
- `src/Domain/Mutation/MutationResult.php` — outcome DTO returned to the
  adapter (`post_id`, `post_type`, `status`, `version`, `changed_fields`,
  `created`).
- `src/Domain/Mutation/VersionToken.php` — optimistic-concurrency primitive
  (`{content_hash}:{modified_gmt}`), from Plan 1.
- `src/Application/Mutation/ContentMutationRepository.php` — write port
  (`post_type`/`current_version`/`create`/`update`/`result_for`).
- `src/Application/Mutation/IdempotencyStore.php` — port
  (`find`/`remember`) for create-draft's idempotency-key replay.
- `src/Application/Mutation/MutationForbidden.php` and `MutationWriteFailed.php`
  — typed failures (`wpcb_forbidden`, `wpcb_write_failed`); `MutationConflict`
  / `InvalidBlockMarkup` / `SeoFieldUnsupported` are from Plan 1.
- `src/Application/Mutation/AuditEvent.php` / `AuditLog.php` (Plan 1 ports) —
  pre-redacted event (field names only, never values) and the sink port.
- `src/Application/Mutation/CreateDraft.php` — validate, authorize (policy),
  idempotency replay, block validation, write, audit; exactly one
  `record_success()`/`record_failure()` call per attempt, placed after the
  `try`/`catch` so a throwing audit sink cannot double-record.
- `src/Application/Mutation/UpdateContent.php` — validate, resolve target,
  authorize (policy), optimistic-concurrency check (`wpcb_conflict` on
  mismatch), block validation, write, audit; same exactly-once-audit
  structure as `CreateDraft`.
- `src/Infrastructure/WordPress/PhpBlockMarkupValidator.php` — `parse_blocks`
  round-trip + registered-block-type check, bounded reason list.
- `src/Infrastructure/WordPress/WordPressContentMutationRepository.php` —
  `wp_insert_post`/`wp_update_post`, revisions, and `result_for()` replay
  lookup; the only place `post_status` is written, and it is never
  `publish`/`future`/`pending`.
- `src/Infrastructure/WordPress/WordPressTransientIdempotencyStore.php` —
  per-user (`wpcb_idem_{user_id}_{md5(key)}`), 24h-TTL transient; recovers
  both a real int (persistent object-cache backends) and a stringified
  numeric value (default DB-backed transient storage).
- `src/Adapter/Abilities/MutationAbilities.php` — registers `create-draft`/
  `update-content` only when `wpcb_writes_enabled` is on; permission callbacks
  enforce `wpcb_edit_content` + the native type/object capability; maps
  thrown failures to stable `WP_Error` codes (`wpcb_invalid_input`,
  `wpcb_conflict`, `wpcb_invalid_blocks`, `wpcb_forbidden`,
  `wpcb_content_unavailable`, `wpcb_write_failed`, `wpcb_internal_error`).
- `src/Adapter/Abilities/AbilitySchemas.php` — adds
  `create_draft_input/output`, `update_content_input/output`, and the
  additive `version_token` property on `get_output()`.
- `src/Adapter/Admin/ContentAccessSettingsPage.php` — adds the global "Enable
  content writes" checkbox bound to `Installer::WRITES_ENABLED_OPTION`.
- `tests/Unit/Domain/Mutation/` and `tests/Unit/Application/Mutation/` —
  DTO validation and use-case contract coverage, including audit-sink-throws
  regression tests for both use cases.
- `tests/Integration/writes-mutation-verification.php` — runtime
  authorization matrix, no-publish invariant, stale-version conflict,
  revision-on-update, block round-trip, idempotent create, and audit
  redaction (scans every real column value for secret markers, not just
  column presence).

**Not yet wired to any MCP client:** the site-infrastructure MCP glue
(`content/mu-plugins/wpcb-mcp-server.php`, outside this repo) hardcodes an
explicit five-read-ability allowlist and has not been updated to add
`create-draft`/`update-content` — see `docs/setup/MCP_ADAPTER.md`.

## Specification routes

- Product behavior: `docs/spec/REQUIREMENTS.md`.
- Access model: `docs/architecture/CONTENT_ACCESS.md` and ADR 0006.
- Ability contracts: `docs/architecture/ABILITIES.md`.
- Threat model: `docs/architecture/SECURITY.md`.
- Delivery order: `docs/plan/IMPLEMENTATION_PLAN.md`.
- Comparative implementation research: `docs/research/`.
- Private credential decision: `docs/adr/0007-private-credentials-are-principal-bound.md`.
- Content/SEO composition decision: `docs/adr/0008-compose-seo-instead-of-embedding.md`.
- Rendered-schema capture decision: `docs/adr/0009-capture-rendered-schema-for-local-multilocation.md`.
- Agent procedures: `.agents/instructions/`.
- Milestone 1B evidence: `docs/verification/ABILITIES_VERIFICATION.md`.

## Expected next feature path

Milestones 1–4 are complete. Milestone 5 (writes) Plans 1–2 are complete and
merged — `create-draft`/`update-content` are the plugin's first live write
surface, off by default. The next path is:

1. Milestone 5 **Plan 3** — `update-seo`: `SeoUpdate` DTO, `SeoWriter` port +
   `YoastFreeSeoWriter`, `UpdateSeo` use case/ability/schema, and a SEO
   write/re-read runtime verifier. Mirror the `src/*/Mutation/` vertical
   slice's layering.
2. Milestone 5 **Plan 4** — `publish-content` (its own `wpcb_publish_enabled`
   flag/capability) + `list-block-patterns`, and the final cross-plan
   integration exit gate.
3. Separately: update the site-infrastructure MCP glue
   (`wpcb-mcp-server.php`) to add the two Plan 2 write abilities to its
   allowlist so an external MCP client can reach them (not blocking Plan 3/4).

Existing and new content reads continue to consume `ContentAccessManager`;
none may call `get_option()` directly. New writes continue to consume
`ContentAccessManager` for per-post-type policy, and must record exactly one
audit row per attempt (success or failure) outside any `try` block whose
`catch` could also record one.

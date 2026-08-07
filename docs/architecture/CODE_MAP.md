# Code map

This is the onboarding map for humans and AI agents. Update it whenever a directory gains a new responsibility or a use case crosses a new boundary.

## Runtime entry points

- `wp-content-bridge.php` — plugin header, Composer guard, activation hook,
  packaged-update registration, and `plugins_loaded` bootstrap.
- `src/Plugin.php` — composition root; runs schema upgrades and wires the admin,
  application, WordPress repository, and Abilities adapters.
- `src/Infrastructure/WordPress/GitHubReleaseUpdateChecker.php` — admin/cron-only
  Plugin Update Checker adapter for packaged GitHub release assets. Git source
  checkouts and site-level opt-outs fail closed; no updater behavior enters the
  composition root or Ability layer (ADR 0018).
- `yahnis-elsts/plugin-update-checker` — production Composer dependency included
  in the release ZIP. It is used only by the updater adapter and never bundled
  into an MCP or domain contract.

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

- `src/Domain/Access/IntegrationCapability.php` and
  `IntegrationPrincipal.php` — closed operational grant vocabulary and bounded
  principal descriptor; no WordPress calls.
- `src/Application/Access/IntegrationAccessManager.php` and
  `IntegrationAccessRepository.php` — native-read/admin rejection invariants
  and the principal persistence port.
- `src/Infrastructure/WordPress/WordPressIntegrationAccessRepository.php` —
  exact WPCB user-capability replacement and managed-principal option adapter.
- `src/Domain/ContentAccess/ContentOperation.php` — stable operation vocabulary and configuration dependencies.
- `src/Domain/ContentAccess/ContentTypePolicy.php` — immutable normalization and dependency enforcement for one row.
- `src/Domain/ContentAccess/ContentTypeDefinition.php` — transport-neutral post-type descriptor.
- `src/Application/ContentAccess/ContentAccessManager.php` — shared use-case policy service; future content abilities call this.
- `src/Application/ContentAccess/ContentAccessSettingsRepository.php` — storage port.
- `src/Application/ContentAccess/ContentTypeCatalog.php` — post-type discovery port.
- `src/Infrastructure/WordPress/WordPressContentAccessSettingsRepository.php` — `wp_options` adapter.
- `src/Infrastructure/WordPress/WordPressContentTypeCatalog.php` — registered post-type adapter and eligibility rules.
- `src/Infrastructure/WordPress/Installer.php` — versioned capability/option setup.
- `src/Adapter/Admin/ContentAccessSettingsPage.php` — Settings API and HTML
  adapter for content policy plus the nonce/capability-guarded integration-user
  form; contains no capability allowlist or content-policy rules.
- `tests/Unit/Application/Access/IntegrationAccessManagerTest.php` and
  `tests/Integration/integration-access-verification.php` — allowlist,
  native-read/admin rejection, exact assignment, prior-principal revocation,
  and unrelated-capability preservation.
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

## Media read feature

```text
get-media / get-media-by-id Ability
  -> wpcb_media_reads_enabled master policy
  -> wpcb_read_media capability
  -> SearchMedia or GetMediaById
  -> WordPressMediaRepository
  -> native read_post per attachment before pagination/output
  -> MediaSearchResult or MediaItem -> strict Ability schema
```

- `src/Domain/Media/` — bounded query, normalized item, and object-envelope DTOs.
- `src/Application/Media/` — policy manager, repository port, and search/detail use cases.
- `src/Infrastructure/WordPress/WordPressMediaRepository.php` — public Core API
  adapter for exact ID/same-site URL/filename and text lookup.
- `src/Adapter/Abilities/MediaAbilities.php` — capability-gated read projection.
- `tests/Integration/media-read-verification.php` — disposable attachment/page
  runtime matrix and featured-image identity verification.
- ADR 0011 keeps attachments outside the content-type catalog and makes media
  exposure a separate off-by-default decision.

## Block-pattern read feature

```text
list-block-patterns Ability
  -> wpcb_pattern_reads_enabled master policy
  -> wpcb_read_patterns capability
  -> PatternAccessManager
     -> WordPressBlockPatternAccess (native editor-level gate)
  -> ListBlockPatterns
     -> BlockPatternCatalog port
     -> WordPressBlockPatternCatalog (current registry only; no remote load)
  -> PatternSearchResult -> strict Ability schema
```

- `src/Domain/Pattern/` — bounded query, allowlisted item, and result envelope.
- `src/Application/Pattern/` — native-access/catalog ports, feature policy, and
  listing use case.
- `src/Infrastructure/WordPress/WordPressBlockPatternAccess.php` — mirrors the
  core editor-level permission boundary.
- `src/Infrastructure/WordPress/WordPressBlockPatternCatalog.php` — sorted,
  filtered, paginated registry projection with a 1,000-candidate and 2 MiB
  content bound; drops filesystem and unknown properties.
- `src/Adapter/Abilities/PatternAbilities.php` — strict read-only Ability and
  stable error mapping.
- `tests/Integration/block-patterns-verification.php` — disposable local
  pattern/principal fixture covering permissions, filters, payload selection,
  and remote/path leakage guards.
- ADR 0013 owns the dedicated opt-in editor policy.

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
create-draft / update-content / preview-update-content / update-seo /
preview-update-seo / get-service-schema / preview-update-service-schema /
update-service-schema / get-custom-schema / preview-update-custom-schema /
update-custom-schema / trash-content Ability
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
        -> successful `wpcb_mutation` event
        -> WordPressPostCacheInvalidator
           -> clean_post_cache(exact post ID)
           -> optional litespeed_purge_post(exact post ID)
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
- `preview-update-content` and `preview-update-seo` (ADR 0021) share the
  matching write's validated input DTO, per-post-type policy, and
  optimistic-concurrency check, and take no `AuditLog` dependency at all, so
  they cannot record a mutation audit row even by accident. Content preview
  round-trips block markup through `BlockMarkupValidator::normalize()`
  (`parse_blocks()`/`serialize_blocks()` only, never content filters). SEO
  preview normalizes through `YoastSeoWriter::preview()` (same sanitization as
  `write()`) but never calls `WPSEO_Meta::set_value()`. Both are registered in
  the same `MutationAbilities::register_abilities()` call as the writes they
  mirror, so they share the same `wpcb_writes_enabled` gate automatically.

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
- `src/Application/Mutation/SeoImageRepository.php` — narrow port that resolves
  an authorized WordPress image attachment ID to its public URL before an SEO
  write; `SeoImageUnavailable` is its non-enumerating failure boundary.
- `src/Domain/Mutation/TrashInput.php` and `MutationTarget.php` — strict trash
  request plus current target state/version snapshot.
- `src/Application/Mutation/ContentTrashRepository.php` and
  `TrashContent.php` — reversible-trash port and policy/concurrency/audit use
  case.
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
- `src/Domain/Mutation/ContentPreviewResult.php` and
  `SeoPreviewResult.php` — immutable preview DTOs (ADR 0021); wire field is
  `writes_performed: false`, not `dry_run`.
- `src/Application/Mutation/ContentSnapshotRepository.php` — additive,
  read-only companion port to `ContentMutationRepository` (`content_snapshot()`
  only); `WordPressContentMutationRepository` implements both, so no existing
  implementer or test double changes shape.
- `src/Application/Mutation/SeoPreviewProvider.php` — additive, read-only
  companion port to `SeoWriter` (`current()`/`preview()`); `YoastSeoWriter`
  implements both.
- `src/Application/Mutation/PreviewContentUpdate.php` and
  `PreviewSeoUpdate.php` — the two preview use cases; no `AuditLog` dependency.
- `src/Infrastructure/WordPress/PhpBlockMarkupValidator.php` — `parse_blocks`
  round-trip + registered-block-type check, bounded reason list; also exposes
  `normalize()` (parse/serialize round-trip only) that
  `PreviewContentUpdate` uses to compute what would actually be stored.
- `src/Infrastructure/WordPress/WordPressContentMutationRepository.php` —
  `wp_insert_post`/`wp_update_post`, revisions, and `result_for()` replay
  lookup; the only place `post_status` is written, and it is never
  `publish`/`future`/`pending`. Also implements `ContentSnapshotRepository`.
- `src/Infrastructure/WordPress/WordPressSeoImageRepository.php` — requires an
  existing image attachment plus native `read_post`, then resolves its public
  URL without accepting caller-controlled URLs or filesystem paths.
- `src/Infrastructure/WordPress/WordPressContentTrashRepository.php` — checks
  trash retention before calling `wp_trash_post`, attempts a pre-trash
  revision, and verifies the resulting `trash` state.
- `src/Infrastructure/WordPress/WordPressTransientIdempotencyStore.php` —
  per-user (`wpcb_idem_{user_id}_{md5(key)}`), 24h-TTL transient; recovers
  both a real int (persistent object-cache backends) and a stringified
  numeric value (default DB-backed transient storage).
- `src/Infrastructure/WordPress/WordPressPostCacheInvalidator.php` —
  best-effort, post-scoped invalidation after successful audited mutations;
  always clears WordPress's post object cache and dispatches LiteSpeed Cache's
  public post-purge hook only when it has a listener. Provider failures are
  contained because the write has already committed (ADR 0012).
- `src/Infrastructure/Yoast/YoastSeoWriter.php` — version-gated Yoast 28.x
  editor-field writer. Free core fields remain available with Yoast Free;
  normalized `keyphrase_synonyms` and `related_keyphrases` additionally require
  Premium 28.x and are mapped to bounded positional JSON under ADR 0014.
  Advanced robots are merged per directive, while social images are
  pre-resolved and written as paired Yoast URL/attachment-ID values under ADR
  0016. Also implements `SeoPreviewProvider`: `current()` re-reads the full
  resolved document; `preview()` reuses the exact same per-field sanitization
  and image resolution as `write()` but never calls `WPSEO_Meta::set_value()`.
- `src/Domain/Mutation/ServiceSchemaUpdate.php` — provider-neutral, bounded
  Service/area/brand/OfferCatalog write intent with explicit clear semantics.
- `src/Application/Mutation/ServiceSchemaWriter.php` and
  `UpdateServiceSchema.php` — optional-provider port plus the shared
  policy/version/audit orchestration. The application layer has no dependency
  on Schema Extended or WordPress metadata.
- `src/Application/Mutation/ServiceSchemaReader.php`, `GetServiceSchema.php`,
  and `PreviewServiceSchema.php` — provider-neutral read-before-write paths;
  preview shares update validation/concurrency but cannot mutate.
- `src/Infrastructure/SchemaExtended/SchemaExtendedServiceSchemaWriter.php` —
  optional adapter for the standalone plugin's public `Meta_Fields` API. It
  feature-detects the loaded plugin, maps only fixed metadata constants,
  normalizes all values before writing, rolls back earlier keys on a later
  write failure, and re-reads effective configuration.
- `src/Adapter/Abilities/ServiceSchemaAbilities.php` — conditionally registers
  read, preview, and write projections using `wpcb_manage_seo` plus native
  `edit_post`, with truthful per-intent annotations.
- `stubs/schema-extended.stub.php` — analysis-only public API declarations for
  the optional provider; it does not load or emulate the plugin at runtime.
- `src/Adapter/Abilities/MutationAbilities.php` — registers `create-draft`,
  `update-content`, `update-seo`, and their two previews only when
  `wpcb_writes_enabled` is on; each preview reuses the exact same permission
  callback (`can_update`/`can_update_seo`) as the write it mirrors, so it
  requires the same WPCB capability and native type/object capability; maps
  thrown failures to stable `WP_Error` codes (`wpcb_invalid_input`,
  `wpcb_conflict`, `wpcb_invalid_blocks`, `wpcb_forbidden`,
  `wpcb_content_unavailable`, `wpcb_write_failed`, `wpcb_seo_field_unsupported`,
  `wpcb_seo_image_unavailable`, `wpcb_internal_error`).
- `src/Adapter/Abilities/TrashAbilities.php` — separately registers
  `trash-content` only through the composition root's write+trash flag gate and
  enforces `wpcb_delete_content` plus native `delete_post`.
- `src/Adapter/Abilities/AbilitySchemas.php` — adds
  `create_draft_input/output`, `update_content_input/output`, the
  additive `version_token` property on `get_output()`, and
  `preview_content_input/output`/`preview_seo_input/output` (ADR 0021); each
  preview input schema is exactly its matching update input schema.
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
- `tests/Integration/cache-invalidation-verification.php` — runtime proof that
  successful events purge one post, failed events do nothing, and cache-adapter
  exceptions cannot change the completed write outcome.
- `tests/Unit/Application/Mutation/PreviewContentUpdateTest.php` and
  `PreviewSeoUpdateTest.php` — changed-fields reporting, stale-token
  rejection, policy/unsupported-field denial, and no-write assertions against
  the existing test doubles (ADR 0021).
- `tests/Integration/preview-verification.php` — runtime proof that repeated
  previews are deterministic and change nothing (post, meta, revision, or
  audit), that a preview followed by the matching write produces exactly the
  previewed state, and that stale tokens are rejected before any mutation.

**MCP projection:** the current source documents a closed profile containing all
20 implemented abilities. The reference Kormas site owns this boundary as a
Composer-installed MU-plugin and passes only profile entries that are currently
registered. Service and Custom Schema entries therefore disappear automatically
when their standalone provider contract or global writes are inactive. OAuth grants remain a
separate site configuration; see
`docs/setup/MCP_ADAPTER.md`.

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
- Post-scoped cache invalidation decision:
  `docs/adr/0012-cache-invalidation-is-post-scoped-and-event-driven.md`.
- Block-pattern policy decision:
  `docs/adr/0013-block-pattern-reads-use-a-dedicated-editor-policy.md`.
- Premium keyphrase write decision:
  `docs/adr/0014-premium-keyphrases-use-a-normalized-versioned-write-contract.md`.
- Status workflow and trash boundary:
  `docs/adr/0015-content-status-transitions-and-trash-are-separate-intents.md`.
- Agent procedures: `.agents/instructions/`.
- Milestone 1B evidence: `docs/verification/ABILITIES_VERIFICATION.md`.

## Expected next feature path

Milestones 1–4 are complete. Milestone 5 Plans 1–3 are merged. Media P0, cache
invalidation, and Plan 4a `list-block-patterns` are code-complete in the current
worktree and await the stopped Kormas Local runtime gate. The next path is:

1. Start Kormas Local and run every pending WordPress runtime verifier.
2. Verify the 0.2.0 MCP discovery profile through the official Adapter, then
   configure and verify the matching miniOrange grants for the intended
   principal.
3. Milestone 5 **Plan 4c** — `transition-content-status`; public/scheduled
   targets add the `wpcb_publish_enabled` flag/capability and final integration
   exit gate.
4. Continue Media P1 as a separate write surface (`update-media`, upload, and
   featured-image assignment/removal), with its own threat model and gates.

Existing and new content reads continue to consume `ContentAccessManager`;
none may call `get_option()` directly. New writes continue to consume
`ContentAccessManager` for per-post-type policy, and must record exactly one
audit row per attempt (success or failure) outside any `try` block whose
`catch` could also record one.

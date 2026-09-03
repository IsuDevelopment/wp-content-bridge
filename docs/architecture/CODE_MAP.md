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
- `src/Adapter/Abilities/AbilityError.php` — the only place an ability's
  `WP_Error` is constructed. Maps the closed public error-code vocabulary onto
  HTTP status, so a domain rejection no longer answers 500.
- `src/Adapter/Abilities/ContentAbilities.php` — thin WordPress Abilities projection and stable error boundary.
- `tests/Unit/Domain/Content/ContentQueryTest.php` — defaults, normalization, bounds, and effective-type copy contract.
- `tests/Unit/Adapter/Abilities/AbilitySchemasTest.php` — strict public schema,
  taxonomy-bound, pagination-safety, and payload-metadata contract.
- `tests/Unit/Adapter/Abilities/AbilityErrorTest.php` — discovers the error
  vocabulary from the source and fails when a code has no status or the map
  carries a code the source cannot produce.
- `src/Adapter/Abilities/AbilityMeta.php` — the only source of ability
  registration metadata: annotations plus the three exposure flags
  (`show_in_rest`, 7.1's `public`, and the Adapter's `mcp.public`).
- `tests/Integration/rest-input-coercion-verification.php` — WordPress 7.1 input
  coercion stays at the REST boundary and inside every schema bound, and each
  domain rejection answers its own HTTP status.

### Invocation telemetry (ADR 0029, off by default)

Closes the one thing nothing else could see: a denial at `permission_callback`.

- `src/Application/Telemetry/InvocationAttempt.php` — immutable record with no
  field that could hold ability input, a message, or a result.
- `src/Application/Telemetry/InvocationLog.php` — port. Deliberately not
  `Application\Mutation\AuditLog`: that one is evidence of what happened, this
  one is a diagnostic of what was attempted.
- `src/Infrastructure/WordPress/WordPressInvocationLog.php` — buffers per
  request, flushes on `shutdown`, keeps a bounded ring buffer so it can never
  grow into or evict the mutation audit.
- `src/Adapter/Abilities/AbilityInvocationTelemetry.php` — listens on
  `wp_ability_invoked` and `wp_after_execute_ability`, ignores other plugins'
  abilities, and is registered only while the flag is on.
- `tests/Unit/Application/Telemetry/InvocationAttemptTest.php` — asserts the
  record cannot hold content.
- `tests/Integration/invocation-telemetry-verification.php` — absent when off,
  a denial recorded exactly once, a success upgraded rather than duplicated,
  reads adding no audit rows, and the ring buffer's bound.
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
update-custom-schema / trash-content / restore-trashed-content Ability
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
- `src/Domain/Mutation/TrashInput.php`, `RestoreInput.php`, and
  `MutationTarget.php` — strict trash/restore requests (identical shape: exact
  `post_id` + `version_token`) plus current target state/version snapshot.
- `src/Application/Mutation/ContentTrashRepository.php`, `TrashContent.php`,
  and `RestoreTrashedContent.php` — reversible-trash port
  (`trash_supported`/`target`/`trash`/`untrash`) and the mirrored
  policy/concurrency/audit use cases. `RestoreTrashedContent` gates on the same
  `ContentOperation::TRASH` policy as `TrashContent` — restoration is part of
  the trash feature, not a new operation — and requires the target's current
  status to be exactly `trash` (any other status is the non-enumerating
  `wpcb_invalid_state` failure, the inverse of `TrashContent`'s check).
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
- `src/Infrastructure/WordPress/PostVersionTokenFactory.php` — the single place
  a version token is derived, so `get-content` and every write repository
  cannot disagree (a divergence would make every write conflict forever). It
  hashes the post's **meta and its other mutable columns**, not just title,
  content and status: meta-only writes (`update-seo`, Custom/Service Schema)
  and column-only writes (slug, excerpt, parent, author, date) both left the
  token unchanged otherwise, and `post_modified_gmt` cannot cover them because
  it has one-second resolution. Meta is fingerprinted wholesale minus a
  filterable volatile-key list; columns are an explicit list, because the row
  also carries derived values (`guid`, `comment_count`) that would move the
  token without the post's meaning changing.
- `src/Infrastructure/WordPress/WordPressPermalinkRepository.php` — the only
  place `post_name` is written. Asks `wp_unique_post_slug()` **before** writing
  and refuses a collision, because `wp_update_post()` would store `slug-2` and
  report success, handing back a URL the caller never requested. Passing the
  post's own ID excludes itself, so re-submitting the current slug is not a
  false collision.
- `src/Application/Mutation/UrlCacheInvalidator.php` and
  `src/Infrastructure/WordPress/WordPressUrlCacheInvalidator.php` — URL-scoped
  invalidation (ADR 0032), called by `UpdatePermalink` rather than from the
  `wpcb_mutation` path: that event is redacted to changed field names, and
  putting URLs on it would make the audit record a carrier of content values.
  The adapter dispatches only public, documented, single-URL purge hooks, and
  only when `has_action()` says something is listening, plus this plugin's own
  `wp_content_bridge_purge_urls`. It never reads a cache plugin's options,
  calls its classes, purges the site, or makes an HTTP request. The result
  reports which channels were notified and never a confirmed purge.
- `src/Infrastructure/WordPress/WordPressSlugNormalizer.php` — defers entirely
  to `sanitize_title()`, filters included, and reports `null` when nothing
  usable remains. It is a port rather than a domain helper because slug
  normalization is WordPress behaviour, and a reimplementation would drift from
  what the database actually receives — which is exactly what the pre-write
  comparison depends on.
- `src/Infrastructure/WordPress/WordPressAttachmentMetadataRepository.php` —
  writes the four descriptive attachment fields (`alt_text` is postmeta, the
  other three are columns) and re-reads every one of them. `wp_update_post()`
  returns the post ID even when a filter rewrote a value, and
  `update_post_meta()` returns false both for "unchanged" and "short-circuited",
  so neither return value answers what is actually stored.
- `src/Infrastructure/WordPress/WordPressMediaUploader.php` — the remote image
  import (ADR 0031). The SSRF defence is `wp_safe_remote_get()`, chosen over a
  hand-rolled IP filter because core's `wp_http_validate_url()` is maintained,
  is re-applied to every redirect target, and already covers the metadata range
  and the reserved space. The stored MIME type comes from
  `wp_check_filetype_and_ext()` against the downloaded bytes with a raster-image
  allowlist — never from the URL, its extension, or the response headers — and
  SVG is excluded because it is script-bearing XML served from the site's
  origin. The byte ceiling is checked against the real body as well as the
  declared `Content-Length`, and the temporary file is deleted on every path.
- `src/Infrastructure/WordPress/WordPressFeaturedImageRepository.php` — the only
  place `_thumbnail_id` is written, and both writes are confirmed by re-reading
  (a filter on `update_post_metadata` can short-circuit a write while the call
  still reports success). `is_assignable_image()` is the gate WordPress does not
  provide: `set_post_thumbnail()` accepts any attachment ID, including a PDF, a
  private upload, or a non-attachment, and themes render the result in a public
  image slot. `remove()` ignores `delete_post_thumbnail()`'s return value — it
  is `false` both when nothing was assigned and when a write failed — and
  asserts the absence instead, which also makes a retried removal idempotent.
- `src/Infrastructure/WordPress/WordPressRenderedSchemaReader.php` — fetches the
  site's own page over HTTP to read its rendered JSON-LD graph, bounded by a
  5 s timeout, 3 redirects, 3 MiB, 200 nodes, and a same-origin guard, cached
  for 10 minutes. It returns `Domain\Seo\RenderedGraph` rather than a bare node
  list: five distinct failure causes previously collapsed into one empty array,
  which made a blocked loopback request indistinguishable from a page that
  emits no JSON-LD. Those need opposite responses from an operator, and a
  blocked self-request is the common production case.
- `src/Infrastructure/WordPress/WordPressSchemaTargetReader.php` — the identity
  projection `get-custom-schema` returns as `target` (title, slug, permalink,
  status, dates, authorized featured image). It exists as its own narrow port
  rather than a method on `ContentMutationRepository` so a schema read does not
  gain the content pipeline as a dependency, and so eleven existing test
  doubles do not have to grow a method they never exercise. It deliberately
  omits the excerpt: generating one renders blocks, which is the expensive read.
- `src/Infrastructure/WordPress/FeaturedMediaProjection.php` — the single place
  a featured attachment is projected for output. Two adapters expose that
  identity, and if their authorization checks diverged, one would leak an
  attachment the caller cannot read while the other hid it.
- `src/Infrastructure/WordPress/WordPressSeoImageRepository.php` — requires an
  existing image attachment plus native `read_post`, then resolves its public
  URL without accepting caller-controlled URLs or filesystem paths.
- `src/Infrastructure/WordPress/WordPressContentTrashRepository.php` — checks
  trash retention before calling `wp_trash_post`, attempts a pre-trash
  revision, and verifies the resulting `trash` state. Its `untrash()` method
  computes the safe restore status (the recorded `_wp_trash_meta_status` only
  when it is `draft`/`pending`/`private`, `draft` otherwise), forces that exact
  status through the `wp_untrash_post_status` filter rather than trusting
  `wp_untrash_post()`'s own default, and verifies the resulting status is never
  `publish`/`future` before returning.
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
- `src/Adapter/Abilities/RestoreTrashedContentAbilities.php` — the mirror image
  of `TrashAbilities`: same flag gate, same `can_restore` permission shape
  (`wpcb_delete_content` plus native `delete_post`), same stable `WP_Error`
  mapping. No preview — it fails the roadmap's preview justification test.
- `src/Adapter/Abilities/AbilitySchemas.php` — adds
  `create_draft_input/output`, `update_content_input/output`, the
  additive `version_token` property on `get_output()`,
  `preview_content_input/output`/`preview_seo_input/output` (ADR 0021), and
  `restore_trashed_content_input/output`; each preview input schema is exactly
  its matching update input schema, and `restore_trashed_content_input()` is
  exactly `trash_content_input()`'s shape.
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

**MCP projection:** ADR 0025. `src/Adapter/Mcp/McpServerProvider.php` answers
`mcp_adapter_init` and projects every ability registered under
`AbilityCategory::SLUG` in the current request — there is no profile list in the
plugin, the docs, or the site. Feature-gated abilities disappear from discovery
because they are never registered, not because a list omits them. The retired
site MU-plugin (`isudev/wp-content-bridge-mcp-server`) and its `ABILITY_PROFILE`
constant are superseded. `wp_content_bridge_mcp_abilities` may narrow the set and
can never widen it; `wpcb_mcp_server_enabled` turns the endpoint off entirely.
`get-diagnostics` reports `mcp_projection`, and
`tests/Integration/abilities-runtime-verification.php` asserts projection parity.
OAuth grants remain a separate site configuration; see
`docs/setup/MCP_ADAPTER.md`.

## Block-level edit feature

ADR 0022. Addresses one block subtree by its position in the parsed tree
instead of replacing the whole document, so a single-paragraph edit costs
~174 characters instead of ~12,000 and cannot damage a block the caller never
sends.

```text
get-block-tree Ability
  -> wpcb_read_content capability
  -> GetBlockTree application service
     -> ContentAccessManager (READ policy)
     -> BlockTreeRepository port
     -> WordPressBlockTreeRepository
        -> parse_blocks(), bounded flat projection
  -> BlockTree DTO -> Ability output schema validation

update-block / preview-update-block / update-block-attributes Ability
  -> wpcb_edit_content capability + native edit_post — BlockMutationAbilities
     permission callback
  -> UpdateBlock / PreviewBlockUpdate / UpdateBlockAttributes application use case
     -> ContentAccessManager per-post-type UPDATE policy
     -> ContentSnapshotRepository (current block_markup read)
     -> BlockTreeSplicer port
        -> PhpBlockTreeSplicer (resolve / splice / merge_attributes via
           parse_blocks() / serialize_blocks())
     -> BlockMarkupValidator (update-block and its preview only; recursive
        registration + full-depth round-trip check)
     -> ContentMutationRepository port -> WordPressContentMutationRepository
     -> AuditLog: exactly one redacted row per attempt (update-block and
        update-block-attributes only; the preview takes no AuditLog
        dependency at all)
     -> WordPressPostCacheInvalidator (post-scoped, on success)
  -> MutationResult / BlockUpdatePreviewResult DTO -> Ability output schema
     validation
```

Rules for this flow:

- `get-block-tree` is registered in `ContentAbilities` alongside the other
  always-on reads rather than in a new adapter, so it shares `can_read`'s
  permission callback; it needs no new flag and no new capability.
- `update-block`, `preview-update-block`, and `update-block-attributes` are
  registered in a dedicated `BlockMutationAbilities` adapter rather than
  folded into `MutationAbilities`, following the
  `RestoreTrashedContentAbilities` precedent, so `writes-mutation-verification.php`
  stays untouched.
- `expected_block_name` (string or `null`) is mandatory on every block write
  and preview. A matching `version_token` proves the document did not
  change; it does not prove `path` points at the block the caller believes
  it does, so this fact is asserted separately and the request fails closed
  with `wpcb_block_mismatch` when it differs.
- `parse_blocks()` emits `block_name: null` freeform nodes for whitespace
  between blocks. They occupy real indices in the array a write mutates, so
  `get-block-tree` always includes them and `expected_block_name: null`
  legitimately targets one. `update-block-attributes` still rejects a
  freeform target with `wpcb_block_mismatch`, since it has no `attrs` to
  merge into.
- A block `path` is positional detail, not a field name, and never enters the
  audit row; `changed_fields` is always `["content"]` for all three writes.
- `update-block-attributes` has no preview. `get-block-tree` with
  `include_attrs: true` already returns the current attributes, the caller
  holds the new values, and a documented shallow merge is something it can
  compute itself — the same preview-justification test that cut three other
  previews before `0.4.0`.
- Domain DTOs (`BlockTree`, `BlockTreeNode`, `BlockPathLookup`, `BlockUpdate`,
  `BlockAttributesUpdate`, `BlockUpdatePreviewResult`) do not call WordPress
  or know about MCP.

Files:

- `src/Domain/Content/BlockTree.php` and `BlockTreeNode.php` — immutable,
  flat, path-addressed block-tree result and one node; each node carries
  `text`/`text_source` (falling back from `innerHTML` to a prose-bearing
  string attribute) and opt-in, size-bounded `attrs`.
- `src/Domain/Content/BlockPathLookup.php` — the block name found at a
  resolved path; a `null` value legally means a freeform node was found
  there, distinct from resolution failure (a `null` lookup itself).
- `src/Application/Content/BlockTreeRepository.php` — read-only port,
  deliberately separate from `ContentRepository`: a different projection of
  the same post, not an extension of the whole-document read.
- `src/Application/Content/GetBlockTree.php` — the `get-block-tree` use
  case; mirrors `GetContent`'s gates exactly (policy, native `read_post`,
  non-enumerating denial).
- `src/Infrastructure/WordPress/WordPressBlockTreeRepository.php` — projects
  `parse_blocks()` into the bounded node list: 500-node cap with
  `truncated`, 120-character `text`, and a 512-byte per-node encoded `attrs`
  bound (`attrs_omitted` above it).
- `src/Domain/Mutation/BlockUpdate.php` — validated `update-block`/
  `preview-update-block` input (`post_id`, `version_token`, `path`,
  `expected_block_name`, `block_markup`); `block_markup` may be empty to
  delete the addressed subtree.
- `src/Domain/Mutation/BlockAttributesUpdate.php` — validated
  `update-block-attributes` input; `attributes` replaces `block_markup` and
  is bounded to 50 keys and a 100,000-byte canonical JSON encoding.
- `src/Domain/Mutation/BlockUpdatePreviewResult.php` — preview DTO scoped to
  the one field (`content`) a block-level edit can ever change; wire field is
  `writes_performed: false`.
- `src/Application/Mutation/BlockTreeSplicer.php` — port for `resolve()`
  (path lookup without mutation), `splice()` (subtree replace/delete), and
  `merge_attributes()` (shallow `attrs` overlay); backs both `UpdateBlock`
  and `UpdateBlockAttributes`.
- `src/Application/Mutation/BlockPathNotFound.php` and `BlockMismatch.php` —
  typed failures (`wpcb_block_path_not_found`, `wpcb_block_mismatch`).
- `src/Application/Mutation/UpdateBlock.php` — validate, resolve `path`,
  assert `expected_block_name`, validate `block_markup` recursively, splice,
  write, audit; same exactly-once-audit structure as `UpdateContent`.
- `src/Application/Mutation/PreviewBlockUpdate.php` — mirrors `UpdateBlock`'s
  validation and concurrency check exactly (ADR 0021) and takes no `AuditLog`
  dependency at all, so it structurally cannot audit.
- `src/Application/Mutation/UpdateBlockAttributes.php` — validate, resolve
  `path`, assert `expected_block_name`, reject a freeform target, merge
  attributes, write, audit; no block-markup validation, since it never
  accepts raw markup.
- `src/Infrastructure/WordPress/PhpBlockTreeSplicer.php` — `parse_blocks()`/
  `serialize_blocks()`-backed implementation. Every level `replace()` or
  `merge()` descends into is re-narrowed rather than trusted from a parent's
  declared (necessarily shallow) `innerBlocks` type, so nested entries are
  always safe to hand back to `serialize_blocks()`.
- `src/Adapter/Abilities/BlockMutationAbilities.php` — registers
  `update-block`, `preview-update-block`, and `update-block-attributes`;
  owns `WP_Error` mapping and the shared `can_update` permission callback,
  and carries no policy of its own.
- `src/Adapter/Abilities/AbilitySchemas.php` — adds
  `get_block_tree_input/output`, `update_block_input/output` (shared
  verbatim by `preview-update-block` per ADR 0021),
  `preview_update_block_output`, and
  `update_block_attributes_input/output`.
- `tests/Unit/Application/Mutation/UpdateBlockTest.php`,
  `PreviewBlockUpdateTest.php`, and `UpdateBlockAttributesTest.php` — one
  unit test file per use case.
- `tests/Integration/block-edits-verification.php` — the runtime verifier:
  path round-trip byte-identity, sibling/subtree isolation,
  `expected_block_name` mismatch and out-of-range path rejection (no write in
  either case), stale-token rejection, the task 1 nested-block-validation
  regression asserted through the public surface, preview determinism (no
  audit/revision/`post_modified_gmt` change), freeform-node addressing, and
  `update-block-attributes` shallow-merge/removal/escaping.

## llms.txt feature

The only feature in this plugin with an unauthenticated public surface.

- `Domain/Llms/` — `LlmsConfig`, `LlmsArtifact`, `LlmsSourceEntry`, the pure
  `LlmsDocumentBuilder`, and the diff/preview/result value objects. The builder
  enforces every bound (document bytes, sections, items per section, excerpt
  length, links) by truncating and recording a warning, never by failing.
- `Application/Llms/` — `GetLlmsTxt`, `PreviewUpdateLlmsTxt`, `UpdateLlmsTxt`,
  `RegenerateLlmsTxt`, `AdoptLlmsTxtOwnership`, and the pure
  `LlmsInitialConfigFactory`, plus the `LlmsArtifactStore`,
  `LlmsSourceSelector`, `LlmsOwnershipInspector`, and closed-target
  `LlmsLegacyArtifactArchiver` ports. The initial factory supplies bounded
  defaults to the admin workflow; ownership adoption is used only by the admin
  adapter. Neither is registered as an Ability.
- `Infrastructure/WordPress/WordPressLlmsArtifactStore` — one option holds the
  configuration, one holds the snapshot. `LlmsTxtEndpoint` reads only the
  latter.
- `Infrastructure/WordPress/WordPressLlmsSourceSelector` — eligibility. Excludes
  draft, private, password-protected, non-public post types, and `noindex`. The
  `noindex` decision goes through `SeoProvider::is_noindex()`, which is
  order-independent by design; see gap 9 in `.agents/status.md` for why reading
  it from `SeoProvider::get()` was wrong.
- `Infrastructure/WordPress/WordPressLlmsOwnershipInspector` — reports whether
  Yoast's llms.txt feature or a physical file already owns the path, whether
  legacy companion outputs remain, and whether pretty permalinks can route the
  virtual endpoint. Probes the
  directory serving the **home** URL, not `ABSPATH`; the two differ on any
  subdirectory install and probing `ABSPATH` produced a false negative. Reports
  existence only, never a path.
- `Infrastructure/WordPress/WordPressLlmsWebRoot` and
  `WordPressLlmsLegacyArtifactArchiver` — share the correct home web-root
  resolution and implement the wp-admin-only, exact-target timestamped archive
  migration with symlink/type/collision checks and best-effort rollback.
- `Infrastructure/WordPress/LlmsTxtEndpoint` — the virtual route. Registered
  only while `wpcb_llms_enabled` is true, gated on `WP::$matched_rule` so the
  canonical path is the only URL that reaches it, and exits during
  `parse_request` so nothing else in WordPress runs.
- `Infrastructure/WordPress/LlmsRegenerationScheduler` and
  `LlmsRegenerationRunner` — the fixed-deadline debounce and the cursor-batched
  cron run that replaces the snapshot atomically at the end.
- `Adapter/Abilities/LlmsAbilities` — the four abilities, all behind
  `wpcb_manage_llms`.
- `Adapter/Admin/ContentAccessSettingsPage` — always-visible two-step local
  workflow: prepare an initial snapshot from site-owned settings and Read policy,
  then archive exact legacy targets after every adoption prerequisite is ready.
- Runtime evidence: `tests/Integration/llms-txt-verification.php`.

## Status workflow feature

- `Domain/Status/` — `ContentStatus` (the fixed five, single source of truth),
  `StatusTransition` (an ordered pair), `StatusTransitionGraph` (the per-type
  allowlist), `StatusTransitionConfig`, and `PublishAt`. All pure. The graph
  **rejects** unknown statuses, self-pairs, and over-bound input rather than
  truncating: a silently dropped pair would move an authorization boundary.
- `Application/Status/` — `GetStatusTransitions`, `TransitionContentStatus`, the
  `StatusTransitionSettingsRepository` / `StatusTransitionTargetRepository` /
  `SiteClock` ports, and `StatusTransitionManager`, whose `config()` catches an
  invalid stored graph and degrades to deny-all rather than fataling.
- `Infrastructure/WordPress/WordPressStatusTransitionRepository` — the
  `wpcb_status_transitions` option. Never seeded by `Installer`, because the
  existence of the option row is what distinguishes "never configured" from
  "configured to nothing".
- `WordPressContentMutationRepository::transition_status()` — sets `post_status`
  with `post_date` and `post_date_gmt` together plus `edit_date`, then re-reads
  and throws if WordPress stored something else.
- `Adapter/Abilities/GetStatusTransitionsAbilities` and
  `TransitionContentStatusAbilities`.
- Runtime evidence: `tests/Integration/status-workflow-verification.php`.

## Redirect feature

Roadmap Slice 5 (ADR 0026, amended 2026-09-01). Reachable from two Abilities
behind the `wpcb_redirects_enabled` switch and the `wpcb_manage_redirects`
capability; the write additionally requires `wpcb_writes_enabled`.

The shape follows one fact: a site can run Redirection and Yoast Premium at the
same time, and then **both engines serve redirects** and whichever hooks first
wins. So reads and the safety guard span every provider, while a write goes to
exactly one the caller names.

- `Domain/Redirect/` — `RedirectSourcePath` (bounded, exact, site-relative,
  non-regex source), `RedirectTargetUrl` (same-site target, normalized to a
  path), `RedirectStatusCode` (301/302/410, the P0 allowlist),
  `RedirectProviderStatus`, and `RedirectRule` (the aggregate; a Gone rule
  must have no target, every other status must have one). All pure, no
  WordPress dependency.
- `Application/Redirect/RedirectProvider` — the provider-neutral port
  (`is_available`, `status`, `search`, `create`).
  `RedirectProviderRegistry` has **no** implicit active-provider accessor: a
  unit test asserts `active()` stays absent, because an ordered first-available
  pick would silently choose a backend whose rule may not be the one that
  fires. `select( $slug )` returns the named provider or refuses;
  `available()` returns all of them; `statuses()` reports every configured
  provider plus the null fallback, so "no provider" stays distinguishable from
  "no redirects". The registry is built in `Plugin::boot()` **above** the
  `wpcb_redirects_enabled` block, because `get-diagnostics` reports provider
  detection with the feature switched off (ADR 0026 s4): otherwise "the switch
  is off" and "neither plugin is installed" would produce the same report,
  while needing opposite fixes.
- `Application/Redirect/RedirectRuleLookup` — asks every available provider who
  claims a source path. Attributes a claim to the provider that **answered**,
  not to the `provider` field stamped on the returned rule, so a mis-stamping
  adapter cannot make a collision name the wrong plugin. A provider that
  cannot answer propagates instead of being skipped: silence there reads as
  "nobody claims this path", the one wrong conclusion.
- `Application/Redirect/RedirectCandidateGuard` — the provider-neutral
  invariants every candidate must pass before any adapter's `create()` runs:
  the reserved-path denylist (core endpoints, `wp-sitemap*`, `robots.txt`, and
  this plugin's own `llms.txt`/`llms-full.txt`), the live-content shadow guard
  (via the `PublishedPermalinkLookup` port), **cross-provider** collision, and
  a 3-hop **cross-provider** chain/loop bound.
- `Application/Redirect/SearchRedirects` / `CreateRedirect` — the use cases.
  The read reports one entry per provider with a `claimed` / `free` /
  `not_representable` / `unavailable` state, so one provider's unreadable rule
  never blanks out another's readable answer, and neither refusal is ever
  reported as `free`. The write audits field names only, with `object_type`
  `redirect` and no object ID.
- `Adapter/Abilities/SearchRedirectsAbilities` /
  `CreateRedirectAbilities` — one class per ability, matching the
  trash/restore pair. The read is registered by the redirect flag alone,
  because knowing which engine holds a path is a diagnostic; the write also
  needs the master write switch.
- `Infrastructure/Redirection/RedirectionProvider` — calls Redirection's
  `redirection/v1` REST routes through an internal `rest_do_request()`
  dispatch, scoped to `wpcb_manage_redirects` via the `redirection_role`/
  `redirection_capability_check` filters (registered and removed around one
  call only) instead of the plugin's `manage_options` default. Its REST
  payload mapping was initially assembled from Redirection's public
  documentation and **reconciled against a live 5.9.0 install on 2026-08-14**
  by reading the plugin's actual source, which disagreed with its own docs
  twice (see the class docblock and `.agents/status.md`).
- `Infrastructure/Yoast/YoastPremiumRedirectProvider` — calls Premium 28.0's
  redirect manager in-process (classmap-autoloaded, no `is_admin()` guard).
  It asserts Yoast's native `wpseo_manage_redirects` itself because that
  manager checks nothing; never calls `WPSEO_Redirect_Validator`, which issues
  a live outbound `wp_remote_head()` against the target; writes through
  `create_redirect()` so the two derived export options the front end actually
  reads are regenerated; and translates origins in both directions, since
  Yoast stores a plain origin with **both** slashes trimmed. A rule it holds
  but this contract cannot express raises `RedirectRuleNotRepresentable`,
  never "no rule found".
- `Infrastructure/WordPress/WordPressPublishedPermalinkLookup` — the
  `PublishedPermalinkLookup` adapter. Handles the site root and public
  post-type archives explicitly, because `url_to_postid()` answers `0` for the
  root: relying on it alone allowed a redirect to be created **on `/`**, found
  by a live probe. Term archives and other rewrite-driven routes are still not
  resolved, and the class says so.
- `tests/Integration/redirects-verification.php` — the repeatable runtime
  fixture, including the `/`-shadow regression guard. Restores the feature flag
  and deletes every rule it created.
- Not built on this port: `disable` as an operation distinct from `update` and
  `delete`.

## Site error statistics feature

ADR 0030. One ability, `get-404-statistics`, behind the
`wpcb_error_statistics_enabled` switch and the `wpcb_read_error_statistics`
capability. It answers the half of the operator's redirect question that
creating a redirect cannot: *which* redirect is missing.

**A separate port from redirects, deliberately.** Statistics availability does
not follow redirect availability — Yoast Premium serves redirects and collects
no 404 data at all (verified from source, not inferred from missing docs), so
hanging this off `RedirectProvider` would force a Yoast-backed site to answer
"no 404s", which reads as "no problems". `RedirectProvider` gains no
statistics method and the two registries are separate.

- `Domain/Statistics/` — `ErrorStatisticsAvailability` (the four states:
  `measured`, `disabled`, `forbidden`, `unavailable`, which never collapse),
  `NotFoundCount` (path and hit count, and *nothing else* — there is no field
  for an IP, agent, or referrer and no parameter that could add one),
  `ErrorStatisticsWindow` (retention, requested and effective boundary, and an
  explicit `truncated` signal), `ErrorStatisticsProviderStatus`, and
  `NotFoundStatistics`, which refuses to carry counts unless it is `measured`
  and refuses to be `disabled` without naming the setting responsible.
- `Application/Statistics/` — the `ErrorStatisticsProvider` port, its registry
  (no implicit active-provider accessor, same reasoning as redirects), the
  null object that answers `unavailable` rather than an empty measurement,
  `NotFoundStatisticsQuery` (bounded `since` and `limit`; a future `since` is
  refused because it would return an empty *measured* result), and
  `GetNotFoundStatistics`, which reports per provider and never merges two
  backends' counts into one top-N over different retention windows.
- `Infrastructure/Redirection/RedirectionErrorStatisticsProvider` — reads
  `{prefix}redirection_404` in SQL, in-process. Not through Redirection's REST
  API, because the aggregation there rests on an undeclared `groupBy`
  parameter in an API its author calls unstable, and the log routes accept no
  date filter while the table's `created` column is indexed. Two costs, both
  handled: it probes the table and the exact columns it reads and reports
  unavailable rather than issuing a query it cannot vouch for; and, since a
  direct read bypasses Redirection's own permission check, it *queries*
  Redirection's documented `redirection_capability_check` filter and requires
  the capability that filter names — never registering the filter, which would
  mean this plugin answering its own permission question.
- `Adapter/Abilities/ErrorStatisticsAbilities` — the ability. Its capability is
  not `wpcb_manage_redirects`: Redirection separates 404 reading from redirect
  management in its own model, so the useful grant — diagnose without
  authority to change routing — stays expressible.
- `tests/Integration/error-statistics-verification.php` — the runtime fixture.
  Read-only against the log; it asserts the three branches on real data, that
  counts carry no key beyond `path` and `hits`, that `since` is actually
  applied (a one-second window must not return the whole log, which is what a
  timezone mistake would produce), and that a range older than retention
  reports `truncated`.
- Not built, and out of scope by ADR 0030: any write, prune, or reset; any
  long-term history beyond the provider's own retention; scheduling,
  thresholds, or notifications.

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
- Block-level edit path-addressing decision:
  `docs/adr/0022-block-level-edits-are-addressed-by-tree-path.md`.
- Virtual llms.txt endpoint decision:
  `docs/adr/0023-llms-txt-is-published-through-a-virtual-endpoint.md`.
- Status transition graph decision:
  `docs/adr/0024-status-transitions-are-an-explicit-per-type-pair-allowlist.md`.
- Plugin-owned MCP projection decision:
  `docs/adr/0025-the-plugin-projects-its-own-abilities-by-category.md`.
- Redirect provider-neutral port and scoped third-party capability decision:
  `docs/adr/0026-redirects-use-a-provider-neutral-port-with-scoped-third-party-capabilities.md`.
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

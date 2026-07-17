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
  including bounded public references to address, geo, hours, and branch data.
- `src/Adapter/Abilities/SeoAbilities.php` — thin read-only
  `wp-content-bridge/get-url-seo` projection and stable error mapping.
- `stubs/yoast.stub.php` — static-analysis-only declarations for Yoast's global
  surface accessor; it is never loaded at runtime.
- `tests/Unit/Domain/Seo/` and `tests/Unit/Application/Seo/` — bounds, selector,
  registry, null-provider, and authorization-ordering contracts.
- `tests/Unit/Infrastructure/Yoast/` — Premium parser, Local public projection,
  nested secret rejection, and pure multi-location branch fixtures.

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

## Specification routes

- Product behavior: `docs/spec/REQUIREMENTS.md`.
- Access model: `docs/architecture/CONTENT_ACCESS.md` and ADR 0006.
- Ability contracts: `docs/architecture/ABILITIES.md`.
- Threat model: `docs/architecture/SECURITY.md`.
- Delivery order: `docs/plan/IMPLEMENTATION_PLAN.md`.
- Comparative implementation research: `docs/research/`.
- Private credential decision: `docs/adr/0007-private-credentials-are-principal-bound.md`.
- Content/SEO composition decision: `docs/adr/0008-compose-seo-instead-of-embedding.md`.
- Agent procedures: `.agents/instructions/`.
- Milestone 1B evidence: `docs/verification/ABILITIES_VERIFICATION.md`.

## Expected next feature path

Milestones 1–2 and Milestone 3B are complete. The next path is:

1. Add a real licensed Local multiple-location fixture covering primary and
   branch entities through SEO and editorial-context abilities.
2. Close Milestone 3 only after that runtime matrix passes.
3. Begin Milestone 4 with official MCP Adapter/client smoke tests and an
   ADR-backed private authentication control-plane decision.

Do not start write abilities until the Milestones 1–3 security gates are
closed. Existing and new content reads continue to consume
`ContentAccessManager`; none may call `get_option()` directly.

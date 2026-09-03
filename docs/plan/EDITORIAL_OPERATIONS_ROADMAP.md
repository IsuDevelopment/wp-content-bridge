# Editorial operations roadmap

Status: accepted for sequential implementation on 2026-08-03.

This roadmap extends WP Content Bridge from safe content/SEO configuration into
a bounded editorial operating surface. Work is deliberately split into
independently releasable slices. A later slice must not start until the previous
slice passes its unit, contract, static-analysis, WordPress runtime, and MCP
discovery gates.

The roadmap is generic. It must not introduce Kormas-specific post types,
workflows, copy, media rules, or deployment assumptions.

## Entry gate

Slice 1A does not start until the runtime verification backlog in
`docs/plan/IMPLEMENTATION_PLAN.md` is clear. Releases 0.1.3 through 0.3.0
shipped on static checks alone because the verification environment has been
unavailable since 2026-07-21. Adding slices on top of unverified write surfaces
compounds the risk that this roadmap's own gates are meant to prevent.

## Architectural rules for every slice

- Abilities remain thin WordPress adapters over transport-neutral application
  services and immutable domain values.
- Reads, previews, and writes are separate semantic intents. A preview never
  accepts a `dry_run` mode on a destructive Ability and never writes metadata,
  creates revisions, emits mutation audit rows, or invalidates cache.
- Every mutation of an existing object requires an authoritative
  `version_token`, object-level native authorization, an operation-specific
  WPCB capability, an enabled feature/policy gate, redacted audit, post-write
  verification, and post-scoped cache invalidation.
- New public Ability IDs and JSON Schemas are treated as stable API. Each slice
  updates the closed MCP profile, runtime discovery fixture, README, Ability
  catalog, code map, security model, test plan, and status file.
- No slice may expose arbitrary post meta, options, REST proxying, remote URL
  fetching, raw hooks, provider internals, SQL, filesystem access, or generic
  action dispatch.
- Provider-specific operations use explicit public provider contracts. Missing
  or incompatible optional providers remove the dependent Abilities from
  registration and MCP discovery.
- Publication, deletion, media writes, redirect writes, and audit reads receive
  distinct capabilities. Broad editor or administrator authority is not
  inferred from a WPCB capability.
- Every implementation begins with an ADR or an update to an accepted ADR when
  the slice adds a write surface, external provider boundary, new persistence,
  or changes an existing public contract.

## Release sequence

### Slice 1A — content and SEO preview (`0.4.0`)

**Status: done, 2026-08-07.** `preview-update-content` and
`preview-update-seo` are implemented per ADR 0021, `composer check` is green,
and `tests/Integration/preview-verification.php` plus the closed-profile
guard both pass on Kormas local. See `.agents/status.md` for the full record.
`0.4.5` (consolidation, `docs/plan/RELEASE_0_4_5_PLAN.md`) comes next, then
Slice 1B as `0.5.0`.

Goal: let clients validate and review prospective content and SEO changes before
the existing mutation Abilities are called.

Prior art in the codebase: `preview-update-service-schema` (ADR 0019) and
`preview-update-custom-schema` (ADR 0020) already implement this pattern — a separate
read-only intent reusing the write's exact validation, policy, provider, and
concurrency paths. Slice 1A extends that pattern to the core content and SEO
writes rather than inventing a second one.

**Naming convention — decided 2026-08-07.** A preview ability ID is
`preview-` followed by the exact ID of the write it mirrors. This makes the
relation mechanical and keeps the later redirect and revision slices
consistent. The two previews shipped in 0.3.0 were renamed accordingly
(`preview-service-schema` → `preview-update-service-schema`,
`preview-custom-schema` → `preview-update-custom-schema`) while the surface was
still one release old with a single connector.

Abilities:

- `wp-content-bridge/preview-update-content`
- `wp-content-bridge/preview-update-seo`

Contract requirements:

- accept the same mutable fields, bounds, provider checks, policy checks, and
  `version_token` as the matching update Ability;
- return normalized `current`, `prospective`, `changed_fields`, and a bounded
  field/block diff plus warnings;
- content preview must parse and round-trip Gutenberg markup without applying
  content filters that can mutate stored source;
- SEO preview must normalize through the active writer/provider without writing
  Yoast metadata and must distinguish configured prospective values from public
  resolved output that is unavailable until render;
- preview responses declare `writes_performed: false`, `context_resolved: false`
  where appropriate, and the token they evaluated. The field is deliberately not
  called `dry_run`: the architectural rule above forbids a `dry_run` *mode* on a
  destructive Ability, and reusing the word for a response flag has proven
  confusing;
- stale tokens, unavailable providers, unsupported fields, invalid blocks, and
  unauthorized targets fail exactly as the corresponding write would fail
  before its first mutation.

Acceptance:

- repeated previews are deterministic and cause no post/meta/revision/audit or
  cache change;
- a preview followed by an update with the same token produces the previewed
  configured state;
- content deletion/replacement warnings are bounded and machine-readable;
- all existing update contracts remain backward compatible.

### Slice 1B — llms.txt read, preview, configuration, and generation (`0.4.0`)

Goal: expose a bounded workflow for inspecting, editing, previewing, and
regenerating `/llms.txt` before the controlled status workflow begins in 0.5.0.
Slice 1A and Slice 1B are implemented sequentially and released together; the
status-transition slice must not be mixed into this release.

Open sequencing question: this is the heaviest slice in the roadmap — a new
domain, generator, versioned state store, virtual endpoint, rewrite/cache ADR,
multisite behavior, debounced regeneration, and two third-party ownership
matrices. Bundling it with the small, low-risk Slice 1A means the previews ship
only when all of that is verified. Splitting the release (0.4.0 = previews,
llms.txt = its own version, later slices shifted) is the recommended
alternative and requires a renumbering decision before Slice 1A starts.

Threat-model note: this slice introduces the first **unauthenticated public
route** the plugin has ever served. Every architectural rule above is written
for capability-gated Abilities and does not cover an anonymous `GET`. Before
implementation, extend the security model with at least: no synchronous
generation on a front-end request, no unbounded regeneration triggerable by
public traffic, cache-header and ETag correctness under shared caches, and
proof that unpublished, private, password-protected, and `noindex` titles or
URLs cannot reach the artifact.

Reference implementation:

- study the GPL implementation in LLMagnet AI SEO Optimizer 3.4.x, including
  public-post-type selection, per-item exclusion, excerpt generation, grouping,
  noindex handling, bounded generation, background regeneration, and its
  `llmagnet/get-llms-txt-status` and `llmagnet/regenerate-llms-txt` Abilities;
- do not copy its storage keys, call undocumented internals from application
  services, or make WP Content Bridge depend on its classes, options, Abilities,
  filesystem layout, activation state, or release lifecycle;
- the currently inspected LLMagnet 3.4.3 generator already accepts configured
  public custom post types. The bridge extends the management surface and
  authorization model rather than assuming that only `post` is supported;
- record all adapted code and compatibility assumptions, and preserve the GPL
  license obligations of both projects.

Candidate Abilities:

- `wp-content-bridge/get-llms-txt`
- `wp-content-bridge/preview-update-llms-txt`
- `wp-content-bridge/update-llms-txt`
- `wp-content-bridge/regenerate-llms-txt`

Architecture and ownership gate:

- WP Content Bridge owns a native `llms.txt` domain, generator, versioned state
  store, publisher, and Abilities. LLMagnet is research material only and is not
  a runtime provider or optional dependency;
- publish a generated snapshot through a native virtual `/llms.txt` endpoint
  instead of writing arbitrary files under `ABSPATH`. Requests serve the last
  accepted snapshot and never regenerate content synchronously;
- store one bounded, versioned configuration + artifact state in non-autoloaded
  WordPress storage so a successful replacement is atomic at the bridge state
  boundary. The public response supplies deterministic `ETag`, `Last-Modified`,
  UTF-8 `text/plain` content type, and bounded cache headers;
- use explicit application ports for artifact storage, public publication, and
  source-content selection so HTTP/rewrite, WordPress persistence, and content
  querying do not enter the domain generator;
- regeneration after relevant content/SEO changes is debounced and queued. A
  front-end request never performs a full site query or provider calculation;
- virtual publishing requires a rewrite/cache ADR plus single-site and
  multisite behavior tests. A physical root file can bypass WordPress and must
  be treated as an ownership conflict, not overwritten;
- never write `llmagnet_*` options, call LLMagnet classes/Abilities, or retain
  an adapter after the reference study is complete.

Third-party generators and ownership transfer:

- before enabling bridge publication, detect whether LLMagnet is active and
  whether physical `llms.txt`, `llms-full.txt`, or `llms-docs` artifacts can
  shadow/conflict with the bridge endpoint;
- when Yoast SEO is active, detect through a version-tested narrow adapter
  whether its `llms.txt` feature is enabled and whether Yoast claims ownership
  of the physical artifact. Never expose the complete `wpseo` option or other
  unrelated Yoast settings;
- treat an enabled Yoast `llms.txt` feature as a blocking ownership conflict.
  Yoast writes a physical root file and schedules regeneration, and that file
  can take precedence over the bridge's virtual endpoint;
- report conflicts through safe diagnostics without returning filesystem paths;
- `get-llms-txt` and preview return a structured ownership state containing the
  detected owner, active/enabled flags, public verification result, blocking
  conflict code, and a safe administrator action. They do not silently choose
  one generator;
- the bridge settings page shows a persistent notice and a safe admin link to
  **Yoast SEO -> Settings -> Site features -> AI tools -> llms.txt** when Yoast
  is enabled. The message instructs the administrator to disable Yoast's
  feature, save, and verify removal of its physical artifact before enabling
  bridge publication;
- do not deactivate LLMagnet or delete/move third-party files automatically.
  Removal is an explicit administrator deployment step outside MCP;
- capture the current public `llms.txt` over the same-site URL as migration
  reference, build and approve the bridge preview, then deactivate/remove
  LLMagnet and its stale physical artifact before enabling bridge publication;
- activation fails closed while a conflicting physical `/llms.txt` wins routing.
  Read/preview may remain available, but update/regeneration cannot claim that
  their artifact is public until same-site verification proves the bridge
  endpoint is serving it;
- disabling Yoast is not part of the initial bridge mutation contract. Research
  whether the supported Yoast version exposes a documented, stable setter that
  triggers its normal cron cleanup and removes only a file it still owns. Never
  update the raw `wpseo.enable_llms_txt` value directly;
- if an automated Yoast handoff is accepted later, implement separate preview
  and confirmed administrator-only intents. Require `wpcb_manage_settings`,
  native `manage_options`, a Yoast-version token, explicit confirmation,
  redacted audit, post-action read-back, cron verification, and same-site public
  verification. A failure must leave bridge publication disabled;
- if no stable Yoast write surface exists, retain read-only detection and guided
  manual disablement instead of coupling to undocumented internals;
- rollback consists of disabling bridge publication and restoring the prior
  deployment artifact or plugin; the bridge never keeps a hidden copy of
  third-party settings or credentials.

Normalized configuration is bounded to:

- enabled state and selected eligible public post types, including public CPTs;
- custom site introduction, section order/labels, grouping, excerpt visibility
  and length, maximum items per section, and optional same-site curated links;
- published, non-password-protected content only;
- explicit exclusion of attachments, internal post types, non-public objects,
  and content resolved as `noindex` by the active SEO provider;
- bounded per-section and whole-document byte, link, line, and nesting limits.

Read and preview requirements:

- `get-llms-txt` returns native publisher/schema identity and version, current
  configuration, current public artifact, generation time, byte/link counts,
  warnings, and a concurrency token derived from provider configuration plus
  effective artifact state;
- `preview-update-llms-txt` accepts the same configurable fields as update, builds a
  bounded prospective document without settings/files/options writes, and
  returns current/prospective summaries plus a line/section diff;
- Markdown validation requires valid UTF-8, no forbidden control characters,
  bounded headings/links, canonical same-site URLs for generated entries, and
  the expected top-level `#` title and blockquote summary from the llms.txt
  proposal;
- generated content is untrusted public content and is never interpreted as
  connector or model instructions.

Write requirements:

- introduce an off-by-default llms.txt write switch and dedicated
  `wpcb_manage_llms` capability;
- require current configuration/artifact token, bridge authorization, native
  configuration access where appropriate, redacted audit, atomic state
  replacement, post-write public read-back, and bridge-scoped cache
  invalidation;
- never grant `manage_options` to the integration user. The dedicated bridge
  capability and closed configuration schema are the external write boundary;
- `update-llms-txt` changes normalized configuration and regenerates only after
  complete validation. A persistence or publication failure preserves/restores
  the last accepted state and returns a verified error result;
- `regenerate-llms-txt` accepts no arbitrary paths or content, is idempotent for
  unchanged source/configuration, and rebuilds only through the native bridge
  generator;
- never expose root paths, arbitrary file writes, per-post raw metadata, remote
  fetches, API keys, analytics, Freemius/account state, or paid-plan bypasses.

Acceptance:

- public CPT selection works against a disposable fixture and preserves
  configured ordering/limits;
- noindex, password-protected, unpublished, excluded, and unauthorized content
  does not enter the artifact;
- preview causes no option/file/audit/cache mutation;
- stale tokens and public-artifact ownership conflicts prevent the first write;
- post-write output is re-read from the public same-site endpoint and matches the
  accepted normalized configuration;
- Yoast enabled/disabled, Yoast-owned file, foreign physical file, LLMagnet
  active/inactive, and no-conflict states have independent runtime fixtures;
- a Yoast or foreign-file conflict cannot enable bridge publication, schedule
  bridge regeneration, or report the bridge as the public owner;
- LLMagnet can be removed with no missing runtime class, option, Ability, cron,
  filesystem, or licensing dependency in WP Content Bridge;
- MCP discovery contains only intents enabled by bridge policy.

### Slice 2 — controlled status workflow (`0.5.0`)

Goal: complete the draft-review-publish/schedule workflow without adding a free-
form `post_status` field to content updates.

Abilities:

- `wp-content-bridge/get-status-transitions`
- `wp-content-bridge/transition-content-status`

`preview-transition-content-status` was cut on 2026-08-07; see "When a preview
Ability is justified". `get-status-transitions` already returns the allowed
set, so any prospective-state reporting belongs in that read.

Implementation follows ADR 0015:

- administrator-configured finite transition graph per post type;
- supported editorial statuses initially limited to `draft`, `pending`,
  `private`, `publish`, and `future` where WordPress supports them;
- `trash`, `auto-draft`, `inherit`, arbitrary registered statuses, and permanent
  deletion are excluded;
- `publish_at` is accepted only for `future`, uses the WordPress site timezone,
  is normalized to UTC for persistence, and is returned in both site and UTC
  forms;
- ordinary editorial transitions require `wpcb_edit_content`, native
  `edit_post`, Read policy, and Transition policy;
- `publish` and `future` additionally require the off-by-default publication
  switch, `wpcb_publish_content`, and native `publish_post`/type equivalent;
- transition writes create/preserve a revision where WordPress supports it,
  verify final status/date, audit changed field names only, and invalidate the
  affected post plus bounded public dependencies.

Acceptance:

- publication is impossible through `create-draft` and `update-content`;
- clients can discover allowed transitions without guessing;
- stale, unauthorized, disabled, and invalid transitions cannot mutate;
- scheduling is verified around DST and site-timezone boundaries;
- explicit human review is demonstrated in a runtime draft -> preview ->
  publish and draft -> preview -> future flow.

### Slice 3 — revision inspection and recovery (`0.6.0`)

Goal: provide a bounded recovery path for connector-created and human-created
content edits.

Abilities:

- `wp-content-bridge/list-content-revisions`
- `wp-content-bridge/get-content-revision`
- `wp-content-bridge/preview-restore-content-revision`
- `wp-content-bridge/restore-content-revision`

**Decided 2026-08-07: `restore-trashed-content` is built, and it is pulled
forward out of this slice into `0.4.5`.** `trash-content` has been live since
0.1.5, so the asymmetry — a connector can trash an object and cannot undo it
without wp-admin — is a present risk, not a future one. Waiting for `0.7.0`
would leave it open across four releases to buy nothing; the ability itself is
small (`wp_untrash_post()`, the existing trash feature-flag family, a
dedicated capability, current token, native `delete_post`, verified
post-restore status). It is specified in `docs/plan/RELEASE_0_4_5_PLAN.md`.

Restoring to the pre-trash status rather than an unconditional `draft` is the
part that needs care: WordPress records `_wp_trash_meta_status`, but it can be
absent or stale, and the fallback must be the safe direction. Publication must
not be reachable through untrash when the publication gate is off.

Contract requirements:

- list bounded revision identity, date, author display identity, parent post,
  changed-field summary when derivable, and pagination; never expose user email,
  login, arbitrary revision meta, or autosave internals;
- revision detail returns selected bounded content representations and the
  authoritative parent `version_token`;
- preview compares the selected revision with the current parent without
  restoring it;
- restore accepts revision ID, parent post ID, and current parent token;
- restore copies only the accepted content fields. It must not silently change
  status, slug, author, publication date, SEO configuration, Schema, or featured
  image;
- restoration preserves the pre-restore state as a recoverable revision and
  verifies the effective result.

Acceptance:

- missing and unauthorized revisions use a non-enumerating error shape;
- stale parent content cannot be overwritten;
- restore is itself reversible and fully audited without storing content in the
  audit log;
- revision-disabled post types report explicit unsupported state.

### Slice 4 — media metadata and featured image writes (`0.7.0`)

Goal: let clients finish page presentation using existing Media Library assets,
without introducing upload or remote import yet.

Abilities:

- `wp-content-bridge/update-media`
- `wp-content-bridge/update-featured-image`

`preview-update-media` and `preview-update-featured-image` were cut on
2026-08-07; see "When a preview Ability is justified". Media metadata is
sanitized strings echoed back, and attachment readability is already
answerable through `get-media-by-id`. The pre-write snapshot, post-write
verification, and rollback requirements below are unaffected — they are
properties of the write, not of a preview.

Contract requirements:

- add an authoritative attachment version token to media detail output under a
  versioned, backward-compatible envelope;
- media update allowlist: title, ALT text, caption, and description only;
- introduce an off-by-default media-write switch and `wpcb_edit_media`; require
  native `edit_post` for the attachment and media read access;
- featured image update accepts one normalized target state: a readable,
  same-site image attachment ID, or explicit `0` to remove the image;
- require the parent post token and native `edit_post`; require native media
  readability/usability before any parent mutation;
- validate MIME using WordPress attachment identity, not caller-supplied MIME or
  URL; SVG support remains outside this slice;
- because attachment revisions are not universally available, media metadata
  writes require pre-write snapshots, post-write verification, and best-effort
  rollback on partial failure.

Acceptance:

- invalid/non-image/unreadable attachments fail before the first write;
- previews cause no changes;
- final media and featured-image identity are re-read and returned;
- cache invalidation remains post-scoped and never accepts caller-selected
  purge targets.

Explicitly deferred: binary upload, base64 upload, remote URL import, image
editing, filesystem paths, arbitrary attachment metadata, and SVG enablement.

### Slice 5 — permalink changes and dual redirect providers (`0.8.0+`)

Goal: support deliberate slug changes without hiding their SEO and routing
impact.

Provider decision:

- support Yoast SEO Premium Redirect Manager and the Redirection plugin by John
  Godley behind one provider-neutral redirect port;
- select a compatible Yoast Premium provider first. Select Redirection only
  when the Yoast Premium redirect provider is unavailable; never dual-write;
- expose the selected provider and version in diagnostics and every redirect
  result. Provider switching never migrates or merges rules implicitly;
- a provider error during a write never triggers an automatic fallback write to
  the other provider.

**Slice 5 gains a statistics half, 2026-09-01 (ADR 0030 proposed).** Creating a
redirect is only half of the operator's question; the other half is *which*
redirect is missing, which needs the site's 404 history. Reading both backends'
source found that statistics do not follow redirect availability: Redirection
5.9.0 keeps a 404 log, a redirect-hit log and per-redirect counters, while Yoast
SEO Premium 28.0 collects none of it (its crawl-error screen is a stub — Google
discontinued the API). So statistics get a **separate port** with unavailable,
disabled and measured as three distinct results, and an **aggregate-only**
surface: requested path, hit count, retention window, and none of the `ip`,
`agent`, `referrer` or request-header fields the log rows also carry. The same
pass corrected ADR 0026: Yoast Premium does have a redirect REST API, which
reopens that ADR's build order and its fixture-gated internal-class path.

**Phase 5A status: research complete, ADR proposed 2026-08-14.** See ADR 0026
(`docs/adr/0026-redirects-use-a-provider-neutral-port-with-scoped-third-party-capabilities.md`)
for the full findings and decisions. Summary: Redirection (John Godley) has a
documented, versioned REST and PHP API plus a granular permission-filter pair
(`redirection_role`, `redirection_capability_check`) that lets WPCB call it
without ever granting `manage_options`; Yoast SEO Premium has no documented API
at all — only three undocumented internal classes reverse-engineered by the
community — though it does expose an already-granular native
`wpseo_manage_redirects` capability. Runtime provider preference stays Yoast
Premium first per the product reasoning below, but **implementation starts with
the Redirection adapter**, and the Yoast Premium adapter does not ship until a
version-pinned compatibility fixture exists and passes. ADR 0026 also fixes the
provider-neutral collision/hierarchy/trailing-slash/chain/loop/cache/canonical
invariants and decides that permalink and redirect mutations remain two
separate, explicit writes — no atomic or implicit coordination.

Phase 5A was research and ADR work:

- compare WordPress old-slug behavior, Yoast Premium redirects, and at least one
  supported version of Redirection using documented public APIs;
- version-test Yoast Premium's redirect endpoint/manager surface and require its
  native `wpseo_manage_redirects` capability in addition to bridge authority;
- prefer Redirection's documented REST API from an infrastructure adapter and
  require its effective redirect-manage/add capability. Its own documentation
  labels the API as not stable, so supported versions require contract fixtures
  and fail-closed compatibility detection;
- define collision, hierarchy, trailing-slash, multilingual, redirect-chain,
  loop, archive, cache, sitemap, and canonical behavior;
- decide whether permalink and redirect mutations can be safely coordinated or
  must remain separate confirmed steps.

Candidate permalink Abilities after acceptance:

- `wp-content-bridge/preview-update-permalink`
- `wp-content-bridge/update-permalink`

Rules:

- accept a bounded slug, never a caller-selected full permalink;
- verify uniqueness and return old/new canonical URLs;
- require current post token, dedicated per-type policy, native `edit_post`,
  redacted audit, and old+new bounded cache invalidation;
- a published URL change must report redirect disposition explicitly;
- no redirect is created implicitly unless an accepted ADR defines an atomic or
  safely recoverable provider workflow.

Candidate redirect Abilities after provider acceptance:

- `wp-content-bridge/search-redirects`
- `wp-content-bridge/get-redirect`
- `wp-content-bridge/preview-create-redirect`
- `wp-content-bridge/create-redirect`
- `wp-content-bridge/preview-update-redirect`
- `wp-content-bridge/update-redirect`
- `wp-content-bridge/disable-redirect`

Redirect P0 is deliberately restricted to exact, non-regex source paths and
same-site targets using a small common HTTP-status allowlist. It excludes
wildcards, regex, pass-through actions, arbitrary headers, external targets,
bulk actions, import/export, log access, 404-log access, and permanent deletion.
Every write requires `wpcb_manage_redirects`, the active provider's native
capability, a disabled-by-default feature flag, a redirect-level concurrency
token, loop/chain/duplicate checks, redacted audit, post-write read-back, and
bounded cache invalidation.

### Slice 6 — targeted Gutenberg block editing (`0.9.0+`)

Goal: update a selected section of a complex page without replacing the entire
`post_content` document.

Research gate:

- define stable block identity for nested blocks, reusable/synced patterns,
  dynamic blocks, template-part references, invalid/freeform blocks, and
  concurrent reordering;
- prefer an explicit block path plus hash of the selected serialized subtree;
  reject stale or ambiguous matches rather than guessing;
- keep reusable entities outside the parent-post update unless a separate
  authorized operation explicitly targets them.

Candidate Abilities:

- `wp-content-bridge/get-block-outline`
- `wp-content-bridge/validate-block-markup`
- `wp-content-bridge/preview-update-block`
- `wp-content-bridge/update-block`

Validation levels:

- server structural validation: payload bounds, delimiter/parser integrity,
  registered block names, nesting/depth/count limits, allowed attributes,
  reusable references, and parse/serialize round-trip;
- targeted-update validation: selected subtree hash, parent version token,
  unchanged sibling serialization, and final full-document structural pass;
- editor-semantic validation: parity with the block editor's regenerated
  `save()` markup. PHP `parse_blocks()`/`serialize_blocks()` do not perform this
  check. A true semantic result therefore requires a reviewed integration with
  the WordPress `@wordpress/blocks` validation runtime; without it the Ability
  must report `editor_validation: unavailable`, never claim full Gutenberg
  validity from the PHP round-trip alone;
- dynamic blocks are structurally checked but are not executed merely to
  validate input. Rendering remains a distinct bounded preview concern.

Acceptance:

- untouched serialized blocks remain byte-stable wherever WordPress parsing
  permits it;
- nested replacement cannot escape its selected subtree;
- preview and final full-document block validation pass;
- a stale subtree hash or parent version token prevents mutation.
- the result reports structural and editor-semantic validation separately, with
  precise bounded diagnostics and no silent block recovery or conversion.

### Slice 7 — connector mutation history (`0.10.0+`)

Goal: answer who/what/when for bridge operations and identify recoverable
changes without exposing content values.

Candidate Ability:

- `wp-content-bridge/get-mutation-history`

Rules:

- dedicated `wpcb_read_audit` capability and off-by-default exposure;
- bounded filters by target post, Ability ID, outcome, and date range;
- return event ID/time, actor display identity or stable redacted identifier,
  target identity, changed field names, outcome/error code, version transition,
  and revision ID when available;
- never return content, SEO values, Schema JSON, credentials, IP addresses,
  request headers, tokens, or arbitrary log context;
- retention and erasure behavior require an ADR before the audit store becomes a
  supported read model. Note that this is not a greenfield decision: the capped
  `{prefix}wpcb_audit` table has been written and pruned to a bounded row count
  since Milestone 5 Plan 1, so the ADR ratifies or changes an existing retention
  behavior and must state what happens to rows already collected.

### Slice 8 — bounded multi-object inventory (`0.11.0+`)

Goal: reduce MCP round trips for site audits without adding bulk writes.

Design gate:

- measure existing `search-content` + per-object read cost before adding a new
  public contract;
- choose a semantic inventory read instead of a generic batch dispatcher;
- limit a request to a small authorized object set and fixed summary fields;
- apply per-object content and SEO authorization before inclusion and report
  exact/inexact totals without leaking denied objects;
- cap provider work, encoded payload size, and execution time.

Candidate Ability:

- `wp-content-bridge/get-content-inventory`

Candidate output includes post identity/status/URL, modification date, title,
featured-image identity, normalized configured SEO summary, canonical, robots,
and explicit completeness/provenance. It does not generate SEO recommendations
and never performs writes.

## Per-slice delivery checklist

1. Confirm a clean worktree and current baseline.
2. Write or update the relevant ADR, Ability catalog, threat model, and test
   matrix before implementation.
3. Add domain values and ports, then application services, WordPress/provider
   adapters, Ability schemas/registrations, and composition-root wiring.
4. Add settings, policy operations, capabilities, option migrations, and safe
   defaults only where the slice requires them.
5. Update the closed MCP profile and assert required inputs on raw MCP tool
   descriptors.
6. Run narrow unit tests while developing, then PHPCS, PHPStan, PHPUnit, and
   `composer check`.
7. Run disposable WordPress runtime authorization, mutation, audit, cache, and
   discovery verification on Kormas local where the optional providers exist.
8. Update README/readme.txt, code map, implementation plan, test plan, status,
   changelog, version constants, release artifact inventory, and upgrade notes.
9. Review the complete diff for public-contract compatibility and security.
10. Commit and push only after explicit user authorization; `main` remains a
    production deployment boundary.

## Starting point

Implementation starts with Slice 1 and its contract/ADR. No code for Slice 2
or later should be mixed into the Slice 1 branch or release.

Three decisions were due before the first Slice 1A commit:

1. ~~the runtime verification backlog is cleared~~ — **cleared 2026-08-07**
   (see "Entry gate" and `.agents/status.md`);
2. ~~the preview ability naming convention is fixed~~ — **fixed 2026-08-07**:
   `preview-` plus the exact ID of the write it mirrors; the two shipped
   `preview-*` IDs were renamed accordingly. Slice 1A shipped against this
   convention with no further rename;
3. ~~whether Slice 1A and Slice 1B share the 0.4.0 release or the llms.txt work
   gets its own version~~ — **decided 2026-08-07: they split.** Slice 1A
   shipped alone as 0.4.0 because it was small, low-risk, and verified;
   holding it behind the heaviest slice in the roadmap would have been the
   worst of both. llms.txt takes its own version and everything after it moves
   up one. See "Release numbering" below.

## Release numbering

Decided 2026-08-07, superseding the version in each slice heading:

| Version | Content | State |
|---|---|---|
| `0.4.0` | Slice 1A — content and SEO preview | shipped |
| `0.4.5` | Consolidation and debt (see below) | shipped |
| `0.5.0` | Block-level edits — path-addressed `get-block-tree`/`update-block`/`preview-update-block`/`update-block-attributes` (ADR 0022) | shipped |
| `0.6.0` | Slice 1B — llms.txt (ADR 0023) | shipped |
| `0.7.0` | Slice 2 — controlled status workflow (ADR 0024) | shipped |
| `0.8.0` | Plugin-owned MCP projection, discovered by category (ADR 0025) | shipped |
| `0.9.0+` | Slice 5 — permalinks and dual redirect providers | planned, next up |
| `0.10.0` | Slice 3 — revision inspection and recovery | planned |
| `0.11.0` | Slice 4 — media metadata and featured image | planned |
| `0.12.0+` | Slice 6 — targeted block editing | planned |
| `0.13.0+` | Slice 7 — connector mutation history | planned |
| `0.14.0+` | Slice 8 — bounded multi-object inventory | planned |

**Decided 2026-08-14: Slice 5 (permalinks and dual redirect providers) is
reprioritized ahead of Slice 3 and Slice 4** and becomes the next release after
`0.8.2`. Slice 3 and Slice 4 are not cancelled, only deferred one release each;
their contract requirements above are unchanged. This is a priority reordering,
not a dependency change — Slice 5 does not require Slice 3 or Slice 4, and
nothing in either of those slices is a prerequisite for Slice 5's Phase 5A
research/ADR work or the permalink and redirect Abilities that follow it. Work
on Slice 5 starts with Phase 5A as specified below; no permalink or redirect
Ability is implemented before its ADR is accepted.

`0.5.0` was inserted ahead of the sequence above and is not one of its
numbered slices; it shipped the block-level editing work specified in
`docs/plan/SLICE_BLOCK_EDITS_EXECUTION_PLAN.md` and ADR 0022, addressing part
of what Slice 6 below still lists as a `0.11.0+` research gate (stable block
identity for nested/reusable/dynamic blocks, hash-based staleness detection,
editor-semantic validation parity). Every slice from Slice 1B onward moved up
one version to make room for it.

`0.4.5` is not a roadmap slice. It is a deliberate consolidation release that
pays down debt accumulated through 0.4.0 before the llms.txt slice adds a new
domain, a new persistence model, and the plugin's first unauthenticated public
route. Its contents are in `docs/plan/RELEASE_0_4_5_PLAN.md`. Nothing in it is
breaking, so the patch-level number is honest.

## When a preview Ability is justified

Added 2026-08-07 after Slice 1A shipped, because the pattern was being applied
reflexively.

A preview costs a public Ability ID, a schema pair, an MCP profile entry, a use
case, tests, and a permanent compatibility obligation. It is only worth that
when it answers a question **the caller cannot answer from the matching `get-*`
read plus the request it already holds.**

The test: *if the caller diffed its own payload against the current read, would
the preview tell it anything more?*

- **Yes → build it.** The server transforms the input in a way the caller
  cannot reproduce (WordPress block parsing, document generation, slug
  collision resolution), or the write's effect is not a simple field
  assignment (replace-not-merge semantics, redirect loops, revision diffs).
- **No → do not build it.** Echoing back a sanitized string the caller just
  sent is not a preview. The matching write already reports policy denial,
  stale tokens, and provider unavailability before its first mutation, so a
  preview that only surfaces those adds an approval-free probe, not new
  information.

Applying the test to this roadmap retires three planned previews:

- `preview-transition-content-status` — **cut.** `get-status-transitions`
  already returns the allowed transitions; the caller picks one from that list
  and knows exactly what it asked for. Fold any prospective-state reporting
  into `get-status-transitions`.
- `preview-update-media` — **cut.** Title, ALT, caption, and description are
  sanitized strings echoed back.
- `preview-update-featured-image` — **cut.** Attachment readability and
  usability are already answerable through `get-media-by-id`.

The previews that survive the test and remain planned:
`preview-update-llms-txt` (generates a whole document from site content),
`preview-restore-content-revision` (diffs a revision against the current
parent), `preview-update-permalink` (uniqueness, canonical URLs, redirect
disposition), `preview-update-block` (subtree hash and sibling byte-stability),
`preview-create-redirect` and `preview-update-redirect` (loop, chain, and
duplicate detection against existing rules).

The two shipped in 0.4.0 both stay, but they are not equal.
`preview-update-content` clears the test comfortably: the
`parse_blocks()`/`serialize_blocks()` round-trip can change what would actually
be stored, and the `content_replaced` / `content_deleted` /
`taxonomies_replaced` warnings state that `update-content` and taxonomy
assignment **replace rather than merge** — the single most destructive property
of the write and one no caller can infer from its own payload.
`preview-update-seo` is close to an echo; its narrow real value is `canonical`
normalization through `esc_url_raw` and social-image attachment resolution,
which can fail with `SeoImageUnavailable`. It is kept for symmetry with the
write pair and because the surface already shipped, not because it earns its
keep as strongly.

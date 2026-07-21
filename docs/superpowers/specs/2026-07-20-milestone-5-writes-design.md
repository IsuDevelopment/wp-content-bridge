# Milestone 5 — Content + SEO + Publish writes

Design/spec. Date: 2026-07-20. Status: approved, ready for implementation planning.

> **2026-07-21 amendment:** ADR 0015 supersedes this document's unreleased
> `publish-content` contract. Public and scheduled publication will be part of
> the future `transition-content-status` ability. Reversible deletion is now a
> separate `trash-content` ability; permanent deletion and restore remain out
> of scope. Historical publication sections below are retained as design
> provenance, not as the current contract.

This document is written to be executed by an implementer who has **not** seen
the brainstorming conversation. Follow it literally. Where it says "reuse", find
the named existing class and call it — do not reimplement.

---

## 0. Before you write any code

Read these first (they define the patterns you must copy):

1. `AGENTS.md`
2. `.agents/status.md` and `.continue-here.md`
3. `docs/architecture/CODE_MAP.md` — the layer boundaries you must preserve
4. `docs/architecture/SECURITY.md` — capability names, audit fields, threat list
5. `docs/plan/IMPLEMENTATION_PLAN.md` — Milestone 5 deliverables and exit gate
6. Read one existing vertical slice end to end as your template:
   - `src/Domain/Content/*`, `src/Application/Content/GetContent.php`,
     `src/Infrastructure/WordPress/WordPressContentRepository.php`,
     `src/Adapter/Abilities/ContentAbilities.php`.

Architecture rule (non-negotiable): **Domain → Application → Infrastructure →
Adapter**. Domain DTOs never call WordPress. Application services never call
WordPress functions directly — they call ports (interfaces). WordPress calls
live only in `src/Infrastructure/`. Ability adapters only map input/output and
`WP_Error`; they contain no policy.

Standing repo rule: **do not commit or push** without explicit authorization
from the maintainer.

---

## 1. Goal and scope

Add the plugin's first write surface. Four new abilities (three write, one
read-discovery), all disabled by default:

| Ability | Kind | Purpose |
|---|---|---|
| `wp-content-bridge/create-draft` | write | Create a new post/page/CPT in `draft` status only. |
| `wp-content-bridge/update-content` | write | Update title/content/excerpt/taxonomies of an existing post. |
| `wp-content-bridge/update-seo` | write | Write the version-tested Yoast Free core and Premium keyphrase allowlist for a post. |
| `wp-content-bridge/publish-content` | write | Transition an existing draft to `publish`. Behind its own flag + capability. |
| `wp-content-bridge/list-block-patterns` | read | Expose bounded registered site-pattern metadata and optional complete markup so an LLM composes valid blocks. |

### In scope
- Block content accepted as **raw Gutenberg block markup**, validated by a basic
  parse round-trip.
- Optimistic concurrency via a version token.
- Idempotent `create-draft`.
- WordPress revisions on update.
- SEO writes limited to a version-tested Yoast editor-field allowlist.
- Append-only capped audit table + `do_action` hook.
- Two independent master feature flags (writes; publish).

### Out of scope (do NOT build — YAGNI)
- Targeted `target_text` find/replace edits (future; note it, don't build it).
- HTML→block auto-conversion.
- Per-attribute block introspection ("far off" per the maintainer).
- Premium/Local SEO writes beyond the two Premium keyphrase fields accepted by
  ADR 0014.
- Scheduled publishing.
- Structural block-tree operations (block-mcp style ops).

---

## 2. Feature flags and enablement

Two options, both stored in `wp_options`, both **false by default**:

- `wpcb_writes_enabled` — master switch for `create-draft`, `update-content`,
  `update-seo`.
- `wpcb_publish_enabled` — separate switch for `publish-content`.

**Registration gating:** when a flag is false, its abilities are **not
registered at all**, so they are invisible to MCP discovery. This is stronger
than a runtime deny — an ability that does not exist cannot be called.
`publish-content` requires **both** `wpcb_writes_enabled` AND
`wpcb_publish_enabled` to be true.

Toggling: add checkboxes to the existing Settings page
(`src/Adapter/Admin/ContentAccessSettingsPage.php`) and expose via WP-CLI
(document commands in `docs/setup/`). No new admin framework.

---

## 3. Capabilities (names already declared in SECURITY.md — reuse, do not rename)

| Ability | Plugin capability | Native (object) capability checked |
|---|---|---|
| `create-draft` | `wpcb_edit_content` | post type's `create_posts` (fallback `edit_posts`) |
| `update-content` | `wpcb_edit_content` | `edit_post` on the target ID |
| `update-seo` | `wpcb_manage_seo` | `edit_post` on the target ID |
| `publish-content` | `wpcb_publish_content` | `publish_post` on the target ID (fallback `publish_posts`) |
| `list-block-patterns` | `wpcb_read_patterns` | editor-level native capability described in ADR 0013 |

Rules:
- An ability's permission callback requires **both** the plugin capability and
  the native object capability. Either missing → deny.
- `wpcb_publish_content` is **never** granted implicitly by `wpcb_edit_content`.
- Configuration (per-type WRITE policy) enables a gate but never grants a
  capability. A user with the flag on and the policy on still needs the caps.

`Installer.php` grants the three write caps to `administrator` on upgrade (as it
already does for `wpcb_read_content`). Broader assignment is explicit/manual.

---

## 4. Per-post-type WRITE policy

The option `wpcb_content_type_access` already reserves write columns
(`ContentOperation`). Wire the write use-cases to `ContentAccessManager` exactly
as reads do:
- `create-draft` / `update-content` require the type's WRITE (edit) operation
  enabled.
- `publish-content` requires the type's PUBLISH operation enabled.
- `update-seo` requires the type's SEO-write (manage-seo) operation enabled.

Confirm the exact `ContentOperation` constants that exist; if a needed write
operation constant is missing, add it in `src/Domain/ContentAccess/` following
the existing pattern and default it to **disabled**. Do not change read
defaults.

---

## 5. Module layout (new files)

```
src/Domain/Mutation/
  DraftInput.php            Immutable. Validated new-post input.
  ContentUpdate.php         Immutable. Validated existing-post update.
  SeoUpdate.php             Immutable. Validated SEO allowlist input.
  VersionToken.php          Immutable value object; equality + parse/serialize.
  MutationResult.php        Immutable. Outcome DTO returned to the adapter.

src/Application/Mutation/
  CreateDraft.php           Use case.
  UpdateContent.php         Use case.
  UpdateSeo.php             Use case.
  PublishContent.php        Use case.
  ContentMutationRepository.php   Port (interface).
  BlockMarkupValidator.php        Port (interface).
  SeoWriter.php                   Port (interface).
  AuditLog.php                    Port (interface).
  BlockPatternCatalog.php         Port (interface).
  MutationConflict.php            Typed failure (stale version).
  InvalidBlockMarkup.php          Typed failure.
  SeoFieldUnsupported.php         Typed failure.

src/Infrastructure/WordPress/
  WordPressContentMutationRepository.php   wp_insert_post/wp_update_post/revisions.
  PhpBlockMarkupValidator.php              parse_blocks round-trip + registry check.
  WordPressAuditLog.php                    capped custom table + do_action.
  WordPressBlockPatternCatalog.php         registered patterns + block-type allowlist.

src/Infrastructure/Yoast/
  YoastSeoWriter.php                       writes the versioned Free/Premium allowlist.

src/Adapter/Abilities/
  MutationAbilities.php     Registers + projects the 4 write abilities.
  PatternAbilities.php      Registers + projects list-block-patterns.
  (extend AbilitySchemas.php with the new input/output JSON schemas)

src/Infrastructure/WordPress/Installer.php   EXTEND: caps, audit table, flags.
src/Plugin.php                                EXTEND: wire new services, gate registration on flags.
src/Adapter/Admin/ContentAccessSettingsPage.php  EXTEND: flag checkboxes.
```

Reads stay untouched. Do not add write methods to `WordPressContentRepository`
or write callbacks to `ContentAbilities`.

---

## 6. Domain DTOs (exact fields)

All are immutable (readonly properties or private + getters, matching the
existing Domain style). Construction validates and throws a typed
`InvalidArgumentException`-style failure on violation; the adapter maps that to
`wpcb_invalid_input`.

### 6.1 `VersionToken`
- Fields: `string $modifiedGmt` (WP `post_modified_gmt`), `string $contentHash`
  (short hash, see below).
- `contentHash` = first 16 hex chars of `sha256(post_content . '|' . post_title . '|' . post_status)`.
- Serialized wire form: `"{modifiedGmt}:{contentHash}"` (single string in
  schemas). Provide `toString()` and `fromString()`.
- `equals(VersionToken $other): bool` compares both fields.
- **This same token must also be returned by the existing read abilities**
  (`get-content`) so a client can obtain it before editing. Add it to the
  `get-content` output as a new optional `version_token` field. This is the one
  permitted touch to a read ability; it is additive and read-only.

### 6.2 `DraftInput`
- `string $postType` (must be an eligible, WRITE-enabled type)
- `string $title` (required, non-empty, trimmed, length-bounded e.g. ≤ 500)
- `string $blockMarkup` (Gutenberg markup; may be empty for an empty draft)
- `?string $excerpt` (bounded)
- `TaxonomyAssignment[] $taxonomies` (reuse/adapt the existing `TaxonomyFilter`
  shape: taxonomy + positive term IDs; validated against the type)
- `?string $idempotencyKey` (client-supplied, bounded charset/length)
- Status is **always** `draft`; there is no status field on this DTO.

### 6.3 `ContentUpdate`
- `int $postId` (positive)
- `VersionToken $expectedVersion` (required)
- Optional, only-if-present-updated fields: `?string $title`, `?string
  $blockMarkup`, `?string $excerpt`, `?TaxonomyAssignment[] $taxonomies`.
- **Must not** carry a status field. Update never changes post status.
- At least one updatable field must be present (else `wpcb_invalid_input`).

### 6.4 `SeoUpdate`
- `int $postId`, `VersionToken $expectedVersion`.
- Nullable allowlist fields only (see §9): `?string $seoTitle`,
  `?string $metaDescription`, `?string $focusKeyphrase`, `?string $canonical`,
  `?bool $robotsIndex`, `?bool $robotsFollow`, `?string $ogTitle`,
  `?string $ogDescription`, `?string $twitterTitle`, `?string $twitterDescription`.
- Any field not in the allowlist present in the raw request → reject the whole
  request with `wpcb_seo_field_unsupported` (list the offending keys).
- At least one allowlist field must be present.

### 6.5 `MutationResult`
- `int $postId`, `string $postType`, `string $status`, `VersionToken
  $version` (the NEW token after write), `string[] $changedFields`,
  `bool $created` (true only for create-draft), and for SEO the
  `array $effectiveSeo` re-read result.

---

## 7. Write flow (applies to every mutation use case)

Order is mandatory. Stop at the first failure.

```
1. Feature flag check (adapter-level: ability not registered if flag off).
2. Permission callback: plugin capability AND native object capability.
3. ContentAccessManager: per-post-type WRITE/PUBLISH/SEO policy enabled.
4. Input validation: construct the Domain DTO (throws → wpcb_invalid_input).
5. Concurrency (update-content / update-seo / publish-content only):
   read current post, build current VersionToken, compare to expectedVersion.
   Mismatch → MutationConflict → wpcb_conflict (NO write performed).
6. Content validation (create-draft / update-content when blockMarkup present):
   BlockMarkupValidator.validate(blockMarkup). Invalid → InvalidBlockMarkup →
   wpcb_invalid_blocks.
7. Perform write via ContentMutationRepository (WordPress keeps a revision).
8. Re-read the object; build the new VersionToken and MutationResult.
9. AuditLog.record(event) and do_action('wpcb_mutation', $event).
10. Adapter validates output against the output schema and returns it.
```

If step 7 fails at the WordPress level, map to a stable error
(`wpcb_write_failed`) and still emit an audit event with outcome=failure.

---

## 8. Block markup validation (basic — do not over-engineer)

`PhpBlockMarkupValidator implements BlockMarkupValidator`:
- Use core `parse_blocks( $markup )`.
- Valid iff: parsing yields at least one block for non-empty input; there are no
  malformed/unclosed delimiters; every parsed top-level block with a
  `blockName` is a **registered** block type
  (`WP_Block_Type_Registry::get_instance()->is_registered()`); freeform/classic
  (`blockName === null`) blocks are allowed only if they contain no block
  delimiter fragments.
- Round-trip guard: `serialize_blocks( parse_blocks( $markup ) )` must reparse to
  the same block name sequence (detects silently dropped/mangled blocks).
- On failure return a bounded reason list (block index + reason). Do NOT return
  the raw markup in the error.
- Empty string is valid (an empty draft body).
- Deeper per-attribute schema validation is explicitly deferred to a later
  milestone. Do not add it.

---

## 9. SEO writes (`update-seo`)

Writable allowlist ONLY (reject anything else):

| Field | Yoast storage |
|---|---|
| `seo_title` | post SEO title (Yoast title meta) |
| `meta_description` | Yoast meta description |
| `focus_keyphrase` | Yoast focus keyphrase |
| `keyphrase_synonyms` | normalized Premium primary-keyphrase synonym list |
| `related_keyphrases` | normalized Premium related-keyphrase list |
| `canonical` | Yoast canonical |
| `robots_index` / `robots_follow` | Yoast robots noindex/nofollow booleans |
| `og_title` / `og_description` | Yoast Open Graph title/description |
| `twitter_title` / `twitter_description` | Yoast Twitter title/description |

`YoastSeoWriter implements SeoWriter`:
- Write only the explicit Yoast 28.x editor-meta allowlist verified against the
  installed editor implementation, through `WPSEO_Meta::set_value()`; never
  write indexables tables or arbitrary provider meta. ADR 0014 owns the Premium
  JSON normalization.
- Version-gate exactly like the read adapter (`YoastSeoProvider`).
- After writing, **re-read** effective SEO via the existing `YoastSeoProvider`
  and return it in `MutationResult.effectiveSeo` so the caller sees the resolved
  result (per plan exit gate "effective SEO is re-read after mutation").
- Other Premium/Local fields are not writable. If requested →
  `wpcb_seo_field_unsupported`.

---

## 10. Idempotency (`create-draft`)

- Optional `idempotency_key` (string, bounded).
- On create, if a key is supplied, store a mapping
  `key → created post ID` (transient or dedicated capped option, TTL ~24h).
- A repeat request with the same key within TTL returns the **existing** created
  post (same `MutationResult`, `created=false`) instead of creating a duplicate.
- Keys are scoped per WordPress user (do not let one user's key collide with
  another's).

---

## 11. Publish gating (`publish-content`)

- Registered only when `wpcb_writes_enabled` AND `wpcb_publish_enabled` are both
  true.
- Requires `wpcb_publish_content` capability + native `publish_post`.
- Input: `postId`, `expectedVersion`. Transitions `draft` → `publish` only. If
  the post is not currently `draft` (e.g. already published, pending, private) →
  `wpcb_invalid_state`.
- It cannot create; it cannot edit content in the same call.
- Returns an **approval-compatible** result contract: include an `approval`
  object (`{required: bool, approved_by: int|null}`) so a future human-approval
  envelope can wrap it without a schema change. In this phase `required` is
  driven by a filter `wpcb_publish_requires_approval` (default false); when true
  and no approval context is present, deny with `wpcb_approval_required` and do
  not publish.
- No `create-draft`/`update-content` code path may set status to `publish`
  (assert this in tests).

---

## 12. `list-block-patterns` (read-only)

This section is refined and superseded by ADR 0013. In particular, pattern
reads have a dedicated off-by-default setting and capability because the
registry is editor/site metadata, not configured content-type access.

`WordPressBlockPatternCatalog implements BlockPatternCatalog`:
- Return registered block patterns via
  `WP_Block_Patterns_Registry::get_instance()->get_all_registered()`:
  allowlisted metadata by default and complete `content` only when explicitly
  requested.
- Filter and paginate a deterministic scan of at most 1,000 candidates, with a
  maximum page size of 50.
- Fail atomically if selected complete content exceeds the combined 2 MiB
  response limit; never truncate block markup.
- Require `wpcb_read_patterns` plus a native editor-level capability. Never
  expose filesystem fields or trigger remote pattern loading.

---

## 13. Audit (`WordPressAuditLog`)

Create table `{$wpdb->prefix}wpcb_audit` in `Installer.php` (versioned, like
existing setup). Columns:

| Column | Notes |
|---|---|
| `id` BIGINT PK AUTO_INCREMENT | event id |
| `created_gmt` DATETIME | timestamp |
| `user_id` BIGINT | acting principal |
| `ability` VARCHAR(191) | ability id |
| `object_id` BIGINT NULL | target post (null for create-failure) |
| `object_type` VARCHAR(64) NULL | post type |
| `changed_fields` TEXT | JSON array of field **names** only |
| `expected_version` VARCHAR(191) NULL | token in |
| `resulting_version` VARCHAR(191) NULL | token out |
| `outcome` VARCHAR(32) | success \| conflict \| invalid \| denied \| failure |
| `error_code` VARCHAR(64) NULL | stable code |

Rules:
- **Never** store full content, secrets, credentials, or raw request headers
  (SECURITY.md). Only changed field names.
- Append-only. Cap total rows (ring-buffer prune oldest beyond N, e.g. 5000).
- Every mutation attempt records exactly one row, success or failure.
- Also `do_action( 'wpcb_mutation', array $event )` so site owners can hook
  their own logging.

---

## 14. Ability schemas (add to `AbilitySchemas.php`)

For each write ability define strict input + output JSON Schemas following the
existing style (no `additionalProperties`, explicit types, bounded strings).
Output schemas return the `MutationResult` shape including `version_token`
(string form) and `changed_fields`. Reuse existing error-code conventions;
new stable codes:
`wpcb_conflict`, `wpcb_invalid_blocks`, `wpcb_seo_field_unsupported`,
`wpcb_invalid_state`, `wpcb_approval_required`, `wpcb_write_failed`,
`wpcb_writes_disabled` (defensive; normally unreachable because unregistered).

---

## 15. Testing and exit gate

Mirror the existing test suites (`tests/Unit/*`, `tests/Integration/*`).

### Unit
- `VersionToken` equality + parse/serialize round-trip.
- `PhpBlockMarkupValidator`: valid markup, malformed delimiters, unregistered
  block type, dropped-block round-trip, empty input.
- DTO validation bounds for `DraftInput`, `ContentUpdate`, `SeoUpdate`.
- SEO allowlist mapping + rejection of non-allowlisted keys.
- Idempotency: repeat key returns same post.

### Integration (runtime, disposable fixtures — copy `authorization-matrix.php`)
- Write authorization matrix: anonymous, subscriber, author (own vs others'),
  editor, admin, least-privilege integration user — across create/update/seo/
  publish. Prove plugin cap, native cap, and policy are each independently
  required.
- Stale-version conflict: edit the post out-of-band, then an update with the old
  token returns `wpcb_conflict` and does **not** change the post.
- Revision created on update (compare revision count before/after).
- Block round-trip preserves valid blocks (create + read back, block sequence
  intact).
- SEO write + re-read parity (write allowlist, read effective values back).
- Audit: a mutation writes exactly one redacted row (no content/secret leakage).
- **Publish is blocked when `wpcb_publish_enabled` is off** (ability not
  registered) and blocked without `wpcb_publish_content` cap.
- **Write abilities are invisible when `wpcb_writes_enabled` is off.**
- Assert no create/update path can produce a `publish` status.

### Static
- PHPCS clean, PHPStan 0 errors (add Yoast write-surface stubs to
  `stubs/yoast.stub.php` if needed, static-analysis only).

### Exit gate (from IMPLEMENTATION_PLAN.md Milestone 5, extended for publish)
- No create/update ability can publish.
- Conflicts never overwrite newer edits.
- `publish-content` requires its own separate flag + capability; edit never
  implies publish.
- Effective SEO is re-read after mutation.
- Security review sign-off before any beta exposure.
- Writes remain globally off by default.

---

## 16. Wiring checklist (`Plugin.php`, `Installer.php`)

- `Installer.php`: bump schema version; grant `wpcb_edit_content`,
  `wpcb_manage_seo`, `wpcb_publish_content` to administrator; create the audit
  table; register the two flag options defaulting false; add any new
  `ContentOperation` write policy defaults (disabled).
- `Plugin.php`: instantiate the new infrastructure adapters and application
  services; register `MutationAbilities` **only if** `wpcb_writes_enabled`;
  register `publish-content` **only if** both flags true; always register
  `list-block-patterns` (read).
- `ContentAccessSettingsPage.php`: add the two flag checkboxes and the per-type
  write/publish/seo policy columns UI.

---

## 17. Notes for weaker/automated implementers

- Do exactly what a named existing file does; open it and copy its structure.
- Never call a WordPress function from `src/Domain` or `src/Application`.
- Every new public method needs a unit test before it is wired into an ability.
- If a Yoast write path is not clearly documented for 28.x, STOP and mark that
  field unsupported — do not reverse-engineer storage (this is a hard project
  rule; Premium keyphrase synonyms are now the narrow, version-tested exception
  defined by ADR 0014).
- Do not commit or push. Leave the working tree for the maintainer to review.
- When unsure whether something is in scope, check §1 "Out of scope" — if it's
  there, don't build it.

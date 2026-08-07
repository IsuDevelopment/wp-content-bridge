# Slice 1A execution plan — content and SEO preview (0.4.0)

Audience: an implementing agent working in this repository.

Goal: add `preview-content-update` and `preview-seo-update` as separate
read-only intents over the existing `update-content` and `update-seo` write
paths, so a client can see exactly what a write would do before calling it.

**This pattern already exists in the codebase.** `PreviewServiceSchema` (ADR
0019) and `PreviewCustomSchema` (ADR 0020) are working, shipped examples of the
same idea. Read them first and mirror them. Do not invent a new approach.

## Working rules

- Follow WordPress coding standards; the project enforces them through
  `composer check` (PHPCS + maximum-level PHPStan + PHPUnit).
- Do not write scripts that test other scripts, harnesses that verify
  harnesses, or throwaway diagnostic files. One unit test file per use case and
  one runtime verifier for the slice is the whole test budget.
- Do not re-run a check that already passed unless you changed something it
  covers.
- Preview must never write. No post meta, no revisions, no audit rows, no cache
  invalidation, no option writes.
- Ability IDs are stable public API. Use exactly the IDs decided in step 0.
- Commit only when the user asks.

## Step 0 — naming convention (already decided, nothing to do)

**Decided 2026-08-07, no longer blocking.** A preview ability ID is `preview-`
followed by the exact ID of the write it mirrors. The two previews shipped in
0.3.0 were already renamed to match
(`preview-update-service-schema`, `preview-update-custom-schema`), and the whole
roadmap was normalised to this shape.

Use `preview-update-content` and `preview-update-seo`. Do not reopen this.

## Step 1 — ADR

Create `docs/adr/0021-content-and-seo-preview-are-separate-read-only-intents.md`.

Model it on `docs/adr/0019-service-schema-preview-is-a-separate-read-only-intent.md`.
Record: why a separate intent instead of a `dry_run` flag on the destructive
ability, the naming decision from step 0, and that the preview response field is
`writes_performed: false` (not `dry_run`, which the roadmap reserves for the
forbidden mode).

## Step 2 — domain result DTOs

Add to `src/Domain/Mutation/`:

- `ContentPreviewResult.php`
- `SeoPreviewResult.php`

Copy the shape of `ServiceSchemaPreviewResult.php`: `post_id`, `post_type`,
`version` (`VersionToken`), `changed_fields`, `current_*`, `preview_*`. Add a
bounded `warnings` list — the roadmap requires machine-readable warnings for
content deletion/replacement.

Keep them immutable, constructor-promoted, and free of WordPress calls.

## Step 3 — application use cases

Add to `src/Application/Mutation/`:

- `PreviewContentUpdate.php`
- `PreviewSeoUpdate.php`

Mirror `PreviewServiceSchema.php` / `PreviewCustomSchema.php`. Each one must:

1. build the same input DTO the write uses — `ContentUpdate::from_input()` /
   `SeoUpdate::from_input()` — so bounds and validation are shared, not
   duplicated;
2. resolve post type through `ContentMutationRepository`;
3. enforce the same policy the write enforces (`ContentOperation::UPDATE` for
   content, `ContentOperation::UPDATE_SEO` for SEO);
4. verify the `version_token` and throw `MutationConflict` on a stale token,
   exactly as the write does before its first mutation;
5. for content: validate block markup through `BlockMarkupValidator` and
   round-trip it **without** applying content filters that could mutate stored
   source;
6. for SEO: normalize through the active `SeoWriter`/provider **without**
   writing Yoast metadata, and clearly separate configured prospective values
   from resolved public output that only exists after render;
7. return the DTO. No `AuditLog` dependency at all — if the constructor cannot
   take one, it cannot accidentally write one.

Unsupported SEO fields must fail with `SeoFieldUnsupported` here just as they do
in the write.

## Step 4 — ability schemas and registration

- `src/Adapter/Abilities/AbilitySchemas.php` — add input schemas (identical
  mutable fields to the matching write, plus required `post_id` and
  `version_token`) and strict output schemas. `additionalProperties` is `false`
  everywhere. Every required property needs a `description`; this is enforced by
  `abilities-runtime-verification.php`.
- `src/Adapter/Abilities/MutationAbilities.php` — register both previews
  alongside the writes they mirror, behind the same `wpcb_writes_enabled` flag
  and the same capability the write requires. Annotations must be
  `readonly: true`, `destructive: false`, `idempotent: true`.
- `src/Plugin.php` — wire the two use cases where `MutationAbilities` is
  constructed.

## Step 5 — closed profile

Add both IDs to:

- `tests/Integration/abilities-runtime-verification.php` → `CLOSED_PROFILE`
- `docs/architecture/ABILITIES.md` (the authoritative inventory)
- `docs/setup/MCP_ADAPTER.md`

Note for the user, do not do it yourself: the consuming site's MU-plugin package
`isudev/wp-content-bridge-mcp-server` also carries the projection list and lives
in a different repository. It is already one release behind.

## Step 6 — unit tests (one file per use case, that is all)

- `tests/Unit/Application/Mutation/PreviewContentUpdateTest.php`
- `tests/Unit/Application/Mutation/PreviewSeoUpdateTest.php`

Model on `tests/Unit/Application/Mutation/ServiceSchemaReadPreviewTest.php`.
Cover only:

1. a changing payload reports the right `changed_fields`;
2. a stale token raises `MutationConflict`;
3. policy denial and unsupported-field failure behave as the write does;
4. no audit row and no repository write occur — assert against the existing test
   doubles, do not build new infrastructure to observe it.

## Step 7 — runtime verifier (one file)

`tests/Integration/preview-verification.php`, modelled on
`tests/Integration/schema-service-verification.php` (recent, compact, correct
teardown).

Assert exactly the roadmap's acceptance criteria:

1. repeated previews are deterministic and change nothing — snapshot
   `post_modified_gmt`, the relevant meta, revision count, and audit row count
   before and after;
2. preview followed by `update-content` / `update-seo` with the same token
   produces the previewed configured state;
3. a stale token is rejected before any mutation.

Fixture rules: create your own post, restore every option you touch in a
`finally` block, delete the fixture. Discover the post type rather than
hard-coding it if a provider constrains it.

Run it with:

```
cd "/Users/lukaszbiedron/Local Sites/kormas-isu/app"
wp eval 'require "/Users/lukaszbiedron/Other Projects/wp-content-bridge/tests/Integration/preview-verification.php";'
```

## Step 8 — documentation and version

- `docs/architecture/ABILITIES.md` — full contract entry for both abilities.
- `docs/architecture/CODE_MAP.md` — the new files.
- `README.md` — catalog entries.
- `readme.txt` — `Stable tag: 0.4.0` and a `= 0.4.0 =` changelog block.
- `wp-content-bridge.php` — `Version: 0.4.0`.
- `.agents/status.md` — what shipped and what is still open.
- `docs/plan/EDITORIAL_OPERATIONS_ROADMAP.md` — mark Slice 1A done.

## Step 9 — final check

Run once, at the end:

```
cd "/Users/lukaszbiedron/Other Projects/wp-content-bridge"
composer check
```

Then run the new runtime verifier plus `abilities-runtime-verification.php`
(the closed-profile guard). Nothing else — the other eleven verifiers were green
on 2026-08-07 and this slice does not touch their surfaces.

If PHPCS reports fixable violations, run
`vendor/bin/phpcbf <files>` rather than hand-editing whitespace.

## Definition of done

- both abilities registered only when `wpcb_writes_enabled` is on;
- preview provably causes no post, meta, revision, audit, or cache change;
- preview and the matching write share one validation path, not two;
- stale tokens rejected identically in both;
- `composer check` green; the new verifier and the closed-profile verifier green;
- existing write contracts unchanged and backward compatible.

## Out of scope

Do not start Slice 1B (llms.txt) or Slice 2 (status transitions) in this branch.
llms.txt in particular is the heaviest slice in the roadmap and introduces the
plugin's first public unauthenticated route; it needs its own threat model and
its own release.

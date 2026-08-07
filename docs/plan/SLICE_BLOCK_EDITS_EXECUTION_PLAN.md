# Slice: block-level edits — execution plan (`0.5.0`)

Audience: an implementing agent working in this repository.

Read [`docs/adr/0022-block-level-edits-are-addressed-by-tree-path.md`](../adr/0022-block-level-edits-are-addressed-by-tree-path.md)
first. It carries the decision, the measurements behind it, and the reasoning
for what is deliberately **not** in this slice. This document is the how.

## What this slice fixes

`update-content` replaces the whole document, so an agent asked to change one
paragraph must re-emit all 69 blocks of a page and drifts on the 68 it was
never meant to touch. Path addressing removes the blast radius structurally:
the other blocks are never sent to the model and never re-parsed from its
output.

## Release numbering

This slice becomes **`0.5.0`**. Slice 1B (llms.txt) moves to `0.6.0` and
`transition-content-status` to `0.7.0`. Update the "Release numbering" table in
`docs/plan/EDITORIAL_OPERATIONS_ROADMAP.md` as part of task 6.

**`0.5.0` is a minor bump and therefore carries the one scheduled breaking
change:** removing the deprecated `dry_run` field from
`preview-update-service-schema` and `preview-update-custom-schema`. That was
promised in 0.4.5 task 2 and documented in `docs/architecture/ABILITIES.md`. It
rides here rather than waiting, because deferring it again means the field
outlives two more releases. See task 5.

## Working rules

- WordPress coding standards; enforced by `composer check` (PHPCS + maximum
  level PHPStan + PHPUnit).
- **Test budget: one unit test file per new use case, one runtime verifier for
  the whole slice.** Do not write tests of tests, re-tests, or throwaway
  diagnostic scripts. Extend existing assertions in place where a new field
  belongs to an already-covered shape.
- Run `composer check` once at the end of each task, not continuously.
- Ability IDs and response fields are stable public API.
- Commit only when the user asks.

## Task 1 — recursive block validation — **done 2026-08-07**

**Prerequisite for everything else. Do it first and alone.**

> **Amended.** Two instructions below were wrong.
>
> `PhpBlockMarkupValidatorTest` does not exist and never has. More usefully:
> **this class cannot be unit-tested under the current bootstrap at all.** It
> calls `parse_blocks()`, `serialize_blocks()` and `WP_Block_Type_Registry`
> directly, and `tests/Unit` never loads a WordPress runtime — every unit test
> touching `BlockMarkupValidator` uses a hand-written fake of the interface.
> The recursive behaviour is therefore covered in
> `tests/Integration/writes-mutation-verification.php`, which instantiates the
> real class. **That check does not run under `composer check`** — PHPUnit's
> directory scan requires a `Test.php` suffix — so it must be run through
> `wp eval` like every other verifier. Do not read a green `composer check` as
> evidence this task works.
>
> "Expect existing fixtures to start failing" did not materialise: nothing in
> the suite fed the real validator nested markup, which is itself why the hole
> survived this long.
>
> Delivered beyond the brief, and correct: `MAX_DEPTH = 64` guarding runaway
> nesting.

`PhpBlockMarkupValidator::validate()` iterates only the top level of
`parse_blocks()`, and `round_trips()` compares only the top-level name
sequence. An unregistered *nested* block passes validation today.

That is survivable while `update-content` replaces whole documents. It is not
survivable here, where the injected subtree is the only thing changing and is
exactly what goes unchecked.

- Walk `innerBlocks` recursively in the registration check.
- Compare the **full** name sequence, depth included, in the round-trip check.
- Keep `MAX_REASONS` bounded and report the failing block's path, not just its
  index, so a caller can act on the message.
- Keep the existing behaviour for empty markup and freeform nodes.

This strengthens `update-content` as a side effect. Extend
`PhpBlockMarkupValidatorTest` in place; no new test file.

**Expect existing fixtures to start failing.** If a fixture in the current
suite contains an unregistered nested block, that is the defect being fixed —
correct the fixture, do not weaken the validator.

## Task 2 — `wp-content-bridge/get-block-tree` — **done 2026-08-07**

> **Amended after measurement.** Two things below were specified wrongly and
> were corrected in the shipped implementation. Read the amendment in ADR 0022
> for the numbers.
>
> - **`attrs` are opt-in**, behind `include_attrs` (boolean, default `false`).
>   Emitting them for a whole document produced a response *larger* than the
>   `post_content` it replaces. When `include_attrs` is `false`, `attrs_omitted`
>   is not emitted either — absence is the contract, not an omission.
> - **`text` falls back to prose-bearing string attributes** when a node's
>   `innerHTML` is empty, and a required `text_source` field records
>   `"inner_html"`, `"attrs"`, or `null`. Without the fallback only 16 of a
>   77-node page carried a label, because this site keeps most editable prose
>   in block attributes. `text_source` also tells a caller whether editing that
>   block means replacing markup or changing an attribute.
>
> Known limits, accepted: the fallback scans only top-level string attribute
> values, so prose nested inside an object attribute is not found; and the
> "contains whitespace, ≥3 characters" heuristic can misread a two-token CSS
> value as prose.

A read. Same sensitivity as `get-content`, so the same gates: always
registered, requires `wpcb_read_content`, native `read_post` on the target, and
the per-post-type **Read** policy. No new flag and no new capability.

Register it alongside the other always-on reads rather than in a new adapter,
so it shares the existing permission callback.

**Input:** `post_id` (required). Optional `max_depth` (default unbounded,
bounded by the node cap) and `path` to return a subtree rather than the whole
document.

**Output:** `version_token`, plus a flat list of nodes in document order:

```json
{
  "version_token": "5556356d4eac2e64:2026-08-07 01:34:51",
  "nodes": [
    { "path": [7], "block_name": "core/accordion", "inner_blocks": 6,
      "text": "Najczęściej zadawane pytania", "attrs": { "faqStructuredData": true } },
    { "path": [7, 0], "block_name": "core/accordion-item", "inner_blocks": 2, "text": null }
  ],
  "truncated": false
}
```

Flat with explicit `path` beats nesting: it is what the caller passes straight
back to `update-block`, and it keeps the payload predictable.

**Freeform nodes are included.** `parse_blocks()` emits `blockName === null`
entries for whitespace between blocks, and they occupy real indices in the
array `update-block` mutates. Omitting them would produce paths that do not
survive the round trip. Emit them with `"block_name": null`. This is the
sharpest edge in the contract — document it in the schema `description`, not
only in the ADR.

**Bounds** (follow the existing bounded-read precedents):

- at most 500 nodes; set `truncated: true` and stop rather than failing;
- `text` is `wp_strip_all_tags()` of the node's own `innerHTML`, trimmed, at
  most 120 characters, `null` when empty;
- per-node `attrs` omitted with `"attrs_omitted": true` when its encoded form
  exceeds 512 bytes, so one pathological block cannot dominate the response;
- the existing 2 MiB representation limit applies to the whole response.

Ports: a read-only `BlockTreeRepository` in `Application`, implemented by
`WordPressBlockTreeRepository` in `Infrastructure`. Do not reach into
`WordPressContentRepository`; this is a different projection of the same post.

## Task 3 — `update-block` and `preview-update-block`

`wp-content-bridge/update-block` shares `update-content`'s gates exactly:
`wpcb_writes_enabled`, `wpcb_edit_content`, native `edit_post`, per-post-type
**Update** policy, optimistic concurrency, redacted audit, post-write re-read,
post-scoped cache invalidation.

Register both in a **new adapter file** rather than folding them into
`MutationAbilities`, following the `RestoreTrashedContentAbilities` precedent —
it keeps `writes-mutation-verification.php` untouched.

**Input:**

| Field | Required | Notes |
|---|---|---|
| `post_id` | yes | |
| `version_token` | yes | rejected stale before any mutation |
| `path` | yes | list of non-negative integers, at least one element |
| `expected_block_name` | yes | `null` legally asserts a freeform node |
| `block_markup` | yes | may be empty string to delete the subtree |

`expected_block_name` is the deterministic replacement for the rejected
`expected_matches`. A matching token proves the document did not change; it
does **not** prove the path points where the caller thinks. Assert one exact
fact about one exact position and fail closed with `wpcb_block_mismatch`
otherwise.

**Behaviour:** parse the current content, resolve `path`, assert the block
name, validate `block_markup` with the now-recursive validator, splice the
parsed replacement into the tree, `serialize_blocks()` the whole tree, and
write through the existing `ContentMutationRepository`. An out-of-range path is
`wpcb_block_path_not_found` — non-enumerating, like every other target failure.

Reuse `MutationResult`. `changed_fields` is `["content"]`; a block path is
positional detail and **must not** enter the audit row, which records field
names only.

**`preview-update-block`** mirrors it per ADR 0021: identical input contract,
identical policy and concurrency check, no `AuditLog` dependency at all, and
`writes_performed: false`. It passes the roadmap's preview justification test
on two counts the caller cannot compute — the parse/serialize round trip can
materially change stored markup, and block-type registration is specific to
this site.

Do not add `preview-update-block-attributes` or any attribute ability; ADR 0022
records why.

One unit test file per use case: `UpdateBlockTest`, `PreviewBlockUpdateTest`,
`GetBlockTreeTest`.

## Task 3b — `update-block-attributes`

**Pulled back into this slice on 2026-08-07, reversing ADR 0022's deferral.**

ADR 0022 deferred attribute editing on the reasoning that `update-block` can
express it — send the whole block markup with new attributes, and malformed
JSON fails closed at `parse_blocks()` rather than corrupting anything. That
reasoning still holds. What changed is the measured frequency.

On the reference site's heaviest page, **20 of 36 labelled nodes have their
text in attributes, not in `innerHTML`.** Site-wide the share is 12%, but on
exactly the pages that are painful to edit it is the majority. Making a model
hand-write delimiter JSON is therefore the *primary* edit path on those pages,
not an edge case, and hand-written JSON is where escaping mistakes live.

Contract: identical to `update-block` (`post_id`, `version_token`, `path`,
`expected_block_name`) except `block_markup` is replaced by `attributes`, a
JSON object. Semantics are a **shallow merge** into the block's existing
`attrs`; a `null` value removes a key. Re-serialize through
`serialize_blocks()` so WordPress owns the JSON encoding — that is the entire
point of the ability.

**No preview.** It fails the roadmap's preview justification test:
`get-block-tree` with `include_attrs: true` returns the current attributes, the
caller holds the new values, and a documented shallow merge is something the
caller can compute. Document the merge semantics in the schema instead. This is
the same test that cut three previews before 0.4.0; apply it consistently.

One unit test file, `UpdateBlockAttributesTest`.

## Task 4 — runtime verifier

One file, `tests/Integration/block-edits-verification.php`, modelled on
`restore-trashed-content-verification.php`. Restore every option in a
`finally`. Assert:

1. the tree's paths round-trip — for every node, `update-block` at that path
   with the node's own markup and correct `expected_block_name` leaves
   `post_content` **byte-identical**. This single property is the whole slice:
   if it holds, addressing is sound;
2. replacing one block changes that block and leaves every sibling and every
   unrelated subtree byte-identical;
3. a wrong `expected_block_name` fails with `wpcb_block_mismatch` and writes
   nothing;
4. an out-of-range path fails with `wpcb_block_path_not_found` and writes
   nothing;
5. a stale `version_token` is rejected before any mutation;
6. an unregistered **nested** block in `block_markup` is rejected — the task 1
   regression, asserted through the public surface;
7. preview is deterministic, adds no audit row, no revision, and no
   `post_modified_gmt` change, and a preview followed by the matching write
   produces exactly the previewed content;
8. a freeform node addressed with `expected_block_name: null` behaves;
9. `get-block-tree` omits `attrs` by default and includes them under
   `include_attrs: true`, and `text_source` reports `attrs` for a block whose
   prose lives in an attribute — the task 2 amendment, asserted through the
   public surface, since the attribute-scanning heuristic has no unit coverage
   (it needs a WordPress runtime);
10. `update-block-attributes` shallow-merges, removes a key on `null`, and
    produces valid delimiter JSON for a value containing `"` and `\` — the
    escaping case that motivates the ability.

Add its row to `docs/setup/VERIFICATION.md` in the same change. Needs `core`
only — it must run without Yoast or Schema Extended.

## Task 5 — remove the deprecated `dry_run` field

Breaking, and the reason this is a minor bump.

- drop `dry_run` from `ServiceSchemaPreviewResult` and
  `CustomSchemaPreviewResult`;
- drop it from both output schemas in `AbilitySchemas.php` and from their
  `required` arrays;
- update `docs/architecture/ABILITIES.md`, removing the deprecation note along
  with the field;
- append an "Amended" note to ADR 0019 and ADR 0020 recording the removal.

`writes_performed` has been present on all four previews since 0.4.5, so a
client that migrated has nothing to do. Call it out in the changelog under a
**Breaking** heading.

## Task 6 — release

### The changelog must lead with the backslash bug

Found on 2026-08-07 while verifying task 3b, and **shipped in every release
from 0.1.5 to 0.4.5 inclusive**. `wp_insert_post()` and `wp_update_post()`
expect slashed data and call `wp_unslash()` on it;
`WordPressContentMutationRepository` passed raw input, so every backslash
written through `create-draft` or `update-content` was silently stripped.

`serialize_block()` escapes a double quote inside a block's attribute JSON as
`"`. Stored unslashed, that became the literal text `u0022`. **Any block
whose attributes contained a quote was corrupted by any bridge write to that
post** — including writes that were not meant to touch that block, which is
exactly the symptom that motivated this whole slice.

Disclose it plainly, and disclose that **the plugin does not repair content
already damaged this way**. There is no safe automatic repair: `u0022` is
indistinguishable from text a user legitimately typed. Anyone who wrote through
the bridge should spot-check posts with custom blocks.

**Decided 2026-08-07: no `0.4.6` patch release.** The fix ships with `0.5.0`.
The bridge has one operator and writes only against a development site, so
nobody is exposed while the slice finishes.

### Release steps

- `readme.txt`: `Stable tag: 0.5.0`, a `= 0.5.0 =` block leading with the
  backslash fix, then the breaking `dry_run` removal;
- `wp-content-bridge.php`: `Version` and `WPCB_VERSION`;
- `docs/architecture/ABILITIES.md`, `CODE_MAP.md`, `README.md`,
  `docs/setup/MCP_ADAPTER.md` (profile becomes 25), `.agents/status.md`;
- the "Release numbering" table in the roadmap;
- record the run in the "Last full run" table of `docs/setup/VERIFICATION.md`.

The consuming site's MU-plugin projection is **optional hygiene, not a
blocker** — see "Two MCP servers, one projection" in `.agents/status.md`. Do
not repeat the claim that a missing bump makes an ability unreachable.

## Final check

```
cd "/Users/lukaszbiedron/Other Projects/wp-content-bridge"
composer check
```

Then the new verifier, plus `writes-mutation-verification.php` and
`preview-verification.php` (task 1 touches validation that both depend on) and
`abilities-runtime-verification.php` (the closed-profile guard). Nothing else.

## Definition of done

- every path returned by `get-block-tree` round-trips byte-identically through
  `update-block`;
- a single-block edit never alters any other block;
- an unregistered nested block cannot be written through any ability;
- `expected_block_name` and the version token are both mandatory on every
  block write;
- no `dry_run` field remains in any response;
- `composer check` green and the new verifier passing.

## Out of scope

`patch-content` with multiple operation types, `replace_text` in any form,
`find-links`, `update-links`, `add-faq-item`, `insert-section`, and any batch
form across multiple `post_ids`. ADR 0022 records the reasoning; the batch case
additionally requires a version token per target and must not be approximated
with one token for many posts.

`update-block-attributes` was originally listed here and was **pulled back into
scope** as task 3b once measurement showed attribute text is the majority case
on the pages this slice exists to fix.

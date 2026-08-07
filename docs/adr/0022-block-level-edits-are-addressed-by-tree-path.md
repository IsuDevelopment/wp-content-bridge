# ADR 0022: Block-level edits are addressed by tree path

**Status:** Accepted
**Date:** 2026-08-07

## Context

`update-content` replaces the entire `post_content`. There is no partial edit.
Changing one sentence means reading the whole document, rewriting it, and
sending all of it back.

This is not only expensive. It is the direct cause of the failure the plugin's
users actually hit: **an agent asked to change one paragraph must re-emit every
other block, and it drifts on the ones it was never meant to touch.** The blast
radius of any edit is the whole document, every time.

Measured on the reference site, page ID 204: 11,977 characters of
`post_content` across 69 blocks, averaging ~174 characters per block. Every
single-paragraph edit currently costs a 12,000-character write in which 68
blocks are rewritten for no reason.

### Why text search is not the answer

The obvious cheap fix — a `search`/`replace` operation on `post_content`, with
a match count as a guard — was evaluated against the reference site's real
content and rejected.

Gutenberg stores most core-block text as HTML between delimiters, where string
replacement is safe. It does **not** do so universally. A scan of the reference
site found 39 attribute values holding prose inside the block delimiter's JSON:

| Block | Attribute |
|---|---|
| `t2/extended-selling-point` | `title`, `description` |
| `isudev/icon-link` | `label` |
| `isudev/read-more` | `readMoreText` |

```
<!-- wp:t2/extended-selling-point {"title":"10+ lat <br>doświadczenia",
     "description":"Sprawdzone rozwiązania i praktyka w branży"} /-->
```

Two failure modes follow, and both produce exactly the symptom this ADR exists
to remove — damage to a block nobody intended to edit:

1. A replacement containing `"`, `\`, or a newline is written into the JSON
   unescaped and **corrupts the block delimiter**. The search string was
   ordinary prose; the block that breaks is collateral.
2. A search string containing quotes never matches inside attributes, because
   they are stored escaped (`class=\"has-inline-color\"`). The operation
   silently does nothing, or matches partially elsewhere.

A match counter does not detect either case: in both, the match count is
exactly what the caller expected.

## Decision

Address edits by **position in the parsed block tree**, not by text.

A path is a list of zero-based indices into successive `innerBlocks` arrays of
`parse_blocks( $post->post_content )`. `[7, 3, 0]` is the first inner block of
the fourth inner block of the eighth top-level block. Freeform nodes — the
`blockName === null` entries the parser emits for whitespace between blocks —
**are counted**, because they occupy real indices in the array the write will
mutate. A path that skipped them would not survive the round trip.

### The three abilities

| ID | Kind | Purpose |
|---|---|---|
| `wp-content-bridge/get-block-tree` | read | The document's structure without its bulk |
| `wp-content-bridge/update-block` | write | Replace exactly one block subtree |
| `wp-content-bridge/preview-update-block` | preview | What that write would store |

`update-block` replaces the subtree at `path` with the caller's
`block_markup`, re-serializes the whole tree through `serialize_blocks()`, and
writes the result. Everything outside the addressed subtree is byte-identical
by construction, not by validation — the other blocks are never re-emitted by
the caller and never re-parsed from caller input.

### Attribute edits are deferred, deliberately

`update-block-attributes` — merging a JSON object into a block's `attrs` — is
the ergonomic answer for the 51 blocks above, and it is **not** in this slice.

`update-block` already expresses it: the caller sends the whole block markup
with the attributes it wants. If their JSON is malformed, `parse_blocks()`
fails and the write is rejected before touching the post. That is the crucial
difference from text replacement: it **fails closed instead of corrupting**.

Ship path addressing first, then decide whether hand-written attribute JSON is
a real problem in practice or an anticipated one. Adding the ability later is
additive; removing a public contract is not.

### The concurrency contract

The existing `VersionToken` already hashes `post_content`, `post_title`, and
`post_status`, so **any** content change invalidates it. Paths therefore need
no new staleness mechanism: a path is valid exactly as long as the token it
was read with is valid.

- `get-block-tree` returns the current `version_token` alongside the tree.
- `update-block` and `preview-update-block` require that token, and reject a
  stale one with `wpcb_version_conflict` before any mutation, exactly as the
  other writes do.

**A matching token is necessary but not sufficient.** It proves the document
did not change; it does not prove the path points at the block the caller
believes it does. An off-by-one in the caller's own reasoning passes the token
check and then silently replaces the wrong block.

So `update-block` additionally requires `expected_block_name`, and fails
closed with `wpcb_block_mismatch` when the block at `path` is not of that
type. This is the deterministic form of the `expected_matches` idea: rather
than counting fuzzy text matches, it asserts one exact fact about one exact
position. `null` is a legal value and asserts a freeform node.

### Nested validation is a prerequisite, not a feature

`PhpBlockMarkupValidator::validate()` iterates only the top level of
`parse_blocks()`, and `round_trips()` compares only the top-level name
sequence. **An unregistered nested block passes validation today.**

That is tolerable while `update-content` replaces documents a caller composed
as a whole. It is not tolerable here: the injected subtree is the only thing
changing, and it is precisely what goes unchecked. Recursive validation lands
in this slice, before any surgical write ships.

## Consequences

**Positive.** A single-block edit costs roughly 174 characters instead of
11,977, and cannot damage the other 68 blocks because they are never in the
model's context. Recursive validation strengthens `update-content` too.

> **Amended 2026-08-07 — corrected measurement.** This section originally
> claimed reading the structure costs 4,692 characters against 11,977, a 61%
> reduction. That number came from a measurement script that returned only
> paths, names and inner-block counts, while the decision above specifies a
> tree that also carries block attributes. Measured against the shipped
> implementation on the same page, the honest figures are:
>
> | Response | Bytes |
> |---|---|
> | `post_content` | 11,977 |
> | `get-block-tree`, default | 8,804 (**26% reduction**) |
> | `get-block-tree`, `include_attrs: true` | 15,323 |
>
> Two consequences were folded into the implementation. Full `attrs` became
> **opt-in** via `include_attrs` (default `false`), because emitting them for a
> whole document produces a response larger than the content it replaces —
> they are meant for a subtree read, not a full-document scan. And the `text`
> label now falls back to prose-bearing string attributes when a node's
> `innerHTML` is empty, with a `text_source` field recording which it came
> from; without that fallback only 16 of the page's 77 nodes carried any label
> at all, because this site keeps most editable prose in block attributes. The
> fallback raises that to 36.
>
> **The read-side saving is modest and was oversold.** It is not the
> justification for this ADR and never was. The prize is the write side: 174
> characters instead of 11,977, and 68 blocks that cannot be damaged because
> they are never emitted by the caller.

**Negative.** Paths are positional and therefore fragile by nature; a caller
that caches one across an edit will be rejected rather than silently misapply
it, which is correct but requires re-reading the tree after every write.
`get-block-tree` adds a second read shape for content, so a caller must choose
between it and `get-content`. Freeform nodes occupying indices is a real
sharp edge and must be documented in the schema, not just here.

**Rejected alternatives.** A `patch-content` ability taking an array of eight
operation types (`replace_text`, `insert_before`, `insert_after`,
`insert_block`, `delete_block`, `replace_block`, `update_block_attributes`,
`update_link_attributes`) was proposed. It was rejected as a first slice: path
addressing makes text-search operations unnecessary, and each remaining
operation is expressible as `update-block` on the appropriate parent. A batch
form across multiple `post_ids` was rejected outright for this slice — the
plugin's safety model is per-object, requiring a native capability check and
a version token **per target**, and the proposal carried one token for eight
posts, which would mean bypassing optimistic concurrency.

A `dry_run` flag on `update-block` was rejected for the reasons already
recorded in ADR 0019 and ADR 0021: safety annotations must be truthful at the
tool level. `dry_run` is additionally deprecated and scheduled for removal in
0.5.0.

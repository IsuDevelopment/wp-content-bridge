# ADR 0014: Premium keyphrases use a normalized, versioned write contract

- Status: Accepted
- Date: 2026-07-21

## Context

The `update-seo` ability initially rejected all Yoast Premium fields. This left
two editor operations unavailable: changing primary-keyphrase synonyms and
changing related keyphrases.

The installed Yoast Premium 28.0 code establishes a narrow storage contract:

- `_yoast_wpseo_focuskeywords` is JSON containing ordered `keyword`/`score`
  objects;
- `_yoast_wpseo_keywordsynonyms` is a positional JSON array of comma-delimited
  strings: the first item belongs to the primary keyphrase and subsequent items
  correspond to related keyphrases in order.

That provider format is unsuitable as a public Ability input. Exposing raw JSON
would couple clients to ordering, permit unknown members, and make it easy to
misalign synonyms with keyphrases.

## Decision

Extend the existing semantic `wp-content-bridge/update-seo` ability with two
normalized optional fields:

- `keyphrase_synonyms`: a unique list of at most 20 non-empty strings;
- `related_keyphrases`: a unique list of at most 20 non-empty strings.

Each item is limited to 191 characters. Commas are rejected inside individual
synonyms because Yoast uses commas as its delimiter. An omitted or null field
is unchanged; an empty list explicitly clears that field.

The writer supports these fields only when both Yoast Free and Premium are in
the tested 28.x compatibility envelope. A request containing a Premium field is
rejected before any field is written when compatible Premium is unavailable.
The adapter encodes the provider JSON itself and writes through Yoast's
`WPSEO_Meta::set_value()` editor-meta API so WordPress slashing and Yoast's
post-meta/indexable watcher hooks remain intact. It preserves the score and synonym
string for a retained related keyphrase, assigns score `0` to a new related
keyphrase, removes positional synonym entries for removed phrases, and never
writes indexables tables.

The normalized read contract adds `configured.keyphrase_synonyms` and
`configured.related_keyphrases`, including related-keyphrase scores and
synonyms. The SEO normalization schema advances from 1.1 to 1.2 so the mandatory
post-write re-read can prove what landed.

The operation retains the existing `wpcb_writes_enabled`, `wpcb_manage_seo`,
native `edit_post`, per-type `update_seo`, optimistic-concurrency, audit, and
post-scoped cache-invalidation gates. No additional settings switch is needed:
these fields are part of the same SEO-editor state transition and have the same
authorization and consequence profile.

## Consequences

- Clients never send or receive Yoast's raw positional JSON.
- Yoast Free-only sites continue to use the existing core-field subset; only a
  request containing Premium fields requires Premium.
- Compatibility outside Premium 28.x remains explicitly unsupported until its
  storage and runtime matrix are verified.
- Yoast Local fields, redirects, analysis scores, and arbitrary `_yoast_*` meta
  remain outside this write allowlist.

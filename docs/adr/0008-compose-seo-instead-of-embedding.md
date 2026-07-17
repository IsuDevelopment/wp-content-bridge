# ADR 0008: Compose SEO reads instead of embedding them in content

## Status

Accepted for normalization schema 1.x.

## Context

`get-content` and `get-url-seo` share a target identifier but have different
availability, payload, compatibility, and caching characteristics. Yoast may be
absent or its derived indexables may be stale while the authoritative WordPress
content remains readable.

Embedding SEO into every content response would couple the content contract to
an optional provider, increase payload size, and make partial provider failure
ambiguous. A caller that only needs Gutenberg source should not pay that cost.

## Decision

Keep `wp-content-bridge/get-content` and `wp-content-bridge/get-url-seo` as
separate composable read abilities. An AI client retrieves content and then SEO
with the same `post_id` when the task needs both.

The application services remain separately reusable by a future workflow or
batch ability. Such an ability must preserve both response envelopes and report
partial failure explicitly; it must not silently merge SEO fields into content.

## Consequences

- Content reads remain operational without an SEO plugin or usable indexables.
- SEO payload and provider warnings are opt-in.
- Clients make two calls for combined analysis, but both calls are read-only and
  idempotent.
- A future client-oriented aggregate can be introduced without changing either
  stable domain ability.

# ADR 0012: Cache invalidation is post-scoped and event-driven

- Status: Accepted
- Date: 2026-07-21
- Narrowed by ADR 0032 (2026-09-03), which adds a URL-scoped path for the
  **old** URL after a slug change. Post-scoped invalidation cannot reach an
  entry keyed by a URL the post no longer has; nothing below changes.

## Context

Content and SEO writes can leave a page-cache plugin serving an older public
representation. WordPress already clears its object cache during normal post
writes, and cache plugins usually observe core lifecycle hooks. That is not
reliable for metadata-only SEO writes, however. Kormas local has LiteSpeed Cache
installed, whose public `litespeed_purge_post` hook accepts a single post ID.

A global cache flush would be operationally expensive and would couple the
application layer to hosting-specific infrastructure.

## Decision

WP Content Bridge subscribes in its WordPress infrastructure layer to the
existing, pre-redacted `wpcb_mutation` lifecycle event. For successful events
with an object ID it:

1. calls `clean_post_cache()` for that post;
2. dispatches `litespeed_purge_post` only when a listener is registered;
3. emits `wp_content_bridge_post_cache_invalidated` for optional additional
   site-level adapters.

Invalidation is best-effort after the write has committed. Adapter exceptions
are contained and exposed through
`wp_content_bridge_cache_invalidation_failed`; they never rewrite a successful
content mutation into a write failure.

The plugin does not inspect cache-plugin options, call private classes, or purge
an entire site. Additional providers integrate at the infrastructure hook, not
inside domain or application services.

## Consequences

- Content, post-meta, and SEO updates share one precise invalidation path.
- LiteSpeed Cache support remains optional and version-tolerant at the public
  hook boundary.
- A site can add another provider without changing stable ability contracts.
- Runtime verification must prove successful mutations emit invalidation and
  failed mutations do not.

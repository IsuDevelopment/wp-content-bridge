# ADR 0023: llms.txt is published through a virtual endpoint serving a stored snapshot

**Status:** Proposed
**Date:** 2026-08-07

## Context

Slice 1B exposes `/llms.txt`. This is the **first unauthenticated public route
the plugin has ever served.** Every architectural rule the plugin has was
written for capability-gated Abilities behind `wp_has_ability()` and a native
WordPress capability check. None of them applies to an anonymous `GET`, and the
plugin currently registers zero REST routes of its own.

The roadmap requires this ADR before implementation and names four minimum
conditions: no synchronous generation on a front-end request, no unbounded
regeneration triggerable by public traffic, correct cache headers and ETag
under shared caches, and proof that unpublished, private, password-protected
and `noindex` content cannot reach the artifact.

### State of the reference site, measured 2026-08-07

The roadmap anticipated an active ownership conflict. There is none today:

| Check | Result |
|---|---|
| `GET https://kormas-isu.local/llms.txt` | **404** |
| Physical `llms.txt`, `llms-full.txt`, `llms-docs` in `ABSPATH` | absent |
| `wpseo['enable_llms_txt']` | `false` |
| LLMagnet 3.4.3 | active, but publishing nothing at `/llms.txt` |
| Rewrite rules matching `llms` | none |

The field is clean. Detection is still mandatory — the conflict can appear at
any time from a plugin update or an administrator toggling Yoast's AI tools —
but the blocking path will ship without ever having fired in anger, and that
must be recorded as a gap rather than mistaken for coverage.

### What the reference implementation does about anonymity

LLMagnet is GPL and the roadmap directs studying it. Its own anonymous routes
are guarded by one small function worth adopting the shape of:

```php
public function public_permission() {
    if ( ! $this->is_enabled() ) {
        return new \WP_Error( 'rest_no_route', '…', [ 'status' => 404 ] );
    }
    if ( ! $this->check_rate_limit() ) {
        return new \WP_Error( 'rate_limited', '…', [ 'status' => 429 ] );
    }
    return true;
}
```

Two properties are worth taking, and one caveat. Worth taking: a disabled
feature answers **404, not 403**, so a switched-off surface is indistinguishable
from one that was never installed; and rate limiting is part of the permission
decision rather than bolted on downstream. The caveat: those routes are
currently disabled on the reference site, so this shape is a design reference,
not a validated one.

## Decision

### The route

`/llms.txt` is served by a **virtual endpoint**, never a file. The plugin adds a
rewrite rule and answers on `parse_request`, before the main query runs and
before any theme or template code executes. It never writes a file under
`ABSPATH`.

A physical `/llms.txt` wins routing at the web-server level and the plugin
cannot and must not remove it. Its presence is an **ownership conflict**: the
bridge reports it and refuses to claim its artifact is public.

### The response is a stored snapshot, always

The handler performs exactly one read of one non-autoloaded option and writes
that bytes-for-bytes to the client. It never queries posts, never calls an SEO
provider, never regenerates, and never writes. **Generation happens only inside
an authenticated Ability or a scheduled job.** A front-end request that finds no
stored snapshot answers 404; it does not build one.

This is the single most important rule in this ADR. It makes the public surface
a static byte-serving path whose cost is independent of site size, and it means
public traffic cannot trigger any query the plugin would otherwise bound.

### Publication is off by default

A dedicated `wpcb_llms_enabled` flag, default false, and a dedicated
`wpcb_manage_llms` capability for the write Abilities. While the flag is off,
the rewrite rule is not registered and the route answers 404 — the LLMagnet
shape above. `manage_options` is never granted to an integration user.

### Cache semantics

`Content-Type: text/plain; charset=utf-8`. A strong `ETag` derived from the
stored snapshot's own content hash — not from a timestamp, so two sites
generating identical content agree — plus `Last-Modified` from the snapshot's
recorded generation time, and a bounded `Cache-Control: public, max-age=…`.
`If-None-Match` and `If-Modified-Since` answer `304` without reading the
snapshot body.

The snapshot is public, anonymous, identical for every requester, and carries
no per-user state, so `Vary: Cookie` is deliberately **not** sent. Any future
change that makes the response depend on the requester invalidates that and
must revisit this paragraph.

### What may enter the artifact

Only content that is `publish`, not password-protected, of a configured public
post type, and not resolved as `noindex` by the active SEO provider. Exclusion
is decided **at generation time and re-checked at generation time only** —
which means a post that becomes private after generation stays in the snapshot
until regeneration.

That staleness window is a real leak vector and is handled explicitly:
transitions out of `publish` enqueue a debounced regeneration, and the roadmap's
requirement that regeneration be debounced must not be allowed to extend that
window indefinitely. The verifier asserts a de-published post leaves the
artifact.

### Regeneration

Debounced and queued through WP-Cron, never synchronous with a front-end
request, and idempotent for unchanged source and configuration. Only an
authenticated Ability or a scheduled event may enqueue it. Nothing reachable
anonymously can cause a regeneration, directly or by cache-busting.

## Consequences

**Positive.** The public surface is a single option read and a byte copy, so it
cannot be turned into an amplification vector. Off-by-default plus a 404-when-off
means an unconfigured install has no new surface at all. Content-hash ETags make
`304` correct behind shared caches.

**Negative.** The artifact is eventually consistent: a post removed from public
view remains in the snapshot until the debounced job runs. Rewrite rules must be
flushed on activation and on flag change, which is a well-known source of
breakage. Multisite is not addressed here and is out of scope for `0.6.0`.

**Rejected alternatives.** Writing a physical `llms.txt` — the approach both
LLMagnet and Yoast take — was rejected: it requires filesystem writes to the
web root, it collides irreconcilably with any other generator, and it survives
plugin deactivation. Generating on demand with a cache was rejected because the
first uncached request after any invalidation becomes a full site query
reachable by anonymous traffic. Serving through the REST API under
`/wp-json/…` was rejected because the llms.txt proposal specifies the root path,
and a redirect from the root would still need the rewrite this ADR describes.

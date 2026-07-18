# ADR 0009: Capture rendered public schema for Local multiple-location profiles

## Status

Accepted for normalization schema 1.x.

## Context

Yoast Local SEO 15.8 emits per-branch structured data
(`#local-branch-organization` with `parentOrganization`, and the branch's own
`PostalAddress`, `GeoCoordinates`, and opening hours) **only during a real
front-end singular page render**, because the Local schema pieces depend on the
global `is_singular( 'wpseo_locations' )` state.

The provider-neutral surfaces this plugin relies on do not reproduce that state:

- `YoastSEO()->meta->for_url()` / `for_post()` (our documented resolved surface,
  ADR 0002) returns only the merged `#organization` node, carrying the primary
  location's address regardless of which location URL is queried.
- The REST `yoast_head_json` for the locations custom post type returns an empty
  schema graph.

Runtime probing confirmed both gaps. As a result, `get-url-seo` and
`get-editorial-context` could not distinguish locations or expose branch
relationships in multiple-location mode. Milestone 3's exit gate requires that
compatibility claims are backed by a real runtime fixture, so this had to be
resolved rather than asserted.

Two mechanisms were evaluated and both proven feasible by spike:

1. A same-origin loopback fetch of the target's public page, parsing its
   `application/ld+json` graph.
2. Server-side simulation of the singular main query before calling
   `for_post()`.

Mechanism 2 couples the plugin to WordPress/Yoast render internals (global
`$wp_query` mutation inside an ability request, a static-cached primary-identity
helper) and is Yoast-specific. Mechanism 1 reads the actual public output, is
provider-neutral (works for any SEO plugin that prints JSON-LD), and is robust
across provider versions.

## Decision

For the `local_businesses` projection only, the SEO provider may obtain the
public JSON-LD `@graph` by performing a **bounded, same-origin loopback fetch**
of the target URL and parsing its `application/ld+json` blocks. The captured
graph is fed through the existing `LocalSchemaProjector` allowlist.

All other resolved fields (title, description, canonical, robots, Open Graph,
Twitter, and the `schema_graph`) continue to come from the documented Yoast Meta
surface. Rendered capture is used only when the `local` module is active.

The captured graph never bypasses the allowlist projector, so no arbitrary page
data enters normalized output. `parentOrganization` is added to the projector's
bounded reference allowlist (schema.org `branchOf` remains supported for
provider neutrality).

### Constraints

- **Same-origin only.** The fetched URL host must equal the site origin host.
  URL selectors are already same-origin validated; post selectors resolve to
  same-origin permalinks. This bounds the request to the site itself.
- **Authorization first.** Target authorization runs before provider access, so
  only readable, public targets are ever fetched.
- **Bounded.** Hard request timeout, redirect cap, response-size cap, node cap,
  and `application/ld+json` parsing only.
- **Cached.** Parsed graphs are cached in a short-lived transient per URL to
  avoid repeated full-page fetches.
- **TLS verified by default.** `sslverify` defaults to `true`; a
  `wpcb_seo_rendered_schema_sslverify` filter allows local self-signed
  development environments to relax it.
- **Never fatal.** On any failure the projection falls back to the Meta-surface
  graph and records a bounded warning; SEO reads never break.

## Consequences

- Multiple-location branch profiles (primary/branch identity via
  `parentOrganization`, branch address/geo/hours) become available through the
  existing abilities.
- The mechanism is provider-neutral and independent of Yoast render internals.
- A same-origin loopback request adds latency on the first read of a local
  target; the transient cache amortizes it.
- The plugin now performs an outbound HTTP request to itself. It is strictly
  same-origin and bounded, but environments that block loopback HTTP will fall
  back to the resolved surface and the documented degraded state.
- Single-location behavior is unchanged in practice: the projector consumes a
  richer graph but produces the same profile it did from the Meta surface.

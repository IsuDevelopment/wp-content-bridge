# ADR 0016: Social images use attachment IDs and advanced robots are merged

- Status: Accepted
- Date: 2026-07-24

## Context

The released `wp-content-bridge/update-seo` contract covers Yoast titles,
descriptions, canonical, primary/Premium keyphrases, index/follow, and social
text. Daily editorial work also requires Yoast Free's three advanced robots
directives and its Open Graph/Twitter image overrides.

Accepting caller-supplied image URLs would create a second, less trustworthy
media identity model, allow external URLs that are not WordPress attachments,
and bypass native attachment authorization. Replacing Yoast's complete
`meta-robots-adv` string for one requested flag could silently remove another
directive.

Yoast Free 28.1 stores the relevant post editor state as:

- `_yoast_wpseo_meta-robots-adv`, a comma-separated allowlist containing
  `noimageindex`, `noarchive`, and `nosnippet`;
- paired `opengraph-image` / `opengraph-image-id` and
  `twitter-image` / `twitter-image-id` editor values.

## Decision

Add five optional normalized fields to the existing ability:

- `robots_noarchive`, `robots_noimageindex`, `robots_nosnippet` — boolean;
- `og_image_id`, `twitter_image_id` — non-negative WordPress attachment ID.

For advanced robots, `true` adds the named directive, `false` removes only that
directive, and omission/null leaves it unchanged. The adapter reads the current
three-value allowlist, merges only explicitly requested flags, writes a stable
Yoast-compatible order, and never accepts the raw comma-separated provider
value.

For social images, a positive ID must identify an existing image attachment
that the current principal may read. The adapter resolves the URL from
WordPress and writes the paired Yoast URL and ID fields. `0` explicitly clears
the pair; omission/null leaves it unchanged. Arbitrary caller-supplied URLs,
filesystem paths, and attachment metadata remain forbidden. Every requested
image is resolved before the first SEO field is written so an invalid image
cannot partially apply a multi-field request.

The existing gates remain: `wpcb_writes_enabled`, `wpcb_manage_seo`, native
`edit_post`, configured `update_seo`, optimistic concurrency, redacted audit,
and post-scoped cache invalidation. Invalid, non-image, or unreadable attachment
IDs return the same non-enumerating `wpcb_seo_image_unavailable` error.

## Consequences

- Clients use stable WordPress media identity rather than provider URLs.
- Existing advanced directives survive partial updates.
- The public change is additive; the ability ID and existing field semantics do
  not change.
- The normalized SEO schema advances from 1.2 to 1.3 because configured robots
  and social output gain the new directive flags and attachment IDs. Existing
  keys keep their meaning.
- Yoast indexables remain derived data and are never written directly.
- Compatibility is claimed only for the tested Yoast Free 28.x editor-meta
  contract.

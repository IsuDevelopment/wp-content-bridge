# Enable Abilities for MCP comparison

Reviewed locally: 2026-07-21, plugin version 2.0.18. This is comparison
material only; WP Content Bridge does not depend on or copy runtime code from
this plugin.

The abilities-audit REST-controller workflow does not apply because this
plugin registers Abilities directly and contains no `register_rest_route()`
controllers. The review therefore follows the same permission, schema,
side-effect, and semantic-intent criteria against its registrations.

## Useful reference patterns

- Media is a separate semantic area instead of being treated as a generic CPT.
- `get-media` caps requested results at 100 and uses public WordPress attachment
  APIs.
- The media projection uses an explicit field list rather than returning the
  complete attachment object or arbitrary meta.
- Every ability can be enabled or disabled from an admin screen and declares
  REST/MCP exposure metadata.
- The upload flow uses WordPress media helpers and cleans its temporary file on
  a failed sideload.

## Patterns not adopted

- `get-media` returns a bare array. Its schema has no required fields,
  `additionalProperties: false`, pagination envelope, total semantics, or
  stable schema version.
- It cannot resolve an exact attachment by ID, original URL, or filename and
  has no deterministic detail ability.
- Listing is gated only by `upload_files`; it does not apply native
  `read_post` to every attachment before output or pagination. A write-oriented
  capability is also the wrong semantic gate for a read-only media library.
- It omits filename, caption, and description. Post/page detail returns only a
  featured-image URL, encouraging clients to guess the attachment ID.
- All abilities are enabled on first activation and several new abilities are
  auto-enabled during upgrades. WP Content Bridge keeps new exposure off by
  default.
- The remote upload ability accepts an arbitrary URL, validates image type by
  path extension, downloads server-side, and can assign the result as a
  featured image without checking `edit_post` on the parent. That design is
  vulnerable to SSRF and privilege-boundary mistakes and is not a safe upload
  reference.
- The shared activity wrapper records every completed callback as success,
  including a returned `WP_Error`; it does not capture bounded failure codes or
  mutation identity.
- A single administrator-bound bearer key is an authentication and ambient-
  authority shortcut. WP Content Bridge keeps transport external and
  principal-bound.
- The large procedural ability file mixes schemas, permissions, queries,
  writes, provider storage details, and responses. It cannot serve as a domain
  or application architecture reference.

## Adopted improvements in WP Content Bridge 0.1.3

- `get-media` returns a strict object envelope and supports exact ID, exact
  same-site URL, exact filename, and bounded text lookup.
- `get-media-by-id` provides deterministic, non-enumerating retrieval.
- Both abilities require an off-by-default media policy, `wpcb_read_media`, and
  native `read_post` for every returned attachment.
- Each item returns ID, title, filename, URL, ALT, caption, description, and
  MIME type.
- Content summaries always return `featured_image_id` and
  `featured_image_url` together or both as null.
- Upload, metadata mutation, featured-image assignment, and remote import stay
  separate future abilities with their own threat models.

# ADR 0011: media uses a dedicated read policy

Status: accepted.

## Context

Attachments are intentionally excluded from the content-type policy. Treating
them as ordinary posts would make media exposure depend on unrelated content
read switches and would silently broaden the meaning of ADR 0006. Agent clients
also need deterministic attachment identity; guessing an attachment ID from a
URL or filename is unsafe.

## Decision

Media reads form a separate vertical slice with two stable domain abilities:

- `wp-content-bridge/get-media` for bounded search;
- `wp-content-bridge/get-media-by-id` for deterministic retrieval.

Both abilities are registered only when the non-autoloaded
`wpcb_media_reads_enabled` option is enabled. Execution additionally requires
the plugin capability `wpcb_read_media` and native WordPress `read_post` access
to every returned attachment. Missing, disabled, and unreadable attachments use
the same non-enumerating unavailable result.

Search accepts at most one identity selector: exact attachment ID, exact
same-site original URL, exact filename, or bounded WordPress text query. It
applies native authorization before pagination and caps candidate inspection at
1,000 objects. Output is always an object envelope and every media item contains
only an explicit public-field allowlist.

Content summaries add the nullable pair `featured_image_id` and
`featured_image_url`. Both are populated together only when the current
principal can read the attachment; otherwise both are `null`. The existing
optional `relationships.featured_media` projection remains compatible.

Media mutation, upload, remote import, and featured-image assignment are not
part of this decision. They require separate write capabilities, concurrency,
audit, MIME/size limits, and threat review.

## Consequences

- Enabling content reads never implicitly enables the media library.
- MCP and REST projections consume the same permission-checked domain abilities.
- Clients receive stable IDs and URLs without filename/URL-to-ID guessing.
- The new capability is available in the bounded integration-principal editor;
  native WordPress permissions and the feature flag remain independent gates.
- This additive public contract ships in plugin version 0.1.3.

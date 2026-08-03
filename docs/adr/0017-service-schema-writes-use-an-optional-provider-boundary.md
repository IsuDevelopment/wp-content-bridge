# ADR 0017: Service schema writes use an optional provider boundary

- Status: Accepted
- Date: 2026-08-03

## Context

IsuDev Schema Extended is now a standalone optional plugin. It owns page-level
`Service` metadata and emits the corresponding Yoast Schema graph, including
typed `areaServed`, brands, and `hasOfferCatalog`. WP Content Bridge needs a
remote write intent for these fields without turning its MCP or Abilities layer
into an arbitrary post-meta editor or a hard dependency on one plugin path.

Registering a generic metadata endpoint would bypass the domain vocabulary,
expand the privilege surface, and make future provider changes part of the MCP
contract. Checking `is_plugin_active()` would couple runtime code to an admin
helper and a particular installation path. Registering the Ability while the
provider is absent would advertise an operation that can never succeed.

## Decision

Add the semantic Ability `wp-content-bridge/update-service-schema` behind a
provider-neutral `ServiceSchemaWriter` application port. Its input contains
only bounded Service fields: enabled state, name/type/description, typed areas,
brands, catalog name, and offers. Omission means unchanged; empty strings or
lists explicitly clear values. Raw JSON-LD and arbitrary metadata are outside
the contract.

The Schema Extended infrastructure adapter is considered available only when:

1. `ISUDEV_SCHEMA_EXTENDED_VERSION` was defined by the standalone plugin;
2. its `Meta_Fields` class is already loaded by WordPress; and
3. the required public read/sanitize methods exist.

The composition root registers the Ability only when that check and the global
writes flag both pass. Execution additionally enforces `wpcb_manage_seo`, native
`edit_post`, the per-type Update SEO policy, a current version token, and the
provider's supported-post-type list.

The adapter maps public fields to fixed provider constants, sanitizes the whole
document before the first write, snapshots affected keys, restores earlier keys
best-effort if a later write fails, and returns a sanitized post-write re-read.
The existing redacted audit and post-scoped cache event remain authoritative.
MCP is only a projection of the registered Ability and requires its own closed
profile entry.

## Consequences

- WP Content Bridge remains loadable and fully useful without Schema Extended.
- Inactive or incompatible providers leave no stale Ability in WordPress or MCP
  discovery.
- The public contract survives provider storage or plugin-path changes.
- Provider-specific compatibility is isolated to one infrastructure adapter and
  one analysis stub.
- The existing `update_seo` policy and capability are reused because this is an
  SEO structured-data mutation; no second overlapping permission vocabulary is
  introduced.
- A future Service-schema provider can implement the same port, but provider
  selection and migration would require explicit composition and compatibility
  tests rather than silent fallback.

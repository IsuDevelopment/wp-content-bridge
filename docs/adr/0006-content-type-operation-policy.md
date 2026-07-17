# ADR 0006: content access is a per-type operation policy

Status: accepted.

## Context

WordPress post types have different editorial intent, capabilities, and exposure risk. A reusable plugin cannot assume that every public custom post type should be readable or writable by an agent. Creating one ability per post type would make the public contract dynamic and would couple domain capabilities to site-specific registrations.

Administrators also need to reserve policy for write operations before those operations are implemented, without accidentally making an unavailable or unsafe write executable.

## Decision

Keep stable semantic abilities and place a shared `ContentAccessManager` in front of every content use case. Store one matrix in the `wpcb_content_type_access` WordPress option:

- row: WordPress post-type name;
- column: stable operation key matching an ability intent;
- value: boolean feature gate.

Initial operation keys are:

- `get_content`;
- `search_content`;
- `create_draft`;
- `update_content`;
- `update_seo`;
- `publish_content`.

`post` and `page` default to read/search until explicitly saved. Eligible custom post types default to deny-all. Attachments and WordPress internal `wp_*` types are not configurable in the initial UI.

Search and every mutation require `get_content`. Invalid combinations are normalized to disabled. This dependency is a configuration invariant, not authorization.

Execution requires all three gates:

1. operation enabled for the post type;
2. relevant WPCB capability;
3. native WordPress type/object capability.

The settings UI may display switches for future writes. A switch never registers an ability and never bypasses a missing implementation or authorization check.

## Consequences

- MCP and other clients receive stable ability IDs across sites.
- Site owners can opt custom types in without code changes.
- Abilities, admin, REST, and CLI must call the same policy service.
- Adding an operation requires a policy migration review, documentation, dependency tests, authorization mapping, and an ability threat review.
- Temporarily unavailable post-type settings are preserved but cannot be newly enabled while the type is unavailable.

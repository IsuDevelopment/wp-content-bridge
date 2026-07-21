# Content access policy

## Purpose

The content access policy is the first gate for every content operation. It answers only whether an administrator enabled an operation for a post type. It does not decide whether the current principal may access a particular object.

## Effective authorization

```text
configured post-type operation
              AND
plugin capability for the operation
              AND
native WordPress type/object capability
              AND
use-case validation and state rules
```

All gates are enforced at execution time. Hiding a checkbox, menu item, REST route, or MCP projection is not authorization.

## Defaults and eligibility

- Unsaved `post` and `page`: `get_content` and `search_content` enabled.
- Eligible custom post types: all operations disabled until opt-in.
- `attachment`, non-UI types, non-public/non-REST types, and `wp_*` internal types: not listed.
- Saved policies for temporarily unavailable post types are retained.

Eligibility controls panel noise, not capability. A future requirement to support an excluded type must change the catalog deliberately and add security/integration tests.

## Operation dependencies

| Operation | Required configured operation | Runtime native capability direction |
|---|---|---|
| `get_content` | none | `read_post` for an existing object |
| `search_content` | `get_content` | per-result readability; no private enumeration |
| `create_draft` | `get_content` | post type's `create_posts`; `read_post` after creation |
| `update_content` | `get_content` | `edit_post` plus concurrency/state rules |
| `update_seo` | `get_content` | `edit_post` plus provider support and WPCB SEO capability |
| `publish_content` | `get_content` | `publish_post`, feature flag, state and approval rules |

The matrix is intentionally more restrictive than WordPress role capabilities. Enabling a cell never grants a role capability.

## Storage

Option: `wpcb_content_type_access`.

The value is a bounded nested boolean map. Input is accepted only through the Settings API sanitizer. Unknown operation keys are removed. Valid saved rows for a temporarily missing post type are retained. The option is not exposed through the REST Settings API.

Settings access requires `wpcb_manage_settings`. The administrator role receives
that capability through the versioned installer.

On single-site installations, the settings page also manages one dedicated,
existing integration user. This surface assigns only the closed operational
allowlist (`wpcb_read_content`, `wpcb_edit_content`, `wpcb_manage_seo`, and
`wpcb_publish_content`); it never grants `wpcb_manage_settings`, changes a role,
or grants native WordPress capabilities. The acting administrator additionally
needs `promote_users` and `edit_user` for both the new and any replaced managed
principal. The target must not be an administrator and must already have native
`read`. Switching principals removes only the four managed WPCB capabilities
from the previous user. Multisite principal management remains unavailable
pending a dedicated ADR.

## Shared-service rule

No ability, REST controller, CLI command, or admin action may read the option directly. Consumers use `ContentAccessManager`, then apply their operation-specific authorization. This keeps defaults, dependencies, and storage normalization consistent.

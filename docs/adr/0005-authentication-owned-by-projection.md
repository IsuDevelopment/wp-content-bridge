# ADR 0005: Authentication is owned by WordPress and the projection

Status: accepted.

## Decision

Do not make authentication part of content or SEO use cases. REST/MCP clients
initially authenticate through supported WordPress, MCP Adapter, or reviewed
tunnel mechanisms. Abilities authorize the resulting WordPress principal
through plugin and object capabilities.

Future plugin-managed credentials are permitted only under ADR 0007: they are
projection credentials bound to a WordPress principal, never independent
bearer authority.

## Consequences

- Dedicated integration users and revocable credentials are supported.
- The read-content core stores no transport credentials.
- Local STDIO and remote HTTP can differ without changing domain contracts.

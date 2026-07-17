# ADR 0001: Abilities are domain contracts

Status: accepted.

## Decision

Register semantic WordPress abilities over shared application services. MCP, REST, CLI, admin UI, and future Agents API workflows are projections/consumers of those contracts.

## Consequences

- Ability IDs must not include `mcp` or client names.
- No business logic belongs in MCP or REST adapters.
- The official MCP Adapter remains an external optional dependency.
- Application services and contract tests are required before new projections.


# ADR 0004: Agents API is an optional later integration

Status: accepted for initial architecture; reassess before Milestone 8.

## Context

External clients already provide model reasoning and agent loops. Automattic Agents API provides an embedded WordPress agent runtime, sessions, memory, workflows, approvals, and tool mediation, but is a young pre-1.0 dependency with a broader runtime surface.

## Decision

Do not require Agents API for base content/SEO abilities or MCP access. Preserve an integration boundary for future scheduled/embedded editorial workflows.

## Consequences

- No double-agent/model loop in MVP.
- External clients remain provider-neutral.
- Future internal blog-planning routines can reuse the same abilities without changing their contracts.


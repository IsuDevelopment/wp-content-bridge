# ADR 0003: Ship read capabilities before writes

Status: accepted.

## Decision

Complete and verify content/SEO reads and client interoperability before implementing mutations. Draft creation, content update, SEO update, and publication are separate milestones and abilities.

## Consequences

- MVP is safe to evaluate with real content.
- Publication cannot be hidden inside create/update operations.
- Writes require concurrency, revision, audit, capability, and security acceptance criteria.


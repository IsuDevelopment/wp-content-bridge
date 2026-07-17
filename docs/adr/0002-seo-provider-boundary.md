# ADR 0002: Normalize SEO behind a provider boundary

Status: accepted.

## Decision

Expose normalized configured/resolved/analysis/Schema/provenance data through an SEO provider interface. Yoast is the first optional implementation.

## Consequences

- Core content reads work without Yoast.
- Documented resolved output is preferred over private storage.
- Private meta access, when unavoidable, is versioned and allowlisted.
- Raw option/indexables dumps are prohibited.
- Future providers can be added without changing ability IDs.


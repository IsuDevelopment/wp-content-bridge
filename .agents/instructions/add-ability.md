# Add or change an ability

1. Start from a user-meaningful use case in `docs/architecture/ABILITIES.md`.
2. Confirm the operation belongs at the domain capability layer.
3. Add or update the shared application service first.
4. Define explicit input and output JSON Schemas with bounded lists and sizes.
5. Add object-level and plugin-capability authorization.
6. Set all safety annotations explicitly.
7. Add unit tests for the service and integration/contract tests for registration, permissions, schemas, errors, and side effects.
8. Update threat model, ability catalog, implementation plan, and `.agents/status.md` when applicable.
9. Treat an ability ID or schema change as a public API change.


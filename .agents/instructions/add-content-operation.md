# Add or change a content operation

1. Read `docs/architecture/CONTENT_ACCESS.md`, `docs/architecture/ABILITIES.md`, `docs/architecture/SECURITY.md`, ADR 0006, and `docs/architecture/CODE_MAP.md`.
2. Confirm the operation is a user intent, not an HTTP verb alias or site-specific post type.
3. Add/change the `ContentOperation` case and declare configuration prerequisites.
4. Update the admin label; do not put dependency rules in the settings page.
5. Map the operation to a dedicated WPCB capability and native WordPress type/object capability.
6. Implement the use case behind an application service that calls `ContentAccessManager`.
7. For writes, update the threat model, audit contract, concurrency/state rules, and ability annotations before implementation.
8. Add unit tests for matrix normalization and integration tests proving that configuration alone cannot grant access.
9. Update ADR/spec/ability contract, code map, implementation plan, test plan, and `.agents/status.md`.
10. Run `composer check` and the relevant WordPress authorization matrix.

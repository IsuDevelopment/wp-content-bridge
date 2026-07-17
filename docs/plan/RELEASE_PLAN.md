# Release plan

## Versioning

- `0.x`: public contracts may evolve, but migrations and changelog entries are still required.
- `1.0`: read abilities and provider contract are stable; write surface may remain feature-gated.
- Ability IDs and schema semantics follow semantic versioning as public API.

## Distribution

- GitHub repository and signed/tagged releases.
- Release ZIP contains production Composer dependencies and excludes tests, local AI state, dev tooling, and secrets.
- MCP Adapter, Yoast, and Agents API are never bundled.

## Release stages

1. Local development via symlink.
2. Internal alpha on disposable/local sites.
3. Read-only beta on staging sites.
4. Read-only stable release.
5. Separate opt-in beta for draft writes.
6. Publication remains experimental until approval/security criteria pass.

## CI gates

- PHPCS.
- PHPStan max level.
- PHPUnit unit and integration suites.
- WordPress/PHP compatibility matrix.
- Plugin Check and release ZIP smoke install.
- Generated artifact inventory to ensure no secret/dev files ship.

## Compatibility policy

- Minimum WordPress 7.0 and PHP 8.2.
- Test the latest patch of supported WordPress branches.
- Publish a Yoast compatibility table based on verified versions, not broad untested claims.
- Pin external adapter versions in test environments; runtime integration remains feature-detected.

## Rollback

- Read-only releases roll back by plugin version.
- Future data migrations are versioned and backward-safe where practical.
- Writes rely on WordPress revisions and audit evidence; plugin rollback must never delete content.
- Uninstall does not delete audit/settings data by default without explicit user opt-in.


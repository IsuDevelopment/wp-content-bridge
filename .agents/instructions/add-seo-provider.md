# Add an SEO provider

1. Implement the provider contract without changing content-domain services.
2. Feature-detect the provider and version at runtime.
3. Return normalized configured/resolved/provenance data.
4. Prefer documented provider APIs. Put unavoidable private-field access behind a versioned allowlist.
5. Never expose arbitrary provider options or secrets.
6. Add absent/free/premium/add-on fixtures and graceful-degradation tests.
7. Record compatibility and limitations in `docs/architecture/SEO.md`.


# ADR 0007: Private credentials are bound to WordPress principals

Status: accepted for future implementation.

## Context

The plugin must eventually support private MCP connections for clients such as
ChatGPT, Codex, and Gemini. A convenient API key must not become an alternative
administrator identity outside WordPress authorization.

## Decision

Authentication belongs to the MCP projection. The read-content milestone does
not implement credentials. The initial supported path uses WordPress/MCP
Adapter authentication or a reviewed secure tunnel.

If plugin-managed keys or OAuth grants are added later, each credential is
bound to a WordPress user ID. Authorization is the intersection of:

1. credential scope;
2. the user's current WP Content Bridge capability;
3. the user's current native WordPress object capability;
4. the configured content-type operation policy.

Credential scope can only restrict authority. Revoking a role/capability or
user must immediately reduce or remove access without reissuing the key.

Secrets are randomly generated, shown once, hashed at rest, expirable,
revocable, rate-limited, and never written to logs. OAuth, if implemented, must
use authorization code flow with PKCE and receive a dedicated security review.

## Consequences

- There is no `user_id = 0` write principal and no legacy plaintext token.
- Dedicated integration users remain visible in WordPress audit and role tools.
- Transport choice can change without changing ability contracts.
- A first-party OAuth server is deferred because it is a security product, not
  a small UI enhancement.


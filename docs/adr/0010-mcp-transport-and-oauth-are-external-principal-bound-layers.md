# ADR 0010: MCP transport and OAuth are external, principal-bound layers

## Status

Accepted (2026-07-19).

## Context

Milestone 4 makes the plugin's five private, read-only WordPress Abilities
(`search-content`, `get-content`, `get-url-seo`, `get-editorial-context`,
`get-diagnostics`) usable from an external MCP client. The Phase 1 priority is
**ChatGPT**.

The ChatGPT app / MCP connector (Apps SDK) requires a remote **HTTPS** MCP
server with **OAuth 2.1**: protected-resource metadata at
`/.well-known/oauth-protected-resource`, authorization-server metadata
discovery, client registration (CIMD preferred, DCR, or predefined), PKCE, and
server-side token verification (signature, issuer, audience, expiry). There is
**no static API key / custom header** option for the connector.

The official `WordPress/mcp-adapter` (WP 6.9+) bridges the Abilities API to
MCP, but for **self-hosted** sites it currently offers **no OAuth** — only
Application Passwords or JWT. OAuth is available only on WordPress.com.

So the official adapter alone cannot serve ChatGPT on a self-hosted site; an
OAuth 2.1 layer is required in front of the MCP endpoint. This decision follows
ADR 0005 ("authentication owned by projection": REST/MCP clients authenticate
through supported WordPress, MCP Adapter, or reviewed tunnel mechanisms, and
Abilities authorize the resulting WordPress principal) and ADR 0007 ("private
credentials are principal-bound": any credential must bind to a WordPress user
ID, and authorization can only narrow — never grant beyond — that user's
authority).

## Decision

**Approach A** — MCP transport and OAuth are **external, configured (not
bundled) layers**. This plugin never bundles or initializes the MCP Adapter or
an OAuth authorization server; AGENTS.md already requires that the MCP Adapter
be optional and not bundled or initialized by this plugin.

Reference composition:

- The **official WordPress MCP Adapter** (`WordPress/mcp-adapter`) projects our
  registered read Abilities to an MCP endpoint.
- An **external OAuth 2.1 layer** (evaluation candidates: miniOrange "OAuth AI
  Agent Connector for WordPress", or Royal MCP) provides the authorization
  server, protected-resource, and discovery metadata ChatGPT requires. A single
  plugin providing both transport and OAuth is acceptable if it projects *our*
  Abilities and passes the evaluation gate below.

An external OAuth layer is adopted only if it passes this gate.

### Evaluation gate

No external OAuth layer is adopted until it passes all of:

1. **Principal-bound** — every token/grant is bound to a specific WordPress user;
   no ambient `user_id = 0` authority.
2. **Executes as that user** — MCP tool calls run as the bound user, so
   `ContentAccessManager` policy and native object capabilities (`read_post`,
   etc.) apply unchanged.
3. **Scope only reduces** — a grant's scope may narrow the user's authority but
   can never grant a capability the user lacks.
4. **ChatGPT-correct OAuth** — HTTPS; `/.well-known/oauth-protected-resource`
   and authorization-server metadata; client registration via CIMD/DCR/predefined;
   PKCE; server-side token verification (signature, issuer, audience, expiry).
5. **Secret hygiene** — secrets never logged or committed; tokens are revocable
   and expiring; a one-time display if any secret is shown.
6. **Read-only** — Phase 1 exposes only the five read abilities; writes stay
   globally blocked.

Failing principal-binding (criteria 1–3) rejects a candidate; fall back to a
dedicated gateway (C) or defer the plugin-owned AS (B) as its own milestone.

### Fallbacks

- **B — plugin-owned OAuth authorization server.** A plugin-owned AS
  (LLMagnet-style) would remove the third-party-candidate risk entirely, but an
  authorization server is a large security surface (client registration, token
  issuance/rotation/revocation, PKCE verification, replay and phishing
  resistance). It is deferred to its own milestone with a dedicated threat
  review, not attempted inside Phase 1's read-access scope.
- **C — dedicated external gateway.** A gateway that terminates OAuth and
  proxies to the MCP endpoint is the other fallback if no existing WordPress
  plugin passes the gate. It carries the same principal-binding requirement and
  is evaluated against the same six criteria before adoption.

## Consequences

- Third-party dependency risk (maintenance, licensing, upstream behavior
  changes) is mitigated by keeping our Abilities as the stable contract: the
  OAuth/transport layer sits in front of and is replaceable independently of
  the Abilities.
- Rejecting a candidate on principal-binding (criteria 1–3) is not optional or
  negotiable for convenience; it is the line that keeps ChatGPT's authority
  equal to, never greater than, the bound WordPress user's.
- Writes (edit/create) remain globally blocked and out of scope for this
  decision; they are Milestones 5–7, each behind its own threat model and audit
  update.
- This ADR's evaluation gate is the canonical six-criterion test that Task 2
  applies to concrete OAuth-layer candidates.

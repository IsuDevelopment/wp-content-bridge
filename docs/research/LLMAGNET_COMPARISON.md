# LLMagnet MCP comparison

Date reviewed: 2026-07-17. Source reviewed locally: LLMagnet 3.4.3. This is a
read-only architectural comparison, not a dependency, endorsement, or formal
security audit.

## Useful patterns

- One registry describes tool identifiers, schemas, annotations, permissions,
  availability, and execution.
- WordPress Abilities are registered as thin projections of that registry.
- The MCP endpoint implements stateless Streamable HTTP JSON-RPC and exposes a
  self-test in the admin UI.
- Managed token secrets are random, hashed at rest, shown once, revocable, and
  split into read and read/write scopes.
- OAuth uses authorization code flow, PKCE S256, short-lived access tokens,
  rotating refresh tokens, consent, and discovery metadata.
- Activity records include actor, tool, outcome, and duration without storing
  request content.

## Differences and risks relevant to WP Content Bridge

LLMagnet's MCP tool registry is the source of truth and Abilities are one of
its projections. WP Content Bridge uses application services and Abilities as
the transport-neutral contract; MCP remains a replaceable projection. This
avoids maintaining two public tool vocabularies.

LLMagnet write tokens can authorize write-scoped calls without a WordPress user
principal. WP Content Bridge will not use ambient token authority. If managed
keys are introduced, every key or OAuth grant must be bound to a WordPress user.
Its scope may reduce that user's authority but can never grant a capability the
user does not currently have. Object-level native capabilities are checked at
execution time.

LLMagnet largely uses `manage_options` for tool access. WP Content Bridge uses
dedicated plugin capabilities plus native object capabilities such as
`read_post`, `edit_post`, and `publish_post`.

The LLMagnet activity buffer rewrites one option on each call. That is simple
for low traffic, but it creates write amplification and contention. WP Content
Bridge will emit audit events behind a persistence port; a bounded option may
be used only for a low-volume development implementation. Production history
should use a dedicated table or external sink with retention controls.

Running an OAuth authorization server inside a content plugin adds a large
security and interoperability surface: dynamic client registration, redirect
validation, proxy trust, consent, token rotation, brute-force controls, and
specification maintenance. It is therefore a separate milestone and threat
review, not part of the read-content core.

## Adopted direction

1. Keep content/SEO policy and execution independent from MCP transport.
2. Register stable WordPress Abilities with strict schemas.
3. Initially project them through the official MCP Adapter or a reviewed
   gateway/tunnel.
4. Add connection status, discovery help, self-test, token management, and
   activity views only after their underlying services have explicit ports.
5. If plugin-managed keys are approved, hash secrets, show them once, support
   revoke/expiry/last-used/rate limits, bind them to a WordPress user, and
   require both scope and current capabilities.

## Deeper review for a ChatGPT + Codex-first rollout (2026-07-18)

Re-examined LLMagnet 3.4.3 specifically for the Milestone 4 client priority
(Codex + ChatGPT first, Claude/Gemini later).

### Transport and protocol (reusable reference)

- One WordPress REST route `POST /wp-json/llmagnet/mcp/v1`, stateless Streamable
  HTTP JSON-RPC (no SSE). `permission_callback => __return_true`; all auth is
  done inside the handler so it can return custom `401`/`429`/`WWW-Authenticate`.
- Advertises protocol versions `2024-11-05`, `2025-03-26`, `2025-06-18`;
  negotiates via the `MCP-Protocol-Version` header then `initialize` params.
- Handles `initialize`, `notifications/initialized`, `ping`, `tools/list`,
  `tools/call`, `resources/list|read`. Tool results carry both a text `content`
  block and `structuredContent` when an `outputSchema` is declared.
- A single tool registry is the source of truth, re-projected to MCP, WordPress
  Abilities, `.well-known/mcp.json`, and an MCP-Apps UI — the same "abilities as
  one projection" direction we adopted.

### The decisive ChatGPT constraint

ChatGPT custom connectors **cannot send custom HTTP headers**, so Bearer/managed
tokens are unusable from ChatGPT. LLMagnet therefore ships a full OAuth 2.1
authorization server as the ChatGPT path:

- Dynamic Client Registration (RFC 7591, public clients,
  `token_endpoint_auth_methods: none`), authorization-code + **mandatory PKCE
  S256**, single-use codes (hashed, 600 s TTL), access tokens 3600 s, refresh
  tokens 30 days with rotation.
- Discovery via `WWW-Authenticate: Bearer resource_metadata=…` plus
  `/.well-known/oauth-protected-resource` (RFC 9728) and
  `/.well-known/oauth-authorization-server` (RFC 8414).
- Tunnel/proxy-aware issuer URL rewriting (configurable `oauth_base_url`,
  forwarded-header aware, char-allowlisted) — needed for local dev exposure.
- Consent screen requires a logged-in admin and special-cases the ChatGPT/OpenAI
  client name for branding.

Implication for our plan: a ChatGPT-first target forces the Milestone 4
authentication decision immediately, because the header/token path that Codex
(local/STDIO or HTTP-with-headers), Claude Desktop, and Gemini can use does not
work for ChatGPT. The `official MCP Adapter/gateway vs plugin-owned OAuth`
decision must resolve whether the OAuth + discovery surface is provided by an
external adapter/gateway or owned in-plugin. Owning an OAuth AS is a large
security surface (kept as its own milestone and threat review per above).

### Confirmed anti-patterns to keep avoiding

- Managed/legacy/unmapped OAuth tokens run with `user_id = 0` (ambient authority,
  no WP user). A legacy CLI token is stored and compared in plaintext with full
  write scope. We keep every credential principal-bound (ADR 0007).
- Coarse `manage_options` gating for all WP-user auth and all write tools, and
  OAuth consent that only admins can grant (every connection becomes admin-level).
  We use dedicated plugin capabilities plus native object capabilities.
- JSON-RPC errors returned on HTTP 200, no positive per-token rate limiting, and
  `resources/templates/list` returning empty while templates exist.

### Directly reusable as reference (not as a dependency)

- The stateless single-route JSON-RPC handler shape with in-handler multi-mode
  auth and standards-compliant discovery (`WWW-Authenticate` + RFC 9728/8414).
- PKCE-S256-mandatory, exact-match https-or-loopback redirect allowlist,
  show-once SHA-256 secrets, `hash_equals`, per-IP brute-force gate, refresh
  rotation, and a loopback self-test through the real handler.


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


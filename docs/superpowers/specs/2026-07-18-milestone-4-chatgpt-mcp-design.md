# Milestone 4 Phase 1 — ChatGPT-first MCP read access — design

Date: 2026-07-18
Status: approved for implementation planning
Milestone: 4 (MCP client interoperability), Phase 1

## Problem

WP Content Bridge exposes five private, read-only WordPress Abilities
(`search-content`, `get-content`, `get-url-seo`, `get-editorial-context`,
`get-diagnostics`). Milestone 4 makes them usable from an external MCP client.
The user's Phase 1 priority is **ChatGPT** specifically: they already run ChatGPT
with other MCP connectors (e.g. Google Search Console) and want WordPress content
reads as another building block for content management. Codex and Claude are a
secondary/optional path; Gemini later.

Research findings that constrain the design:

- The ChatGPT app / MCP connector (Apps SDK) requires a remote **HTTPS** MCP
  server with **OAuth 2.1**: protected-resource metadata at
  `/.well-known/oauth-protected-resource`, authorization-server metadata, client
  registration (CIMD preferred, DCR, or predefined), PKCE, and server-side token
  verification. There is **no static API key / custom header** option for the
  connector.
- The official `WordPress/mcp-adapter` (WP 6.9+) bridges the Abilities API to
  MCP, but for **self-hosted** sites it currently offers **no OAuth** — only
  Application Passwords or JWT. OAuth is available only on WordPress.com.

So the official adapter alone cannot serve ChatGPT on a self-hosted site; an
OAuth 2.1 layer is required in front of the MCP endpoint.

## Goal

Let ChatGPT securely read the five abilities from the (staged) WordPress site
through MCP + OAuth, with every call executing as a bound, least-privilege
WordPress user, and no weakening of the existing authorization model. Produce
setup documentation, a self-test, and an ADR recording the decision.

## Decision (Approach A)

MCP transport and OAuth are provided by **external, configured (not bundled)
layers**, not owned by this plugin. This follows AGENTS.md ("MCP Adapter is
optional and must not be bundled or initialized by this plugin") and ADR 0005
("authentication owned by projection"). Building a plugin-owned OAuth
authorization server (Approach B, LLMagnet-style) is a large security surface and
remains a documented fallback / separate milestone; an external gateway
(Approach C) is the other fallback.

Reference composition:

- **Official WordPress MCP Adapter** (`WordPress/mcp-adapter`) projects our
  registered read Abilities to an MCP endpoint.
- **An external OAuth 2.1 layer** (evaluation candidates: miniOrange "OAuth AI
  Agent Connector for WordPress", or Royal MCP) provides the authorization
  server, protected-resource, and discovery ChatGPT requires. A single plugin
  providing both transport and OAuth is acceptable if it projects *our* Abilities
  and passes the evaluation gate below.

## Evaluation gate (the core Phase 1 deliverable, recorded in ADR 0010)

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

## Least-privilege principal

A dedicated WordPress user (a "bridge reader" with only `wpcb_read_content` plus
read capabilities) is the identity the ChatGPT OAuth grant maps to, so the
connector holds minimal authority (ADR 0007). No administrator grant.

## Phase 1 flow

1. Expose the local Kormas site over public HTTPS via a tunnel (cloudflared),
   handling forwarded-host/proto so OAuth discovery URLs are correct.
2. Install and configure the official MCP Adapter to expose the five read
   abilities with their existing strict schemas and safety annotations.
3. Install and configure the chosen OAuth layer; confirm its discovery documents.
4. Connect ChatGPT (developer mode) via OAuth; approve consent as the bridge
   reader user.
5. Run the read scenario in ChatGPT: search-content, get-content, get-url-seo,
   get-editorial-context, get-diagnostics.
6. Verify only authorized content is returned, per-user capabilities are
   enforced, no private data leaks, and there is no ambient authority.
7. Repeat and consolidate on the staging environment with a real certificate
   before any support claim.

## Verification

- A client-agnostic MCP smoke check (discovery `tools/list` → schema retrieval →
  `tools/call` for each read ability) runnable via the MCP Inspector or a script.
- A documented manual ChatGPT walkthrough captured from the actual working setup,
  including the exact discovery URLs and consent screen.
- Secondary: Codex running the same read scenario (STDIO/local or HTTP), after
  ChatGPT works. Gemini deferred.

## Deliverables

- ADR 0010: MCP transport and OAuth are external, principal-bound layers; records
  Approach A, the evaluation gate, and B/C fallbacks.
- Setup guide: tunnel + MCP Adapter + OAuth layer + ChatGPT connector.
- Connection self-test and troubleshooting notes.
- `docs/plan/IMPLEMENTATION_PLAN.md` Milestone 4 updated to ChatGPT-primary,
  read-only Phase 1, Approach A.

## Out of scope for Phase 1 (YAGNI)

Plugin-owned OAuth authorization server, write abilities (edit/create — deferred
to Milestones 5–7, which the user wants once connections are stable), Agents API,
multi-site relay, and any managed-key management UI.

## Risks

- The chosen OAuth layer may not bind tokens to a WordPress user or may execute
  with ambient authority; the evaluation gate rejects such candidates before
  adoption.
- Tunnel/forwarded-host misconfiguration can produce wrong OAuth discovery URLs;
  the setup guide addresses proxy-aware base URLs.
- Third-party plugin dependency (maintenance, licensing); mitigated by keeping
  our Abilities as the stable contract so the OAuth/transport layer is
  replaceable.
- ChatGPT connector behavior evolves; support is claimed only from a verified
  working setup, per the milestone exit gate.

## Alignment with later milestones

Writes (edit/create) remain blocked and are Milestones 5–7, each behind its own
threat model and audit updates. A stable Phase 1 read connection is the
prerequisite the user named before pursuing writes.

# OAuth candidate evaluation (ADR 0010 gate)

Date: 2026-07-19. Purpose: apply the six-criterion evaluation gate defined in
[ADR 0010](../adr/0010-mcp-transport-and-oauth-are-external-principal-bound-layers.md)
to the two named candidates so Milestone 4 Phase 1 can pick the external
OAuth 2.1 layer that fronts the official `WordPress/mcp-adapter` for ChatGPT.
This is a **desk evaluation from vendor documentation** (WebFetch/WebSearch on
official plugin pages, blog posts, and public GitHub READMEs) — not an
endorsement, not a security audit, and not a substitute for the live checks
Task 6 must run against an installed instance. No license keys, secrets, or
credentials were used or are reproduced below.

Candidates evaluated:
- **miniOrange "OAuth AI Agent Connector for WordPress"** (also marketed as
  the "LLM OAuth Connector" paired with the official MCP Adapter plugin).
- **Royal MCP** (`royalplugins/royal-mcp`).

Baseline reference only (already reviewed, not a candidate): LLMagnet's
plugin-owned authorization server, see
[`LLMAGNET_COMPARISON.md`](./LLMAGNET_COMPARISON.md) — notably the finding
that ChatGPT custom connectors **cannot send custom HTTP headers**, so any
Bearer/API-key/managed-token scheme is unusable from ChatGPT; only OAuth 2.1
with discovery metadata works.

## A naming caveat that affects confidence in this evaluation

miniOrange ships several similarly-named, architecturally different products:
a standalone **"miniOrange AI Agent"** plugin with its own bundled tool
catalog and execution log (closer in shape to Royal MCP), and a separate
**OAuth-only connector** documented as adding OAuth 2.1 in front of the
official WordPress MCP Adapter ("miniOrange is enhancing the MCP adapter with
OAuth support"; a setup guide is titled "Connect ChatGPT to WordPress Using
MCP Adapter and LLM OAuth Connector"). The brief's candidate — "OAuth AI Agent
Connector for WordPress" — maps to the latter, OAuth-only product, which is
the architecture ADR 0010 actually wants (an external layer that authenticates
in front of the Abilities the plugin already registers, adding no tool
surface of its own). Task 6 must confirm at install time that this is the
product being installed, not the bundled-catalog "AI Agent" plugin.

## Evaluation matrix

| # | Criterion | miniOrange OAuth AI Agent Connector | Royal MCP |
|---|---|---|---|
| 1 | Principal-bound | UNKNOWN — vendor copy says the connector authorizes after the admin "log[s] in ... and authorize[s] LLM access," implying the grant is tied to the authenticating WP user, but no technical spec (token claims/storage) confirms binding. | PASS (OAuth path only) — GitHub README states OAuth sessions are bound to "the OAuth `client_id` + `user_id`." FAIL (API-key path) — README states "API-key authenticated requests now run as administrator," i.e. ambient admin authority with no WP-user binding; this mode is irrelevant to ChatGPT (which cannot send the required header) but is a documented anti-pattern in the same plugin. |
| 2 | Executes as that user | UNKNOWN — marketing text states the connection "operates using the same permissions as the logged-in WordPress user," but this is not corroborated by a technical description of how `wp_set_current_user()` context is set for each MCP call through the official adapter. | PASS (OAuth path, documented) — README: "Every tool checks WordPress capabilities" tied to the authenticated principal (`edit_posts`, `edit_post`, `manage_options` per tool). Moot for our use case: see criterion 6 — Royal MCP does not execute *our* Abilities at all. |
| 3 | Scope only reduces | UNKNOWN — no scope/consent model documented for this specific connector beyond a generic "approved permissions" claim. | PASS (OAuth path, documented) — capability checks are described as enforcement/narrowing of existing role capabilities, not elevation ("not capability elevation; it's enforcement of existing role boundaries"). FAIL (API-key path) — admin-equivalent execution grants authority beyond whatever principal issued the key (there is no bound principal), the exact anti-pattern ADR 0007/LLMagnet review flags. Not the path ChatGPT would use. |
| 4 | ChatGPT-correct OAuth | UNKNOWN — a related miniOrange product page claims "OAuth 2.1" generically; PKCE, DCR/CIMD, and the two specific `/.well-known/...` discovery endpoints are not documented for this connector in any source found. | PASS (strong, documented) — README/support docs confirm RFC 7591 Dynamic Client Registration, RFC 8414 authorization-server metadata, RFC 9728 protected-resource metadata at `/.well-known/oauth-authorization-server` and `/.well-known/oauth-protected-resource`, mandatory PKCE S256, no implicit/`client_credentials` grants, and a published "Connecting Royal MCP to ChatGPT" guide showing successful ChatGPT auto-discovery with no manual client ID/secret entry. |
| 5 | Secret hygiene | UNKNOWN — no token storage, hashing, or one-time-display details found in any source. | PASS (documented) — access tokens stored as SHA-256 hashes, single-use auth codes with 10-minute expiry, API keys are 128-bit-entropy hex shown once, a "Reset OAuth State" admin action revokes all clients/tokens/codes, and activity logs explicitly never record argument values (secrets/tokens/emails redacted as `[REDACTED]`). |
| 6 | Read-only | PASS (by architecture, pending Task 6 confirmation of product identity) — this connector is documented as adding OAuth only in front of the official MCP Adapter; it is not described as shipping any tools of its own, so it adds no write surface beyond whatever Abilities we register (our five read-only Abilities). | FAIL (practical) — Royal MCP is a **fixed, self-contained tool catalog** ("a complete, production-ready MCP server that predates the official adapter") of 67 core tools plus up to 60 integration tools, independent of the WordPress Abilities API; no `wp_register_ability`/Abilities-API integration is documented. It does not project *our* Abilities at all — ADR 0010's composition clause requires the layer to "project our Abilities" if it also owns transport. Only two write surfaces (`wp_update_option`, theme mods) are documented as globally toggle-gated (off by default); core content/media/comment create-update-delete tools and WooCommerce/Elementor write tools are not documented as globally disableable, so adopting Royal MCP would add write-capable tools running alongside — not in place of — our read-only Abilities. |

## Decisive rule (restated from ADR 0010)

Any **FAIL on criteria 1–3** rejects a candidate outright; the response is to
fall back to a dedicated gateway (**C**) or defer the plugin-owned
authorization server (**B**) as its own milestone. Criteria 4–6 inform the
choice between otherwise-eligible candidates but are not independently
disqualifying under this rule.

Applying the rule: neither candidate has a confirmed FAIL on 1–3 for the
authentication path ChatGPT will actually use (OAuth, not API keys/headers).
Royal MCP's documented API-key admin-elevation behavior is a FAIL on 1 and 3,
but it applies to a mode ChatGPT cannot use per the LLMagnet baseline finding
(no custom headers) — so it does not, by itself, invoke the decisive rule for
the ChatGPT integration. Royal MCP is excluded instead on architecture fit and
criterion 6, discussed in the Verdict below.

## Verdict

**Chosen layer: miniOrange "OAuth AI Agent Connector for WordPress"**, to be
trialed live in Task 6, pending confirmation of the criteria below. No
criterion is a confirmed FAIL for this candidate from docs alone, and its
documented architecture — pure OAuth in front of the official MCP Adapter,
adding no tool surface of its own — is exactly the composition ADR 0010
specifies, which keeps criterion 6 (read-only) satisfied by construction as
long as we register only the five read Abilities.

**Royal MCP is not chosen.** It is not rejected by the decisive rule (its
OAuth path does not fail criteria 1–3), but it fails the fit for this
milestone on two grounds: (a) it does not integrate with the WordPress
Abilities API and cannot project *our* five Abilities — it brings its own
fixed, largely write-capable tool catalog instead, which conflicts with
criterion 6's requirement that Phase 1 expose only the five read abilities
with writes globally blocked; and (b) its otherwise-strong OAuth
implementation is undermined by a documented admin-equivalent API-key
fallback mode elsewhere in the same plugin, a pattern this project has
already committed to avoiding (ADR 0007, LLMagnet review). If a future
milestone wants Royal MCP's OAuth/discovery layer specifically, it would
still need to be re-evaluated as a dedicated gateway (**C**) fronting the
official MCP Adapter rather than adopted for its bundled tools.

No fallback to gateway (C) or deferral of the plugin-owned AS (B) is needed
at this time; both are on standby if Task 6's live checks fail criteria 1–3
for miniOrange.

### Criteria Task 6 must confirm live (UNKNOWN from docs alone)

For **miniOrange OAuth AI Agent Connector**:
1. **Principal-bound** — verify the issued token/grant is bound to the
   specific admin/user account that authorized it (inspect token contents or
   plugin DB rows), not an ambient or shared identity.
2. **Executes as that user** — verify an MCP tool call runs under that user's
   WordPress capabilities (e.g., a WP user without `edit_others_posts` cannot
   retrieve another user's private content through `get-content`/
   `search-content`), confirming `ContentAccessManager` policy and native
   object capabilities apply unchanged through the official adapter.
3. **Scope only reduces** — verify the connector's consent/scope UI cannot be
   configured to grant a capability the authorizing user does not already
   hold.
4. **ChatGPT-correct OAuth** — verify `/.well-known/oauth-protected-resource`
   and authorization-server metadata are actually served, confirm the client
   registration method (CIMD/DCR/predefined) actually used, confirm PKCE is
   enforced, and confirm server-side token verification (signature, issuer,
   audience, expiry) rejects tampered/expired tokens.
5. **Secret hygiene** — verify secrets are not logged or committed, confirm
   tokens are revocable/expiring, and confirm any displayed secret is
   shown once only.
6. **Product identity** — confirm the installed plugin is the OAuth-only
   connector described above and not the separate "miniOrange AI Agent"
   plugin with its own bundled tool catalog, since that product would need
   re-evaluation against this same gate (particularly criterion 6).

# ADR 0025: The plugin projects its own abilities, discovered by category

## Status

Accepted (2026-08-11). Narrows ADR 0010; supersedes no decision in full.

## Context

Until 0.8.0 the plugin registered abilities and stopped there. Turning them into
MCP tools required site-owned code: an MU-plugin holding an `ABILITY_PROFILE`
constant — a hand-written list of ability names — intersected per request with
`wp_has_ability()` and handed to `McpAdapter::create_server()`.

Two consequences, both observed on a live install running 0.7.1:

1. **Silent drift.** The profile is a copy of knowledge the plugin already owns.
   A release that adds an ability does not update the copy, so the ability is
   registered, permitted, and invisible. On the observed install 11 of 31
   abilities reached the client and nothing anywhere reported a problem: the
   symptom is identical to a disabled feature area. The same list existed in
   four places (the MU-plugin constant, two copies in
   `docs/setup/MCP_ADAPTER.md`, and a `wp eval` snippet that was already stale).
2. **No usable install path.** A third party installing this plugin gets 31
   registered abilities and an instruction to write PHP. A plugin whose entire
   product is agent tooling cannot require hand-written code to expose any of
   it.

ADR 0010 said transport and OAuth are external, configured layers, and included
"this plugin never bundles or initializes the MCP Adapter". The reasoning behind
that ADR was entirely about **authentication and transport** — ChatGPT's OAuth
requirements, principal binding, and not shipping an authorization server. The
"never creates a server" clause was collateral, not the argument, and it is what
forced the copied list.

## Decision

The plugin projects its own abilities. `Adapter\Mcp\McpServerProvider` answers
`mcp_adapter_init` and calls `create_server()` for server `wpcb-bridge` at
`/wp-json/wpcb-mcp/mcp` — the identifiers the retired MU-plugin used, so no
endpoint URL moves.

- **Discovery replaces enumeration.** The tool set is every ability registered
  under `AbilityCategory::SLUG` in the current request. There is no name list in
  the plugin, the documentation, or the site. Registration is already the gate:
  a feature area whose flag is off is not registered, so it cannot be projected.
- **The category is one constant.** `AbilityCategory::SLUG` replaced the
  per-class literals, because discovery keyed on a slug makes a drifted literal
  a silent dropout.
- **The Adapter stays optional and unbundled.** It is not a Composer dependency
  and is never installed or initialized here. `mcp_adapter_init` fires only on
  installs that added the Adapter themselves; without it this class is inert.
- **On by default.** `wpcb_mcp_server_enabled` gates projection, and an absent
  row means enabled. A site that installed the Adapter did so to reach these
  abilities; requiring a second opt-in reproduces the defect above.
- **The filter may only narrow.** `wp_content_bridge_mcp_abilities` can remove
  abilities. Names outside the discovered set are dropped, so no other plugin's
  tools can enter this server, which is the one property the "closed profile"
  actually bought.
- **Projection is reported.** `get-diagnostics` gains `mcp_projection`
  (`enabled`, `endpoint`, `projected_abilities`), read from the same discovery
  the projection uses. "Missing tool" and "unregistered ability" are now
  distinguishable from a single read.

### What ADR 0010 still governs

Unchanged and not reopened here: transport and OAuth are external, principal-
bound layers; the plugin ships no authorization server; the six-criterion
evaluation gate applies to any OAuth layer; authentication is owned by the
projection (ADR 0005) and credentials are principal-bound (ADR 0007).

## Consequences

- The site-owned MU-plugin `isudev/wp-content-bridge-mcp-server` is retired.
  While it is still installed it wins: it registers at the default priority,
  this provider hooks at 20 and declines when `wpcb-bridge` already exists.
  Operators should delete it, then reconnect the client.
- **Projection is not authorization**, and this decision widens projection only.
  Execution still requires the WPCB capability, the native object capability,
  per-type policy, schema validation, and the write safeguards. A read-only
  integration principal is read-only because of its capabilities, not because a
  list omitted the write tools — and any posture that relied on a curated
  projection list to withhold destructive intents was already fictional on the
  miniOrange path, which never read `ABILITY_PROFILE` and discovers abilities
  from the registry itself.
- The miniOrange OAuth path (`/wp-json/mosmcp/v1/mcp`) is unaffected: it already
  auto-discovered. Its per-principal NHI grant remains the gate there, and its
  unset-allowlist fail-open behavior remains a documented hazard.
- `create_server()`'s positional signature is now a real coupling to Adapter
  v0.5.0. `method_exists()` keeps an older Adapter from fataling; an upstream
  signature change is meant to surface rather than be papered over.
- `tests/Integration/abilities-runtime-verification.php` asserts projection
  parity: every registered ability of this category is projected. The next
  ability cannot silently miss the endpoint.
- `CLOSED_PROFILE` in that verifier stays. It is no longer a projection list —
  it is a review gate that fails the run when an unreviewed public surface
  appears.

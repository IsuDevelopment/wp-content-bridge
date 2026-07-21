# ChatGPT connector setup — OAuth-fronted MCP access to the five read abilities

This is the Milestone 4 Phase 1 ChatGPT connector recipe: the external OAuth
2.1 layer that fronts the plugin's five read-only abilities so ChatGPT's
"Connectors" (Apps SDK) can reach them. It documents the actual live setup
from Task 6, including both fixes it forced and the troubleshooting the live
run needed.

> **This is a different endpoint than `docs/setup/MCP_ADAPTER.md`.** That
> document covers the official `WordPress/mcp-adapter` App-Password endpoint
> (`/wp-json/wpcb-mcp/mcp`) used by the client-agnostic smoke suite. This
> document covers the **OAuth-fronted** endpoint
> (`/wp-json/mosmcp/v1/mcp`) that ChatGPT actually connects to. Both project
> the plugin's same five read abilities; keep the two distinct — do not merge
> their setup steps or credentials.

## Site infrastructure — not part of the plugin package

Per ADR 0010 (Approach A), this plugin never bundles or initializes an MCP
transport or an OAuth authorization server. Everything below — the miniOrange
plugin, the tunnel, and the proxy-base shim — is **site-level infrastructure**
configured next to the plugin, not shipped inside it. Only this document
lives in the repo.

## miniOrange dependency — recommendation, not a requirement

WP Content Bridge is designed to be fronted by an external OAuth 2.1 MCP
layer; the plugin itself has no opinion on which one. The reference/tested
layer for this milestone is **miniOrange Secure MCP Connector**
(`miniorange-secure-mcp-server`). It is a **recommended companion, documented
here for setup convenience — not a bundled or hard-required dependency.** The
plugin does not declare it via `Requires Plugins`, and any OAuth layer that
passes the ADR 0010 six-criterion gate (`docs/adr/0010-mcp-transport-and-oauth-are-external-principal-bound-layers.md`,
`docs/research/OAUTH_CANDIDATES.md`) is an acceptable substitute.

## Resolved endpoint (Task 6 used exactly this)

| Property | Value |
| --- | --- |
| Endpoint URL | `https://<tunnel-host>/wp-json/mosmcp/v1/mcp` |
| REST namespace | `mosmcp/v1` |
| Route | `mcp` |
| Auth | OAuth 2.1 — RFC 8414/9728 discovery, RFC 7591 Dynamic Client Registration, PKCE S256 |
| Plugin | `miniorange-secure-mcp-server` v1.3.0 |

Deliberately **not** used: `miniorange-ai-agent` — that is an MCP *client*
plugin bundling its own write-capable tool catalog, the wrong direction for a
read-only, principal-bound bridge.

## Least-privilege principal — the shipped shape

The shipped configuration grants miniOrange's ability policy **only** the
five `wp-content-bridge/*` abilities, bound to the least-privilege
`wpcb-bridge-reader` user (`tests/Integration/bridge-reader-fixture.php`;
capabilities `read` + `wpcb_read_content` only, no role):

- `wp-content-bridge/search-content`
- `wp-content-bridge/get-content`
- `wp-content-bridge/get-url-seo`
- `wp-content-bridge/get-editorial-context`
- `wp-content-bridge/get-diagnostics`

On a single-site installation running WP Content Bridge 0.1.2 or newer, an
administrator can assign the WPCB capability through **Settings → WP Content
Bridge → Integration user access**. Select an existing dedicated user that
already has native WordPress `read` (a Subscriber role is sufficient for the
read-only surface), then enable **Read content, SEO, editorial context, and
diagnostics**. The plugin does not create the user, change its role, or change
miniOrange's separate ability grant.

> **Live consent caveat.** Task 6's live ChatGPT walkthrough was performed as
> the WordPress administrator `dev` with the full ability catalog available,
> for exploration. That is **not** the shipped shape. Before treating a
> deployment as production-ready, repeat consent as `wpcb-bridge-reader` with
> miniOrange's ability policy locked to only the five abilities above, and
> confirm no `mosmcp/*` write grants exist:

```bash
wp eval 'global $wpdb; echo $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}mosmcp_nhi_grants WHERE ability_id LIKE \"mosmcp/%-post\"" );'
```

Expected: `0`. Writes stay globally blocked in Phase 1 regardless of any
ability policy — this is a defense-in-depth check, not the only control.

## Install steps

1. Install and activate **miniOrange Secure MCP Connector**
   (`miniorange-secure-mcp-server`) on the site (not the plugin repo), the
   same way as any other WordPress plugin (`wp plugin install
   miniorange-secure-mcp-server --activate` or via the plugin directory).
2. In its settings, lock the ability/tool policy to exactly the five
   `wp-content-bridge/*` abilities listed above. Do not enable its bundled
   `mosmcp/*` write abilities.
3. Confirm the MCP endpoint resolves: `https://<your-site>/wp-json/mosmcp/v1/mcp`.
4. For local development only, continue with the tunnel and proxy-base shim
   below so ChatGPT (a remote client) can reach a `Local by Flywheel` site.
   On staging/production with a real public host and TLS certificate, skip
   both — they exist only to work around a local-only, self-signed dev
   environment.

## Tunnel (cloudflared quick tunnel) — local development only

Local sites are not publicly reachable, and ChatGPT's connector requires a
real HTTPS URL for OAuth discovery. The live setup used a cloudflared quick
tunnel:

```bash
cloudflared tunnel --no-tls-verify --http-host-header kormas-isu.local --url https://kormas-isu.local
```

Both flags are required, not optional:

- `--no-tls-verify` — the local site uses Local by Flywheel's self-signed
  certificate; without this flag cloudflared refuses to connect to the
  origin.
- `--http-host-header kormas-isu.local` — without an explicit `Host` header
  matching the site's configured domain, Local's dev proxy returns "does not
  have an associated route" for the tunnel's traffic.

**Gotcha: the quick-tunnel hostname changes on every restart.** This
invalidates any previously registered OAuth client, because RFC 7591 Dynamic
Client Registration and the discovery documents were issued against the old
hostname. After every tunnel restart:

1. Clear miniOrange's OAuth tables (`wp_mosmcp_oauth_clients`,
   `wp_mosmcp_oauth_codes`, `wp_mosmcp_oauth_tokens`) so no stale registration
   or token survives.
2. Update the proxy-base shim's option (below) to the new tunnel URL.
3. Delete and re-add the connector in ChatGPT so discovery re-runs against
   the current host.

A staging/production deployment with a stable hostname and a real TLS
certificate does not have this problem and should replace the tunnel
entirely — see `.continue-here.md` for that as the tracked next step.

## Proxy-base shim (dev-only)

File: `content/mu-plugins/wpcb-public-base.php` (site infra, not committed to
this repo). It overrides `option_home` and `option_siteurl` to the tunnel host
(`option_siteurl` also appends `/wp`, since this Bedrock-style install keeps
WordPress core under `/wp`), so that OAuth/MCP discovery metadata
(`/.well-known/oauth-protected-resource`, authorization-server metadata,
`rest_url()`-derived endpoints) advertises the publicly reachable tunnel URL
instead of the local-only origin.

It is active **only** while the option `wpcb_public_base_url` is non-empty:

```bash
# Point discovery at the current tunnel host after every restart
wp option update wpcb_public_base_url 'https://<tunnel-host>'

# Turn the shim off (restores normal local behavior)
wp option delete wpcb_public_base_url
```

To fully restore normal local behavior, delete both the option and the
mu-plugin file. This shim exists purely to make local development testable
with a remote client; it has no reason to exist on staging/production, where
the site's own public host and certificate already satisfy discovery.

## ChatGPT connector add flow

1. ChatGPT → **Settings → Connectors (Plugins) → Browse → "+" (Add)**.
2. Choose **Server URL** mode.
3. Paste `https://<tunnel-host>/wp-json/mosmcp/v1/mcp` (or the staging host's
   equivalent URL).
4. Complete the OAuth sign-in and consent screen as the intended principal
   (`wpcb-bridge-reader` for the shipped shape; see the live-consent caveat
   above).
5. Once connected, ask ChatGPT to use the connector to search, read, or
   inspect editorial/SEO context — this exercises `search-content`,
   `get-content`, `get-url-seo`, `get-editorial-context`, and
   `get-diagnostics` through the five projected tools.

## Self-test

There is no separate automated self-test for the OAuth-fronted endpoint (the
automated, repeatable coverage is the client-agnostic smoke suite against the
App-Password endpoint — see `docs/setup/MCP_ADAPTER.md` and
`docs/plan/TEST_PLAN.md`). The self-test for this endpoint is the manual
walkthrough above: confirm discovery succeeds, sign-in completes, and each of
the five abilities returns a real, bounded, non-leaking result when invoked
from ChatGPT.

## Troubleshooting

**`502 http://127.0.0.1:4750`**
This is ChatGPT **desktop**'s local gateway process, not the WordPress site.
Fixes, in order of preference: use **Server URL mode** (not an app-directory
listing) when adding the connector; restart the ChatGPT desktop app; or use
the ChatGPT web client instead of desktop.

**Sign-in lands on a dead/old tunnel host**
This is cached OAuth state from a previous cloudflared tunnel session (the
quick-tunnel hostname changes every restart). Clear
`wp_mosmcp_oauth_clients`, `wp_mosmcp_oauth_codes`, and `wp_mosmcp_oauth_tokens`,
then delete and re-add the connector in ChatGPT so discovery re-runs against
the current host. Also re-check `wpcb_public_base_url` is set to the current
tunnel URL (see the shim section above).

**`rest_no_route`**
The REST route for the OAuth/MCP endpoint did not resolve. Check: miniOrange
Secure MCP Connector is active; the site's permalink structure is not
"Plain" (REST routes need pretty permalinks); the proxy-base shim's option
(`wpcb_public_base_url`) matches the URL ChatGPT is actually calling, since a
mismatch between the advertised discovery host and the requested host can
surface as a routing failure; and that the tunnel is actually forwarding to
the site (`--http-host-header` set correctly, see the tunnel section above).

**SEO/diagnostics oddities that are NOT plugin bugs**
The live audit also found `yoast-seo/*` abilities returning empty scores and
an odd `ai/get-post-terms` shape. Those are miniOrange's own bundled
abilities, not the plugin's five — do not confuse them with a
`wp-content-bridge/*` defect. Any mixed tunnel/`.local` URLs seen in output
are an artifact of the dev tunnel environment, not a plugin issue.

## Real defects this setup found and fixed (for context)

The live ChatGPT audit through this connector found two real defects, both
fixed on this branch:

- **`get-url-seo` leaked the server filesystem path** via
  `resolved.open_graph.images[].path` (Yoast's Open Graph image data passed
  through unfiltered). Fixed in
  `src/Infrastructure/Yoast/YoastSeoProvider.php` by allowlisting Open Graph
  image keys (`url`, `width`, `height`, `type`, `alt`) and correctly handling
  Yoast's URL-keyed map shape for multiple images. Verified live: images now
  serialize as `[{url,width,height,type,alt}]` with no `/Users/...` paths.
- **`get-diagnostics` reported `mcp_adapter: false` incorrectly** — detection
  checked for symbols the installed adapter version does not expose. Fixed in
  `src/Adapter/Abilities/ContentAbilities.php` to detect the
  `WP\MCP\Core\McpAdapter` class / `mcp_adapter_init` hook instead. Verified
  live: `mcp_adapter: true`.

## Verification status of the ADR 0010 gate through this connector

| Criterion | Status |
| --- | --- |
| 1. Principal-bound | Confirmed live — token bound to a WordPress user. |
| 2. Executes as that user | Observed consistent with admin-consent exploration; strict re-verification as `wpcb-bridge-reader` is the tracked staging follow-up. |
| 3. Scope only reduces | Same as above — verified by design, strict least-privilege re-consent still to repeat on staging. |
| 4. ChatGPT-correct OAuth | Confirmed — discovery, DCR, PKCE S256, sign-in all completed. |
| 5. Secret hygiene | No secrets committed or logged; the smoke suite's disposable Application Password was deleted after use. |
| 6. Read-only | Confirmed — only the five reads are the shipped surface; `mosmcp/*` write grants were deleted from the site. |

## Files

| Location | File | Committed to repo? |
| --- | --- | --- |
| Site (WP root) | `content/plugins/miniorange-secure-mcp-server/` (miniOrange plugin) | No — site infra |
| Site (WP root) | `content/mu-plugins/wpcb-public-base.php` (dev-only proxy-base shim) | No — site infra |
| Repo | `docs/setup/CHATGPT_CONNECTOR.md` (this file) | Yes |
| Repo | `docs/setup/MCP_ADAPTER.md` (the distinct App-Password endpoint) | Yes |

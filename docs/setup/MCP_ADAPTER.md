# Official MCP Adapter setup

WP Content Bridge registers transport-neutral WordPress abilities and, since
0.8.0, projects them itself: install the official `WordPress/mcp-adapter` plugin
and the endpoint exists. No site-owned server code, and no list of ability names
anywhere (ADR 0025).

This guide describes the reference App-Password endpoint:

| Property | Value |
|---|---|
| Endpoint | `/wp-json/wpcb-mcp/mcp` |
| Server ID | `wpcb-bridge` |
| Transport | Streamable HTTP / JSON-RPC 2.0 |
| Authentication | WordPress Application Password over HTTP Basic auth |
| Adapter | Official `WordPress/mcp-adapter`, v0.5.0 or later |

ChatGPT uses the separate OAuth-fronted miniOrange endpoint documented in
[CHATGPT_CONNECTOR.md](CHATGPT_CONNECTOR.md). That path discovers abilities from
the WordPress registry on its own and is unaffected by this projection; what
governs its reach is the per-principal miniOrange grant.

## How the tool set is determined

`Adapter\Mcp\McpServerProvider` answers `mcp_adapter_init` and projects **every
ability registered under this plugin's category in the current request**.

Registration is the only gate that matters, and it already reflects
configuration: an ability whose feature area is switched off is never
registered, so it cannot be projected. Enabling a feature area in **Settings →
WP Content Bridge** therefore adds its abilities to `tools/list` with no further
configuration, and a release that adds an ability needs no site-side change.

Roughly, from least to most gated:

- the core content, SEO, block-tree, diagnostics, llms.txt and status-graph
  **reads** are always registered;
- media reads and block-pattern reads each need their own read flag;
- every **write** needs `wpcb_writes_enabled`, and trash/restore, llms.txt
  publication, and `publish`/`future` status targets need their own flag on top;
- Service and Custom Schema abilities additionally need a compatible standalone
  IsuDev Schema Extended plugin.

`docs/architecture/CONTENT_ACCESS.md` and the settings screen are authoritative
on the flags; this page deliberately does not restate the inventory.

Projection is not authorization. A projected tool still requires the ability's
WPCB capability, the native WordPress capability, per-type policy, schema
validation, and the write safeguards before it executes anything.

## Install the Adapter

```bash
wp plugin install https://github.com/WordPress/mcp-adapter/releases/latest/download/mcp-adapter.zip --activate
wp plugin list --status=active
```

That is the whole setup. Do not add the Adapter as a Composer dependency of WP
Content Bridge; the plugin must keep working with it absent.

## Retiring the site-owned MU-plugin

Installs predating 0.8.0 carry an MU-plugin (`isudev/wp-content-bridge-mcp-server`)
whose `ABILITY_PROFILE` constant was a hand-written projection list. That list
is what silently withheld 20 of 31 abilities on a 0.7.1 install, and it is now
redundant.

While it is still present it wins: it registers at the default priority and the
plugin's provider, hooked at priority 20, declines rather than registering
`wpcb-bridge` twice. So:

1. Delete the MU-plugin (or remove it from the site's Composer requirements).
2. Confirm the endpoint still answers and now lists everything (below).
3. Reconnect the client — MCP clients cache `tools/list`.

The server ID, REST namespace, and route are unchanged, so the endpoint URL and
existing Application Passwords keep working.

## Narrowing the projection

Two controls, both optional:

- **`wpcb_mcp_server_enabled`** — the settings-screen switch. Off means the
  plugin registers no MCP server at all. An absent option row means on: a site
  that installed the Adapter did so to reach these abilities.
- **`wp_content_bridge_mcp_abilities`** — a filter for sites that want a smaller
  tool set:

  ```php
  add_filter(
      'wp_content_bridge_mcp_abilities',
      static fn ( array $abilities ): array => array_values(
          array_filter( $abilities, static fn ( string $name ): bool => str_contains( $name, '/get-' ) )
      )
  );
  ```

  The filter can only subtract. Names outside the discovered set are dropped, so
  no other plugin's abilities can enter this server through it.

Neither control is an authorization mechanism. To give an integration less
authority, give its user fewer capabilities — see below.

## Principal and capability configuration

Use a dedicated WordPress user. In **Settings → WP Content Bridge**, assign only
the capabilities required by that integration:

- `wpcb_read_content` for the content reads, including `get-block-tree`;
- `wpcb_read_media` for media reads;
- `wpcb_read_patterns` plus native editor access for block patterns;
- `wpcb_edit_content` plus native create/edit capabilities for draft, content,
  and block writes;
- `wpcb_manage_seo` plus native `edit_post` for SEO, Service, and Custom Schema
  writes; Schema operations also require provider support;
- `wpcb_delete_content` plus native `delete_post` for trash;
- `wpcb_manage_llms` for llms.txt writes;
- `wpcb_publish_content` plus native `publish_post` for publishing transitions —
  grant this only deliberately.

This is the layer that decides what an integration may do. It is enforced per
call regardless of what discovery listed.

## Verification

Ask the plugin what it is projecting — `get-diagnostics` reports it, and so does
this one-liner:

```bash
wp eval 'echo wp_json_encode( IsuDev\WPContentBridge\Adapter\Mcp\McpServerProvider::projection_status(), JSON_PRETTY_PRINT ), PHP_EOL;'
```

`projected_abilities` is the exact set the Adapter receives. If a tool is
missing from a client but present here, the client is caching or its principal
lacks the capability; if it is missing here too, its feature area is off.

Then run the client-agnostic smoke test. It executes only the safe baseline
reads and never executes write or destructive tools. Derive the expected tool
list from the runtime rather than typing one:

```bash
WPCB_SITE_URL=https://example.test \
WPCB_WP_ROOT=/absolute/path/to/site/public \
WPCB_MCP_PATH=/wp-json/wpcb-mcp/mcp \
WPCB_EXPECTED_TOOLS="$(wp eval 'echo implode( ",", array_map( static fn ( string $name ): string => substr( $name, strlen( "wp-content-bridge/" ) ), IsuDev\WPContentBridge\Adapter\Mcp\McpServerProvider::abilities() ) );')" \
tests/Integration/mcp-smoke-verification.sh
```

The smoke script creates a disposable Application Password and deletes it on
exit. It also verifies the raw MCP `inputSchema.required` declaration for known
targeted tools, including `post_id` and `version_token` on Service and Custom
Schema preview/update. Never print, log, or commit that secret.

`tests/Integration/abilities-runtime-verification.php` asserts projection
parity — every registered ability of this plugin's category is projected — so a
new ability cannot silently miss the endpoint.

MCP tool names are derived from the ability ID by the Adapter, which replaces
the slash, for example:

```text
wp-content-bridge/get-media -> wp-content-bridge-get-media
```

Client-side naming may differ again (some clients render `__` between namespace
and name). Match on the trailing intent, not the separator.

## Release checks

- Disabled feature flags remove their abilities from `tools/list`, because they
  remove them from the registry.
- Enabling a feature flag adds its abilities to `tools/list` on the next
  request, with no site-side configuration change.
- With the Adapter deactivated, the plugin loads and the abilities register as
  before; nothing tries to create a server.
- `wpcb_mcp_server_enabled` off removes the endpoint entirely.
- A user without the matching WPCB capability cannot execute a listed tool.
- Native object authorization still denies inaccessible content or media.
- Write tools are not invoked by discovery smoke tests.
- No Application Password, OAuth token, client registration, or site URL is
  committed to either repository.

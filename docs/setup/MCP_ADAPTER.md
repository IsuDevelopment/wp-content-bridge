# MCP Adapter setup — exposing the five WP Content Bridge read abilities

This recipe stands up an MCP (Model Context Protocol) HTTP endpoint that projects
**exactly five** read-only WP Content Bridge abilities as MCP tools. It is the
App-Password endpoint the client-agnostic smoke suite (Task 5) targets.

> **ChatGPT itself connects through a different, OAuth-fronted endpoint.**
> ChatGPT's connector requires OAuth 2.1, which this adapter does not provide
> on a self-hosted site (ADR 0010). The endpoint ChatGPT actually uses is
> `/wp-json/mosmcp/v1/mcp` via miniOrange Secure MCP Connector — see
> `docs/setup/CHATGPT_CONNECTOR.md`. Both endpoints project the same five
> abilities; keep them distinct.

> **Site infrastructure — not part of the plugin package.**
> The official `WordPress/mcp-adapter` plugin **and** the `wpcb-mcp-server.php`
> mu-plugin below are **site-level infrastructure**. Per the project architecture
> (ADR 0010) the `wp-content-bridge` plugin registers abilities but must **never**
> initialize the MCP Adapter. Neither the adapter nor the mu-plugin is bundled in
> or committed to the plugin repo. Only this document lives in the repo.

## Resolved endpoint (Task 5 uses exactly this)

| Property | Value |
| --- | --- |
| Endpoint URL | `https://kormas-isu.local/wp-json/wpcb-mcp/mcp` |
| REST namespace | `wpcb-mcp` |
| Route | `mcp` |
| MCP server id | `wpcb-bridge` |
| Transport | `HttpTransport` (Streamable HTTP, JSON-RPC 2.0) |
| Protocol version | `2025-06-18` |
| Auth | WordPress Application Passwords over HTTP (Basic auth) |

The endpoint resolves as `/wp-json/{namespace}/{route}` → `/wp-json/wpcb-mcp/mcp`.
Confirmed empirically (see transcript below).

## Adapter plugin

| Property | Value |
| --- | --- |
| Slug | `mcp-adapter` |
| Version | `0.5.0` |
| Source | `https://github.com/WordPress/mcp-adapter/releases/latest/download/mcp-adapter.zip` |

Verified class paths in the installed `0.5.0` source (`content/plugins/mcp-adapter/includes/`):

- `WP\MCP\Core\McpAdapter` — provides `create_server( ... )`, fires the `mcp_adapter_init` action.
- `WP\MCP\Transport\HttpTransport`
- `WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler`
- `WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler`

## Install steps

Run from the WP root (`/Users/lukaszbiedron/Local Sites/kormas-isu/app/public`):

```bash
# 1. Confirm the five abilities resolve (each line must end =1)
wp eval 'do_action("wp_abilities_api_init"); foreach (array("search-content","get-content","get-url-seo","get-editorial-context","get-diagnostics") as $a) { echo "wp-content-bridge/$a=", (int) (function_exists("wp_get_ability") && wp_get_ability("wp-content-bridge/$a")), "\n"; }'

# 2. Install + activate the official adapter (into the SITE, not the plugin repo)
wp plugin install https://github.com/WordPress/mcp-adapter/releases/latest/download/mcp-adapter.zip --activate
wp plugin list --status=active | grep -i mcp   # => mcp-adapter  active  0.5.0
```

## Glue mu-plugin (site only)

Place at **`content/mu-plugins/wpcb-mcp-server.php`** on the site. (Local by
Flywheel uses `content/`, not `wp-content/`. mu-plugins auto-loads top-level PHP
files; the existing `isudev-mu-plugin-loader.php` is untouched.) This file is the
only thing wiring the abilities into an MCP server, and it lives on the site
because the plugin is forbidden from initializing the adapter.

WP Content Bridge 0.1.2+ can assign its operational plugin capabilities to one
existing dedicated integration user under **Settings → WP Content Bridge →
Integration user access**. Native WordPress capabilities and this MCP server's
tool allowlist remain independent gates; the settings surface never changes a
user role or grants native object permissions.

WP Content Bridge 0.1.3 also registers two media read abilities when the
separate media-read setting is enabled. The example below deliberately keeps
the original five-read-ability allowlist; add the media abilities only after
granting `wpcb_read_media` and completing the local media runtime verifier.
The same rule applies to `list-block-patterns`: grant `wpcb_read_patterns`,
confirm native editor access, enable its master setting, and pass the dedicated
runtime verifier before adding it to a site-level MCP allowlist.

```php
<?php
/**
 * Plugin Name: WP Content Bridge - MCP Server (site infra)
 * Description: Registers a single read-only MCP server that projects exactly the five wp-content-bridge abilities as MCP tools. Site infrastructure only - excluded from the wp-content-bridge plugin package.
 * Author: Dekode
 * Version: 1.0.0
 *
 * This mu-plugin is intentionally NOT part of the wp-content-bridge plugin. Per
 * the project architecture (ADR 0010) the plugin registers abilities but must
 * never initialize the MCP Adapter. The official WordPress/mcp-adapter plugin
 * and this glue file are site-level infrastructure and live outside the repo.
 *
 * @package wp-content-bridge-site-infra
 */

declare(strict_types=1);

use WP\MCP\Core\McpAdapter;
use WP\MCP\Transport\HttpTransport;
use WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler;
use WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler;

/**
 * Register the read-only MCP server for the wp-content-bridge abilities.
 *
 * The MCP Adapter fires `mcp_adapter_init` and passes the adapter instance.
 * We expose exactly five read abilities as tools - no resources, no prompts.
 *
 * Endpoint: /wp-json/wpcb-mcp/mcp  (namespace "wpcb-mcp", route "mcp").
 * Auth: WordPress Application Passwords over HTTP; the default transport
 * permission requires the `read` capability.
 *
 * @param McpAdapter $adapter The MCP Adapter instance.
 * @return void
 */
add_action(
	'mcp_adapter_init',
	static function ( McpAdapter $adapter ): void {
		$adapter->create_server(
			'wpcb-bridge',
			'wpcb-mcp',
			'mcp',
			'WP Content Bridge',
			'Read-only access to WP Content Bridge editorial and SEO abilities.',
			'1.0.0',
			array( HttpTransport::class ),
			ErrorLogMcpErrorHandler::class,
			NullMcpObservabilityHandler::class,
			array(
				'wp-content-bridge/search-content',
				'wp-content-bridge/get-content',
				'wp-content-bridge/get-url-seo',
				'wp-content-bridge/get-editorial-context',
				'wp-content-bridge/get-diagnostics',
			),
			array(),
			array()
		);
	}
);
```

## Authentication (local smoke)

Auth is WordPress **Application Passwords** sent as HTTP Basic auth. The default
adapter transport permission requires the `read` capability
(`mcp_adapter_default_transport_permission_user_capability` filter, default
`read`), so the least-privilege `wpcb-bridge-reader` user (Task 3) is sufficient.

For the smoke test, create a **disposable** Application Password, run the checks,
then delete it immediately. **Never print, log, or commit the password value.**

```bash
# Create a disposable App Password (porcelain prints the secret once — do NOT commit it)
wp user application-password create wpcb-bridge-reader wpcb-mcp-smoke --porcelain
# ... run the curl calls below with --user "wpcb-bridge-reader:<APP_PASSWORD>" ...

# Delete it as soon as the smoke test finishes (runs even on failure via a shell trap)
wp user application-password delete wpcb-bridge-reader --all
```

## Verification transcript (`tools/list`, secret redacted)

The Streamable HTTP transport is session-based: `initialize` returns an
`Mcp-Session-Id` response header that must be echoed on subsequent requests.
`initialize` is followed by a `notifications/initialized` notification, then
`tools/list`.

```bash
ENDPOINT="https://kormas-isu.local/wp-json/wpcb-mcp/mcp"
# --user "wpcb-bridge-reader:<REDACTED_APP_PASSWORD>" ; -k for the self-signed local cert

# 1) initialize (capture headers to read the session id)
curl -sk -D headers.txt --user "wpcb-bridge-reader:<REDACTED>" -X POST "$ENDPOINT" \
  -H "Content-Type: application/json" -H "Accept: application/json, text/event-stream" \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"smoke","version":"1.0"}}}'

SID=$(grep -i '^Mcp-Session-Id:' headers.txt | awk '{print $2}' | tr -d '[:space:]')

# 2) notifications/initialized
curl -sk --user "wpcb-bridge-reader:<REDACTED>" -X POST "$ENDPOINT" \
  -H "Content-Type: application/json" -H "Accept: application/json, text/event-stream" \
  -H "Mcp-Session-Id: $SID" \
  -d '{"jsonrpc":"2.0","method":"notifications/initialized"}'

# 3) tools/list
curl -sk --user "wpcb-bridge-reader:<REDACTED>" -X POST "$ENDPOINT" \
  -H "Content-Type: application/json" -H "Accept: application/json, text/event-stream" \
  -H "Mcp-Session-Id: $SID" \
  -d '{"jsonrpc":"2.0","id":2,"method":"tools/list","params":{}}'
```

`initialize` response:

```json
{
  "jsonrpc": "2.0",
  "id": 1,
  "result": {
    "protocolVersion": "2025-06-18",
    "capabilities": {
      "prompts": { "listChanged": false },
      "resources": { "subscribe": false, "listChanged": false },
      "tools": { "listChanged": false }
    },
    "serverInfo": { "name": "WP Content Bridge", "version": "1.0.0" },
    "instructions": "Read-only access to WP Content Bridge editorial and SEO abilities."
  }
}
```

`tools/list` response — exactly five tools:

```json
{
  "jsonrpc": "2.0",
  "id": 2,
  "result": {
    "tools": [
      {
        "name": "wp-content-bridge-search-content",
        "description": "Searches configured WordPress content types and returns only objects readable by the current principal."
      },
      {
        "name": "wp-content-bridge-get-content",
        "description": "Returns selected source, rendered, or plain-text representations of one readable WordPress content object."
      },
      {
        "name": "wp-content-bridge-get-url-seo",
        "description": "Returns normalized configured and effective SEO metadata for one readable WordPress object or same-site URL."
      },
      {
        "name": "wp-content-bridge-get-editorial-context",
        "description": "Returns bounded policy-approved content vocabulary, readable recent inventory, observed authors, and normalized public Local business data for editorial planning."
      },
      {
        "name": "wp-content-bridge-get-diagnostics",
        "description": "Returns safe compatibility and content-policy diagnostics without paths, secrets, or user data."
      }
    ]
  }
}
```

> **Tool-name mapping.** MCP tool names cannot contain `/`, so the adapter maps
> each ability id `wp-content-bridge/<name>` to the tool name
> `wp-content-bridge-<name>`. The underlying abilities are unchanged.

After the smoke test the disposable App Password was deleted; a follow-up
`wp user application-password list wpcb-bridge-reader --format=count` returned
`0` (no credentials left behind).

## Files

| Location | File | Committed to repo? |
| --- | --- | --- |
| Site (WP root) | `content/plugins/mcp-adapter/` (official plugin, v0.5.0) | No — site infra |
| Site (WP root) | `content/mu-plugins/wpcb-mcp-server.php` (glue) | No — site infra |
| Repo | `docs/setup/MCP_ADAPTER.md` (this file) | Yes |
| Repo | `docs/setup/CHATGPT_CONNECTOR.md` (the distinct OAuth-fronted endpoint ChatGPT uses) | Yes |

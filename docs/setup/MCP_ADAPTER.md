# Official MCP Adapter setup

WP Content Bridge registers transport-neutral WordPress abilities. The official
`WordPress/mcp-adapter` plugin can project an explicit subset of those abilities
as MCP tools, but neither the Adapter nor its server configuration is bundled
with WP Content Bridge.

This guide describes the reference App-Password endpoint:

| Property | Value |
|---|---|
| Endpoint | `/wp-json/wpcb-mcp/mcp` |
| Server ID | `wpcb-bridge` |
| Transport | Streamable HTTP / JSON-RPC 2.0 |
| Authentication | WordPress Application Password over HTTP Basic auth |
| Adapter | Official `WordPress/mcp-adapter` |

ChatGPT uses the separate OAuth-fronted miniOrange endpoint documented in
[CHATGPT_CONNECTOR.md](CHATGPT_CONNECTOR.md). The two projections have separate
allowlists and credentials.

## Projection profile for current source

The complete WP Content Bridge profile contains 18 potential abilities:

```text
wp-content-bridge/search-content
wp-content-bridge/get-content
wp-content-bridge/get-url-seo
wp-content-bridge/get-editorial-context
wp-content-bridge/get-diagnostics
wp-content-bridge/get-media
wp-content-bridge/get-media-by-id
wp-content-bridge/list-block-patterns
wp-content-bridge/create-draft
wp-content-bridge/update-content
wp-content-bridge/update-seo
wp-content-bridge/get-service-schema
wp-content-bridge/preview-service-schema
wp-content-bridge/update-service-schema
wp-content-bridge/get-custom-schema
wp-content-bridge/preview-custom-schema
wp-content-bridge/update-custom-schema
wp-content-bridge/trash-content
```

The first five are always registered. The remaining abilities enter the
WordPress registry only when their WP Content Bridge feature flags are enabled:

- media reads: `wpcb_media_reads_enabled`;
- block patterns: `wpcb_pattern_reads_enabled`;
- draft/content/SEO writes: `wpcb_writes_enabled`;
- Service schema: `wpcb_writes_enabled` plus a loaded, compatible standalone
  IsuDev Schema Extended plugin;
- Custom Schema: `wpcb_writes_enabled` plus Schema Extended's compatible public
  `Integration_API` contract;
- trash: both `wpcb_writes_enabled` and `wpcb_trash_enabled`.

The MCP server should therefore intersect its explicit profile with abilities
registered in the current request. This keeps disabled operations absent from
MCP discovery.

Projection is not authorization. Execution still requires the ability's WPCB
capability, native WordPress capability, per-type policy, schema validation,
and write safeguards. Adding an ID to the MCP profile grants none of those.

## Install the Adapter

Install and activate the official Adapter at site level:

```bash
wp plugin install https://github.com/WordPress/mcp-adapter/releases/latest/download/mcp-adapter.zip --activate
wp plugin list --status=active
```

Do not add the Adapter as a dependency of WP Content Bridge and do not create an
MCP server from the plugin's composition root.

## Site-owned server configuration

Place the following logic in version-controlled site infrastructure. A
Composer-installed MU-plugin is recommended; do not maintain an ignored,
hand-edited runtime file as the deployment source.

```php
<?php
/**
 * Plugin Name: WP Content Bridge MCP Server
 * Description: Projects registered WP Content Bridge abilities through the official MCP Adapter.
 * Version:     0.3.0
 */

declare(strict_types=1);

use WP\MCP\Core\McpAdapter;
use WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler;
use WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler;
use WP\MCP\Transport\HttpTransport;

$wpcb_profile = array(
	'wp-content-bridge/search-content',
	'wp-content-bridge/get-content',
	'wp-content-bridge/get-url-seo',
	'wp-content-bridge/get-editorial-context',
	'wp-content-bridge/get-diagnostics',
	'wp-content-bridge/get-media',
	'wp-content-bridge/get-media-by-id',
	'wp-content-bridge/list-block-patterns',
	'wp-content-bridge/create-draft',
	'wp-content-bridge/update-content',
	'wp-content-bridge/update-seo',
	'wp-content-bridge/get-service-schema',
	'wp-content-bridge/preview-service-schema',
	'wp-content-bridge/update-service-schema',
	'wp-content-bridge/get-custom-schema',
	'wp-content-bridge/preview-custom-schema',
	'wp-content-bridge/update-custom-schema',
	'wp-content-bridge/trash-content',
);

add_action(
	'mcp_adapter_init',
	static function ( McpAdapter $adapter ) use ( $wpcb_profile ): void {
		$registered = function_exists( 'wp_has_ability' )
			? array_values( array_filter( $wpcb_profile, 'wp_has_ability' ) )
			: array();

		if ( array() === $registered ) {
			return;
		}

		$adapter->create_server(
			'wpcb-bridge',
			'wpcb-mcp',
			'mcp',
			'WP Content Bridge',
			'Capability-gated access to registered WP Content Bridge abilities.',
			'0.3.0',
			array( HttpTransport::class ),
			ErrorLogMcpErrorHandler::class,
			NullMcpObservabilityHandler::class,
			$registered,
			array(),
			array()
		);
	}
);
```

The example uses an explicit closed profile rather than discovering every
public ability from every plugin. This prevents unrelated or newly installed
tools from entering the server accidentally.

## Principal and capability configuration

Use a dedicated WordPress user. In **Settings → WP Content Bridge**, assign only
the capabilities required by that integration:

- `wpcb_read_content` for the five core reads;
- `wpcb_read_media` for media reads;
- `wpcb_read_patterns` plus native editor access for block patterns;
- `wpcb_edit_content` plus native create/edit capabilities for draft/content
  writes;
- `wpcb_manage_seo` plus native `edit_post` for SEO, structured Service, and
  Custom Schema writes; Schema operations also require provider support;
- `wpcb_delete_content` plus native `delete_post` for trash.

Do not grant `wpcb_publish_content` until
`wp-content-bridge/transition-content-status` is implemented and separately
approved. Do not expose another plugin's generic content-write abilities for
the same operation.

## Verification

First verify registration inside WordPress:

```bash
wp eval 'foreach (array("search-content","get-content","get-url-seo","get-editorial-context","get-diagnostics","get-media","get-media-by-id","list-block-patterns","create-draft","update-content","update-seo","get-service-schema","preview-service-schema","update-service-schema","get-custom-schema","preview-custom-schema","update-custom-schema","trash-content") as $name) { $id = "wp-content-bridge/" . $name; echo $id, "=", (int) (function_exists("wp_has_ability") && wp_has_ability($id)), PHP_EOL; }'
```

Then run the client-agnostic smoke test. `WPCB_EXPECTED_TOOLS` controls the
discovery profile; the script executes only the safe baseline reads and never
executes write or destructive tools.

```bash
WPCB_SITE_URL=https://example.test \
WPCB_WP_ROOT=/absolute/path/to/site/public \
WPCB_MCP_PATH=/wp-json/wpcb-mcp/mcp \
WPCB_EXPECTED_TOOLS=search-content,get-content,get-url-seo,get-editorial-context,get-diagnostics,get-media,get-media-by-id,list-block-patterns,create-draft,update-content,update-seo,get-service-schema,preview-service-schema,update-service-schema,get-custom-schema,preview-custom-schema,update-custom-schema,trash-content \
tests/Integration/mcp-smoke-verification.sh
```

The smoke script creates a disposable Application Password and deletes it on
exit. It also verifies the raw MCP `inputSchema.required` declaration for known
targeted tools, including `post_id` and `version_token` on Service and Custom
Schema preview/update. Never print, log, or commit that secret.

MCP tool names replace the ability ID slash with a hyphen, for example:

```text
wp-content-bridge/get-media -> wp-content-bridge-get-media
```

## Release checks

- Disabled feature flags remove their abilities from `tools/list`.
- A user without the matching WPCB capability cannot execute the tool.
- Native object authorization still denies inaccessible content or media.
- Write tools are not invoked by discovery smoke tests.
- `trash-content` remains absent unless both writes and trash are enabled.
- all three Service-schema abilities remain absent unless writes and the
  compatible standalone Schema Extended provider are both active.
- all three Custom Schema abilities remain absent unless writes and Schema
  Extended's compatible `Integration_API` contract are both active.
- No Application Password, OAuth token, client registration, or site URL is
  committed to either repository.

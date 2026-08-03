=== WP Content Bridge ===
Contributors: isudev
Tags: abilities, mcp, ai, content, seo, yoast
Requires at least: 7.0
Tested up to: 7.0
Requires PHP: 8.2
Stable tag: 0.2.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Provider-neutral WordPress content and SEO abilities for MCP and other agent clients.

== Description ==

The read-only content core exposes access-aware search, content detail, provider-neutral SEO, and safe diagnostics through the WordPress Abilities API. Responses use strict schemas, native WordPress object permissions, per-post-type policy, bounded search, and an explicit content payload limit. A Yoast SEO adapter covers Yoast Free / Premium / Local 28.x.

Safe write abilities (create draft, update content, update SEO, optional structured Service schema, and reversible trash) are also available behind off-by-default feature flags, per-post-type write policy, dedicated capabilities, native object checks, and optimistic concurrency. Service schema configuration additionally provides read-before-write and read-only preview abilities when the standalone IsuDev Schema Extended plugin is loaded. Publication and other status transitions are not yet supported.

MCP transport is provided separately by the official WordPress MCP Adapter and is not bundled with this plugin.

Packaged installs can update from the plugin's GitHub release ZIP through Plugin Update Checker. Source checkouts containing `.git` do not self-update. Composer-managed or deployment-managed sites can define `WPCB_DISABLE_SELF_UPDATES` or use the `wp_content_bridge_self_updates_enabled` filter.

== Installation ==

Install a packaged release or run Composer before activating a source checkout.

== Changelog ==

= 0.2.3 =
* Added read-only `wp-content-bridge/get-service-schema` and `wp-content-bridge/preview-service-schema` operations for read-before-write and provider-sanitized dry-run workflows.
* Added raw MCP descriptor checks for required `post_id` and `version_token` fields and documented the miniOrange nested-schema projection defect.
* Expanded the documented closed MCP profile to 15 potential abilities; only currently registered abilities enter discovery.

= 0.2.2 =
* Added packaged GitHub release updates using the release ZIP asset, limited to admin/cron requests and disabled for Git source checkouts.
* Added conditional `wp-content-bridge/update-service-schema` support for the standalone IsuDev Schema Extended plugin, covering Service fields, typed areaServed values, brands, and hasOfferCatalog.
* Added strict nested bounds, capability and per-type policy gates, optimistic concurrency, redacted audit, post-write re-read, and rollback of already-written metadata when a write fails.
* Expanded the documented closed MCP profile to 13 potential abilities; only currently registered abilities enter discovery.

= 0.2.0 =
* Added an explicit MCP projection profile for all 12 implemented WP Content Bridge abilities. Only currently registered abilities enter discovery; authorization and policy gates remain mandatory.
* Added configurable MCP discovery verification without executing write or destructive abilities.
* Extended `update-seo` with merged noarchive/noimageindex/nosnippet controls and Open Graph/Twitter image overrides by authorized WordPress attachment ID.

= 0.1.5 =
* Added `wp-content-bridge/trash-content`, separately gated reversible trash with native `delete_post`, optimistic concurrency, audit, and fail-closed behavior when WordPress trash is disabled.
* Replaced the unreleased `publish-content` plan with the future `transition-content-status` contract; public and scheduled transitions retain stronger publication gates.
* Added the `wp-content-bridge/create-draft` and `wp-content-bridge/update-content` write abilities, gated behind the `wpcb_writes_enabled` flag, dedicated capabilities, and per-post-type policy.
* Added the `wp-content-bridge/update-seo` write ability: writes a fixed Yoast Free core-field SEO allowlist on an existing post and returns the re-read effective SEO.
* Added optimistic-concurrency version tokens, WordPress revisions on update, idempotent draft creation, and a redacted write audit log.

= 0.1.0 =
* Added the standalone plugin scaffold and architecture documentation.
* Added per-post-type read policy and WordPress capability gates.
* Added read-only search, content detail, and diagnostics Abilities.
* Added bounded taxonomy search, authorization-aware pagination, and a 2 MiB representation limit.

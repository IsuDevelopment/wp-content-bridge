=== WP Content Bridge ===
Contributors: isudev
Tags: abilities, mcp, ai, content, seo, yoast
Requires at least: 7.0
Tested up to: 7.0
Requires PHP: 8.2
Stable tag: 0.4.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Provider-neutral WordPress content and SEO abilities for MCP and other agent clients.

== Description ==

The read-only content core exposes access-aware search, content detail, provider-neutral SEO, and safe diagnostics through the WordPress Abilities API. Responses use strict schemas, native WordPress object permissions, per-post-type policy, bounded search, and an explicit content payload limit. A Yoast SEO adapter covers Yoast Free / Premium / Local 28.x.

Safe write abilities (create draft, update content, update SEO, optional structured Service and bounded Custom Schema, and reversible trash) are also available behind off-by-default feature flags, per-post-type write policy, dedicated capabilities, native object checks, and optimistic concurrency. Update content and update SEO each have a matching read-only preview ability that shares the write's exact input contract and never mutates. Schema Extended integrations additionally provide read-before-write and read-only preview abilities when their compatible public contracts are loaded. Publication and other status transitions are not yet supported.

MCP transport is provided separately by the official WordPress MCP Adapter and is not bundled with this plugin.

Packaged installs can update from the plugin's GitHub release ZIP through Plugin Update Checker. Source checkouts containing `.git` do not self-update. Composer-managed or deployment-managed sites can define `WPCB_DISABLE_SELF_UPDATES` or use the `wp_content_bridge_self_updates_enabled` filter.

== Installation ==

Install a packaged release or run Composer before activating a source checkout.

== Changelog ==

= 0.4.0 =
* **Breaking:** renamed `preview-service-schema` to `preview-update-service-schema` and `preview-custom-schema` to `preview-update-custom-schema`. A preview ability is now named `preview-` plus the exact ID of the write it mirrors, so the convention holds for every current and planned preview. Update any MCP projection profile and client that references the old IDs.
* Added `description` to the required `taxonomy` and `term_ids` fields of the nested taxonomy assignment schema in `create-draft` and `update-content`; they were the only required fields in the public profile without one.
* Added runtime verifiers for the Service schema and Custom Schema slices, asserting graph-level output rather than the provider's own re-read.
* Repaired three runtime verifiers that had drifted against the current source and would fail on any release after 0.1.0.
* Added `preview-update-content` and `preview-update-seo`, read-only previews of `update-content` and `update-seo` that share the write's exact input contract, policy, and concurrency check but never mutate; responses report `writes_performed: false` (ADR 0021).
* Added a runtime verifier for both new previews, proving repeated calls are deterministic and cause no post, meta, revision, or audit change, that a preview followed by the matching write produces exactly the previewed state, and that stale tokens are rejected before any mutation.
* Collapsed the Yoast adapter's field sanitization into one shared normalization path consumed by both `update-seo` and `preview-update-seo`, so a field added to the write cannot silently go missing from the preview.
* Expanded the closed MCP profile to 20 potential abilities.

= 0.3.0 =
* Added conditional `get-custom-schema`, `preview-custom-schema`, and `update-custom-schema` abilities through Schema Extended 0.3.0's public integration contract.
* Added bounded JSON source and provider-result validation, read-only dry run, safe diagnostics, redacted audit, and post-write verification.
* Kept `get-url-seo` as the authoritative complete resolved graph read and expanded the closed MCP profile to 18 potential abilities.

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

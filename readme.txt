=== WP Content Bridge ===
Contributors: isudev
Tags: abilities, mcp, ai, content, seo, yoast
Requires at least: 7.0
Tested up to: 7.0
Requires PHP: 8.2
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Provider-neutral WordPress content and SEO abilities for MCP and other agent clients.

== Description ==

The read-only content core exposes access-aware search, content detail, and safe diagnostics through the WordPress Abilities API. Responses use strict schemas, native WordPress object permissions, per-post-type policy, bounded search, and an explicit content payload limit.

SEO providers and MCP transport are planned separately and are not included in the current development build.

== Installation ==

Install a packaged release or run Composer before activating a source checkout.

== Changelog ==

= 0.1.0 =
* Added the standalone plugin scaffold and architecture documentation.
* Added per-post-type read policy and WordPress capability gates.
* Added read-only search, content detail, and diagnostics Abilities.
* Added bounded taxonomy search, authorization-aware pagination, and a 2 MiB representation limit.

=== WP Content Bridge ===
Contributors: isudev
Tags: abilities, mcp, ai, content, seo, yoast
Requires at least: 7.0
Tested up to: 7.0
Requires PHP: 8.2
Stable tag: 0.7.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Provider-neutral WordPress content and SEO abilities for MCP and other agent clients.

== Description ==

The read-only content core exposes access-aware search, content detail, provider-neutral SEO, and safe diagnostics through the WordPress Abilities API. Responses use strict schemas, native WordPress object permissions, per-post-type policy, bounded search, and an explicit content payload limit. A Yoast SEO adapter covers Yoast Free / Premium / Local 28.x.

Individual Gutenberg blocks can be read and edited by tree path, so changing one paragraph does not require rewriting the whole document.

Safe write abilities (create draft, update content, update SEO, single-block edits, optional structured Service and bounded Custom Schema, and reversible trash with a matching restore) are also available behind off-by-default feature flags, per-post-type write policy, dedicated capabilities, native object checks, and optimistic concurrency. Update content and update SEO each have a matching read-only preview ability that shares the write's exact input contract and never mutates. Schema Extended integrations additionally provide read-before-write and read-only preview abilities when their compatible public contracts are loaded. Status transitions — including publication and scheduling — run against an administrator-configured allowlist of permitted status pairs per post type, which is empty until someone configures it.

MCP transport is provided separately by the official WordPress MCP Adapter and is not bundled with this plugin.

Packaged installs can update from the plugin's GitHub release ZIP through Plugin Update Checker. Source checkouts containing `.git` do not self-update. Composer-managed or deployment-managed sites can define `WPCB_DISABLE_SELF_UPDATES` or use the `wp_content_bridge_self_updates_enabled` filter.

== Installation ==

Install a packaged release or run Composer before activating a source checkout.

== Uninstall ==

Deleting the plugin removes its options, its dedicated `wpcb_*` capabilities from every role and from any user granted them directly, and its transient caches.

The `{prefix}wpcb_audit` table is deliberately left in place. It records who changed what through the bridge — field names only, never values — as a rolling window of the most recent 5,000 mutation attempts. Destroying that record silently on delete is not the plugin's call to make. Remove the table deliberately if you want it gone.

== Changelog ==

= 0.7.1 =
* Added row, column, and whole-matrix bulk selection to the **Status transitions** settings matrix. Five statuses produce twenty ordered pairs per content type, and ticking that by hand was the practical barrier to configuring the allowlist at all.
* The bulk toggles are progressive enhancement. They carry no form field, stay hidden without JavaScript, and change nothing about how a submitted matrix is normalized — the same server-side sanitization runs, and a selected pair still grants no publication without the publication flag, the `wpcb_publish_content` capability, and native `publish_post`.
* A partially selected row or column now shows an indeterminate toggle rather than an unchecked one, so "some of these are on" is distinguishable from "none of these are on".
* Fixed a packaging leak: the maintainer notes file was shipped inside the release artifact from 0.5.0 through 0.7.0. It is now excluded from the build and asserted absent, alongside the existing development-file check.

= 0.7.0 =
* Added `wp-content-bridge/transition-content-status` and its companion read `wp-content-bridge/get-status-transitions`, completing the draft, review, publish and schedule workflow without adding a free-form `post_status` field to content updates.
* Transitions run against an **allowlist of ordered status pairs per post type**, not a list of target statuses. That is what lets an administrator permit unpublishing while withholding publishing — the common arrangement for an automated principal, and one a target-only list cannot express. Statuses are limited to `draft`, `pending`, `private`, `publish` and `future`; `trash`, `auto-draft`, `inherit` and plugin-defined statuses are not expressible at all.
* **The allowlist starts empty, and upgrading adds no write surface.** Until an administrator configures pairs, every transition is refused. A documented editorial preset is available as a button, never as a default.
* `publish` and `future` require three gates the other transitions do not: the off-by-default `wpcb_publish_enabled` flag, the `wpcb_publish_content` capability, and the native `publish_post` capability. Ordinary editorial transitions need only `wpcb_edit_content`, native `edit_post`, and the per-type policy.
* Scheduling takes `publish_at` in the site timezone, stores UTC, and returns both. An invalid or past `publish_at` is refused before the write rather than trusted to degrade safely — WordPress stores `publish` when asked for `future` with a past date, so a bad value would put content live immediately.
* Daylight-saving time is handled deliberately: a local time inside the spring-forward gap does not exist and is rejected, the autumn fold resolves deterministically, and a site on a fixed UTC offset is handled uniformly.
* Every transition response reports the status **read back from storage**, never the one requested. Where WordPress rewrote the transition, the ability fails and says what was actually stored instead of reporting a success it did not perform.
* `get-status-transitions` reports whether scheduled publication can actually run on the site. A site with `DISABLE_WP_CRON` and no alternate runner can reach `future` but will never publish it, and that is now stated rather than implied.
* Expanded the closed MCP profile to 31 potential abilities.

= 0.6.0 =
* Added a published `/llms.txt`, off by default. While the feature is disabled there is no new public surface at all: no rewrite rule is registered and the path 404s exactly as any unknown URL does, indistinguishable from a plugin that was never installed.
* The public route serves a stored snapshot and nothing else. It performs one option read and writes those bytes — no post query, no SEO call, no generation, no write of any kind. If no snapshot exists it returns 404 rather than building one on the request. Responses carry a strong `ETag` derived from the document's own content hash, a `Last-Modified` from its generation time, and a bounded `Cache-Control`; `If-None-Match` and `If-Modified-Since` are answered with a bodyless `304`.
* Added `wp-content-bridge/get-llms-txt`, `preview-update-llms-txt`, `update-llms-txt`, and `regenerate-llms-txt`, gated by a new `wpcb_manage_llms` capability. The three writes are withheld entirely while publication is disabled.
* Draft, private, password-protected, `noindex`, and non-public-post-type content never reaches the document. This is asserted by a runtime verifier, not by inspection.
* **A `noindex` page could reach the document before this release's fix.** Yoast's own surface API returns the first-resolved post's meta for every subsequent post in the same request, and the eligibility check resolved many posts per request. The check now reads Yoast's indexable data instead, which does not depend on call order. If you enabled an earlier build of this feature, regenerate.
* Regeneration is debounced and batched. Content and SEO transitions queue a single run 90 seconds out, and later triggers never push that deadline back — a sliding window would never fire at all on a steadily edited site. Large sites are processed in bounded batches across cron ticks, and the public document is replaced once, at the end, so a reader mid-run always gets the previous complete snapshot rather than half a document.
* Un-publishing regenerates. The trigger reads the status a post is *leaving*, not only the one it now has, so content withdrawn by its author leaves the public document instead of lingering until an unrelated scheduled run.
* A physical `llms.txt` already served by the site, or Yoast's own llms.txt feature, is reported as a blocking ownership conflict. The plugin never overwrites or deletes another owner's file, and no response field exposes a filesystem path.

= 0.5.0 =
* **Fixed a data-corruption defect present in every release from 0.1.5 to 0.4.5.** `wp_insert_post()` and `wp_update_post()` expect slashed data and call `wp_unslash()` on it; the plugin passed raw input, so every backslash written through `create-draft` or `update-content` was silently stripped. Gutenberg escapes a double quote inside a block's attribute JSON as a backslash-u escape, which was therefore stored as the literal text `u0022`. **Any block whose attributes contained a quote was corrupted by any bridge write to that post** — including blocks the write was never meant to touch. The plugin does **not** repair content already damaged this way: the damaged form is indistinguishable from text a user typed deliberately. If you have written through the bridge, spot-check posts built from blocks that keep text in attributes.
* **Breaking:** removed the deprecated `dry_run` field from `preview-update-service-schema` and `preview-update-custom-schema`. It was deprecated in 0.4.5, where `writes_performed` was added to all four previews additively; a client that migrated then has nothing to do. All previews now report `writes_performed` and nothing reports `dry_run`.
* Added `wp-content-bridge/get-block-tree`, a read returning a post's Gutenberg structure as a flat list of nodes carrying their tree `path`, block name, child count, and a short text label — without the document's bulk. Block attributes are opt-in through `include_attrs`.
* Added `wp-content-bridge/update-block` and its read-only mirror `preview-update-block`, which replace exactly one block subtree addressed by `path`. Everything outside that subtree is unchanged by construction rather than by validation, because the caller never sends it. An agent editing one paragraph no longer re-emits the whole document, which is what caused edits to damage untouched blocks.
* Added `wp-content-bridge/update-block-attributes`, which shallow-merges a JSON object into one block's attributes so WordPress performs the delimiter encoding; a `null` value removes a key.
* Every block write requires `expected_block_name` in addition to `version_token`. A matching token proves the document did not change; it does not prove the path points at the block the caller believes, and an off-by-one would otherwise silently replace the wrong block.
* Block markup validation is now recursive. An unregistered block nested inside another block previously passed validation, because only the top level was checked.
* Added a runtime verifier asserting that every path returned by `get-block-tree` round-trips byte-identically through `update-block`, alongside the escaping regression for the fix above.
* Expanded the closed MCP profile to 25 potential abilities.

= 0.4.5 =
* Added `wp-content-bridge/restore-trashed-content`, the missing inverse of `trash-content`. Until now an agent could perform a destructive operation it could not reverse. It is gated behind the existing `wpcb_trash_enabled` flag rather than a new one, requires `wpcb_delete_content` plus native `delete_post`, and requires the target to currently be in trash. It restores the recorded pre-trash status only when that status is `draft`, `pending`, or `private`, and falls back to `draft` in every other case — a post trashed while published comes back as a draft. Untrash can never reach `publish` or `future`; republication stays behind the publication gate.
* All four preview abilities now report `writes_performed: false`. `preview-update-service-schema` and `preview-update-custom-schema` previously reported `dry_run: true` instead — one concept under two names with opposite polarity. Both fields are present, so nothing breaks; **`dry_run` is deprecated and will be removed in 0.5.0.**
* Added an uninstall routine. The plugin previously left its options, its `wpcb_*` capabilities, and its transients behind on delete. Uninstall now removes all three, including capabilities granted directly to a user rather than through a role, and runs without the Composer autoloader so it succeeds on an install with a missing `vendor/`. The audit table is deliberately kept; see the Uninstall section.
* Release packaging no longer ships development files. The 0.4.0 artifact contained 74 files under `docs/` and `.agents/`, including the security model, known gaps, and notes about a consuming site's grants.
* Releases are now cut only by pushing a tag. The workflow previously fired on any push touching the version line, which published a 0.4.0 built from a commit that did not contain the release's own headline feature.
* `list-block-patterns` passed its first runtime verification, covering the registration gate, the authorization matrix, filesystem-path non-disclosure, the 2 MiB bound, and deterministic pagination. No defect was found.
* Recorded the Milestone 5 security sign-off with eight named evidence gaps, and added a verification run book covering all 18 runtime verifiers.
* Expanded the closed MCP profile to 21 potential abilities.

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

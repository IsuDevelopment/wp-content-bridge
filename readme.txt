=== WP Content Bridge ===
Contributors: isudev
Tags: abilities, mcp, ai, content, seo, yoast
Requires at least: 7.1
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 0.10.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Provider-neutral WordPress content and SEO abilities for MCP and other agent clients.

== Description ==

The read-only content core exposes access-aware search, content detail, provider-neutral SEO, and safe diagnostics through the WordPress Abilities API. Responses use strict schemas, native WordPress object permissions, per-post-type policy, bounded search, and an explicit content payload limit. A Yoast SEO adapter covers Yoast Free / Premium / Local 28.x.

Individual Gutenberg blocks can be read and edited by tree path, so changing one paragraph does not require rewriting the whole document.

Safe write abilities (create draft, update content, update SEO, single-block edits, optional structured Service and bounded Custom Schema, and reversible trash with a matching restore) are also available behind off-by-default feature flags, per-post-type write policy, dedicated capabilities, native object checks, and optimistic concurrency. Update content and update SEO each have a matching read-only preview ability that shares the write's exact input contract and never mutates. Schema Extended integrations additionally provide read-before-write and read-only preview abilities when their compatible public contracts are loaded. Status transitions — including publication and scheduling — run against an administrator-configured allowlist of permitted status pairs per post type, which is empty until someone configures it.

Media writes cover assigning or removing a featured image, editing an attachment's descriptive fields, and importing one image from a remote URL. Each is a separate off-by-default grant, the import screens the URL against WordPress's own host allowlist and decides the file type from the downloaded bytes rather than the URL, and it accepts raster images only.

Permalink changes report the previous and the new URL, and refuse a slug that is already taken instead of quietly altering it. Redirects are read across, and written to, either Yoast SEO Premium's Redirect Manager or the Redirection plugin through one provider-neutral port; a site running both has two live engines, so every result names which one holds a path and collision and loop checks span both.

Aggregate 404 statistics answer the other half of that question — which redirect is missing — from the Redirection plugin's own log, behind a separate off-by-default switch and its own capability. They report the requested path and a hit count only: no visitor IP, user agent, referrer, or request data ever enters the plugin, and there is no option that could include them.

Install the official WordPress MCP Adapter and every enabled ability is served as an MCP tool at `/wp-json/wpcb-mcp/mcp` — the tool set is discovered from the ability registry on each request, so there is no list to configure or maintain. The Adapter itself is optional and never bundled, and MCP transport, authentication, and OAuth remain external to this plugin.

Packaged installs can update from the plugin's GitHub release ZIP through Plugin Update Checker. Source checkouts containing `.git` do not self-update. Composer-managed or deployment-managed sites can define `WPCB_DISABLE_SELF_UPDATES` or use the `wp_content_bridge_self_updates_enabled` filter.

== Installation ==

Install a packaged release or run Composer before activating a source checkout.

== Uninstall ==

Deleting the plugin removes its options, its dedicated `wpcb_*` capabilities from every role and from any user granted them directly, and its transient caches.

The `{prefix}wpcb_audit` table is deliberately left in place. It records who changed what through the bridge — field names only, never values — as a rolling window of the most recent 5,000 mutation attempts. Destroying that record silently on delete is not the plugin's call to make. Remove the table deliberately if you want it gone.

== Changelog ==

= 0.10.0 =
* **New: `get-404-statistics`** answers which redirect is *missing*, which creating a redirect cannot. It reports the paths that returned 404 and how often, read from the Redirection plugin's own log, behind the new off-by-default `404 statistics ability` switch and the new `wpcb_read_error_statistics` capability.
* **Aggregate only, by construction.** A result carries the requested path and a hit count and nothing else. Visitor IP, user agent, referrer, and request data are never read into the plugin, never stored, never returned, and never projected to an MCP client — and no parameter exists that could include them, so no retention or redaction obligation attaches to this plugin.
* **"Nothing collects this", "the log is switched off", "you may not read it", and "no 404s happened" are four different answers and stay four different answers.** Yoast SEO Premium collects no 404 data at all, so a Yoast-only site reports the data as unavailable rather than reporting zero — which would read as a healthy site. A disabled log names the Redirection setting responsible. Every result also reports the retention window it covers, and says so explicitly when retention cut a requested range short, because pruned rows would otherwise look like 404s that stopped happening.
* Reading statistics is deliberately **not** `wpcb_manage_redirects`. Redirection separates the two in its own permission model, so "diagnose the site without authority to change its routing" is a grant you can actually express. Because the log is read directly, Redirection's own permission for it still applies — by default that means Administrator, widened through Redirection's own filter if a site wants an integration principal to read it. Nothing here enables, prunes, or resets any log.
* **`get-diagnostics` now reports redirect providers**, as `redirects.enabled` plus a `redirects.providers` list with a `detected` flag each — and it reports them whether or not the redirect abilities are switched on. Missing redirect abilities have two very different causes, the switch being off and no provider being installed, and they need opposite fixes. Diagnostics schema version moves to 1.2; the addition is backward-compatible.
* **`update-permalink` now invalidates the old URL's cache and reports what it did.** A page-cache entry for the old URL is keyed by URL, so the post-scoped invalidation every write already performs cannot reach it, and it keeps serving the old page — the rename appears not to have taken effect. The write now notifies a bounded set, exactly the old and new URL, through public single-URL purge hooks and a new `wp_content_bridge_purge_urls` action for sites to bind. The result reports which channels were notified and flags `delegated` when the actual purge depends on site-level glue; it never claims a purge it cannot observe.
* **Fixed: `wpcb_upload_media`, added in 0.9.0, could not be granted through the settings screen and survived uninstall.** It was missing from the capability list the screen renders and from the uninstall cleanup. Both now include it.
* The database schema version moves to 14, so **an active install grants the new capability only when the plugin is reactivated** — until then the statistics ability refuses, which is the correct failure direction.
* Adds `tests/Integration/error-statistics-verification.php`, a read-only runtime fixture for the statistics port. It writes no log row, changes no Redirection setting, and prunes nothing.

= 0.9.0 =
* **Action required if you chain writes: use the `version_token` a write returns, not the one you first read.** The token was blind to most of what a write changes. It hashed only `post_modified_gmt`, the title, the content and the status, so an SEO write, a Custom or Service Schema write, a featured-image change, an attachment edit, and a slug change all left it **byte-identical after succeeding**. Two agents could read the same token, both write, and the second would silently overwrite the first with no conflict raised — the one thing the token exists to prevent. It now covers the post's meta and its other mutable columns. Nothing changes for a caller that already re-read before each write; a caller that reused a stale token was relying on a defect and now gets the `409` it should always have had.
* `post_modified_gmt` could not have covered this on its own: it has one-second resolution, so a second edit inside the same second leaves it unchanged. That is why a slug change was accepted against a stale token, and it failed intermittently rather than always.
* **New: redirect abilities** — `search-redirects`, `create-redirect`, `update-redirect`, `delete-redirect`, behind the off-by-default `Redirect abilities` switch and the `wpcb_manage_redirects` capability. They work through one provider-neutral port over Yoast SEO Premium's Redirect Manager and the Redirection plugin, requiring each provider's own native permission in addition to the bridge's.
* Reads span **every** available provider and writes name their target explicitly; nothing is ever dual-written or silently substituted into a different engine. A site running both plugins has two live redirect engines serving the same paths, so every result names which provider holds a path, and collision, chain, and loop checks are cross-provider. Verified on a live site with both engines active.
* Reserved paths are refused, including this plugin's own `/llms.txt`, and a redirect cannot shadow live content. That last check had a defect during development: a redirect on the site root `/` was accepted, because `url_to_postid()` answers `0` there. Fixed and covered by a regression test.
* **New: permalink changes** — `update-permalink` changes one post's slug and returns the previous URL beside the new one, so a redirect can be created from it. A slug already in use is **refused**, not quietly turned into `slug-2` the way WordPress would; a slug that normalizes to nothing is refused rather than stored empty, which would make WordPress regenerate one from the title. It cannot change the site-wide permalink structure. Gated by a new per-type `Change permalink` policy.
* **New: media writes** — `update-featured-image` assigns or removes a featured image, `create-media` imports one image from a remote URL, and `update-media` edits an existing attachment's title, alternative text, caption, and description. Each is behind its own off-by-default switch: assigning an image the site already holds, importing a file from the internet, and editing text about a file are separate grants.
* `create-media` requires the new `wpcb_upload_media` capability plus native `upload_files`, and is deliberately **not** covered by `wpcb_edit_content`: a principal that may edit text is not thereby one that may put files on the server. The URL is screened by WordPress's own host allowlist, which refuses loopback, private, link-local and cloud-metadata addresses and re-checks every redirect target. The stored file type is decided by the downloaded bytes, not the URL or its extension, and only JPEG, PNG, GIF, WebP and AVIF are accepted — never SVG. An `idempotency_key` is required so a retried call returns the same attachment instead of importing a second copy. It creates the attachment only; it never attaches it to a post.
* `update-featured-image` refuses any attachment that is not an image or that the caller cannot read. WordPress itself accepts any attachment ID as a thumbnail — a PDF, or a private upload — and themes then render it in a public image slot.
* **Fixed: `search-content` results had no stable order.** Sorting by date, modification time, or title used no tie-break, so rows sharing that value came back in whatever order the database chose. Two posts with the same modification time is what every bulk import produces. On a single read that was untidy; on a paginated read it was a correctness bug, because a row could appear on two pages or on none. Results are now ordered deterministically. `search-content`, `get-editorial-context`, and llms.txt source selection all read through the same path and are all fixed.
* **Fixed: `get-url-seo` reported "rendered schema unavailable" without saying why.** The reader answered five different failures — a refused request, a non-200 response, an oversize page, a cross-origin URL, and a page that genuinely emits no JSON-LD — with the same empty result. A blocked loopback request is a host fault to fix; a page with no JSON-LD is a correct answer, and self-requests are exactly what a firewall or edge proxy blocks. The warning now names the cause.
* `get-custom-schema` now returns the post it describes: title, slug, permalink, status, publication and modification dates, and featured-image identity. These are the fields a JSON-LD document is built from, and their absence meant authoring a schema for one page needed a separate content read. Measured at 14 ms and under a kilobyte for the whole response.
* `get-media-by-id` now returns a `version_token`. It is the only read that issues one for an attachment, so without it `update-media` would have had no token to submit.
* `validation.context_resolved` on the Custom Schema abilities is documented as always false and **not** a failure signal — it reports that validation is source-level only, and it can accompany `valid: true`.
* Three new off-by-default options (`Redirect abilities`, `Media writes`, `Import images from a URL`), one new capability (`wpcb_upload_media`), and two new per-type policies (`Set featured image`, `Change permalink`). The database schema version moves to 13, and **an active install grants the new capability only when the plugin is reactivated** — until then abilities requiring it refuse, which is the correct failure direction.
* Adds `tests/Integration/ability-timing-probe.php`, a read-only diagnostic that times each read ability in-process so a slow transport can be told apart from slow PHP. It asserts nothing and changes nothing, so it is safe to run on a production install.

= 0.8.4 =
* **WordPress 7.1 is now the minimum supported version** (ADR 0027). The plugin declares it and contains no 6.x compatibility branches. Nothing here is optional on an older release — the exposure flag, the lifecycle hooks, and the filtering used below all arrived in 7.1.
* **Fixed: every rejection answered HTTP 500.** No ability error carried a status, so a missing post, a refused capability, and an oversized payload were all indistinguishable from a server fault over REST — agent clients retried them and monitoring read them as outages. Domain rejections now answer the status they always meant: 400 for invalid input, 403 for a refused capability, 404 for content that is missing or not visible to the caller, 409 for a concurrency or state conflict, 413 for an over-limit payload, 501 for an unavailable provider, and 500 only for an actual internal fault. **Public error codes are unchanged**, so any client matching on `code` keeps working; only the status differs.
* Missing and not-visible deliberately share 404. Which of the two it was is not disclosed, so status codes cannot be used to enumerate content a caller may not read.
* `wp-content-bridge/update-llms-txt` is now annotated `destructive` (ADR 0028), because its input is a complete configuration that replaces the stored one — a caller omitting a field loses it. Its HTTP method is unchanged: it remains non-idempotent, so it is still POST, not DELETE. No other annotation changed; the other thirty were already correct under the definition this release finally writes down.
* Abilities now declare 7.1's unified `public` exposure flag alongside the explicit `show_in_rest` they already carried. Registration metadata is built in one place, which removed thirteen near-identical per-class helpers — two of which took different single booleans under the same name.
* Added an off-by-default **invocation telemetry** diagnostic mode (ADR 0029). Enabled, it records the last 200 invocation attempts — ability name, principal, channel, outcome, timestamp, and nothing else — including the permission denials that previously left no trace anywhere. It never touches the audit table, never stores ability input, and writes once per request. It is a diagnostic, not an audit record: the hook fires before validation and authorization, so an entry proves an attempt was made, never that anything happened.
* `get-diagnostics` reports the site's minimum WordPress version and which Abilities API features it actually detected at runtime, rather than assuming them from a version number.

= 0.8.3 =
* Internal-only groundwork for the upcoming redirect-provider slice (Slice 5, ADR 0026): a provider-neutral `RedirectProvider` port, an ordered provider registry with a required null fallback, and the shared collision, reserved-prefix, live-content-shadow, and bounded chain/loop invariants every future redirect write must pass before any provider adapter runs.
* Includes a Redirection (John Godley) REST adapter, reconciled against a live 5.9.0 install rather than only its public documentation, and scoped to a dedicated `wpcb_manage_redirects` capability instead of that plugin's `manage_options` default.
* None of this is registered as an Ability, capability, or MCP tool yet. This release changes no behavior, permission, or public contract; it exists to keep the working tree's foundation code under version control before the redirect Abilities themselves ship.

= 0.8.2 =
* Replaced the hidden llms.txt migration prerequisite with a visible two-step workflow in **Settings -> WP Content Bridge**. Administrators can now create the first bridge configuration and snapshot without MCP, then archive legacy artifacts and adopt ownership.
* The initial snapshot uses only the WordPress site name, tagline, and public content types already allowed by Content Access Read policy. The form accepts no paths or content-type input and cannot widen publication through crafted POST data.
* Both actions remain visible at all times. An unavailable step is disabled with the exact missing prerequisites instead of disappearing, so operators can see how to complete the workflow before touching legacy files.

= 0.8.1 =
* Added an explicit administrator-only ownership-adoption action for sites left with physical `llms.txt`, `llms-full.txt`, or `llms-docs` artifacts by a retired generator. It renames only those exact known targets to timestamped `.backup_YYYYmmdd_His` names; it never deletes them and accepts no caller-supplied path.
* Adoption is fail-closed: a complete bridge configuration and snapshot, enabled publication, a routable pretty-permalink endpoint, disabled Yoast llms.txt generation, writable web root, expected filesystem object types, and collision-free backup names are all required before anything moves. Symlinks are rejected and a partial multi-target failure is rolled back best-effort.
* The migration exists only in **Settings -> WP Content Bridge** and additionally requires native `activate_plugins`. It is deliberately not an Ability and cannot be projected through MCP; filesystem ownership remains a local administrator operation.
* llms.txt diagnostics now report legacy companion artifacts and whether the bridge rewrite route is routable. A physical-file conflict points administrators to the adoption action instead of asking them to move files manually.
* Public endpoint verification now runs even before a bridge configuration exists, mutation results include the post-write ownership state, and the `update-llms-txt` Ability correctly declares itself non-idempotent while `regenerate-llms-txt` remains idempotent.
* WPCB does not recreate LLMagnet's proprietary `/llms-docs/` layout. The current llms.txt v2 proposal recommends Markdown alternates beside their canonical URLs; that broader public-surface feature is deferred to a separate design and security decision.

= 0.8.0 =
* **The plugin now projects its own abilities as MCP tools.** Install the official WordPress MCP Adapter and the endpoint exists at `/wp-json/wpcb-mcp/mcp`. Until this release, turning registered abilities into tools required writing a site-level MU-plugin holding a hand-maintained list of ability names — so a fresh install had no usable path to any of them without hand-written PHP.
* The tool set is **discovered from the WordPress ability registry by category on every request**, and there is no list of ability names in the plugin, its documentation, or the site. Enabling a feature area in settings adds its abilities to discovery immediately; a disabled area is absent because it was never registered. A release that adds an ability needs no site-side change.
* This fixes a silent failure the copied list caused: on an install running every feature, 11 of 31 abilities reached the client and nothing reported a problem, because a missing entry looks exactly like a disabled feature.
* `get-diagnostics` gained `mcp_projection` — the switch state, the endpoint, and the exact ability names being projected — so "the tool is missing from my client" can now be told from "the ability is not registered".
* The Adapter remains **optional, unbundled, and never installed by this plugin**. With it inactive, nothing changes: the abilities register as before and no server is created. Transport, authentication, and OAuth stay external.
* Projection is on by default and can be switched off in **Settings → WP Content Bridge → MCP projection**. The new `wp_content_bridge_mcp_abilities` filter narrows the projected set for sites that want fewer tools; it can only subtract, so no other plugin's abilities can enter this server through it.
* **Projection is not authorization, and this release widens projection only.** Every listed tool still requires its WPCB capability, the native WordPress capability, the per-post-type policy, schema validation, and the write safeguards before it does anything. An integration is read-only because of the capabilities its user holds, not because a list omitted the write tools.
* Sites running the retired `wp-content-bridge-mcp-server` MU-plugin keep working — it registers first and the plugin declines rather than creating a second server under the same ID. Delete it, then reconnect the client. The endpoint URL, server ID, and existing Application Passwords are unchanged.
* The OAuth/miniOrange endpoint is unaffected; it always discovered abilities from the registry itself, and its per-principal grant remains the gate there.

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

# Security and threat model

## Assets

- Draft, private, scheduled, and published content.
- SEO strategy, keyphrases, and unpublished metadata.
- User identities and editorial relationships.
- WordPress credentials and Application Passwords.
- Provider/connector API keys and commercial plugin licenses.
- Publication authority and site reputation.

## Trust boundaries

1. External MCP/REST client to WordPress authentication.
2. Authenticated WordPress user to ability permission callback.
3. Ability adapter to application service.
4. Application service to WordPress content and SEO provider.
5. Stored content/tool results back to a model.

## Principal model

Remote clients initially authenticate through WordPress/MCP Adapter or a
reviewed secure tunnel using a dedicated integration user. A future managed key
or OAuth grant must be bound to that WordPress principal as specified by ADR
0007; it is not an independent authorization system.

Plugin capabilities:

- `wpcb_read_content`
- `wpcb_read_media`
- `wpcb_read_patterns`
- `wpcb_edit_content`
- `wpcb_manage_seo`
- `wpcb_publish_content`
- `wpcb_delete_content`
- `wpcb_manage_llms`
- `wpcb_manage_settings`

An ability requires both its plugin capability and the native object capability.
Administrators receive management capabilities on activation. On single-site
installations, an administrator with `wpcb_manage_settings`, `promote_users`,
and per-target `edit_user` may explicitly manage the eight operational WPCB
capabilities for one dedicated, non-administrator integration user from the
plugin settings page. The surface never grants native WordPress capabilities or
`wpcb_manage_settings`, rejects unknown capability tokens, requires native
`read`, and revokes its managed grants from the previous principal when the
selection changes. Multisite/network behavior requires a separate ADR before
support is claimed.

## Threats and mitigations

### Excessive data disclosure

Mitigations: deny-by-default custom post-type policy, object-level filtering, bounded fields, no arbitrary meta/options, explicit representations, secret denylist, safe diagnostics, private-content tests.

Media uses a separate off-by-default policy and capability. Search filters
native `read_post` before pagination, returns only a fixed attachment field
allowlist, never exposes disk paths or arbitrary attachment meta, and accepts
only same-site URLs without performing a remote fetch (ADR 0011).

Block-pattern reads use their own off-by-default policy and capability plus the
same native editor-level gate as WordPress core. Metadata is allowlisted,
content is opt-in and capped, filesystem paths are discarded, and listing does
not trigger WordPress.org remote pattern downloads (ADR 0013).

### Privilege escalation

Mitigations: shared content-operation policy, required permission callbacks,
per-object `current_user_can`, separate publish/SEO capabilities, no
caller-supplied user identity, no generic action dispatcher. Content-type
configuration enables a gate but never grants a capability. The separate
integration-principal form uses a nonce plus `wpcb_manage_settings`,
`promote_users`, and per-target `edit_user`; it accepts only the closed WPCB
operational capability enum and refuses administrator principals.

### Prompt injection in stored content

Mitigations: mark content as untrusted data with provenance, keep system/policy instructions outside content payloads, avoid executing instructions found in posts, expose structured fields instead of concatenated prompts.

### Stale write/overwrite

Mitigations: expected-version token, conflict response, WordPress revisions, edit-lock awareness where practical, no implicit merge.

### Accidental publication

Mitigations: `create-draft` never publishes or schedules. The future
`transition-content-status` ability uses an explicit transition graph;
transitions to `publish` or `future` require the separate disabled-by-default
publication flag, `wpcb_publish_content`, native `publish_post`, concurrency,
and an audit event.

### Accidental permanent deletion

Mitigations: `trash-content` has a separate off-by-default flag, per-type
policy, `wpcb_delete_content`, native `delete_post`, and optimistic concurrency.
It rejects internal/already-trashed states and fails closed when WordPress trash
retention is disabled, preventing `wp_trash_post()` from falling back to
permanent deletion. Permanent deletion and restoration are not part of this
ability.

### SSRF through URL-based SEO lookup

Mitigations: require an absolute HTTP(S) URL with the exact site scheme, host,
and effective port; reject credentials, control characters, backslashes, and
path traversal; discard fragments; never fetch arbitrary external URLs from the
server. A URL resolving to a post is additionally subject to the same content
type policy and native `read_post` check as a post-ID selector.

### Resource exhaustion

Mitigations: result pages are capped at 100 items; taxonomy search is capped at
10 filters and 100 terms per filter; authorization-aware search inspects at most
1,000 candidates and marks lower-bound totals as inexact; selected content
representations are capped at a combined 2 MiB and fail without truncating raw
Gutenberg source. Relationship output is capped at 20 taxonomies and 100 terms
per taxonomy. Future Schema output receives its own explicit limit. Rate limits
belong at the authenticated projection boundary.

### Sensitive logging

Mitigations: log IDs, action, actor, result, hashes and changed field names; do not log full content, credentials, raw request headers, Application Passwords, or connector keys.

### Stolen or over-privileged connector credentials

Mitigations: bind credentials to a WordPress user, intersect scope with current
plugin/native capabilities, show secrets once, hash at rest, support expiry and
revocation, rate-limit failures, and never retain legacy plaintext tokens.

### SEO-provider internal leakage

Mitigations: normalized allowlist, resolved public output first, no raw option dump, no indexables-table dump, version compatibility tests.

Premium keyphrase writes accept bounded string lists only. The adapter owns the
version-tested positional JSON mapping, rejects Premium fields before any write
when compatible Premium is absent, preserves only allowlisted scores/synonyms,
and never accepts raw provider JSON or caller-supplied analysis scores (ADR
0014).

SEO social-image writes accept WordPress attachment IDs only. The current
principal must be able to read the object, the object must be an image
attachment, and WordPress supplies its public URL. Zero is the explicit clear
operation; arbitrary URLs and paths are never accepted. Advanced robots writes
merge three explicit booleans with the existing Yoast allowlist instead of
accepting or replacing a raw directive string (ADR 0016).

Structured Service writes are conditionally available only when the standalone
IsuDev Schema Extended plugin marker and compatible public `Meta_Fields` API are
loaded. They reuse `wpcb_manage_seo`, native `edit_post`, per-type `update_seo`
policy, optimistic concurrency, redacted audit, and post-scoped cache
invalidation. The input is a strict normalized allowlist with bounded nested
area and offer objects. Raw JSON-LD, arbitrary metadata keys, Schema node IDs,
and caller-selected provider functions are never accepted. Every value is
normalized before the first write; a later metadata failure triggers
best-effort restoration of already-written keys from their pre-write values
(ADR 0017).

Read-before-write and dry-run behavior use separate read-only
`get-service-schema` and `preview-update-service-schema` Abilities rather than a mode
flag on the destructive write. They retain `wpcb_manage_seo`, native
`edit_post`, per-type `update_seo` policy, provider compatibility, and (for
preview) optimistic concurrency. Preview shares provider sanitization with the
write but cannot reach metadata writes, mutation audit, or cache invalidation
(ADR 0019).

Custom Schema is an explicit bounded exception to the Service Ability's ban on
raw JSON-LD (ADR 0020). It is not a generic metadata surface: the bridge accepts
only `enabled` and a maximum 100,000-byte `source`, and calls only Schema
Extended's compatible public `Integration_API`. The provider limits graph size
and depth, validates JSON and Schema node shape, controls placeholders and
Yoast-owned IDs, and fails closed at render time. The bridge independently
enforces `wpcb_manage_seo`, native `edit_post`, per-type `update_seo` policy,
optimistic concurrency, strict provider-result normalization, and post-write
verification. A verification mismatch triggers a best-effort restore through
the same public provider API. Audit contains field names only; JSON source and nodes are never
stored in the audit sink. Preview is a separate read-only Ability and never
mutates. The complete context-resolved graph is read after saving through the
existing `get-url-seo` contract rather than by exposing Yoast internals or a
second arbitrary graph renderer.

### Cache invalidation abuse or stale public output

Mitigations: invalidation is triggered only by a successful internal mutation
event and derives one post ID from the authoritative result. Callers cannot
provide cache keys, action names, paths, URLs, or full-purge commands. Core and
supported cache adapters receive only that post ID; failures are contained and
reported through a redacted infrastructure event after the write has committed.

### Supply-chain and self-update safety

Packaged installs query the canonical GitHub repository through Plugin Update
Checker only during WordPress admin or cron requests. The updater is configured
to install the built `wp-content-bridge.zip` release asset, never GitHub's source
archive, because production Composer dependencies are required at runtime. A
directory containing `.git` disables registration so WordPress cannot overwrite
a source checkout. Composer/deployment-managed sites can fail closed with
`WPCB_DISABLE_SELF_UPDATES` or the
`wp_content_bridge_self_updates_enabled` filter. Release builds lock the updater
dependency, include production `vendor/`, exclude Git metadata/tests, and must
pass Composer advisory review plus artifact inventory (ADR 0018).

## The unauthenticated public surface (`/llms.txt`)

Added 2026-08-08 in `0.6.0`. **Every rule in this document above this section
assumes a capability-gated Ability behind a permission callback and a native
WordPress capability check. This route has none of that, by design.** It is the
one exception, and it is stated here rather than left to be inferred.

Boundary 1 in "Trust boundaries" does not apply: there is no authentication step
to cross. The mitigation is not authorization but a drastically reduced
capability — the route can do almost nothing.

### What the route may do

Exactly one thing: read the stored snapshot option and write those bytes. Per
ADR 0023 it must never query posts, call an SEO provider, generate, or write.
No stored snapshot means `404`; it never builds one to satisfy a request.

This is what makes the surface safe. It is not "a cheap endpoint" — it is an
endpoint whose cost is constant and independent of site size, so it cannot be
turned into an amplification vector by traffic the plugin does not control.

### The four conditions the roadmap set, and how each is met

| Condition | How |
|---|---|
| No synchronous generation on a front-end request | Generation exists only inside an authenticated Ability or a scheduled event. The request path has no code path that can generate. |
| No unbounded regeneration triggerable by public traffic | Only Abilities and cron may enqueue. A cache-busting query string changes nothing, because the handler never regenerates regardless of what it is asked. |
| Cache-header and ETag correctness under shared caches | Strong `ETag` from the snapshot's own content hash, not a timestamp; `Last-Modified` from generation time; bounded `Cache-Control: public`. `Vary: Cookie` is deliberately absent because the response is identical for every requester — see ADR 0023, and revisit it if that ever stops being true. |
| Unpublished, private, password-protected and `noindex` content cannot reach the artifact | Enforced in `WordPressLlmsSourceSelector` at generation time: `publish` only, no `post_password`, a public non-attachment type re-checked against WordPress rather than trusted from configuration, and not `noindex` per the SEO provider port. Verified on a live install, 5 of 5. |

### Off by default, and invisible when off

`wpcb_llms_enabled` defaults to false. While it is off the rewrite rule is not
registered and the path answers `404` — indistinguishable from a path that was
never claimed. An install that does not use the feature gains no new surface at
all.

### Residual risks, accepted and named

**Eventual consistency.** The artifact is a snapshot, so content that leaves
public view remains in it until regeneration runs. Transitions out of an
eligible state enqueue a debounced regeneration, but the window is real and
non-zero. It is bounded by the debounce interval; debouncing must never be
allowed to make it unbounded.

**Ownership conflict.** A physical `/llms.txt` at the web root is served by the
web server before WordPress runs, so the bridge's artifact silently stops being
what the public sees. The plugin detects this and refuses to claim its artifact
is public. Detection reports existence as booleans and **never returns a
filesystem path** in an Ability field.

An explicit wp-admin-only adoption action can archive the exact known
`llms.txt`, `llms-full.txt`, and `llms-docs` targets under one timestamped
`.backup_YYYYmmdd_His` suffix. It is recovery from a retired generator, not
normal publication: it accepts no path, rejects symlinks and unexpected object
types, refuses collisions and multisite, attempts rollback after a partial
failure, and requires a ready bridge snapshot and route plus
`wpcb_manage_settings` and native `activate_plugins`. It is not registered as
an Ability and therefore cannot be reached through MCP. No code path deletes a
legacy artifact.

**Legacy companion output.** `llms-full.txt` and `llms-docs` do not shadow the
bridge route, but leaving them publicly reachable preserves stale content after
LLMagnet is disabled. Diagnostics expose only their presence. Adoption archives
them together with the root file so ownership recovery does not leave the old
publication surface behind.

**Generated content is untrusted.** Titles and excerpts come from site content
and land in a document written to be read by language models. They are emitted
as literal Markdown and must never read as instructions; a title cannot break
out of its link to another origin. This is boundary 5 in "Trust boundaries",
reached without any authentication in front of it.

## Audit events

Read events are observable but persistence may be configurable to control volume. Mutation attempts and results are always emitted with:

- event ID and timestamp;
- user/principal ID;
- ability ID;
- target object ID/type;
- changed field names, not secret/full values;
- expected/resulting version;
- outcome and stable error code;
- client/projection metadata when safely available.

## Security release gates

- No write milestone begins without object-capability tests and conflict tests.
- No remote production setup documentation uses a shared administrator account.
- No Premium/Local support claim is made from reverse engineering alone.
- Static analysis, coding standards, unit tests, integration tests, and a manual authorization matrix pass before release.
- `composer audit --locked` reports no known dependency advisories, and the
  release ZIP contains the updater runtime but no `.git` metadata or dev-only
  packages.
- A plugin-owned OAuth server or managed-key system requires its own threat
  review and cannot be introduced as part of a content ability change.

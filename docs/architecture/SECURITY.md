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
- `wpcb_manage_settings`

An ability requires both its plugin capability and the native object capability.
Administrators receive management capabilities on activation. On single-site
installations, an administrator with `wpcb_manage_settings`, `promote_users`,
and per-target `edit_user` may explicitly manage the seven operational WPCB
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
`get-service-schema` and `preview-service-schema` Abilities rather than a mode
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

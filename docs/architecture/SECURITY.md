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
- `wpcb_manage_settings`

An ability requires both its plugin capability and the native object capability.
Administrators receive management capabilities on activation. On single-site
installations, an administrator with `wpcb_manage_settings`, `promote_users`,
and per-target `edit_user` may explicitly manage the six operational WPCB
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

Mitigations: create-draft never publishes, publication is a separate disabled-by-default ability and capability, future approval envelope, audit event.

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

### Cache invalidation abuse or stale public output

Mitigations: invalidation is triggered only by a successful internal mutation
event and derives one post ID from the authoritative result. Callers cannot
provide cache keys, action names, paths, URLs, or full-purge commands. Core and
supported cache adapters receive only that post ID; failures are contained and
reported through a redacted infrastructure event after the write has committed.

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
- A plugin-owned OAuth server or managed-key system requires its own threat
  review and cannot be introduced as part of a content ability change.

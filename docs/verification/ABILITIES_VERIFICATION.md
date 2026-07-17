# WP Content Bridge Abilities verification

## Status: WARN (non-blocking vocabulary advisory)

Verified on 2026-07-17 against WordPress 7.0.1 and PHP 8.4.6. Runtime,
authorization, schema, REST, annotation, and quality gates pass. The WARN status
records that the plugin intentionally uses several stable, security-oriented
domain error codes outside the verifier's preferred `plugin_invalid_<field>` /
`plugin_<resource>_data_unavailable` vocabulary. This does not block Milestone
1B, but error names must be reviewed before a 1.0 contract freeze.

## Scope and inventory

Static source: `src/Adapter/Abilities/ContentAbilities.php`.

Runtime and static inventories agree on exactly three owned Abilities:

- `wp-content-bridge/search-content`
- `wp-content-bridge/get-content`
- `wp-content-bridge/get-diagnostics`

All belong to `wp-content-bridge`, have explicit permission and execution
callbacks, and set `show_in_rest` to `true`.

## Annotation correctness

Every Ability reports:

```json
{
  "readonly": true,
  "destructive": false,
  "idempotent": true
}
```

Static review of the callback path found no option, post, post-meta, taxonomy,
or event-hook mutation. Runtime twin invocations returned identical SHA-256
hashes for search, detail, and diagnostics. The callbacks read WordPress state,
so identical hashes assume unchanged state between calls, which is the intended
meaning of idempotence here.

## Permission verification

The plugin gate is `current_user_can( 'wpcb_read_content' )`. The runtime verifier
confirmed anonymous denial and administrator access for all three Abilities.

The isolated authorization matrix additionally covered:

- anonymous and subscriber denial;
- owning and non-owning authors;
- editor and administrator;
- a subscriber-based integration principal granted only
  `wpcb_read_content` plus its native WordPress rights;
- published, own/foreign draft, own/foreign private, page, and opted-in public
  CPT objects;
- independent post-type policy denial even for an administrator;
- non-enumerating detail denial;
- search IDs and totals after native per-object authorization.

Fixtures are deleted by exact IDs and the prior policy option is restored in a
`finally` block.

## Schema verification

- Search and detail inputs are strict objects with
  `additionalProperties: false`.
- Search supports a real empty-object default for omitted input.
- All required input fields, including nested taxonomy fields, have non-empty
  descriptions.
- Pagination exposes `total_is_exact`, `has_more`, and
  `candidate_scan_limit` so a bounded scan cannot masquerade as an exact count.
- Detail output requires per-representation and total byte measurements.
- WordPress runtime input and output validation passed during direct and REST
  execution.

No input schema is necessary for diagnostics because it accepts no input.

## REST projection

Authenticated REST discovery at `/wp-abilities/v1/abilities` returned the same
three Ability names with HTTP 200. A GET execution of
`wp-content-bridge/get-content` through the core Abilities run controller also
returned HTTP 200. Anonymous Ability execution remains denied by the Ability
permission callback.

This verifies the WordPress REST projection only. It does not claim that an MCP
Adapter or any external client is connected; that work belongs to Milestone 4.

## Error vocabulary

Current adapter-owned codes are:

- `wpcb_invalid_input`
- `wpcb_content_unavailable`
- `wpcb_content_too_large`
- `wpcb_forbidden`
- `wpcb_internal_error`

The runtime contract locks `ability_invalid_input` for core schema rejection,
`wpcb_invalid_input` for a schema-valid but unsupported taxonomy/type query, and
`wpcb_content_unavailable` for a missing/unreadable detail selector. The
authorization harness separately locks `wpcb_content_too_large` at the payload
boundary.

`wpcb_content_unavailable` deliberately merges missing, policy-disabled, and
native-capability denial to prevent object enumeration. The other custom codes
are stable and namespaced, but not all follow the verifier's preferred
field/resource-specific vocabulary. This is the sole reason for WARN. Any
renaming is a public contract change and should happen before 1.0, with fixture
updates in the same change.

## Resource and disclosure verification

- Search authorizes every candidate before pagination; inaccessible object
  counts are never copied from `WP_Query::found_posts`.
- Candidate inspection is capped at 1,000. A capped response marks totals
  inexact and reports possible continuation.
- A 500-block fixture measured 47,000 raw, 39,000 rendered, and 13,500
  plain-text bytes: 99,500 combined representation bytes and 103,898 encoded
  response bytes.
- Selected representations above 2,097,152 bytes return
  `wpcb_content_too_large`; raw Gutenberg source is never silently truncated.
- Raw Gutenberg content remained byte-for-byte authoritative, plain text was
  normalized, and an arbitrary secret fixture meta key/value did not appear in
  the encoded response.

## Reproduction

From the standalone plugin repository:

```bash
composer check
```

From a WordPress root where the plugin is active:

```bash
wp eval 'require "/absolute/path/to/wp-content-bridge/tests/Integration/authorization-matrix.php";'
wp eval 'require "/absolute/path/to/wp-content-bridge/tests/Integration/abilities-runtime-verification.php";'
```

Latest quality result: PHPCS pass, PHPStan pass, and PHPUnit 35 tests / 85
assertions pass. The local WP-CLI 2.11.0 process emits dependency deprecation
messages on PHP 8.4; no message originates from WP Content Bridge and both
plugin verifiers return PASS.

## Decision

Milestone 1B is complete. Begin Milestone 2 at the provider-neutral SEO domain
contract and null-provider boundary. Do not attach Yoast meta directly to the
existing Ability callbacks.

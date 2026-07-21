# Test plan

## Automated layers

### Unit

- Content-type operation defaults, dependency normalization, and untrusted checkbox values.
- DTO validation and serialization.
- Query normalization and bounds.
- Plain-text conversion.
- SEO normalization and provenance.
- Same-site URL validation.
- Concurrency-token comparison.
- Error mapping and audit redaction.
- Editorial-context selection/default normalization, section bounds, exact
  requested type/taxonomy behavior, and provider degradation.
- Media selector exclusivity, strict object envelopes, deterministic identity,
  policy ordering, and featured-image ID+URL pair invariants.
- Pattern query bounds, metadata-only defaults, strict item/envelope shapes,
  content byte accounting, and feature/native-access ordering.

### WordPress integration

- Eligible/excluded post-type discovery and access-option sanitization.
- Settings menu registration, nonce/Settings API path, and `wpcb_manage_settings` enforcement.
- Integration-user capability assignment requires `wpcb_manage_settings`,
  `promote_users`, a valid nonce, and per-target `edit_user`; unknown
  capabilities are rejected, native `read` is a prerequisite, switching the
  managed principal revokes only WPCB capabilities from the previous user, and
  multisite remains unsupported.
- Plugin bootstrap and hooks.
- Ability category/registration lifecycle.
- Input/output schema validation.
- Permission callbacks and object visibility.
- WP_Query behavior, revisions, taxonomies, media, CPTs.
- Yoast adapter integration where fixtures are available.
- Editorial taxonomy/term vocabulary, observed-author projection, configured
  READ+SEARCH denial, and published/readable recent inventory.
- Media registration flag, dedicated capability, native per-attachment denial,
  exact ID/URL/filename lookup, normalized fields, and featured-image identity.
- Pattern registration flag, dedicated capability, native editor-level denial,
  deterministic filters/pagination, content opt-in, and remote/path leakage
  rejection.

Repeatable Kormas local media verification:

```bash
cd "/Users/lukaszbiedron/Local Sites/kormas-isu/app"
wp eval 'require "/Users/lukaszbiedron/Other Projects/wp-content-bridge/tests/Integration/media-read-verification.php";'
wp eval 'require "/Users/lukaszbiedron/Other Projects/wp-content-bridge/tests/Integration/block-patterns-verification.php";'
```

### Contract

- Snapshot/fixture tests for every ability definition.
- Stable machine error codes.
- Schema version behavior.
- MCP discovery and execution envelopes through the official adapter.
- Client-agnostic MCP smoke check: session-based `initialize` →
  `notifications/initialized` → `tools/list` (asserts exactly the five
  hyphenated tool names) → `tools/call` for each of the five read abilities
  with a minimal valid input, executed as the least-privilege bridge-reader
  user via a disposable Application Password that is deleted on exit (even on
  failure, via a shell trap). Repeatable command:

```bash
WPCB_SITE_URL=https://kormas-isu.local \
WPCB_WP_ROOT="/Users/lukaszbiedron/Local Sites/kormas-isu/app/public" \
WPCB_MCP_PATH="/wp-json/wpcb-mcp/mcp" \
"/Users/lukaszbiedron/Other Projects/wp-content-bridge/tests/Integration/mcp-smoke-verification.sh"
```

  This targets the official `WordPress/mcp-adapter` App-Password endpoint
  (`docs/setup/MCP_ADAPTER.md`), not the miniOrange OAuth endpoint ChatGPT
  uses (`/wp-json/mosmcp/v1/mcp`, `docs/setup/CHATGPT_CONNECTOR.md`) — the two
  endpoints project the same five abilities but are deliberately kept
  distinct in setup and verification.

### End-to-end

- Install release ZIP on a clean WordPress instance.
- Activate with/without MCP Adapter and Yoast.
- Connect a client, discover abilities, search, read, retrieve SEO.
- Later create/update a draft and verify revision/audit behavior.

## Environment matrix

| Dimension | Initial values |
|---|---|
| WordPress | 7.0 latest patch; latest supported 7.x |
| PHP | 8.2, 8.3, 8.4 |
| Site | single site; multisite unsupported until ADR |
| SEO | none; Yoast Free; Free+Premium; Free+Local; Free+Premium+Local |
| MCP | absent; official adapter current pinned release; miniOrange Secure MCP Connector (OAuth-fronted) for ChatGPT |
| Client | **ChatGPT — verified Phase 1 client** (miniOrange OAuth 2.1, DCR+PKCE, `docs/setup/CHATGPT_CONNECTOR.md`); Codex/Gemini CLI — secondary/deferred, covered only by the client-agnostic smoke suite |

## Authorization matrix

For each ability test:

- unauthenticated user;
- subscriber;
- author owning/not owning object;
- editor;
- administrator;
- dedicated integration user with selected WPCB and native capabilities;
- revoked Application Password/session.

The MCP contract layer's dedicated integration user is the least-privilege
**bridge-reader fixture** (`tests/Integration/bridge-reader-fixture.php`):
user `wpcb-bridge-reader` locked to exactly the `read` and
`wpcb_read_content` capabilities (idempotent setup/teardown via
`WPCB_BRIDGE_MODE=setup|teardown`), used both by the smoke suite's disposable
Application Password and as the intended shipped identity a ChatGPT OAuth
grant should be bound to.

For Milestone 1B, exercise every principal against:

| Object | Expected distinction |
|---|---|
| Published post/page | Plugin capability plus `read_post` and enabled policy |
| Owning author's draft | Ownership and native draft readability |
| Another author's draft | No access unless native role capability permits it |
| Private post/page | `read_private_posts`/object capability required |
| Opted-in public CPT | Same three authorization gates as built-in types |
| Policy-disabled CPT | Denied even to administrator |
| Missing object | Same public error shape as unreadable object |

Search assertions include both returned IDs and pagination totals so an
unreadable object cannot be inferred from counts.

## Milestone 1B contract fixtures

- ability IDs, category, labels, descriptions, annotations, and REST exposure;
- strict input/output schemas and application-side default normalization;
- stable `wpcb_invalid_input`, `wpcb_content_unavailable`, `wpcb_forbidden`, and
  `wpcb_internal_error` mappings where the adapter owns the failure, plus
  `wpcb_content_too_large` for the explicit detail boundary;
- raw Gutenberg source remains byte-for-byte authoritative;
- rendered content follows the WordPress content filter pipeline;
- plain text is normalized without exposing arbitrary post meta;
- taxonomy filters are bounded and reject unregistered/unrelated taxonomies;
- raw/rendered/plain-text payload byte counts are recorded for representative
  long and block-heavy fixtures.

The repeatable local commands are:

```bash
wp eval 'require "/absolute/path/to/wp-content-bridge/tests/Integration/authorization-matrix.php";'
wp eval 'require "/absolute/path/to/wp-content-bridge/tests/Integration/abilities-runtime-verification.php";'
wp eval 'require "/absolute/path/to/wp-content-bridge/tests/Integration/integration-access-verification.php";'
wp eval 'require "/absolute/path/to/wp-content-bridge/tests/Integration/cache-invalidation-verification.php";'
```

The cache verifier creates one disposable draft, proves a successful mutation
dispatches only post-scoped WordPress/LiteSpeed invalidation, proves a denied
event does not purge, contains a synthetic adapter exception, and deletes the
fixture.

The 2026-07-17 WordPress 7.0.1 run measured a 500-block fixture at 47,000 raw,
39,000 rendered, and 13,500 plain-text bytes (99,500 combined; 103,898 bytes for
the complete encoded response). A raw representation over 2 MiB returned
`wpcb_content_too_large`.

## SEO fixtures

Include examples for:

- explicit and inherited title/description;
- custom canonical and robots;
- social overrides and images;
- focus keyphrase and missing analysis;
- Premium primary/additional keyphrase roles, bounded scores, normalized
  primary/related synonyms, malformed JSON, duplicate removal, and
  arbitrary-member leakage;
- indexable missing/stale;
- Schema Article/WebPage/Organization;
- LocalBusiness, location page, address references, multiple-location branch
  relationships, hours, geo, and nested arbitrary-member leakage.

Repeatable Yoast Free/Premium 28.0 + Local 15.8 verification commands on Kormas:

```bash
wp eval 'require "/Users/lukaszbiedron/Other Projects/wp-content-bridge/tests/Integration/yoast-configured-runtime-verification.php";'
wp eval 'require "/Users/lukaszbiedron/Other Projects/wp-content-bridge/tests/Integration/writes-seo-verification.php";'

WPCB_SITE_URL=https://kormas-isu.local \
WPCB_WP_ROOT="/Users/lukaszbiedron/Local Sites/kormas-isu/app/public" \
"/Users/lukaszbiedron/Other Projects/wp-content-bridge/tests/Integration/http-url-runtime-verification.sh"

WPCB_SITE_URL=https://kormas-isu.local \
WPCB_WP_ROOT="/Users/lukaszbiedron/Local Sites/kormas-isu/app/public" \
"/Users/lukaszbiedron/Other Projects/wp-content-bridge/tests/Integration/local-multilocation-runtime-verification.sh"
```

The HTTP verifier creates a disposable subscriber, grants only
`wpcb_read_content`, creates an Application Password, verifies the public REST
projection, and deletes the user on exit. The configured-value verifier creates
and deletes one exact post fixture in a `finally` block. The multiple-location
verifier provisions a licensed primary+branch fixture, disables `sslverify` for
the local self-signed loopback via a temporary mu-plugin, and restores the exact
prior Local configuration on exit.

Verified on 2026-07-17: single-location runtime output reports both licensed
modules and safe versions, Premium additional keyphrases, a non-empty public
Local profile, public-head parity, and no arbitrary test marker leakage. The
multiple-location matrix (primary + branch) also passes: branch identity via
`parentOrganization`, branch address/geo/hours through rendered-schema capture
(ADR 0009), bounds, and private-option (`local_api_key`/`googlemaps_api_key`)
leakage rejection.

## Editorial-context fixtures

- all six sections and individual section selection;
- maximum 20 post types/taxonomies, 50 recent summaries, and 100 terms per
  taxonomy;
- explicit rejection of unavailable requested post types and taxonomies;
- configured READ or SEARCH disabled independently;
- recent results restricted to published objects readable by the principal;
- authors limited to IDs observed in readable recent results and keys limited to
  `id`/`display_name`;
- public/REST-visible taxonomy filtering and term truncation flags;
- normalized Local public profile present, unavailable, and provider-failure
  states;
- no arbitrary post meta, user email/login, Local options, license keys, or
  injected marker leakage;
- Ability annotations, deterministic twin calls, REST discovery/execution, and
  real-HTTPS least-privilege execution.

Verified on 2026-07-17 against WordPress 7.0.1 with Yoast Free 28.0, Premium
28.0, and Local 15.8 in the single-location fixture. Evidence is recorded in
`docs/verification/EDITORIAL_CONTEXT.md`.

## Security tests

- Cross-site URL attempts and encoded host confusion.
- Prompt-injection strings preserved as data and never interpreted by the plugin.
- Private content enumeration.
- Arbitrary meta/option requests rejected.
- Oversized query/schema/content requests bounded.
- Concurrent stale writes rejected.
- Capability revocation effective immediately.
- Logs contain no secrets or full private content.

## Manual release checklist

- Install packaged ZIP without development dependencies.
- No PHP warnings/notices with debug enabled.
- Site Health/diagnostics contain no secrets.
- Deactivation is non-destructive.
- Uninstall behavior matches documented retention setting.
- Client setup examples use a dedicated least-privilege user.

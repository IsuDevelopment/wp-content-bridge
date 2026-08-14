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
- GitHub updater policy: packaged admin and cron requests allowed; front-end,
  source checkout, missing dependency, constant opt-out, and filter opt-out
  denied.

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
- Trash registration requires both write and trash flags; execution requires
  per-type Read+Trash policy, `wpcb_delete_content`, native `delete_post`, a
  current version token, supported reversible trash, and a valid source state.
- Trash verification proves the object reaches `trash`, content is retained,
  audit stores field names only, cache invalidation receives the exact post ID,
  and stale/denied attempts do not mutate.

Repeatable Kormas local media verification:

```bash
cd "/Users/lukaszbiedron/Local Sites/kormas-isu/app"
wp eval 'require "/Users/lukaszbiedron/Other Projects/wp-content-bridge/tests/Integration/media-read-verification.php";'
wp eval 'require "/Users/lukaszbiedron/Other Projects/wp-content-bridge/tests/Integration/block-patterns-verification.php";'
wp eval 'require "/Users/lukaszbiedron/Other Projects/wp-content-bridge/tests/Integration/trash-content-verification.php";'
```

### Contract

- Snapshot/fixture tests for every ability definition.
- Stable machine error codes.
- Schema version behavior.
- MCP discovery and execution envelopes through the official adapter.
- Client-agnostic MCP smoke check: session-based `initialize` →
  `notifications/initialized` → `tools/list` (asserts the profile supplied in
  `WPCB_EXPECTED_TOOLS` and known required input fields on the raw MCP
  descriptors) → `tools/call` for the five safe baseline reads with
  minimal valid input. Write/destructive tools are discovery-tested but never
  executed by this smoke script. Authentication uses a disposable Application
  Password that is deleted on exit, including failure. Repeatable command:

```bash
WPCB_SITE_URL=https://kormas-isu.local \
WPCB_WP_ROOT="/Users/lukaszbiedron/Local Sites/kormas-isu/app/public" \
WPCB_MCP_PATH="/wp-json/wpcb-mcp/mcp" \
WPCB_EXPECTED_TOOLS=search-content,get-content,get-url-seo,get-editorial-context,get-diagnostics,get-media,get-media-by-id,list-block-patterns,create-draft,update-content,update-seo,get-service-schema,preview-update-service-schema,update-service-schema,trash-content \
"/Users/lukaszbiedron/Other Projects/wp-content-bridge/tests/Integration/mcp-smoke-verification.sh"
```

  This targets the official `WordPress/mcp-adapter` App-Password endpoint
  (`docs/setup/MCP_ADAPTER.md`), not the miniOrange OAuth endpoint ChatGPT
  uses (`/wp-json/mosmcp/v1/mcp`, `docs/setup/CHATGPT_CONNECTOR.md`) — the two
  endpoints have separate projection/grant configuration and are deliberately
  kept distinct in setup and verification.

### End-to-end

- Install release ZIP on a clean WordPress instance.
- Verify the release ZIP contains production `vendor/`, excludes `.git`, and
  exposes the GitHub release update only from the uploaded ZIP asset.
- Verify a Git checkout and a site defining `WPCB_DISABLE_SELF_UPDATES` expose
  no WP Content Bridge update checker.
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
- advanced robots merge/removal without collateral directive loss;
- social overrides and images, including attachment-ID authorization, paired
  URL/ID persistence, explicit clearing, and fail-before-write behavior;
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

## Structured Service configuration matrix

- Schema Extended inactive: none of the three Service-schema abilities is registered and they are
  absent from MCP discovery even when global writes are enabled;
- Schema Extended active but global writes disabled: still absent;
- active provider and writes enabled: strict input/output schemas, complete
  annotations, and MCP-public metadata are present;
- `wpcb_manage_seo`, native `edit_post`, and per-type `update_seo` policy each
  deny independently;
- stale `version_token` rejects before the provider write;
- `get-service-schema` returns independently saved configuration and a current
  token before any change;
- `preview-update-service-schema` returns current plus provider-sanitized prospective
  configuration with `dry_run: true` and performs no metadata, audit, revision,
  or cache mutation;
- raw MCP descriptors mark `post_id` required for get, and both `post_id` plus
  `version_token` required for preview/update;
- unsupported provider post type returns
  `wpcb_service_schema_unavailable`;
- typed City/AdministrativeArea/Country values, brands, catalog name, and
  offers round-trip through `effective_service_schema`;
- omissions preserve existing values, while empty strings/arrays clear only
  their named fields;
- arbitrary keys, raw JSON-LD, duplicate areas/brands/offers, invalid types,
  and values over bounds reject atomically;
- an injected failure after one metadata update restores keys changed earlier
  in the same request;
- audit contains field names only and success invalidates only the target post;
- public `get-url-seo` Schema contains the expected `Service`, `areaServed`, and
  `hasOfferCatalog` nodes after WordPress runtime rendering.

Unit coverage includes the inactive-provider gate, DTO bounds, use-case policy,
concurrency, provider support, audit classification, and public schema contract.
The full static/unit baseline on 2026-08-03 is 214 tests / 532 assertions; the
WordPress runtime matrix remains a release check on a site with Schema Extended
active.

## Custom Schema configuration matrix

- Schema Extended inactive, pre-0.3, incompatible contract, or global writes
  disabled: all three Custom Schema abilities are absent from WordPress and MCP
  discovery;
- raw MCP descriptors require `post_id` for get and `post_id` plus
  `version_token` for preview/update;
- `wpcb_manage_seo`, native `edit_post`, and per-type `update_seo` policy deny
  independently;
- stale tokens reject before provider validation or persistence;
- preview returns current and prospective configurations with `dry_run: true`
  and performs no metadata, audit, revision, or cache mutation;
- valid single-node, node-array, and `@graph` sources round-trip through
  `effective_custom_schema` within the 100,000-byte, 20-node, and depth bounds;
- malformed JSON, unknown placeholders, nested contexts, duplicate IDs, and
  oversized/deep graphs return bounded diagnostics;
- invalid source with `enabled: true` rejects with
  `wpcb_invalid_custom_schema`; disabled invalid source remains editable but is
  not render eligible;
- unsupported posts and provider drift fail closed with
  `wpcb_custom_schema_unavailable`;
- arbitrary input keys never become meta writes, and audit contains only
  `enabled`/`source` field names, never JSON;
- after a successful update, `get-url-seo` returns the complete resolved Yoast
  graph with the expected custom nodes and no duplicate JSON-LD script.

The full static/unit baseline after adding this matrix is 234 tests / 596
assertions with PHPCS and maximum-level PHPStan clean. The final provider-active
WordPress runtime checks remain pending.

## Redirect provider fixtures (planned, ADR 0026)

Nothing below is active yet — no Ability, capability, or MCP entry exists for
Slice 5. Recorded here so the obligation is not lost before implementation
reaches it.

- **Done manually, needs a permanent fixture:** Redirection's REST payload
  shapes were reconciled against a live 5.9.0 install on 2026-08-14 by
  activating it on Kormas Local and driving `RedirectionProvider` through a
  throwaway REST route as a `wpcb_manage_redirects`-only principal; four
  defects were found and fixed (see `.agents/status.md`). The site was
  restored to its original inactive, table-free state afterward. This was a
  one-off manual pass, not a repeatable check — a `tests/Integration` script
  covering `is_available()`/`search()`/`create()` end-to-end still needs to be
  added before this adapter is trusted on a second install or a future
  Redirection version;
- Yoast SEO Premium's adapter needs a version-pinned compatibility fixture
  before it registers at all, since `WPSEO_Redirect`/`WPSEO_Redirect_Option`/
  `WPSEO_Redirect_Manager` are undocumented and carry no deprecation policy;
- `RedirectCandidateGuard`'s five invariants (reserved prefix, live-content
  shadow, collision, chain/loop bound, post-write canonical verification) need
  a runtime fixture per provider, not just the unit tests against fakes that
  exist today;
- the scoped `redirection_role`/`redirection_capability_check` filter pair
  needs a runtime assertion that WPCB's capability is granted only for the
  duration of one call and never leaks into an unrelated Redirection request
  in the same process.

## Security tests

- Cross-site URL attempts and encoded host confusion.
- Prompt-injection strings preserved as data and never interpreted by the plugin.
- Private content enumeration.
- Arbitrary meta/option requests rejected.
- Oversized query/schema/content requests bounded.
- Concurrent stale writes rejected.
- Trash cannot fall back to permanent deletion when WordPress trash retention
  is disabled.
- Capability revocation effective immediately.
- Logs contain no secrets or full private content.

## Manual release checklist

- Install packaged ZIP without development dependencies.
- No PHP warnings/notices with debug enabled.
- Site Health/diagnostics contain no secrets.
- Deactivation is non-destructive.
- Uninstall behavior matches documented retention setting.
- Client setup examples use a dedicated least-privilege user.
- `composer audit --locked` is clean.
- GitHub release contains `wp-content-bridge.zip`; updater metadata resolves that
  asset rather than the repository source archive.

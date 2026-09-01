# Runtime verification

Static analysis cannot sign off this plugin. Every gate that matters —
capabilities, per-post-type policy, optimistic concurrency, audit redaction,
cache invalidation, the trash status floor, provider graph output — is behaviour
against a live WordPress. `composer check` proves none of it.

Between 2026-07-21 and 2026-08-07 nobody ran the runtime inventory and releases
0.1.3 through 0.3.0 shipped on static checks alone. The cause was not a missing
environment; the environment was there the whole time. The cause was that no
document said what the inventory *was*, so "verified" had no definition and
skipping it left no trace. This file is that definition.

## The environment

| | |
|---|---|
| Site | LocalWP `kormas-isu` |
| WordPress root | `/Users/lukaszbiedron/Local Sites/kormas-isu/app/public` |
| Site URL | `https://kormas-isu.local` |
| WordPress / PHP | 7.1 / 8.4.6 |
| Providers | Yoast SEO Free 28.2, Yoast Local 15.8, IsuDev Schema Extended 0.3.0 |

The plugin directory is a symlink to this repository, so the working tree is
what runs — no copy step, and an uncommitted change is verified as written:

```
content/plugins/wp-content-bridge -> /Users/lukaszbiedron/Other Projects/wp-content-bridge
```

The Abilities API is WordPress core as of 7.0; no feature plugin is involved.
Since ADR 0027 the plugin requires 7.1, so this environment must stay on 7.1 or
later — a run on 7.0 verifies a configuration the plugin no longer supports.

### Why one machine

Yoast Premium/Local are licensed and IsuDev Schema Extended is private. Neither
can be committed here, so the SEO and Schema surfaces — a third of the
inventory — cannot be verified on a clean checkout by anyone. A container would
reproduce the WordPress-core half and silently leave the rest uncovered, which
is a worse failure mode than a documented single environment: it would look
like coverage.

The mitigation is this inventory plus the release gate that requires it, not a
second environment.

## Running the inventory

```bash
cd "/Users/lukaszbiedron/Local Sites/kormas-isu/app/public"
IT="/Users/lukaszbiedron/Other Projects/wp-content-bridge/tests/Integration"
```

Every PHP verifier is repeatable, creates its own fixtures, restores every
option it touches in a `finally` block, and **exits non-zero on failure**. Trust
the exit code; most also print a JSON or `PASS:` summary, but three print an
evidence dump only (marked below).

```bash
for v in abilities-runtime-verification \
         authorization-matrix \
         integration-access-verification \
         block-patterns-verification \
         media-read-verification \
         cache-invalidation-verification \
         writes-foundation-verification \
         writes-mutation-verification \
         trash-content-verification \
         restore-trashed-content-verification \
         block-edits-verification \
         preview-verification \
         yoast-configured-runtime-verification \
         writes-seo-verification \
         schema-service-verification \
         schema-custom-verification \
         llms-txt-verification \
         status-workflow-verification \
         status-matrix-bulk-verification \
         rest-input-coercion-verification \
         invocation-telemetry-verification; do
  wp eval "require \"$IT/$v.php\";" >/dev/null 2>&1 \
    && echo "PASS $v" || echo "FAIL $v"
done
```

The three shell verifiers exercise real HTTP and the MCP transport, so they run
from the repository root rather than the WordPress root:

```bash
cd "/Users/lukaszbiedron/Other Projects/wp-content-bridge"
export WPCB_SITE_URL="https://kormas-isu.local"
export WPCB_WP_ROOT="/Users/lukaszbiedron/Local Sites/kormas-isu/app/public"

bash tests/Integration/http-url-runtime-verification.sh
bash tests/Integration/mcp-smoke-verification.sh
bash tests/Integration/local-multilocation-runtime-verification.sh
```

## Inventory

`Needs` records the hardest dependency, which is what determines whether a
given machine can run the check at all:
**core** — WordPress only ·
**yoast** — licensed Yoast SEO ·
**schema** — private IsuDev Schema Extended ·
**http** — a resolvable site URL and host `wp-cli` ·
**mcp** — the MCP Adapter stack on the consuming site.

| Verifier | Needs | What it proves |
|---|---|---|
| `abilities-runtime-verification.php` ¹ | core | Discovery, schemas, permission callbacks, direct execution, REST projection, closed-profile guard, MCP projection parity (ADR 0025) |
| `authorization-matrix.php` ¹ | core | Per-post-type read policy against native object permissions |
| `integration-access-verification.php` | core | Managed integration-user capability grant and revoke |
| `block-patterns-verification.php` | core | Pattern-read gating, that filesystem paths never appear in a response (ADR 0013), the 2 MiB bound, deterministic pagination |
| `media-read-verification.php` | core | Media read surface, identity lookup, normalized fields, anonymous denial |
| `cache-invalidation-verification.php` | core | Post-scoped invalidation after a mutation (ADR 0012) |
| `writes-foundation-verification.php` | core | Capabilities, flags default-off, audit table schema, upgrade path |
| `writes-mutation-verification.php` | core | `create-draft` / `update-content` authorization matrix, idempotency, concurrency, audit, write invariants |
| `trash-content-verification.php` | core | Reversible trash, gating, concurrency, audit |
| `restore-trashed-content-verification.php` | core | Untrash, and that no input restores to `publish` or `future` |
| `block-edits-verification.php` | core | `get-block-tree` path addressing round-trips byte-identically through `update-block`, single-block edits leave every other block untouched, recursive nested-block validation, `preview-update-block` purity, `update-block-attributes` merge/removal semantics, and the attribute-escaping regression |
| `preview-verification.php` | core / yoast ² | Both previews are deterministic, mutate nothing, match the subsequent write, and reject stale tokens |
| `yoast-configured-runtime-verification.php` ¹ | yoast | Configured-value reads and partial-result behaviour |
| `writes-seo-verification.php` | yoast | `update-seo` authorization matrix, conflict, Free/Premium write and re-read parity, audit |
| `schema-service-verification.php` | schema | Service / `areaServed` / `hasOfferCatalog` parity in the rendered front-end JSON-LD graph |
| `schema-custom-verification.php` | schema | The custom node coexists with Yoast's own nodes in the emitted graph |
| `llms-txt-verification.php` ³ | core | The flag-off rewrite rule and 404 are indistinguishable from never-installed; exact byte/`ETag`/`Last-Modified` fidelity and `304` handling; the front-end route performs no post query and no write, proven by query count plus option `option_id`/value identity plus a behavioural absence proof; the leak matrix (draft, private, password-protected, `noindex`, non-public-post-type); de-publish staleness after regeneration; `preview-update-llms-txt` purity; `update-llms-txt` rejecting a stale token before any write; `regenerate-llms-txt` idempotency; the physical-artifact ownership conflict and its ABSPATH-vs-web-root regression with no filesystem path leaked; and deterministic bound truncation |
| `status-workflow-verification.php` | core | `transition-content-status` absent while `wpcb_writes_enabled` is off, `get-status-transitions` always present; the empty-graph deny-all default; the response reporting the status read back from storage; ADR 0024's "may unpublish but not publish" asymmetry; `publish`/`future` refused while `wpcb_publish_enabled` is off despite the pair and capability being held; a stale `version_token` and a past `publish_at` both rejected with the stored row untouched; a scheduled transition storing the exact requested `post_date_gmt`; DST spring-forward-gap rejection and autumn-fold/ordinary-instant round-trips against the real Europe/Warsaw tz database; the revision and field-names-only audit invariants; the full draft → pending → publish flow; the deliberate per-target `gates` semantics for non-privileged targets; and the mutation repository's own read-back defence against a WordPress-rewritten transition |
| `status-matrix-bulk-verification.php` | core | The settings matrix bulk toggles: one whole-matrix, one per ordered pair, one per content type; both axis attributes on every governed cell; that **no toggle carries a form field name**, so the submitted matrix is byte-for-byte what 0.7.0 submitted; that every toggle ships inside a hidden wrapper and so does nothing without JavaScript; the preset and legacy-adoption confirmation prompts; that both always-visible llms.txt workflow actions render; that the assets enqueue on the settings screen and on no other, at the current plugin version, with the hook suffix resolved the way `add_options_page()` resolves it rather than hard-coded; and that the content-access matrix above gained no bulk attributes |
| `rest-input-coercion-verification.php` | core | WordPress 7.1 coerces run-endpoint input to the declared schema. Pins that coercion stays at the REST boundary (a direct `execute()` still refuses a string `post_id`), that every schema bound survives it, that `type: array` fields accept the comma-separated form and resolve identically to the array form, that `type: string` fields are never split, that nested objects get no coercion, and that a capability-less principal is still refused on a perfectly coercible request. Also pins the error-status contract: a domain rejection answers 400/404 with its public error code, not 500. Requires 7.1 and refuses to run below it. |
| `invocation-telemetry-verification.php` | core | The off-by-default invocation-telemetry diagnostic (ADR 0029). Proves no listener is attached and nothing is written while the flag is off; that a `permission_callback` denial — invisible everywhere else — produces exactly one `attempted` entry attributed to the right principal; that a success upgrades that entry in place instead of duplicating it; that read invocations add **no** `wpcb_audit` rows, so read traffic cannot evict mutation history; that the ring buffer discards rather than grows; and that a stored entry carries exactly its five declared fields, never ability input. Restores both options in a `finally`. |
| `http-url-runtime-verification.sh` | http | URL-target resolution through a real HTTP request context, public head parity |
| `mcp-smoke-verification.sh` | mcp | `initialize` → `tools/list` → `tools/call` over Streamable HTTP as the least-privilege bridge principal |
| `local-multilocation-runtime-verification.sh` | yoast + http | Branch identity from provider-emitted Local Schema, bounds, and that no private Local option leaks |

¹ Prints an evidence dump, not a `PASS`/`FAIL` line. Read the exit code.
² The `preview-update-content` half is WordPress-core only and always runs. The
`preview-update-seo` half needs Yoast; without it those three checks are
reported in the result's `skipped` array rather than passing silently. **A
`PASS` with a non-empty `skipped` is not a full sign-off.**
³ Every check is a hard assertion. The noindex leg needs Yoast; without it
that one leg prints a `WARN` to stderr instead of being exercised, and the
other four leak vectors still run.

That leg is a regression test for a defect found on 2026-08-08. Yoast's own
`YoastSEO()->meta->for_post()` returns the **first-resolved post's meta for
every subsequent post in the same request** — reproduced with raw Yoast calls
and no plugin code involved, on Yoast SEO Free 28.2 with Yoast Local 15.8.
`WordPressLlmsSourceSelector::is_noindex()` is the first caller in this
codebase to resolve SEO for many posts in one request, so nothing had
triggered it before and a `noindex` page leaked into the public document. The
fix moved that decision onto Yoast's indexable data through
`SeoProvider::is_noindex()`, which is order-independent.

`YoastSeoProvider::get()` is still subject to Yoast's memoization for
multi-post reads. No remaining caller resolves more than one post per request,
so this is a recorded gap rather than a live exposure — see `.agents/status.md`.

`bridge-reader-fixture.php` and `local-multilocation-fixture.php` are fixtures
driven by the shell verifiers, not verifiers themselves.

## Last full run

**2026-09-01 — 24 of 24 green on WordPress 7.1**, the last of five runs that
day. The 7.1 adoption plan is complete; each run below re-ran the whole
inventory because every one of those changes touched a cross-cutting path —
error returns, ability registration, or execution itself. Against the
environment above at 0.8.3 plus those unreleased changes.

Record a dated line here on every release. A release whose line is missing
shipped unverified, and that should be visible rather than reconstructable.

| Date | Result | Notes |
|---|---|---|
| 2026-09-01 | 24/24 | Adds `invocation-telemetry-verification` for the ADR 0029 diagnostic mode, and covers the ADR 0028 annotation change (`update-llms-txt` is now `destructive`, with the HTTP method checked to be unmoved). This closes the 7.1 adoption plan: tasks 1–9 all done. Static gate: 513 tests / 1,269 assertions. |
| 2026-09-01 | 23/23 | Fourth run, after the `AbilityMeta` factory replaced thirteen per-class metadata helpers and eight inline literals and added 7.1's `public` flag to all 31 abilities. The 31 abilities' annotations were parsed before and after and compared: zero differences, so only the new key changed. Static gate: 510 tests / 1,261 assertions. |
| 2026-09-01 | 23/23 | Third run of the day, after declarative ability discovery (`wp_get_abilities( array( 'category' => … ) )`, defensive category comparison kept) and the diagnostics surface report (`minimum_wordpress_version`, `abilities_api_features.declarative_filtering`, diagnostics `schema_version` 1.1). Projection parity holds at 28 abilities. Static gate: 504 tests / 1,228 assertions. |
| 2026-09-01 | 23/23 | Re-run after the error-status mapping (`AbilityError`), which rewrote all 86 `WP_Error` construction sites and therefore every error path in the plugin — a change that only a full inventory can sign off. Domain rejections now answer 400/404/409/413/501 instead of 500; public error codes are unchanged. Static gate: 503 tests / 1,223 assertions. |
| 2026-09-01 | 23/23 | First run on **WordPress 7.1** (ADR 0027 makes it the minimum), and the run that earns `Tested up to: 7.1`. Adds `rest-input-coercion-verification`. Nothing regressed: all 19 pre-existing PHP verifiers and all three shell verifiers, MCP smoke included, pass unchanged on 7.1. Two things worth carrying forward: 7.1's input coercion widens what REST callers may *send* without widening what they may *do* (see `ABILITIES.md`), and the run exposed a pre-existing defect — every domain `WP_Error` answers HTTP 500 because none carries a `status` (task 9 of the adoption plan). Anomaly, recorded rather than hidden: the very first invocation of `abilities-runtime-verification` in the batch exited non-zero and then passed on four consecutive re-runs, immediately after a preceding verifier deleted its fixture post; not reproducible, most plausibly a settling object cache. Static gate: PHPCS + maximum-level PHPStan against the 7.1 stubs + 498 tests / 1,208 assertions. |
| 2026-08-12 | 22/22 | 0.8.2. Full inventory after replacing the circular hidden llms.txt migration prerequisite with an always-visible, two-step wp-admin workflow. Step 1 creates a bounded initial snapshot from site-owned settings and existing Read policy; Step 2 lists its actual blockers and retains the exact-target adoption gate. No Ability or MCP surface changed. Static release gate: PHPCS + maximum-level PHPStan + 429 tests / 1,137 assertions. |
| 2026-08-11 | 22/22 | 0.8.1. Full inventory after adding the wp-admin-only legacy llms.txt ownership-adoption path, additive ownership diagnostics, post-mutation ownership reads, and the endpoint verification fix. The llms.txt and settings-screen verifiers were also run visibly before the full inventory. Static release gate: PHPCS + maximum-level PHPStan + 425 tests / 1,126 assertions. |
| 2026-08-09 | 22/22 | 0.7.1. Adds `status-matrix-bulk-verification`. No ability, schema, or stored value changed; the run is what proves it, since the release edits the screen that writes the transition allowlist. Also fixes a packaging leak: the maintainer notes file shipped inside every artifact from 0.5.0 through 0.7.0. |
| 2026-08-09 | 21/21 | 0.7.0. Adds `status-workflow-verification`, which pins the publication gates, the pair-allowlist asymmetry, DST handling driven from the real tz database, and the read-back defence against a WordPress-rewritten transition. |
| 2026-08-08 | 20/20 | 0.6.0. Adds `llms-txt-verification`. Its `noindex` leg found a leak into the public document caused by Yoast returning the first-resolved post's meta for every later post in one request; `local-multilocation-runtime-verification` found `get-editorial-context` rejecting its own valid output over a missing `parentOrganization` key. Both fixed before the row was written. |
| 2026-08-07 | 19/19 | 0.5.0. Adds `block-edits-verification`, whose escaping assertion found a backslash-stripping defect shipped since 0.1.5. |
| 2026-08-07 | 18/18 | 0.4.5. First complete inventory run since 2026-07-21. Covers Slice 1A previews and `restore-trashed-content`. |

## Keeping this current

A verifier added without a row here is a verifier the next person will not run.
That is precisely how the blackout started, so adding the row is part of adding
the verifier, not follow-up work.

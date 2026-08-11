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
| WordPress / PHP | 7.0.2 / 8.4 |
| Providers | Yoast SEO Free 28.2, Yoast Local 15.8, IsuDev Schema Extended 0.3.0 |

The plugin directory is a symlink to this repository, so the working tree is
what runs — no copy step, and an uncommitted change is verified as written:

```
content/plugins/wp-content-bridge -> /Users/lukaszbiedron/Other Projects/wp-content-bridge
```

The Abilities API is WordPress core as of 7.0; no feature plugin is involved.

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
         status-matrix-bulk-verification; do
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
| `status-matrix-bulk-verification.php` | core | The settings matrix bulk toggles: one whole-matrix, one per ordered pair, one per content type; both axis attributes on every governed cell; that **no toggle carries a form field name**, so the submitted matrix is byte-for-byte what 0.7.0 submitted; that every toggle ships inside a hidden wrapper and so does nothing without JavaScript; the preset confirmation prompt; that the assets enqueue on the settings screen and on no other, at the current plugin version, with the hook suffix resolved the way `add_options_page()` resolves it rather than hard-coded; and that the content-access matrix above gained no bulk attributes |
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

**2026-08-11 — 22 of 22 green**, against the environment above at 0.8.1.

Record a dated line here on every release. A release whose line is missing
shipped unverified, and that should be visible rather than reconstructable.

| Date | Result | Notes |
|---|---|---|
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

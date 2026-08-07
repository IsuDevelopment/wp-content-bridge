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
         schema-custom-verification; do
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
| `abilities-runtime-verification.php` ¹ | core | Discovery, schemas, permission callbacks, direct execution, REST projection, closed-profile guard |
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
| `http-url-runtime-verification.sh` | http | URL-target resolution through a real HTTP request context, public head parity |
| `mcp-smoke-verification.sh` | mcp | `initialize` → `tools/list` → `tools/call` over Streamable HTTP as the least-privilege bridge principal |
| `local-multilocation-runtime-verification.sh` | yoast + http | Branch identity from provider-emitted Local Schema, bounds, and that no private Local option leaks |

¹ Prints an evidence dump, not a `PASS`/`FAIL` line. Read the exit code.
² The `preview-update-content` half is WordPress-core only and always runs. The
`preview-update-seo` half needs Yoast; without it those three checks are
reported in the result's `skipped` array rather than passing silently. **A
`PASS` with a non-empty `skipped` is not a full sign-off.**

`bridge-reader-fixture.php` and `local-multilocation-fixture.php` are fixtures
driven by the shell verifiers, not verifiers themselves.

## Last full run

**2026-08-07 — 18 of 18 green**, against the environment above at 0.4.5-dev.

Record a dated line here on every release. A release whose line is missing
shipped unverified, and that should be visible rather than reconstructable.

| Date | Result | Notes |
|---|---|---|
| 2026-08-07 | 18/18 | First complete inventory run since 2026-07-21. Covers Slice 1A previews and `restore-trashed-content`. |

## Keeping this current

A verifier added without a row here is a verifier the next person will not run.
That is precisely how the blackout started, so adding the row is part of adding
the verifier, not follow-up work.

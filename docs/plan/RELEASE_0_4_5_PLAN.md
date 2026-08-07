# Release 0.4.5 — consolidation

Audience: an implementing agent working in this repository.

`0.4.5` is not a roadmap slice. It pays down debt accumulated through 0.4.0
before Slice 1B (llms.txt, `0.5.0`) adds a new domain, a new persistence model,
and the plugin's first unauthenticated public route. Opening that surface on
top of unfinished business is how the roadmap's own gates stop meaning
anything.

**Nothing in this release is breaking.** If a task turns out to require a
breaking change, stop and move it to `0.5.0` rather than breaking a public
contract in a patch release.

## Working rules

- WordPress coding standards; enforced by `composer check` (PHPCS + maximum
  level PHPStan + PHPUnit).
- One unit test file per new use case and one runtime verifier per task that
  needs one. Do not write scripts that test scripts, harnesses that verify
  harnesses, or throwaway diagnostic files.
- Do not re-run a check that already passed unless you changed something it
  covers. Run `composer check` once at the end of each task, not continuously.
- Ability IDs and response fields are stable public API.
- Commit only when the user asks.

## Task 1 — `restore-trashed-content`

The one task in this release that adds a public Ability. Do it first; the rest
is cleanup that will not conflict with it.

`trash-content` shipped in 0.1.5 and nothing undoes it. Recovery currently
requires wp-admin, which means an agent can perform a destructive operation it
cannot reverse.

Model on `src/Application/Mutation/TrashContent.php` and its ability
registration — the new intent is its mirror image and shares every gate.

Contract:

- ID `wp-content-bridge/restore-trashed-content`;
- input: required `post_id` and `version_token`, nothing else;
- gated behind the **existing** `wpcb_trash_enabled` flag — untrash is part of
  the trash feature, not a new one;
- requires the same capability `trash-content` requires plus native
  `delete_post` on the target;
- requires the target to currently be in `trash`; any other status is a
  non-enumerating failure;
- redacted audit, post-write re-read, post-scoped cache invalidation, exactly
  as `trash-content` does.

**Status restoration is the part to get right.** WordPress stores the
pre-trash status in `_wp_trash_meta_status`. Rules:

- restore to the recorded pre-trash status when it is present **and** is one of
  `draft`, `pending`, or `private`;
- restore to `draft` in every other case — missing meta, unparseable meta, or a
  recorded status of `publish` or `future`;
- **never** let untrash reach `publish` or `future`. Republication is Slice 2's
  contract (`transition-content-status`, `0.6.0`) and is gated behind the
  publication switch and `wpcb_publish_content`. Untrash must not become a way
  around that gate;
- return the resulting status explicitly so the caller is never guessing.

`wp_untrash_post()` respects the `wp_untrash_post_status` filter and its
default changed across WordPress versions. Do not rely on the default — set the
intended status explicitly and verify the effective status on re-read.

No preview. It fails the justification test in the roadmap: the caller sends
one post ID and gets back one status it could not have changed the value of.

Deliverables: domain result DTO, use case, port method if the repository lacks
untrash, schemas, registration, `Plugin.php` wiring, one unit test file, and
one runtime verifier asserting trash → restore → correct status, that a
`publish` pre-trash status still lands on `draft`, and that a stale token is
rejected.

## Task 2 — unify the preview response flag

The four preview Abilities disagree about how they say "this wrote nothing":

| Ability | Field |
|---|---|
| `preview-update-service-schema` (0.3.0) | `dry_run: true` |
| `preview-update-custom-schema` (0.3.0) | `dry_run: true` |
| `preview-update-content` (0.4.0) | `writes_performed: false` |
| `preview-update-seo` (0.4.0) | `writes_performed: false` |

One concept, two names, opposite polarity. ADR 0021 explains why the newer
previews avoid the word `dry_run`: the roadmap's architectural rules forbid
`dry_run` as a *mode* on a destructive Ability, and reusing it as a response
flag reads as a contradiction.

Do this **additively**, because a straight rename would be breaking:

- add `writes_performed: false` to `ServiceSchemaPreviewResult` and
  `CustomSchemaPreviewResult` alongside the existing `dry_run: true`;
- add it to both output schemas in `AbilitySchemas.php` and to their `required`
  arrays;
- document `dry_run` as deprecated in `docs/architecture/ABILITIES.md`, with
  removal scheduled for `0.5.0`;
- append an "Amended" note to ADR 0019 and ADR 0020 recording the addition,
  without altering their decisions.

After this, one client code path reads all four previews. `dry_run` is removed
in `0.5.0`, which is a minor bump and may break.

## Task 3 — `list-block-patterns` runtime sign-off

Plan 4a is code-complete and has never had a runtime verification. It is in the
closed MCP profile and in the `wpcb-bridge-reader` grant set on the Kormas
site, which means it is reachable without ever having been exercised.

Write `tests/Integration/block-patterns-verification.php`, modelled on
`tests/Integration/schema-service-verification.php`. Assert:

1. the ability is absent while `wpcb_pattern_reads_enabled` is off, and present
   when it is on;
2. a principal without `wpcb_read_patterns` is denied;
3. metadata-only is the default and pattern **filesystem paths never appear in
   any response field** (ADR 0013);
4. optional complete markup respects the 2 MiB bound;
5. filters and pagination are deterministic across repeated calls.

Restore every option touched in a `finally` block.

**If it fails, that is the useful outcome.** Fix the product, do not weaken the
verifier. If sign-off cannot be reached in this release, remove
`list-block-patterns` from the `wpcb-bridge-reader` grant and say so — an
unverified read in a live grant set is the thing this task exists to resolve.

## Task 4 — the verification run book — **done 2026-08-07**

> **Amended 2026-08-07.** As originally written this task called for a
> `.wp-env.json` so the PHP verifiers could run against a disposable WordPress.
> That was the wrong diagnosis and it was mine. It was tried and abandoned; see
> "Why not a container" below.

Runtime sign-off depends on one machine's LocalWP instance. That was blamed for
the 2026-07-21 → 2026-08-07 verification blackout, during which 0.1.3 through
0.3.0 all shipped on static checks alone.

The blame was misplaced. The environment existed and worked throughout the
blackout — the same instance ran the whole inventory green on 2026-08-07 with no
setup at all. What was missing was any document stating what the inventory
*was*, so "verified" had no definition and skipping it left no trace.

Deliverable: [`docs/setup/VERIFICATION.md`](../setup/VERIFICATION.md) — all 18
verifiers, what each proves, its hardest dependency, the exact commands, and a
dated record of the last complete run. Run it before cutting a release.

Also fixed here: `preview-verification.php` hard-asserted Yoast availability in
`set_up()`, so the `preview-update-content` half — WordPress core only, no
provider involved — could not be verified without a licence. The halves are now
independent and an absent provider is reported in a `skipped` array instead of
failing the run or, worse, passing quietly.

### Why not a container

A `wp-env` instance reproduces the WordPress-core half of the inventory and
cannot ever cover the rest: Yoast Premium/Local are licensed and IsuDev Schema
Extended is private, so neither may be committed. Green on such an environment
would look like coverage while a third of the surface went unchecked — a worse
failure mode than one documented environment that is honest about being one
machine.

## Task 5 — retire the inert `wpcb_public_base_url` option

The option is no longer read anywhere. Confirm that with a repository-wide
search before touching anything.

Delete it in the uninstall routine and add a bounded one-time cleanup on
upgrade. Do not add a migration framework for a single option.

**Out of scope, user action:** the old root-owned `cloudflared` service on the
development machine still needs uninstalling. It is a `sudo`-level operation
outside this repository and must not be attempted from here. Leave it in
`.agents/status.md` as an open manual step.

## Task 6 — Milestone 5 security sign-off

Still outstanding and blocking nothing formally, which is why it keeps slipping.

Record it as a dated section in `.agents/status.md` covering the write surface
as it actually exists at 0.4.5: feature flags, capabilities, per-type policy,
optimistic concurrency, audit redaction, post-write verification, cache
invalidation, and the preview intents. Reference the runtime verifiers that
evidence each claim.

If a claim has no verifier behind it, write that down as a gap instead of
asserting it. An honest sign-off with three named gaps is useful; a clean one
that nobody can trace is not.

## Task 7 — release packaging

Verified against the published `v0.4.0` artifact on 2026-08-07: **74 files
under `docs/` and `.agents/` ship inside the production plugin ZIP.** The
`rsync` exclude list in `.github/workflows/release.yml` only drops `.git`,
`.github`, `build`, `tests`, `node_modules`, `*.zip`, and `.gitignore`.

That means every installed copy carries the implementation plans, the
editorial roadmap, the ADRs, `AGENTS.md`, `CLAUDE.md`, and `.agents/status.md`
— which includes the security model, known gaps, verification state, and notes
about the consuming site's grants. It is bloat and low-grade information
disclosure in the same breath.

Extend the exclude list with `docs`, `.agents`, `AGENTS.md`, `CLAUDE.md`,
`.editorconfig`, `phpcs.xml.dist`, `phpstan.neon.dist`, `phpunit.xml.dist`,
`composer.lock`, and any other development-only root file. Keep `readme.txt`,
`README.md`, `LICENSE`, the plugin bootstrap, `src/`, and `vendor/`.

Verify by listing the built ZIP, not by reading the workflow:

```
unzip -l wp-content-bridge.zip | grep -E 'docs/|\.agents/'
```

The correct result is no matches.

Also fix the release trigger while you are in this file. It currently fires on
any push to `main` that touches `wp-content-bridge.php` and derives the tag
from the version header. On 2026-08-07 that published a `v0.4.0` release built
from the rename commit alone, missing the entire Slice 1A feature set, because
the follow-up commit did not touch the version line. It also auto-published a
`v0.3.1` that was never intended as a release. Prefer triggering on tag push
only, so cutting a release stays a deliberate act.

## Task 8 — release

- `readme.txt`: `Stable tag: 0.4.5` and a `= 0.4.5 =` changelog block;
- `wp-content-bridge.php`: `Version: 0.4.5` and `WPCB_VERSION`;
- `docs/architecture/ABILITIES.md`, `CODE_MAP.md`, `README.md`,
  `.agents/status.md`;
- the consuming site's MU-plugin projection package
  (`isudev/wp-content-bridge-mcp-server`, different repository) may take
  `restore-trashed-content` with a version bump, making the profile 21 entries.
  **Report this to the user; do not edit that repository.**

> **Corrected 2026-08-07.** This bullet originally said the package "needs" the
> new ID, and the first report to the user claimed the ability was unreachable
> over MCP without it. Both are wrong. That package projects only the official
> MCP Adapter endpoint; the miniOrange OAuth server ChatGPT uses reads
> `wp_get_abilities()` directly and never consults `ABILITY_PROFILE`. The bump
> is hygiene for `mcp-smoke-verification.sh`, not a reachability blocker. The
> corrected account is in `.agents/status.md`, "Two MCP servers, one
> projection".

## Final check

Run once, at the end:

```
cd "/Users/lukaszbiedron/Other Projects/wp-content-bridge"
composer check
```

Then the new verifiers from tasks 1 and 3, plus
`tests/Integration/abilities-runtime-verification.php` (the closed-profile
guard) and `tests/Integration/trash-content-verification.php` (task 1 touches
that surface). Nothing else.

## Definition of done

- `restore-trashed-content` cannot reach `publish` or `future` under any input;
- all four previews report `writes_performed`;
- `list-block-patterns` is either verified or out of the live grant set;
- the verifier inventory states honestly which checks need the provider
  environment;
- the built ZIP contains no `docs/` or `.agents/` entries, confirmed by listing
  the artifact;
- releases are cut deliberately, not as a side effect of editing a version line;
- `composer check` green;
- no public contract broken.

## Out of scope

Do not start Slice 1B (llms.txt). It is `0.5.0`, it is the heaviest slice in
the roadmap, and it needs its own threat model before any code.

# Local SEO multiple-location runtime verification — design

Date: 2026-07-17
Status: approved for implementation
Milestone: 3 (closing item, exit gate 3C)

## Problem

Milestone 3 is complete except for one exit-gate item: a **real, licensed Yoast
Local SEO multiple-location runtime fixture**. Today the multiple-location
projection is proven only by a pure unit fixture
(`LocalSchemaProjectorTest::test_projects_multi_location_branch_references`).
That proves the provider-neutral projector contract, not that Yoast Local 15.8
actually emits primary/branch (`branchOf`) Schema that the projector consumes
over real HTTPS. We must not claim multiple-location compatibility until that
runtime path passes.

## Goal

Prove, against a live local Yoast Local 15.8 in multiple-location mode, that:

- an organization/primary location and at least one branch produce public
  `LocalBusiness`/`Place` Schema in `yoast_head_json`;
- `get-url-seo` on the primary and branch URLs returns normalized public local
  profiles with correct primary/branch identity (`branchOf`), address, geo, and
  opening hours;
- `get-editorial-context` returns the same bounded local businesses;
- output bounds hold, degraded states are explicit, and no secret
  (`local_api_key`, `googlemaps_api_key`), raw option, or unknown nested member
  ever leaks.

Then restore the local site to its prior single-location configuration.

## Environment (confirmed)

- WordPress 7.0.1, Yoast Free 28.0, Premium 28.0, Local (`wpseo-local`) 15.8.
- Currently single-location: `wpseo_local.use_multiple_locations` unset, CPT
  `wpseo_locations` not registered.
- `wpseo_local` holds the multiple-location switches we need:
  `use_multiple_locations`, `multiple_locations_same_organization` (drives
  `branchOf`), `multiple_locations_primary_location`, plus CPT/taxonomy slugs
  and per-location business meta.
- Local, not production. Staging exists downstream. Mutating local state freely
  is acceptable; restore is for repeatability/hygiene, not production safety.

## Non-goals

- No change to public ability IDs or schemas (no ADR triggered).
- No Kormas-specific brand data in fixtures — generic neutral data only, per
  AGENTS.md ("plugin must remain reusable; never add Kormas-specific data").
- No Premium synonyms, no per-target analysis scores (still out of scope).

## Approach

Two files, matching the existing integration-test pattern (PHP setup/teardown +
bash HTTPS assertions, self-cleaning via `trap`):

### 1. `tests/Integration/local-multilocation-fixture.php`

Reusable helper with `setup` and `teardown` modes (selected by an env var or
CLI arg):

- **setup**
  - snapshot the entire `wpseo_local` option (in-memory / a transient) so exact
    restore is possible;
  - enable `use_multiple_locations`, `multiple_locations_same_organization`, and
    set the primary location id after creating the primary;
  - create two `wpseo_locations` posts with neutral data — a primary HQ and one
    branch — populating the business meta keys Yoast 15.8 reads (confirmed
    empirically in Phase 0), including a deliberate `must-not-leak` marker in a
    non-allowlisted meta field to prove isolation;
  - flush rewrite rules and force Yoast indexable generation for the new posts;
  - print the created post ids and public URLs for the bash orchestrator.
- **teardown**
  - restore the exact `wpseo_local` snapshot;
  - delete the created location posts (force delete);
  - flush rewrite rules.

Idempotent and safe to re-run. Honors `WPCB_KEEP_FIXTURE=1` to skip teardown so
the configuration can be inspected in wp-admin.

### 2. `tests/Integration/local-multilocation-runtime-verification.sh`

Orchestrator, modeled on `http-url-runtime-verification.sh`:

- run the PHP `setup`, capture primary/branch ids + URLs;
- create a disposable least-privilege Application Password principal
  (subscriber + `wpcb_read_content`), as the existing HTTP verifier does;
- `curl` `get-url-seo` for the primary URL and the branch URL, and
  `get-editorial-context`, then assert with `jq`;
- always run PHP `teardown` and delete the principal in a `trap`/cleanup;
- print a `PASS` summary JSON.

## Assertions

Primary location (`get-url-seo`):
- `provenance.provider.detected == true`, `modules` includes `local`,
  `module_versions.local == "15.8"`;
- `resolved.local_businesses.state == "generated"` with ≥1 entity carrying
  `name`, `address.streetAddress`, `geo.latitude/longitude`, and
  `openingHoursSpecification`.

Branch location (`get-url-seo`):
- an entity with `branchOf` resolving to the parent organization name (proves
  primary/branch identity), plus its own address/geo/hours.

Editorial context:
- `context.local_businesses.state == "generated"`, items > 0, bounded.

Bounds & degraded:
- entity/list caps respected (`length <= 50`, schema_graph `<= 200`);
- a non-location URL (or home) returns explicit `partial`/`unavailable`, never a
  hard failure.

Leakage (critical):
- response never contains `local_api_key`, `googlemaps_api_key`, any raw
  `wpseo_local` option dump, `must-not-leak`, or unknown nested members.

Exit gate (from IMPLEMENTATION_PLAN.md M3):
- compatibility claim backed by this runtime fixture;
- no direct indexables-table dependency;
- Schema limits and completeness reporting verified.

## Phase 0 — empirical confirmation (first implementation step)

Before finalizing the fixture, confirm on the live local Yoast:
- exact meta keys Yoast Local 15.8 uses for location business fields;
- that a location page's `yoast_head_json.schema["@graph"]` emits
  `LocalBusiness` + `branchOf` in multiple-location mode, and how
  `multiple_locations_same_organization` / `..._primary_location` affect it;
- whether indexables must be generated before HTTPS returns full Schema.

If the real Schema reveals the projector misses a shape (e.g. `branchOf`
resolution, an alternate hours container), fix `LocalSchemaProjector` **and its
unit test together** (test-first). No projector change is made speculatively.

## Verification

- `composer check` (PHPCS, PHPStan max, PHPUnit) stays green;
- all existing runtime verifiers still pass;
- the new `local-multilocation-runtime-verification.sh` passes and self-cleans.

## Documentation to update on completion

- `docs/verification/YOAST_PREMIUM_LOCAL.md` — replace the "pure unit fixture
  only" boundary with the verified multiple-location result;
- `.agents/status.md` — mark the M3 exit item complete;
- `docs/plan/IMPLEMENTATION_PLAN.md` — close Milestone 3;
- `docs/architecture/CODE_MAP.md` — register the new fixture files;
- `docs/plan/TEST_PLAN.md` — add the multiple-location matrix run;
- `.continue-here.md` — update state and next task (begin Milestone 4).

## Risks

- Yoast may not emit `branchOf` exactly as the unit fixture assumes; Phase 0
  de-risks this and the projector is adjusted test-first if so.
- Indexable timing could yield `unavailable` on first read; setup forces
  indexable generation and the assertions tolerate an explicit degraded state
  only where genuinely expected (home/non-location), not for the primary/branch.

## Addendum (2026-07-17) — Phase 0 outcome and scope change

Phase 0 probing changed the plan. Findings on the live local Yoast Local 15.8:

- Yoast uses `parentOrganization`, not `branchOf`, for the branch→parent link.
- The branch schema (`#local-branch-organization` + `parentOrganization` +
  branch address/geo/hours) is emitted **only on a real front-end singular
  render**. The resolved Meta surface (`for_url`/`for_post`) returns only the
  merged `#organization` node with the primary address for every location URL,
  and the REST `yoast_head_json` for the locations CPT returns an empty graph.

So the original assertions (branch identity through the ability) could not pass
via the resolved surface. Two capture mechanisms were spiked and both proven
feasible; the chosen mechanism is a **bounded same-origin loopback fetch of the
target's rendered public JSON-LD**, fed through the existing allowlist projector.
This is recorded in **ADR 0009**.

Revised scope:

- Add `parentOrganization` to `LocalSchemaProjector` (keep `branchOf`); fix its
  unit test to the real Yoast shape.
- Add a `RenderedSchemaReader` port and a bounded, same-origin, cached WordPress
  adapter; wire it into `YoastSeoProvider` for `local_businesses` only, with a
  Meta-surface fallback and explicit degraded warning.
- The runtime fixture then asserts real primary/branch identity, address, geo,
  hours, bounds, degraded states, and secret/leakage rejection.

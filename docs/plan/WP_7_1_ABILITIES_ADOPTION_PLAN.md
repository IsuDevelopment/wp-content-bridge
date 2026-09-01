# WordPress 7.1 Abilities API adoption plan (`0.9.x` line)

Target: the plugin runs on WordPress 7.1 as its baseline, uses the 7.1 Abilities
API where 7.1 does the job better than our own code, and has an explicit,
written refusal for the parts of 7.1 that would move authorization or auditing
out of the layers this project deliberately put them in.

**Status 2026-09-01: complete. All nine tasks are done** (ADR 0027, ADR 0028,
ADR 0029), verified against a live WordPress 7.1 with the full runtime inventory
green at 24/24. Completed tasks keep their record below rather than being
deleted, because what was measured and what was rejected are the parts a later
reader needs.

## The 7.1 surface, and our position on each item

Sourced from the 7.1 dev notes (2026-07-29 … 2026-08-05) and, since task 2, all
**measured on a live WordPress 7.1** rather than taken from the notes. Where the
two disagreed the measurement won — see task 2 on how much more conservative
core's input coercion turned out to be.

| 7.1 addition | Position |
|---|---|
| REST input coercion to `input_schema` types | **Verified (task 2)** and pinned by a verifier. Safer than the dev note implies; no plugin defect. |
| `wp_get_abilities( $args )` — `category`, `namespace`, `meta`, `item_include_callback`, `result_callback` | **Adopted (task 4)**, with the defensive post-filter kept permanently. |
| `meta.public`, resolved as `$meta['show_in_rest'] ?? $meta['public'] ?? false` | **Adopted (task 5)** alongside an explicit `show_in_rest`, never instead of it. |
| Action `wp_ability_invoked` (fires before normalization, validation and permission checks, for every invocation) | **Adopted (task 7, ADR 0029)** as an off-by-default diagnostic mode. The only item that closed a known gap. |
| `wp_prepare_json_schema_for_client()`, profiles `draft-04` / `rest-api` | **No action.** We hand canonical schemas to core; core prepares them. We expose no WordPress-style schema outside the PHP validation boundary. |
| Filters `wp_ability_validate_input` / `wp_ability_validate_output` | **Rejected (task 8)**, with the reason written down. |
| Filter `wp_ability_permission_result` | **Rejected (task 8)**, with the reason written down. |
| Filter `wp_pre_execute_ability` | **Rejected (task 8)**, with the reason written down. |
| Filter `wp_ability_normalize_input` | **Rejected (task 8)**, with the reason written down. |
| Filter `wp_ability_execute_result` | **Rejected (task 8)**, with the reason written down. |
| `wp_before_execute_ability` / `wp_after_execute_ability` now receive `WP_Ability` | Not consumed today; nothing to do. |
| Core ability changes (`core/get-user-info` fields, `core/get-environment-info` `fields`) | Out of scope. We register no `core/*` ability and must not depend on one. |

## What already exists — do not re-create it

Measured in this repository at `0.8.3` (31 registration call sites, 498 static
tests green):

- **Meta is already uniform.** All 31 registrations pass exactly
  `annotations{readonly,destructive,idempotent}` + `show_in_rest => true` +
  `mcp => ['public' => true]`. No ability is missing an annotation and none is
  missing `show_in_rest`. There is nothing to *fix* here; task 5 is a
  deduplication and a single-declaration change, not a repair.
- **`mcp.public` is not core's `public`.** Core reads top-level
  `$meta['public']`; our existing key is nested under `mcp` and is read by the
  Adapter. They do not collide, and they are not the same flag. Task 5 must not
  "consolidate" them.
- **Schemas are already client-clean.** All 31 schemas are built in
  `src/Adapter/Abilities/AbilitySchemas.php`. Repository-wide there are zero
  `sanitize_callback` / `validate_callback` / `arg_options` inside ability
  schemas and zero property-level `'required' => true`; every `required` is an
  object-level array. This is why `wp_prepare_json_schema_for_client()` buys us
  nothing.
- **Mutation auditing exists and is deliberate.** `AuditLog` port
  (`src/Application/Mutation/AuditLog.php:15`), `AuditEvent` DTO, and the
  `WordPressAuditLog` sink writing the `wpcb_audit` table and firing
  `wpcb_mutation` (`src/Infrastructure/WordPress/WordPressAuditLog.php:71`).
  13 write use cases emit it. Reads and previews structurally cannot audit
  (they take no `AuditLog`), by design.
- **Registration is the real gate.** Feature flags decide *registration*, so a
  disabled area has no ability at all (`src/Plugin.php:330-349`,
  `LlmsAbilities.php:93`). This is stronger than any execution-time
  short-circuit 7.1 offers, which is the core of task 8's refusal.
- **Category discovery.** `McpServerProvider::discover()`
  (`src/Adapter/Mcp/McpServerProvider.php:210-226`) loops `wp_get_abilities()`
  and compares `get_category()` to `AbilityCategory::SLUG`. ADR 0025 is the
  decision behind it.

## Traps

1. **`wp_get_abilities( $args )` fails open on 7.0.** Extra arguments to a
   userland PHP function are silently ignored, so the same call that filters by
   category on 7.1 returns *every registered ability* on 7.0 — including other
   plugins'. `narrow()` intersects the filter result with the discovered set, so
   it would not save us: the discovered set itself is what widens. Task 4 keeps
   the explicit category comparison after the args-based call. Belt and braces,
   permanently, not as a migration shim.
2. ~~**The stubs do not know 7.1.**~~ **Cleared by task 1.** The stubs are on
   `v7.1.0` (which also forced `szepeviktor/phpstan-wordpress` to `^2.0.4`;
   v2.0.3 constrains the stubs to `^6.6.2`). Tasks 4 and 7 now lint. The
   remaining lesson: the stub bump surfaced eight pre-existing PHPStan errors
   because the 7.1 stubs are *more* precise, so expect the same on the next
   stub bump — and see ADR 0027's consequences for why four of them were fixed
   in code and four by `treatPhpDocTypesAsCertain: false`.
3. **Do not put invocation telemetry in `wpcb_audit`.** That table is pruned to
   `max_rows = 5000` (`WordPressAuditLog.php:80`). Reads outnumber writes by
   orders of magnitude on an agent workload, so writing invocations there
   evicts the mutation history the security posture depends on. Task 7 needs its
   own sink and its own bound.
4. **`wp_ability_invoked` fires before the permission check.** Anything it
   records is *attempted*, not authorized. A telemetry row is not evidence that
   an operation happened, and the field names must make that unmistakable —
   otherwise the next reader will treat the two sinks as one log.
5. **The REST closed profile is asserted, in two places.**
   `tests/Integration/abilities-runtime-verification.php` fails when REST
   discovery lists anything outside `CLOSED_PROFILE` and when the runtime
   inventory does. Any change to exposure metadata is a change to a tested
   public surface — which is why task 5 kept `show_in_rest` explicit instead of
   leaning on 7.1's fallback.
6. **Annotations decide the HTTP method, not just client advice.** WordPress
   maps `readonly` → GET and `destructive` **and** `idempotent` → DELETE, so
   editing an annotation can move an endpoint's method and break every client of
   it. Check the pair before changing either value. Task 6 (ADR 0028) is the
   worked example: exactly one annotation changed and the method provably did
   not.

## Tasks

### Task 1 — baseline: WordPress 7.1 — **done 2026-09-01, ADR 0027 accepted**

Decision: 7.1 is the minimum; 7.0 is not guarded for. Rationale and the full
list of consequences are in ADR 0027. What landed:

- `Requires at least: 7.1` in `wp-content-bridge.php` and `readme.txt`.
- `php-stubs/wordpress-stubs: "^7.1"` (v7.1.0) and
  `szepeviktor/phpstan-wordpress: "^2.0.4"`.
- `AGENTS.md` code conventions now say `WordPress 7.1+` and forbid 7.0
  back-compatibility branches.
- Eight PHPStan errors surfaced by the more precise stubs: four real
  `WP_Query::$posts` null guards fixed in code, four PHPDoc-certainty reports
  handled by `treatPhpDocTypesAsCertain: false` (the flagged guards are
  load-bearing, one set of them in an origin comparison), and three now-dead
  `@phpstan-ignore` comments removed.
- `Tested up to:` was held at 7.0 until task 2's full inventory earned it; it
  is now 7.1.
- No runtime version check was added; WordPress enforces the header.

`composer check` green: phpcs, PHPStan `level: max` against the 7.1 stubs, 498
tests / 1,208 assertions. No new `ignoreErrors` entry.

### Task 2 — measure 7.1's REST input coercion — **done 2026-09-01**

Landed as `tests/Integration/rest-input-coercion-verification.php` (a new
verifier rather than an extension of `abilities-runtime-verification.php`: it
needs its own comma-bearing fixture post and its own capability-less user, and
it refuses to run below 7.1). The measured contract is written into
`docs/architecture/ABILITIES.md`; the mechanism is
`WP_REST_Abilities_V1_Run_Controller::coerce_input_to_schema()`.

**No defect in the plugin.** The dev note undersells how conservative core is:
coercion runs `rest_sanitize_value_from_schema()` **only when
`validate_input()` already accepts the raw input**, and falls back to the raw
input on any sanitization error. So every bound held, an uncoercible value is
rejected exactly as before, `type: string` fields are never split, and nested
objects get no coercion. What did change: over REST a numeric string now reaches
the use case natively typed, so requests that previously failed inside our code
now succeed — a widening of what callers may *send*, not of what they may do. A
direct `execute()` still refuses a string `post_id`, which is the property worth
protecting and is now asserted.

The full inventory (23/23, MCP smoke included) also passed unchanged on 7.1, so
`Tested up to: 7.1` is earned and set. See `docs/setup/VERIFICATION.md`.

**One pre-existing defect found, tracked as task 9.**

### Task 3 — diagnostics report the 7.1 surface — **done 2026-09-01, scaled down**

Landed smaller than planned, on purpose. Diagnostics gained
`minimum_wordpress_version` (read from the plugin header via `get_file_data()`,
so the requirement keeps one source of truth) and
`abilities_api_features.declarative_filtering` (probed by reflecting
`wp_get_abilities()`'s parameter count). `schema_version` 1.0 → 1.1;
`abilities_api` stays a boolean because changing its type would break clients,
so the report is additive.

What was dropped and why: the plan sketched a broader feature report, but with
7.1 as the floor most entries would be tautologies, and the two that were not —
`wp_ability_invoked` and `meta.public` — **cannot be probed at all**. An action
that has not fired and a meta key read only at registration leave nothing to
observe, and a probe that inferred them from `get_bloginfo( 'version' )` would
be the exact guess this report exists to replace. `rest_input_coercion` was also
dropped: core performs it, this plugin never calls it, and PHPStan proves the
probe constant against the 7.1 stubs. What remains is the one capability the
plugin's own projection depends on, plus the requirement itself — enough to tell
"this install is below the floor" from "this is a defect" in one read.

Verified live: `{"schema_version":"1.1","wordpress_version":"7.1",
"minimum_wordpress_version":"7.1","abilities_api_features":
{"declarative_filtering":true}}`.

### Task 4 — declarative discovery in `McpServerProvider` — **done 2026-09-01**

`discover()` now calls
`wp_get_abilities( array( 'category' => AbilityCategory::SLUG ) )` and **keeps**
the `AbilityCategory::SLUG === $ability->get_category()` comparison over the
result, with the reason written at the call site so it survives a future
cleanup: PHP ignores arguments a userland function does not declare, so the same
call on a non-conforming WordPress returns every ability on the site and
`narrow()` cannot help — the discovered set is what widens, and the failure mode
is handing a client another plugin's tools. The `function_exists` guard stays;
an Abilities-less install remains inert.

The existing `test_projects_every_ability_in_this_plugins_category` turned out
to *be* the fail-open assertion, because the unit suite's
`wp_get_abilities()` stub declares no parameters and therefore behaves exactly
like a WordPress without the filter. Its docblock now says so, so neither half
gets deleted as redundant. Projection parity re-verified live: 28 abilities,
`abilities-runtime-verification` green.

Value delivered is small and honest, as planned: the category is a query rather
than a convention, and nothing about what is projected changed.

### Task 5 — `meta.public` and one metadata factory — **done 2026-09-01**

`src/Adapter/Abilities/AbilityMeta.php` is now the single source of registration
metadata: `read()`, `preview()`, `write( bool $destructive, bool $idempotent )`.
It replaced thirteen private per-class helpers and eight inline literals, and
every ability now declares `public => true` **alongside** an explicit
`show_in_rest => true` — not instead of it, because `CLOSED_PROFILE` asserts
exactly which abilities REST lists and an explicit value keeps a future
intentional divergence a one-line reviewable change. `mcp.public` is untouched.

Two real footguns died with the helpers, and both are recorded in the class
docblock so they do not grow back:

- `LlmsAbilities::write_meta( bool $idempotent )` versus
  `MutationAbilities::write_meta( bool $destructive )` — same name, different
  single boolean, so no call site could be read without opening the helper.
  `write()` names both.
- Three helper names produced one identical array. `preview()` now states that a
  preview *is* a read, rather than leaving the reader to compare bodies.

**Verified byte-identical, not assumed.** The 31 abilities' annotations were
parsed out of the pre-change tree and the post-change tree and compared: 31
parsed on both sides, **zero differences**. Nothing about published safety
annotations moved; the only change to registered metadata is the added `public`
key. `AbilityMetaTest` pins the shape (closed key set, exactly three
annotations, `preview() === read()`, a write is never `readonly`), and
`abilities-runtime-verification.php` now asserts all three exposure flags per
registered ability against the live registry, where per-ability values belong.
Full inventory 23/23 after the change.

### Task 5 (original plan text)

Today every ability states `show_in_rest => true` explicitly, so core's new
fallback never fires and nothing is broken. The reason to act is forward-facing:
future channels (AI Client function declarations, other adapters) read `public`,
and an ability that only says `show_in_rest` will be invisible to them.

Two changes, together or not at all:

1. A single shared meta factory (`Adapter\Abilities\AbilityMeta` or equivalent)
   replacing the ~13 duplicated private `read_meta()` / `write_meta()` /
   `preview_meta()` helpers. This also removes a live footgun:
   `LlmsAbilities::write_meta( bool $idempotent )` and
   `MutationAbilities::write_meta( bool $destructive )` share a name and mean
   different things.
2. The factory emits `public => true` **and keeps `show_in_rest => true`**
   explicitly. Do not rely on the fallback: `CLOSED_PROFILE` (trap 5) asserts
   REST exposure, and an explicit value is what makes a future intentional
   divergence (public elsewhere, not in REST) a one-line, reviewable change.

`mcp.public` is untouched (see "What already exists").

Acceptance: the meta array produced per ability is byte-identical to today's
plus the one new key — assert this in a unit test over all registered abilities,
not per class. REST discovery still lists exactly `CLOSED_PROFILE`.

### Task 6 — `destructive` annotation consistency — **done 2026-09-01, ADR 0028**

The rule: **`destructive => true` if and only if the operation can lose content
or configuration the client did not supply in the request.** Written into
`docs/architecture/ABILITIES.md` next to the catalogue, so the next ability is
annotated from a definition instead of intuition.

**The audit overstated this one.** Under a definition, 30 of 31 annotations were
already correct, and exactly one changed: `update-llms-txt` `false` → `true`,
because its input is a complete prospective configuration and a field absent
from the request is a field removed. The defect was the missing definition, not
the values.

The risk that mattered was not the annotation but the method: WordPress derives
the expected HTTP method from these annotations, and `destructive && idempotent`
means **DELETE**. That was checked before the change, not after —
`update-llms-txt` keeps `idempotent: false`, and verified live with the
publication flag temporarily on (and restored): `readonly=0 destructive=1
idempotent=0 method=POST`. No endpoint's method moved; no ability in this plugin
sets the DELETE pair at all. `llms-txt-verification` green afterwards.

### Task 7 — invocation telemetry — **done 2026-09-01, ADR 0029**

Shipped as an **off-by-default diagnostic mode**, not an always-on log. What
landed:

| Piece | File |
|---|---|
| DTO (shapes only — no field can hold input) | `src/Application/Telemetry/InvocationAttempt.php` |
| Port | `src/Application/Telemetry/InvocationLog.php` |
| Ring-buffered sink, buffers per request | `src/Infrastructure/WordPress/WordPressInvocationLog.php` |
| Lifecycle listener | `src/Adapter/Abilities/AbilityInvocationTelemetry.php` |
| Flag + buffer options, uninstall cleanup | `Installer`, `uninstall.php` |
| Runtime proof | `tests/Integration/invocation-telemetry-verification.php` |

Three design points worth carrying forward:

- **`wp_after_execute_ability` fires only on success.** Every failure path in
  `WP_Ability::execute()` returns a `WP_Error` before it. So the pair of hooks
  yields `completed` and `attempted` and nothing else — the *reason* a call did
  not complete is not observable from any hook. The outcome is named `attempted`
  rather than `denied` so no reader believes it says more than it does.
- **One write per request, not per invocation.** Entries buffer in memory and
  flush on `shutdown`, which is what makes an outcome knowable without paying
  two writes for every successful call: the entry is created before the
  permission check and upgraded in place after success.
- **Bounded by construction**, a 200-entry ring buffer in a non-autoloaded
  option. No pruning job, so nothing here can grow into or evict the mutation
  audit — trap 3 closed structurally rather than by policy.

Verified live, all six properties: flag off → no listener attached and nothing
written; a `permission_callback` denial → **exactly one entry, `attempted`,
attributed to the right principal, core error `ability_invalid_permissions`** (the
gap closing); a success → one entry upgraded to `completed`, not duplicated;
three reads → audit rows 584 → 584; 225 entries written → 200 kept; every stored
entry carries exactly the five declared fields. Full inventory 24/24.

### Task 7 (original plan text)

The gap this closes is real and currently unfixable from inside our
architecture: **an ability rejected at `permission_callback` leaves no trace
anywhere.** Reads leave none either. Every denial we can currently see is one
that already passed the permission layer and was refused inside a use case.
`wp_ability_invoked` is the first core hook that fires for every invocation
regardless of outcome, which is exactly the missing observation point.

Design constraints the ADR must fix:

- **A separate sink from `wpcb_audit`** (trap 3), with its own bound and its own
  table or a ring-buffered option, and its own retention.
- **Off by default**, behind a flag, and never on a public unauthenticated path.
- **No values, only shapes** — the existing `AuditEvent` records field *names*
  only; invocation telemetry records ability name, principal id, and outcome
  class. Ability input can contain content and must not be persisted.
- **A thin adapter, not a second audit system.** Registration belongs in an
  `Adapter\Abilities\*` class; the sink is an application port like `AuditLog`.
  The domain must not learn that WordPress has hooks.
- **Correlate, do not merge.** Telemetry says *attempted* (trap 4); the audit
  says *happened*. `SECURITY.md` must state which one is evidence.
- Whether `wp_ability_execute_result` is needed to record the outcome, or
  whether an invoked-only record plus the existing audit is enough. Prefer the
  latter: one hook, one row, no filter in the execution path.

Acceptance: a denial at `permission_callback` produces exactly one telemetry row
and zero `wpcb_audit` rows; a successful write produces one of each; the flag
off produces neither and registers no hook. `SECURITY.md` and
`docs/architecture/ABILITIES.md` updated in the same change.

### Task 8 — write the refusals down — **done 2026-09-01**

Landed as "WordPress 7.1 execution filters this plugin does not use" in
`docs/architecture/ABILITIES.md`, with the objection each adoption would have to
answer, plus the `meta.public` / `meta.mcp.public` distinction so nobody
"consolidates" two different flags. `wp_ability_invoked` is listed there as
ungated rather than refused, pointing at task 7.

### Task 8 (original plan text)

Add to `docs/architecture/ABILITIES.md` a short "7.1 execution filters we do not
use" section, so a later agent does not adopt them as an obvious improvement:

- `wp_pre_execute_ability` — registration-time flags already make a disabled
  area *absent* rather than present-and-refusing (`src/Plugin.php:330-349`).
  A short-circuit filter is a weaker gate that also hides the ability's absence.
- `wp_ability_permission_result` — AGENTS.md mandates a `permission_callback`
  per ability; a global filter that can flip an authorization decision is an
  invisible gate and inverts the layered model in `SECURITY.md`. The real gap it
  seems to fix — `ContentAccessManager` being enforced inside use cases rather
  than in the permission layer — is fixed by a shared gate object usable from
  both layers, not by a filter.
- `wp_ability_normalize_input` — input shaping belongs in the schema and the
  use case; a filter that mutates input before validation makes the schema stop
  describing what actually ran.
- `wp_ability_execute_result` — output redaction belongs where the payload bound
  and provenance already live; a late filter can reintroduce a leak past every
  test that asserts a response shape.
- `wp_ability_validate_input` / `wp_ability_validate_output` — our schemas are
  centralized and contract-tested; a second validation channel splits the
  contract in two.

Acceptance: documentation-only.

### Task 9 — domain rejections must not answer HTTP 500 — **done 2026-09-01**

Found by task 2, not a 7.1 item. All 86 `new WP_Error()` returns in `src/`
lacked a `status`, so core defaulted every one to **500**: an unknown
`post_types` value, a non-existent `post_id`, an invalid URL selector. Agent
clients read 500 as transient and retry; monitoring reads routine refusal as an
outage.

Landed as `src/Adapter/Abilities/AbilityError.php` — one map from the closed
public error-code vocabulary onto HTTP status, and now the only place an
ability's `WP_Error` is constructed. All 86 sites call
`AbilityError::create()`; the factory applies the status last, so extra error
data (`wpcb_invalid_custom_schema` carries validation detail) survives while no
caller can override its own status. An unmapped code answers 500 rather than
guessing a 4xx that blames the client.

Status classes and the reasoning behind each are in
`docs/architecture/ABILITIES.md`. Two decisions worth not relitigating:

- **`wpcb_*_unavailable` for an absent optional provider is 501, not 503.**
  Nothing is overloaded and retrying will not help; the install simply cannot
  implement the operation.
- **404 deliberately conflates "does not exist" and "not visible to you"**, so
  the status cannot be used to enumerate content. Written into `SECURITY.md`
  under excessive data disclosure, because the next reader will otherwise be
  tempted to turn one of them into a 403.

Verification: `AbilityErrorTest` discovers the vocabulary from the source (the
literals passed to the factory plus the application layer's `error_code()`
returns) and fails both when a code has no status and when the map carries a
code the source cannot produce — so adding an error code without a status is a
failing test rather than a silent 500. `rest-input-coercion-verification.php`
gained an `error_statuses` block asserting 400/404/400 with unchanged public
error codes plus a 200 baseline. Measured on 7.1: 400 `wpcb_invalid_input`, 404
`wpcb_content_unavailable`, 400 `wpcb_invalid_selector`. Full inventory re-run
23/23 after the change, since it touched every error path in the plugin.

**Client-visible contract change**, so it belongs in a release note: consumers
that saw 500 now see 4xx.

## Sequencing

```
1 · 2 · 3 · 4 · 5 · 6 · 7 · 8 · 9  — all done 2026-09-01
```

Task 2 ran first among the code tasks because it was the only one that could
reveal a defect in shipped behavior. It did — though not the one expected: 7.1's
input coercion turned out to be safe, while the error-status mapping was not
(task 9). Task 7 landed last because it is the one that changes the security
posture rather than a contract detail.

Tasks 1 and 2 are complete. Task 2 ran first among the code tasks because it was
the only one that could reveal a defect in shipped behavior — and it did, though
not the one expected: coercion is safe, while the error-status mapping is not
(task 9).

## Release framing

`0.9.0` is reserved for the complete Slice 5 (redirects) feature. This work is
therefore either its own `0.9.x` patch line after Slice 5 ships, or — if it
lands first — a `0.8.4` for tasks 1–4 and 8 (no ability, schema, or contract
change beyond diagnostics) with tasks 5–7 held for a minor release, since they
touch exposure metadata, annotations, and the security posture.

Task 2 must run against a real 7.1 install. Static checks alone are not a pass
for it — releases 0.1.3 through 0.3.0 shipped that way and the plan's own
verification backlog is the record of what that cost.

## Documents to update

| Task | Documents |
|---|---|
| 1 | ADR 0027, `AGENTS.md`, `readme.txt`, `wp-content-bridge.php` |
| 2 | `docs/architecture/ABILITIES.md`, `docs/setup/VERIFICATION.md` |
| 3 | `docs/architecture/ABILITIES.md` (diagnostics contract) |
| 4 | ADR 0025 (consequences note; the decision does not change) |
| 5 | `docs/architecture/ABILITIES.md` |
| 6 | ADR 0028, `docs/architecture/ABILITIES.md` |
| 7 | ADR 0029, `docs/architecture/SECURITY.md`, `docs/architecture/ABILITIES.md` |
| 8 | `docs/architecture/ABILITIES.md` |
| 9 | `docs/architecture/ABILITIES.md` (Versioning), `docs/architecture/SECURITY.md` |

All tasks: `.agents/status.md` and `docs/architecture/CODE_MAP.md` when a file
is added.

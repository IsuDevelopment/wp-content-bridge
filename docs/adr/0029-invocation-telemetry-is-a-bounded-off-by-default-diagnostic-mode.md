# ADR 0029: Invocation telemetry is a bounded, off-by-default diagnostic mode

## Status

Accepted (2026-09-01). Task 7 of `docs/plan/WP_7_1_ABILITIES_ADOPTION_PLAN.md`,
the last item in that plan.

## Context

**An ability refused at `permission_callback` currently leaves no trace
anywhere.** The `wpcb_audit` table records mutation attempts only, and only
those that reached a use case (`MutationForbidden`, `TrashUnavailable`); reads
and previews take no `AuditLog` and structurally cannot audit. So the questions
an operator actually asks after handing an agent credentials — *is something
hammering an ability it may not call? which principal? how often?* — have no
answer in this plugin at all.

WordPress 7.1 added `wp_ability_invoked`, which fires for **every** invocation
before normalization, validation and the permission check. It is the first
vantage point from which a denial is observable. Two properties of core's
`WP_Ability::execute()` shape everything below:

- `wp_ability_invoked` fires on every call, before anything can reject it.
- `wp_after_execute_ability` fires **only on success** — every failure path
  returns a `WP_Error` before it.

So the pair yields *attempted* and *completed*, and nothing in between. There is
no hook that fires on the failure paths, so the **reason** a call did not
complete is not observable from here.

Three hazards were identified before writing any code:

1. **`wpcb_audit` prunes at 5,000 rows.** Reads outnumber writes by orders of
   magnitude on an agent workload, so writing invocations there would evict the
   mutation history the security posture depends on.
2. **Telemetry is not evidence.** A row from `wp_ability_invoked` says a call was
   *attempted*, not that anything happened. Conflating the two sinks would let a
   reader treat an attempt as an act.
3. **Ability input can contain content.** Persisting it would turn a diagnostic
   into a second copy of the site's content, outside every bound the read
   abilities enforce.

## Decision

Invocation telemetry is a **diagnostic mode**, not an accounting system.

- **Off by default**, gated by the non-autoloaded option
  `wpcb_invocation_telemetry_enabled`. While off, the hooks are **not
  registered** — consistent with this plugin's rule that a disabled feature is
  absent rather than present-and-refusing. Nothing is written and no hook runs.
- **Its own sink, never `wpcb_audit`.** A ring-buffered, non-autoloaded option
  `wpcb_invocation_telemetry` holding the most recent 200 entries. A ring buffer
  is bounded *by construction*, so there is no pruning that could evict anything
  else, and no schema migration for a diagnostic. Deleted on uninstall.
- **One database write per request, not per invocation.** Entries buffer in
  memory and flush once on `shutdown`. This is what makes the outcome knowable
  without paying two writes for every successful call: the entry is created by
  `wp_ability_invoked` and upgraded in place to `completed` by
  `wp_after_execute_ability` before the flush.
- **Shapes, never values.** An entry records the ability name, the user ID, the
  channel (`rest` / `cli` / `admin` / `other`), the outcome, and a GMT
  timestamp. Ability input, error messages, and result payloads are never
  passed to the sink, which has no field that could hold them.
- **Outcome is `completed` or `attempted`, and `attempted` means only "did not
  complete".** It does not distinguish a permission denial from invalid input or
  an internal error, because no hook fires on those paths. Naming it `attempted`
  rather than `denied` or `failed` is deliberate: an operator reading it must not
  believe it says more than it does.
- **Only this plugin's abilities.** The listener ignores any ability outside
  `AbilityCategory::SLUG`, so enabling a WPCB diagnostic never starts logging
  another plugin's invocations.
- **No new public surface.** The log is not exposed through an ability or REST.
  It is an option, read by an operator with `wp option get` or WP-CLI. Exposing
  principal activity to an agent client would create the disclosure problem this
  ADR is meant to help investigate.
- **Layering.** The port `Application\Telemetry\InvocationLog` and the DTO
  `InvocationAttempt` live in the application layer; the WordPress hooks live in
  `Adapter\Abilities\AbilityInvocationTelemetry`; the buffering ring-buffer sink
  is infrastructure. The domain does not learn that WordPress has hooks.

### What this is not

It is not the mutation audit's replacement or extension. `wpcb_audit` remains
the record of what *happened* to content, with its own retention and its own
threat model. Telemetry records what was *attempted*. Correlate them; do not
merge them, and never cite a telemetry entry as evidence that an operation
occurred.

## Consequences

- The gap closes only while the mode is on. That is the trade accepted here: an
  always-on log of every read invocation is a write per request on the hot path,
  and a plugin whose reads are the product should not pay that by default. An
  operator investigating a suspected denial pattern turns it on, reproduces,
  reads 200 entries, turns it off.
- 200 entries is a *recent-activity window*, not a history. Under heavy traffic
  it covers seconds. This is stated in `SECURITY.md` so nobody plans a
  compliance story around it.
- The flush is on `shutdown`, so a fatal error mid-request loses that request's
  buffered entries. Acceptable for a diagnostic; unacceptable for an audit,
  which is one more reason the two sinks stay separate.
- `wp_ability_invoked` receives the raw input as its second argument. The
  listener must never pass it on. The `InvocationAttempt` DTO has no field for
  it, so the type system, not reviewer discipline, enforces this.
- Because entries carry a user ID, the option is principal data. It is
  non-autoloaded, never exposed through an ability, and removed on uninstall.
- WordPress 7.1 is required for the hook, which ADR 0027 already guarantees.

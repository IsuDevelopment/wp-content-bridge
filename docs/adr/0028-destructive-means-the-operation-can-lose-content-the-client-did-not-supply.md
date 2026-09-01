# ADR 0028: `destructive` means the operation can lose content the client did not supply

## Status

Accepted (2026-09-01). Task 6 of `docs/plan/WP_7_1_ABILITIES_ADOPTION_PLAN.md`.
Narrows nothing; it gives an existing published annotation a written definition
it never had.

## Context

Every ability declares `readonly`, `destructive` and `idempotent`. Clients use
them to decide what may run unattended and what needs a human. `readonly` and
`idempotent` have obvious readings. `destructive` never had a definition
anywhere in this repository, so 31 abilities were annotated by intuition, and the
7.1 adoption audit flagged the results as inconsistent: `create-draft` and
`update-llms-txt` were `false` while `update-block`, `update-block-attributes`,
`update-service-schema` and `update-custom-schema` were `true`.

Two candidate readings were available, and they disagree:

1. **"The operation writes."** Then every write is destructive, the annotation
   carries no information beyond `readonly`, and a client gains nothing by
   reading it.
2. **"The operation can lose content the client did not supply."** Then it marks
   exactly the case a human should confirm: the request cannot describe what it
   is about to overwrite, so the caller cannot know what it is giving up.

Reading 2 is the only one that makes the annotation worth transmitting.

There is a second, sharper constraint. WordPress derives the **expected HTTP
method** from these annotations
(`WP_REST_Abilities_V1_Run_Controller::validate_request_method()`): `readonly`
means GET, `destructive` **and** `idempotent` together mean DELETE, everything
else is POST. An annotation change is therefore not only advisory — it can move
an endpoint's method and break every existing client of it.

## Decision

**`destructive => true` if and only if the operation can lose content or
configuration the client did not supply in the request.**

Applied to the catalogue, the rule endorses the existing annotation on 30 of 31
abilities and changes exactly one:

- `create-draft` — creates a new object, destroys nothing. `false`, unchanged.
- `update-content`, `update-seo`, `update-block`, `update-block-attributes`,
  `update-service-schema`, `update-custom-schema` — replace stored values, so
  the previous ones are gone. `true`, unchanged.
- `trash-content` — `true`, unchanged.
- `restore-trashed-content`, `transition-content-status` — move an object
  between states without losing content. `false`, unchanged. Publication is
  consequential, but it is gated separately (ADR 0024) and it is not content
  loss; overloading `destructive` to mean "risky" would put us back in reading 1.
- `regenerate-llms-txt` — rebuilds a derived document from the stored
  configuration. Nothing authored is lost. `false`, unchanged.
- **`update-llms-txt` — `false` → `true`.** Its own description is the argument:
  it "validates a **complete prospective** llms.txt configuration" and
  "atomically **replaces** the stored configuration and snapshot". A field absent
  from the request is a field removed from the stored configuration, which is the
  rule's exact case.
- All previews — `readonly: true`, so `destructive: false`. Unchanged.

`idempotent` is untouched by this ADR.

## Consequences

- **No HTTP method moves.** `update-llms-txt` keeps `idempotent: false`, so it
  is POST before and after; DELETE requires `destructive && idempotent` and no
  ability in this plugin sets that pair. This was checked before the change
  rather than discovered after it, and it is why the change is safe to ship
  without a client migration.
- **One client-visible annotation change.** A client that consults
  `destructive` to decide whether to ask a human will now ask before
  `update-llms-txt`. That is the intended outcome: the operation replaces a
  whole stored configuration and a partial request silently drops fields.
- The rule is written into `docs/architecture/ABILITIES.md` next to the
  catalogue, so the next ability is annotated by the definition rather than by
  intuition — the failure mode this ADR exists to end.
- `AbilityMeta::write( bool $destructive, bool $idempotent )` names both
  annotations at every call site, so applying the rule is a readable diff. The
  earlier same-named per-class helpers taking one unexplained boolean are what
  let the inconsistency survive this long.
- The audit's framing — "`destructive` is used inconsistently for non-deleting
  writes" — overstated the problem. Under a definition, 30 of 31 annotations
  were already right. The defect was the missing definition, not the values.

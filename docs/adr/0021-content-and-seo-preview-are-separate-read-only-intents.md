# ADR 0021: Content and SEO preview are separate read-only intents

**Status:** Accepted
**Date:** 2026-08-07

## Context

`update-content` and `update-seo` are the plugin's core write Abilities. A
caller currently cannot see what either write would actually produce —
normalized field values, the block-markup round-trip, or a stale-token
rejection — without calling the write itself and living with its effect.

ADR 0019 (Service schema) and ADR 0020 (Custom Schema) already solved this
exact problem for their own optional surfaces: a dedicated `preview-*` Ability
that shares the write's input contract, validation, policy, provider, and
optimistic-concurrency check, but performs no mutation. Slice 1A of the
editorial operations roadmap (`docs/plan/EDITORIAL_OPERATIONS_ROADMAP.md`)
extends that pattern to the two core writes rather than inventing a second
mechanism.

As with ADR 0019, the alternative of adding a `dry_run` flag to `update-content`
or `update-seo` was rejected: it would force one public Ability to describe
itself as both `readonly: false, destructive: true` for some calls and
`readonly: true, destructive: false` for others, depending on a flag agent
clients do not reliably inspect before choosing whether a tool needs approval.
Safety annotations must stay truthful at the tool level, not the call level.

## Decision

Add two new Abilities, each mirroring one existing write:

- `wp-content-bridge/preview-update-content` mirrors `update-content`;
- `wp-content-bridge/preview-update-seo` mirrors `update-seo`.

**Naming.** Per the naming convention fixed on 2026-08-07 (see Step 0 of
`docs/plan/SLICE_1A_EXECUTION_PLAN.md` and the roadmap), a preview ability ID
is `preview-` followed by the exact ID of the write it mirrors. This is
already the shape of the two previews shipped in 0.3.0
(`preview-update-service-schema`, `preview-update-custom-schema`), so Slice 1A
does not introduce a second convention.

**Response field.** A preview response reports `writes_performed: false`, not
`dry_run`. The two shipped 0.3.0 previews still expose `dry_run: true` under
their earlier contract and are unaffected by this ADR, but the roadmap
reserves the word `dry_run` for the destructive-Ability-with-a-flag *mode*
that the architectural rules above forbid. Reusing that word for a plain
response flag on a Preview Ability has proven confusing when read next to the
architectural rule that forbids it as a mode, so every preview added after
this ADR uses `writes_performed` instead. A later slice may reconcile the two
shipped fields if the shipped previews are revised for another reason; this
ADR does not require that migration on its own.

**Contract sharing.** Each new use case:

- builds the exact same validated input DTO the write uses
  (`ContentUpdate::from_input()` / `SeoUpdate::from_input()`), so bounds and
  field validation are defined once;
- resolves the target and checks the same per-post-type policy
  (`ContentOperation::UPDATE` / `ContentOperation::UPDATE_SEO`) and the same
  optimistic-concurrency token as the write, failing with `MutationConflict`
  on a stale token before doing anything else;
- for content, validates block markup with the existing
  `BlockMarkupValidator` and round-trips it through `parse_blocks()` /
  `serialize_blocks()` only — never through `the_content`-style filters that
  could mutate what would actually be stored;
- for SEO, calls the same private `YoastSeoWriter::normalize_fields()` that
  `write()` calls, so per-field sanitization (`sanitize_text_field`,
  `esc_url_raw`) is defined once rather than mirrored in two places. `write()`
  differs only by adding the Yoast storage encoding and the writes themselves.
  Both entry points share one `assert_supported()` provider-tier guard, and
  both resolve social-image attachment IDs through the existing read-only
  `SeoImageRepository` port; preview never calls `WPSEO_Meta::set_value()`.
  Unsupported SEO fields fail with `SeoFieldUnsupported` exactly as they do in
  the write;
- takes no `AuditLog` dependency at all, so a mutation audit row cannot be
  recorded even by accident.

**New read-only ports.** Neither preview can be built from the existing write
ports alone, because those ports expose no way to read a post's *current*
field values or a provider's *current* SEO configuration without also being
able to write them:

- `ContentSnapshotRepository` (`src/Application/Mutation/`) adds one method,
  `content_snapshot()`, returning the current title/block-markup/excerpt for
  comparison. `WordPressContentMutationRepository` implements it alongside
  the existing `ContentMutationRepository`, mirroring how
  `SchemaExtendedServiceSchemaWriter` already implements both a Reader and a
  Writer interface on one class.
- `SeoPreviewProvider` (`src/Application/Mutation/`) adds `current()` (the
  full resolved SEO document, since it already exists and is already public)
  and `preview()` (prospective *configured* field values only — explicitly
  not the resolved public output, which does not exist until the change is
  actually rendered). `YoastSeoWriter` implements it alongside the existing
  `SeoWriter`.

Both new ports are additive. No existing interface (`ContentMutationRepository`,
`SeoWriter`) changes shape, so no existing test double or production
implementer needs to change.

**Warnings.** The roadmap requires machine-readable warnings for content
deletion/replacement. `ContentPreviewResult` carries a bounded
`warnings: {code, field, message}[]` list, emitting `content_replaced` /
`content_deleted` when `block_markup` changes and `taxonomies_replaced` when a
taxonomy assignment is present (assignments always replace a taxonomy's
current terms). `SeoPreviewResult` carries the same shape and emits
`field_cleared` when a field is explicitly set to an empty string.

## Consequences

- Agents can validate a content or SEO change before committing to it, using
  the same two Abilities pattern already proven for Service and Custom
  Schema.
- `preview-update-content` and `preview-update-seo` are registered only when
  `MutationAbilities` registers at all (i.e. only when `wpcb_writes_enabled`
  is on), and require the exact same WPCB capability and native object
  capability as the write they mirror.
- The closed MCP profile grows by two conditional entries.
- `ContentSnapshotRepository` and `SeoPreviewProvider` are new, narrow ports
  with a single production implementer each; they add no new WordPress write
  surface and cannot be reached without also passing the write's own
  authorization and concurrency checks.

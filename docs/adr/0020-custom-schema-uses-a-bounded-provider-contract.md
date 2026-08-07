# ADR 0020: Custom Schema uses a bounded provider contract

**Status:** Accepted  
**Date:** 2026-08-03

> **Amended 2026-08-07 — ability ID renamed.** The decision below is unchanged,
> but the public ID `preview-custom-schema` is now
> `preview-update-custom-schema`. A preview ability is named `preview-` plus the
> exact ID of the write it mirrors. The rename landed in 0.4.0, while the
> surface was one release old with a single connector.

## Context

The fixed Service contract is appropriate for common local-service markup but
does not cover every useful Schema.org type. Adding a bespoke bridge Ability
and UI model for each type would multiply provider coupling, maintenance, and
release work. A generic WordPress metadata or provider-dispatch endpoint would
solve the extensibility problem by creating a much larger security problem.

Schema Extended 0.3.0 provides a standalone Custom Schema editor and a public
`Integration_API` contract. It owns storage, bounded JSON parsing, graph-node
validation, supported placeholders, Yoast ID protection, and final graph
integration. The bridge needs a safe way for authorized agents to read,
validate, and update that same configuration without duplicating provider
internals.

## Decision

Expose three conditional semantic intents:

- `get-custom-schema` reads saved configuration and structural diagnostics;
- `preview-custom-schema` validates prospective configuration without writing;
- `update-custom-schema` performs the mutation.

They register only when global writes are enabled and Schema Extended exposes
compatible `Integration_API` contract version 1.0. All operations require
`wpcb_manage_seo`, native `edit_post`, and the target type's `update_seo`
policy. Preview and update also require the current content `version_token`.

The write input contains only `enabled?: boolean` and `source?: string`. Source
must be valid UTF-8 without null bytes and is capped at 100,000 bytes. It is
passed only to the provider's public validation/update functions. The connector
does not accept caller-selected metadata keys, option names, provider methods,
callbacks, script markup, or WordPress REST routes.

Provider output is normalized and bounded again before it reaches an Ability.
Validation exposes at most 20 open Schema.org node objects plus strict bounded
diagnostics. This intentional open node payload is data inside a closed output
envelope, not a WordPress write surface. Enabled invalid JSON is rejected with
safe diagnostics; invalid source may be preserved only while disabled and will
not render. Mutation audit records only `enabled` and/or `source` field names,
never the JSON value.

Preview performs structural source validation only and returns
`context_resolved: false`. The bridge will not couple to Yoast internals to
simulate a speculative front-end graph. After a successful update, the existing
`get-url-seo` Ability is the authoritative read for the complete, resolved
Yoast graph.

## Consequences

- Agents can optimize additional Schema.org types without a new connector
  release for every type.
- The bridge deliberately permits bounded Schema.org JSON while preserving a
  closed WordPress command surface.
- Provider activation, contract compatibility, capability, policy, version,
  validation, audit, and post-write verification remain independent gates.
- Preview cannot promise the final context-resolved graph; callers must perform
  a post-write `get-url-seo` verification.
- The closed MCP profile grows from 15 to 18 potential abilities.

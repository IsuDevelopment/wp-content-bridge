# ADR 0019: Service schema preview is a separate read-only intent

**Status:** Accepted  
**Date:** 2026-08-03

## Context

The structured Service integration originally exposed only
`update-service-schema`. A caller could inspect the rendered public graph after
a write, but could not independently read the saved provider configuration or
see provider-sanitized prospective values before mutation.

Adding `dry_run` to the write Ability would mix read-only and destructive
behavior under one public contract. MCP annotations would then have to describe
the complete tool as destructive even when a particular call writes nothing,
making approval behavior and agent planning less reliable.

## Decision

Expose three semantic intents through one shared provider boundary:

- `get-service-schema` reads the saved effective configuration;
- `preview-service-schema` validates the exact update document, checks the
  current optimistic-concurrency token, applies provider sanitization, and
  returns current plus prospective configuration without writing;
- `update-service-schema` remains the only mutation.

The read and preview Abilities use `readonly: true`, `destructive: false`, and
`idempotent: true`. All three require `wpcb_manage_seo`, native `edit_post`, the
post type's Update SEO policy, global writes, and a compatible standalone Schema
Extended provider. Preview and update share the same strict input schema;
`post_id` and `version_token` are required in both.

The provider adapter implements separate read and write ports. Preview consumes
the same normalization logic as write but never calls WordPress metadata write
functions, cache invalidation, or the mutation audit sink.

## Consequences

- Agents can perform read-before-write and preview-before-write workflows.
- Safety annotations remain truthful at tool level.
- The MCP profile grows by two conditional abilities.
- The raw `tools/list` smoke verifier checks required fields on the exact MCP
  descriptor so projection regressions are caught independently of endpoint
  validation.

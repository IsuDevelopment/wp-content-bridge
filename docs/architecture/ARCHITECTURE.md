# Architecture

## Context

```text
External client (Codex / ChatGPT / Gemini)
                |
                | MCP
                v
Official WordPress MCP Adapter (optional projection)
                |
                v
WordPress Abilities API
                |
                v
WP Content Bridge ability adapters
                |
                v
Application services
       |                    |
       v                    v
WordPress content port      SEO provider port
       |                    |
       v                    +--> Yoast provider
WordPress repositories      +--> Null provider
                            +--> future providers
```

## Layer responsibilities

### Domain

Contains provider- and transport-neutral concepts:

- content identity and representation;
- content query and pagination;
- SEO document, configured values, resolved output, analysis, Schema, and provenance;
- mutation intent and concurrency token;
- audit event vocabulary.

Domain code must not call WordPress functions.

### Application

Implements use cases:

- search content;
- get content;
- get SEO for content or URL;
- get editorial context;
- later create/update draft, update SEO, and publish.

Application services depend on ports/interfaces. They own orchestration and invariants, not transport serialization.

`ContentAccessManager` is a shared application policy dependency for every content read or mutation. See `CONTENT_ACCESS.md`.

### Infrastructure

Implements WordPress repositories, renderers, authorization adapters, provider detection, audit sinks, and settings persistence.

The option-backed access repository and registered post-type catalog are infrastructure adapters; neither admin nor ability adapters read options directly.

### Ability adapters

Register schemas and convert WordPress ability input/results to application DTOs. They do not query posts or metadata directly.

### Integration modules

- `Integrations/Yoast`: optional SEO provider.
- `Integrations/AgentsApi`: future optional embedded-agent/workflow bridge.
- MCP does not need a module unless diagnostics or projection metadata require it. The official adapter discovers public abilities.

## Composition

`Plugin` is the composition root. It feature-detects integrations, constructs services, and registers adapters on WordPress lifecycle hooks. It must remain small and deterministic.

## Data contracts

Core result DTOs are normalized and versioned independently from provider internals. Provider-native complex objects, notably Schema graph nodes, may be preserved within explicitly typed extension fields.

Every result carries:

- `schema_version`;
- source/provenance;
- completeness (`complete`, `partial`, `unavailable`);
- warnings with stable codes where relevant.

## Error boundary

Domain/application failures are typed. Ability adapters map them to stable `WP_Error` codes such as:

- `wpcb_not_initialized`
- `wpcb_invalid_post_id`
- `wpcb_content_not_found`
- `wpcb_content_forbidden`
- `wpcb_seo_unavailable`
- `wpcb_conflict`
- `wpcb_provider_write_unsupported`

Validation errors originating in Abilities API may remain `ability_invalid_input`.

## Extensibility hooks

Extensibility should prefer typed provider registration over broad filters. Public WordPress filters/actions will be added only for concrete integration needs and documented as API.

## Performance posture

- Search returns compact summaries by default.
- Detail representations are selected explicitly.
- SEO is optionally included in detail reads.
- Schema graphs may have configurable byte/node limits with truncation metadata, never silent truncation.
- No unbounded `posts_per_page=-1` agent surface.
- Avoid dispatching internal REST requests when a shared service/provider API is available.

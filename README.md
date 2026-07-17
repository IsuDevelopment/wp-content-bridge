# WP Content Bridge

WP Content Bridge is a standalone WordPress 7 plugin that exposes secure, provider-neutral content and SEO capabilities through the WordPress Abilities API. The official WordPress MCP Adapter can project those abilities to Codex, ChatGPT, Gemini, and other MCP clients.

The repository is intentionally independent from any site project. During early development it may be symlinked into a local WordPress installation for integration testing.

## Current status

Milestone 1B is complete. The plugin registers three private, read-only
Abilities for content search, content detail, and safe diagnostics. Their
post-type policy, WordPress capability checks, payload bounds, schemas, and REST
projection have been verified on WordPress 7.0.1. SEO and MCP transport are not
implemented yet. See [implementation plan](docs/plan/IMPLEMENTATION_PLAN.md) and
[.agents/status.md](.agents/status.md).

## Product boundary

- WordPress Abilities API defines the stable domain contracts.
- MCP Adapter is an optional projection dependency, not bundled into this plugin.
- Yoast SEO is an optional SEO provider.
- Agents API is reserved for a later optional embedded-agent integration.
- The first deliverable is read-only. Content mutation arrives only after the read surface and authorization model are verified.

## Requirements

- WordPress 7.0+
- PHP 8.2+
- Composer for source installations

## Development

```bash
composer install
composer check
```

Read [AGENTS.md](AGENTS.md) before making changes.

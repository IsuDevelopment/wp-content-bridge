# ADR 0013: Block-pattern reads use a dedicated editor policy

- Status: Accepted
- Date: 2026-07-21

## Context

Agents creating Gutenberg content need an inventory of patterns that the active
site has registered. Patterns are not content objects and therefore do not fit
the per-post-type `ContentAccessManager` policy. Their markup may contain site
copy, media references, reusable-block references, or theme/plugin-specific
editor details, so native `read` alone is too broad.

WordPress core's block-pattern REST controller requires `edit_posts`, or the
equivalent `edit_posts` capability for at least one REST-visible post type. It
also actively loads remote WordPress.org patterns. WP Content Bridge needs a
transport-neutral, bounded contract and must not cause an external fetch merely
because an agent lists local editor capabilities.

## Decision

Register `wp-content-bridge/list-block-patterns` only when the separate
`wpcb_pattern_reads_enabled` option is enabled. The ability requires both:

- the dedicated `wpcb_read_patterns` plugin capability; and
- the same native editor-level test used by the WordPress core controller:
  `edit_posts`, or a REST-visible post type's mapped `edit_posts` capability.

The application service repeats the feature and native-access decisions before
catalog access. The WordPress adapter reads only the currently registered
`WP_Block_Patterns_Registry`; it does not call remote-pattern loading functions
and never returns `filePath` or another filesystem value.

One semantic list ability owns bounded query, namespace, category, and post-type
filters. Results are sorted by stable pattern name, scan at most 1,000
candidates, return at most 50 items per page, and expose exact/inexact totals.
Metadata is returned by default. Callers may request complete block markup with
`include_content`; selected page content has a combined 2 MiB limit and fails
atomically rather than truncating valid block markup.

Pattern fields and content are untrusted output. Arrays and text fields use
explicit allowlists and bounds.

## Consequences

- Pattern discovery can be independently enabled and granted in the plugin
  settings without granting content writes.
- A read-only integration principal still needs a native editor-capable role to
  receive pattern markup, matching WordPress's own editor boundary.
- MCP projection remains a separate site-infrastructure decision. Existing MCP
  allowlists do not change automatically.
- Future pattern insertion composes this read ability with existing content
  mutations; it is not a separate generic execute-pattern action.

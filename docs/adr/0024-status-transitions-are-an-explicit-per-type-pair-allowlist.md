# ADR 0024: status transitions are an explicit per-type pair allowlist

Status: proposed.

Supersedes nothing. Refines ADR 0015, which decided *that*
`transition-content-status` uses "an administrator-configured transition graph
per content type" without deciding what that graph is, how it is stored, or what
it says when nobody has configured one.

## Context

`create-draft` creates drafts and `update-content` never touches status, so
today the bridge cannot move a post through review or publication at all. ADR
0015 reserved `transition-content-status` for that and excluded a free-form
`post_status` field on updates. `ContentOperation::TRANSITION_STATUS`
(`transition_content_status`), the `wpcb_publish_content` capability, and the
off-by-default `wpcb_publish_enabled` flag already exist; nothing consumes them
(gap 8 in `.agents/status.md`).

Three things about WordPress make the graph's shape load-bearing rather than a
detail.

**A status pair is not a status.** "May this principal set `publish`?" cannot
express "may unpublish but may not publish", which is exactly the arrangement an
editor-facing integration wants. Reviewing an allowlist of target statuses tells
an administrator nothing about which direction content can move.

**WordPress silently rewrites a transition in one direction.** Measured on
WordPress 7.0.2 rather than assumed: `wp_update_post()` given
`post_status => 'future'` with a past date stores **`publish`** — the post goes
live immediately, which is the worst possible outcome for a caller that asked to
schedule it. The reverse does not happen: `publish` with a future date stores
`publish` and the post is public at once, despite its date. So a caller cannot
schedule by setting `publish` either. A contract that reports what the caller
asked for rather than what WordPress stored would be lying in exactly the case
that matters, and `publish_at` must be validated before the write rather than
trusted to degrade safely.

**Scheduling depends on WP-Cron.** A post set to `future` becomes public only
when `wp_publish_post()` runs from the `publish_future_post` event. On a site
with `DISABLE_WP_CRON` and no system cron, scheduled content never publishes.
That is a property of the site, not of this plugin, and the plugin must not
imply otherwise.

## Decision

The graph is an **explicit allowlist of ordered `from → to` status pairs, per
post type**, stored in one non-autoloaded option and defaulting to empty.

- A transition is permitted only if its exact `(from, to)` pair is listed for
  that post type. Default deny; an unconfigured site can perform no transitions
  at all, matching every other capability surface in this plugin.
- Both ends are drawn from a fixed set: `draft`, `pending`, `private`,
  `publish`, `future`. `trash`, `auto-draft`, `inherit`, and any other
  registered status are not expressible, so a plugin-defined status cannot be
  reached by configuration mistake. Trash remains `trash-content` per ADR 0015.
- The pair allowlist is a *second* gate, not a replacement for the existing
  ones. A transition additionally requires the per-type
  `transition_content_status` policy, `wpcb_edit_content`, and native
  `edit_post`. Transitions whose target is `publish` or `future` additionally
  require `wpcb_publish_enabled`, `wpcb_publish_content`, and native
  `publish_post`.
- `publish_at` is accepted only when the target is `future`, is interpreted in
  the site timezone, is persisted as both local and UTC, and is returned in both
  forms. A `publish_at` that is not in the future is rejected rather than
  quietly downgraded to an immediate publish.
- The response reports the status **read back from storage after the write**,
  never the requested one. Where WordPress rewrote the transition, the ability
  fails rather than reporting a success it did not perform.
- `get-status-transitions` returns the pairs available for one object *and*
  principal — the configured pairs from the object's current status, minus those
  the principal cannot satisfy. It is a read: it never reveals the existence of
  a post the principal cannot read, using the same non-enumerating failure as
  every other read.

## Consequences

- An administrator can express "editors may move drafts to pending and back, and
  may unpublish, but only this one integration may publish" without a new
  ability or a code change.
- A site that has not configured the graph gains no new write surface by
  upgrading. The feature is inert until someone deliberately turns it on, twice:
  the pairs, and the publication flag for the two privileged targets.
- Reviewing authorization means reading a list of pairs, which states the
  workflow directly. A target-only allowlist would have required inferring it.
- The configuration is larger than a list of statuses, and a settings UI has to
  render a matrix. That cost is accepted; the alternative hides the direction of
  every permitted move.
- Scheduling correctness remains partly a site property. `get-status-transitions`
  reports whether the site can actually run scheduled publication, so a client
  can tell "scheduled" from "scheduled and will happen".

## Alternatives considered

**A fixed built-in graph.** Smallest surface, and no configuration to get wrong.
Rejected because it forces every site into one editorial workflow, and ADR 0015
already decided against accepting WordPress's statuses wholesale for the same
reason: the plugin should not assume a house style.

**A per-type allowlist of target statuses.** Half the configuration for most of
the benefit. Rejected on the "may unpublish but not publish" case above — it is
the common shape for an AI integration, and a target-only list cannot say it.

**Deriving the graph from WordPress capabilities alone.** Attractive because it
adds no configuration, but capabilities do not encode workflow: an editor holds
`publish_posts` and could therefore publish anything, which is the exact
authority this slice exists to withhold from an automated principal.

**Defaulting to a conservative editorial subset** (`draft ↔ pending`,
`→ private`) instead of empty. Rejected because an upgrade would then add a
write surface nobody asked for. A documented preset the administrator applies
deliberately gives the same convenience without that.

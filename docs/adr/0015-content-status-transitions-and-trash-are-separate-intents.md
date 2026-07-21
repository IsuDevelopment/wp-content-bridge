# ADR 0015: content status transitions and trash are separate intents

Status: accepted.

## Context

The original write plan reserved `wp-content-bridge/publish-content` for a
single draft-to-publish transition. WordPress editorial workflows also include
pending review, private content, scheduled publication, unpublishing, and
plugin-defined statuses. Treating publication as the only transition would
force more one-off abilities or encourage a free-form `post_status` field on
`update-content`.

Moving content to trash looks like a status change in storage, but it has a
different native capability, reversibility model, cache impact, and destructive
meaning. WordPress can also bypass trash and delete permanently when trash is
disabled, which is unacceptable for an ability named `trash-content`.

## Decision

Replace the planned, never-released `publish-content` contract with the future
semantic ability `wp-content-bridge/transition-content-status`.

The transition ability will:

- accept `post_id`, `version_token`, and a runtime-validated `target_status`;
- accept `publish_at` only for a scheduled transition;
- expose allowed transitions for the current object/principal so clients do not
  guess;
- use an administrator-configured transition graph per content type rather than
  accepting every registered status automatically;
- reject internal states such as `auto-draft`, `inherit`, and `trash`;
- require `wpcb_edit_content` and native `edit_post` for editorial transitions;
- additionally require `wpcb_publish_content`, native `publish_post`, and the
  disabled-by-default `wpcb_publish_enabled` flag for transitions to `publish`
  or `future`;
- preserve optimistic concurrency, revisions, audit events, and post-scoped
  cache invalidation.

`create-draft` remains draft-only. Creating and scheduling content is a
two-step workflow: create the draft, read/verify it, then transition it to
`future` with `publish_at`.

Trash is a separate ability: `wp-content-bridge/trash-content`. It requires its
own off-by-default `wpcb_trash_enabled` flag, `wpcb_delete_content`, native
`delete_post`, the per-type `trash_content` policy, and a current
`version_token`. It must fail closed when WordPress trash is disabled and must
verify that the resulting status is `trash`. Permanent deletion and restoration
from trash remain separate future abilities.

The previously reserved `publish_content` policy key is replaced by
`transition_content_status`. Because no publication ability was released, the
old reserved value grants no authority and is not migrated automatically.

## Consequences

- The public status workflow remains semantic and extensible without becoming a
  generic WordPress status setter.
- Publication and scheduling retain stronger authorization than ordinary
  editorial transitions.
- Trash cannot accidentally become permanent deletion because of WordPress
  configuration.
- Clients must compose draft creation with a later status transition.
- Any future permanent-delete or restore ability requires its own threat review,
  capability mapping, schema, and runtime matrix.

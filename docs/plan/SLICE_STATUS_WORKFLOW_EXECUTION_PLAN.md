# Slice 2 execution plan — controlled status workflow (`0.7.0`)

Implements ADR 0015 (the two intents) and ADR 0024 (the graph's shape).

Goal: complete draft → review → publish/schedule without a free-form
`post_status` on `update-content`, and without an unconfigured site gaining any
new write surface.

Names are fixed here so tasks do not invent competing ones:

| Thing | Name |
|---|---|
| Read ability | `wp-content-bridge/get-status-transitions` |
| Write ability | `wp-content-bridge/transition-content-status` |
| Graph option | `wpcb_status_transitions` (non-autoloaded) |
| Publication flag | `wpcb_publish_enabled` (exists, unused) |
| Capability | `wpcb_publish_content` (exists, unused) |
| Policy key | `transition_content_status` (exists, unused) |

Statuses are exactly `draft`, `pending`, `private`, `publish`, `future`.
Nothing else is expressible anywhere in this slice.

## What already exists

Do not re-create these. `ContentOperation::TRANSITION_STATUS`,
`IntegrationCapability::PUBLISH_CONTENT`, `Installer::PUBLISH_ENABLED_OPTION`,
and the `wpcb_publish_content` entry in `uninstall.php` are all present and
consumed by nothing. This slice is what makes them mean something, which also
closes gap 8 in `.agents/status.md`.

## Traps this project has already paid for

Every one of these was a real defect found by verification, not by review. They
are listed because the same shapes recur in a write slice.

1. **`wp_slash()` on every field passed to the post APIs.** They call
   `wp_unslash()` on what they are given. `WordPressContentMutationRepository::slashed()`
   exists for this; a new write path that forgets it silently strips every
   backslash. Shipped undetected from 0.1.5 to 0.4.5.
2. **Never resolve SEO for more than one post per request.** Yoast returns the
   first-resolved post's meta for every later post in the same request — gap 9.
   If cache invalidation or a response needs SEO for several posts, it must not
   loop `SeoProvider::get()`.
3. **Read back from storage; do not report the request.** ADR 0024 requires it,
   and WordPress rewrites `future`/`publish` by date.
4. **Assert the failure mode, not just the error code.** A rejected transition
   must leave `post_status` *and* `post_date_gmt` untouched — check the stored
   row, not only the returned error. Existing codes are `wpcb_conflict` and
   `wpcb_invalid_input`-style; match the convention already in `src/`, do not
   invent new ones.
5. **A disabled feature 404s or is absent; it does not advertise itself.** The
   write ability must be *unregistered* while its gates are off, not registered
   and refusing.

## Measured WordPress and environment facts

Taken on the reference LocalWP site, WordPress 7.0.2 / PHP 8.4, on 2026-08-09.
Do not re-derive these; do re-check them if the environment changes.

- `wp_update_post()` with `post_status => 'future'` and a **past** date stores
  `publish`. Scheduling with a bad date publishes immediately.
- `wp_update_post()` with `post_status => 'publish'` and a **future** date
  stores `publish`, not `future`. There is no accidental path into scheduling.
- A **status-only** change does create a revision (0 → 1), so task 6's revision
  assertion is satisfiable without also changing content.
- The reference site's timezone is the fixed offset `+00:00`
  (`timezone_string` empty, `gmt_offset` 0). **It has no DST at all.** Task 5's
  gap/fold cases therefore cannot be exercised as the site stands; the verifier
  must set a named zone such as `Europe/Warsaw` for those assertions and restore
  the original two options in its `finally`.
- `DISABLE_WP_CRON` is `true` on the reference site. Scheduled posts will sit at
  `future` forever unless cron is invoked explicitly. This is precisely the
  condition ADR 0024 requires `get-status-transitions` to report, and it means
  task 6 must drive publication with an explicit cron run rather than waiting.

## Task 1 — the transition graph domain

`Domain/Status/`: `StatusTransition` (an ordered pair), `StatusTransitionGraph`
(the per-type allowlist with `permits(from, to)`), and `StatusTransitionConfig`
built from untrusted input. Pure; no WordPress.

Reject at construction: any status outside the fixed five, a pair whose ends are
equal, and more pairs than a sane bound (20 per type, 50 types). Unknown post
types are kept but inert — a type can stop existing after configuration, exactly
as `WordPressLlmsSourceSelector` re-checks post-type registration at use.

Unit-testable in full. This is where the tests for this slice belong.

## Task 2 — the graph store and settings

`wpcb_status_transitions`, non-autoloaded, default absent. Absent means empty
means deny-all — and "absent" must stay distinguishable from "configured to
nothing", because the settings screen needs to tell them apart.

Add the option to `Installer` and to `uninstall.php`. Add the per-type matrix to
the settings screen behind `wpcb_manage_settings`, with the documented editorial
preset from ADR 0024 as a button the administrator presses, never a default.

## Task 3 — `get-status-transitions`

Read. Requires `wpcb_read_content`, native `edit_post`, and the Read policy;
uses the same non-enumerating failure as other reads for a post the principal
cannot see.

Returns: current status; the permitted target statuses from it; for each, which
gates the principal currently satisfies; whether `publish_at` is required; and
the site timezone with its current UTC offset.

Also returns whether scheduled publication can actually run on this site —
`DISABLE_WP_CRON` set with no alternate runner means `future` is reachable but
inert, and ADR 0024 requires saying so rather than implying it works.

## Task 4 — `transition-content-status`

Write. Registered only while `wpcb_writes_enabled` is on. Inputs: `post_id`,
`version_token`, `target_status`, and `publish_at` only for `future`.

Order of checks, all before any write:

1. post exists and is readable (non-enumerating failure otherwise);
2. per-type `transition_content_status` policy;
3. `wpcb_edit_content` and native `edit_post`;
4. the `(current, target)` pair is in the graph for that post type;
5. if target is `publish` or `future`: `wpcb_publish_enabled`,
   `wpcb_publish_content`, native `publish_post`;
6. `version_token` still current;
7. `publish_at` present, parseable, and strictly in the future for `future`;
   absent for every other target.

Then write through the existing mutation repository — slashed, per field. Set
`post_date` and `post_date_gmt` together; setting one and letting WordPress
derive the other is how scheduled times drift.

After the write, re-read and assert the stored status equals the target and, for
`future`, that the stored GMT date equals what was asked. If WordPress rewrote
either, fail and say what it stored. Audit field names only. Invalidate the
affected post's cache through the existing invalidator.

## Task 5 — timezone and DST correctness

`publish_at` is site-local. Persist UTC. Return both.

Three cases must be handled deliberately, not by whatever `strtotime()` does:

- a local time inside the spring-forward gap, which does not exist;
- a local time inside the autumn-fold, which exists twice;
- a site whose timezone is a fixed UTC offset rather than a named zone, which
  WordPress permits and which has no DST at all — **this is the reference
  site's own configuration**, so it is the default case here, not the exotic
  one.

Use `DateTimeImmutable` with `wp_timezone()`. Never `strtotime()` on a local
string.

## Task 6 — runtime verifier

`tests/Integration/status-workflow-verification.php`, modelled on
`block-edits-verification.php`, restoring every option and post in a `finally`.
Assert:

1. with `wpcb_writes_enabled` off, `transition-content-status` is not
   registered, while `get-status-transitions` still is. An earlier draft of
   this line said "neither ability", which contradicted task 3 above: the read
   is always registered, like every other read in this plugin, so that an
   operator can inspect the configured graph before enabling writes;
2. with an empty graph, every transition is refused and nothing is written;
3. a configured pair transitions, and the response reports the **stored** status;
4. the reverse pair, if unlisted, is refused — the "may unpublish but not
   publish" case ADR 0024 exists for;
5. `publish` and `future` are refused while `wpcb_publish_enabled` is off, even
   with the pair listed and `wpcb_publish_content` held;
6. a stale `version_token` is refused before any write — assert the stored row;
7. `publish_at` in the past is refused, not downgraded to an immediate publish;
8. a scheduled transition stores `future` with the exact expected
   `post_date_gmt`, and the response returns matching site and UTC times;
9. DST: a `publish_at` on each side of a transition boundary round-trips to the
   intended UTC instant;
10. a transition creates or preserves a revision where WordPress does, and audits
    field names only;
11. the whole roadmap flow end to end: draft → `get-status-transitions` →
    `pending` → `publish`.

Add its row to `docs/setup/VERIFICATION.md` (`Needs` = `core`).

## Task 7 — release `0.7.0`

Version, changelog, `README.md`, `ABILITIES.md`, `CODE_MAP.md`, `SECURITY.md`,
`MCP_ADAPTER.md` (profile 29 → 31), `.agents/status.md` including closing gap 8,
the roadmap table, and a dated row in "Last full run" — written only after the
run is green, as in 0.6.0.

## Definition of done

- publication remains impossible through `create-draft` and `update-content`;
- an unconfigured site can perform no transition at all;
- `publish`/`future` require three gates the other transitions do not;
- no response reports a status WordPress did not store;
- scheduling is correct across DST and under a fixed-offset timezone, and a site
  that cannot run cron is told so rather than left to assume;
- `composer check` green and the new verifier passing.

## Out of scope

Permanent deletion, restoring from trash (shipped), plugin-defined statuses,
bulk transitions, editorial comments or review assignment, and any settings
migration from the reserved `publish_content` policy key — ADR 0015 already
decided it grants no authority and is not migrated.

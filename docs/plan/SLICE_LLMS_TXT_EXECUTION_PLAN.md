# Slice 1B: llms.txt — execution plan (`0.6.0`)

Audience: an implementing agent working in this repository.

Read [`docs/adr/0023-llms-txt-is-published-through-a-virtual-endpoint.md`](../adr/0023-llms-txt-is-published-through-a-virtual-endpoint.md)
first — it carries the decision, the measured state of the reference site, and
the security reasoning. Then read the "Slice 1B" section of
`docs/plan/EDITORIAL_OPERATIONS_ROADMAP.md` for the contract requirements this
plan implements.

## Scope, cut deliberately

The roadmap's Slice 1B is a new domain, a generator, a versioned state store, a
virtual endpoint, debounced regeneration, two ownership matrices, and multisite.
**`0.6.0` ships all of that except the last two**, decided 2026-08-07:

| In `0.6.0` | Deferred |
|---|---|
| Configuration, generator, versioned snapshot store | Automated Yoast handoff (the roadmap already defers it) |
| `get` / `preview-update` / `update` / `regenerate` Abilities | Multisite behaviour and its tests |
| Virtual `/llms.txt` behind an off-by-default flag | `llms-full.txt` and `llms-docs` variants |
| Read-only ownership-conflict detection | Any automatic deactivation or file removal |
| Debounced regeneration | |

**The plugin registers no REST routes today.** This slice adds the first public
route of any kind. Treat every task below as security work.

## Working rules

- WordPress coding standards; `composer check` (PHPCS + max-level PHPStan +
  PHPUnit) must be green at the end of each task.
- **Minimal tests: one unit test file per new use case, one runtime verifier for
  the whole slice.** No tests of tests, no throwaway scripts.
- Ability IDs and response fields are stable public API.
- Commit only when the user asks.

## Names fixed up front

Do not invent alternatives; later tasks depend on these.

| Thing | Name |
|---|---|
| Publication flag (option, non-autoloaded, default `false`) | `wpcb_llms_enabled` |
| Configuration (option, non-autoloaded) | `wpcb_llms_config` |
| Snapshot (option, non-autoloaded) | `wpcb_llms_artifact` |
| Capability | `wpcb_manage_llms` |
| Abilities | `wp-content-bridge/get-llms-txt`, `preview-update-llms-txt`, `update-llms-txt`, `regenerate-llms-txt` |
| Cron hook | `wpcb_llms_regenerate` |

Bounds, applied at generation and re-asserted on write: 1 MiB whole document,
20 sections, 100 items per section, 200-character excerpts, 2,000 links.
Exceeding a bound truncates deterministically and records a warning — it does
not fail generation, because a large site must still get an artifact.

## Task 1 — domain, generator, state store

No WordPress HTTP, no Abilities, no endpoint. Pure domain plus one storage
adapter, so the generator is unit-testable without a WordPress runtime.

- `Domain/Llms/LlmsConfig` — immutable, validated: enabled post types, site
  introduction, section order and labels, grouping, excerpt visibility and
  length, max items per section, optional same-site curated links.
- `Domain/Llms/LlmsArtifact` — the generated document plus its content hash,
  generation time, byte and link counts, and warnings.
- `Domain/Llms/LlmsDocumentBuilder` — pure: takes a config and an ordered list of
  already-selected, already-authorized entries and returns the Markdown. It must
  emit the llms.txt proposal's shape: a top-level `#` title, a `>` blockquote
  summary, then `##` sections of `- [title](url): excerpt` lines.
- `Application/Llms/LlmsSourceSelector` (port) + `LlmsArtifactStore` (port).
- `Infrastructure/WordPress/WordPressLlmsArtifactStore` — reads and writes the
  two options, non-autoloaded, atomic replacement.

**Validation rules.** Valid UTF-8; no C0 control characters except newline; only
canonical same-site absolute URLs; headings bounded in depth. Generated content
is untrusted public content and must never be interpreted as instructions to a
model or connector — the builder emits it as literal Markdown text and performs
no interpolation of anything that could read as a directive.

Unit tests: `LlmsDocumentBuilderTest`, `LlmsConfigTest`.

## Task 2 — source selection

`Infrastructure/WordPress/WordPressLlmsSourceSelector`.

Only `publish`, non-password-protected posts of configured public post types.
Exclude attachments, non-public types, and anything the active SEO provider
resolves as `noindex`. Reuse the existing SEO provider port — do not call Yoast
directly, and degrade to "include" only when no provider is active, recording a
warning.

Query in bounded batches; never load the whole site into memory. This runs only
inside an Ability or a cron job, never on a front-end request.

One unit test file against fakes.

## Task 3 — the Abilities

All four require `wpcb_manage_llms`. The three writes additionally require the
`wpcb_llms_enabled` flag; **`get-llms-txt` does not**, so an administrator can
inspect state and ownership conflicts before enabling anything.

- `get-llms-txt` — configuration, current artifact summary, generation time,
  byte and link counts, ownership state (task 4), warnings, and a
  `version_token` derived from the configuration plus the artifact hash.
- `preview-update-llms-txt` — same input fields as update; builds a prospective
  document and returns current and prospective summaries plus a section diff.
  Writes nothing, takes no `AuditLog` dependency, reports
  `writes_performed: false`. It passes the roadmap's preview justification test
  easily: it produces a whole document from site content the caller cannot
  compute.
- `update-llms-txt` — validated configuration change, then regeneration, then
  atomic replacement, redacted audit, post-write read-back. A failure restores
  the last accepted state.
- `regenerate-llms-txt` — no arbitrary paths or content, idempotent for
  unchanged source and configuration.

Register in a new adapter file. Reuse existing error codes: `wpcb_conflict`,
`wpcb_invalid_input`. One unit test file per use case.

## Task 4 — ownership detection, read only

`Application/Llms/LlmsOwnershipInspector` returning a structured state: detected
owner, whether a physical artifact wins routing, whether Yoast's llms.txt
feature is enabled, the public verification result, a blocking conflict code,
and a safe administrator action string.

- Detect a physical `llms.txt` at the web root **without returning any
  filesystem path**.
- Detect Yoast's feature through a narrow, version-tested read of only
  `wpseo['enable_llms_txt']`. Never expose the whole `wpseo` option. Never
  write it.
- Do not deactivate anything and do not delete or move any file.

Measured on the reference site 2026-08-07: no physical artifact, Yoast's flag
`false`, `/llms.txt` returns 404. **The blocking path will therefore ship
without ever having fired against a real conflict.** Say so in the sign-off; do
not imply it is verified. Construct a synthetic conflict in the verifier
instead.

## Task 5 — the virtual endpoint

The security-critical task. Follow ADR 0023 exactly.

- Rewrite rule plus a `parse_request` handler, registered **only** while
  `wpcb_llms_enabled` is true. While the flag is off the rule is absent and the
  path 404s like any unknown URL — a disabled feature must be indistinguishable
  from one never installed.
- The handler performs **one option read and writes those bytes**. No post
  query, no SEO provider call, no generation, no write of any kind. No stored
  snapshot means 404 — never build one on the request.
- `Content-Type: text/plain; charset=utf-8`; strong `ETag` from the snapshot's
  own content hash, not a timestamp; `Last-Modified` from its generation time;
  bounded `Cache-Control: public, max-age=…`; `If-None-Match` and
  `If-Modified-Since` answer `304` without the body. Do **not** send
  `Vary: Cookie` — see the ADR for why, and revisit it if the response ever
  becomes requester-dependent.
- Flush rewrite rules on activation and on flag change, never on every request.
- `exit` after the response so nothing else in WordPress runs.

## Task 6 — debounced regeneration

A single scheduled `wpcb_llms_regenerate` event. Content and SEO transitions
that affect eligibility enqueue it; repeated triggers within the debounce window
collapse to one run. Only authenticated Abilities and scheduled events may
enqueue — nothing reachable anonymously, directly or by cache-busting.

**Mind the staleness window the ADR names:** a post leaving `publish` stays in
the snapshot until this runs. Debouncing must not make that window unbounded.

## Task 7 — threat model

Extend `docs/architecture/SECURITY.md` with an "Unauthenticated public surface"
section: the anonymous `GET`, what it may and may not touch, the four roadmap
conditions and how each is met, the eventual-consistency window, and the
ownership-conflict failure mode. Every existing rule in that document assumes a
capability-gated Ability; say plainly that this route is the exception and why
it is safe.

## Task 8 — runtime verifier

One file, `tests/Integration/llms-txt-verification.php`, modelled on
`block-edits-verification.php`. Restore every option in a `finally`. Assert:

1. with the flag off, `/llms.txt` returns **404** and no rewrite rule exists;
2. with it on and a snapshot stored, the route returns the stored bytes exactly,
   with correct content type, `ETag` and `Last-Modified`;
3. `If-None-Match` with the current ETag returns `304` and an empty body;
4. a request to the route performs **no** post query and creates no option
   write — assert via `$wpdb->num_queries` around the request and by comparing
   the artifact option's value and its `option_id` before and after;
5. a draft, a private, a password-protected, a `noindex` and a non-public-type
   post are all absent from a freshly generated artifact — the leak proof;
6. a post de-published after generation is absent after regeneration;
7. `preview-update-llms-txt` writes nothing and is deterministic;
8. `update-llms-txt` rejects a stale `version_token` before any write;
9. `regenerate-llms-txt` is idempotent for unchanged source and configuration;
10. a synthetic physical `llms.txt` is reported as a blocking ownership conflict
    and no response field contains a filesystem path; remove the file in the
    `finally`. **Write it to the directory serving the home URL, not to
    `ABSPATH`** — on a subdirectory install those differ, and a probe aimed at
    `ABSPATH` returns false while a real file wins routing. That false negative
    was found and fixed on 2026-08-07; this assertion is its regression. Also
    assert the inverse: a file in `ABSPATH` on a subdirectory install is **not**
    reported, because it does not win routing;
11. bounds truncate deterministically and record a warning rather than failing.

Add its row to `docs/setup/VERIFICATION.md`, `Needs` = `core`.

## Task 9 — release

`readme.txt` `Stable tag: 0.6.0` and changelog; `wp-content-bridge.php` version
and `WPCB_VERSION`; `README.md`, `docs/architecture/ABILITIES.md`, `CODE_MAP.md`,
`docs/setup/MCP_ADAPTER.md` (profile becomes 29), `.agents/status.md`; the
roadmap's release-numbering table; and a dated row in the "Last full run" table
of `docs/setup/VERIFICATION.md`.

The consuming site's MU-plugin projection is optional hygiene, not a blocker —
see "Two MCP servers, one projection" in `.agents/status.md`.

## Definition of done

- with the flag off there is no new public surface at all;
- a front-end request to `/llms.txt` performs no post query and no write, proven
  by query count, not by inspection;
- no unpublished, private, password-protected, `noindex`, or non-public-type
  content reaches the artifact;
- a physical `/llms.txt` is a reported blocking conflict, never overwritten;
- no response field anywhere exposes a filesystem path;
- `composer check` green and the new verifier passing.

## Out of scope

Automated Yoast handoff, multisite, `llms-full.txt`, `llms-docs`, deactivating
or deleting any third-party file, and any dependency on LLMagnet classes,
options, or Abilities. LLMagnet is research material only; record what was
studied and preserve both projects' GPL obligations.

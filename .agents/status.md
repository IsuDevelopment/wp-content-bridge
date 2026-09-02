# Project status

**Released version: 0.8.4.** Static quality is green at 513 tests / 1,269
assertions. Runtime verification is defined in `docs/setup/VERIFICATION.md`
and stands at 24 of 24 green on WordPress 7.1.

`0.8.4` is the WordPress 7.1 adoption release (ADRs 0027-0029, plan
`docs/plan/WP_7_1_ABILITIES_ADOPTION_PLAN.md`). **Two changes are visible to
existing clients** and the release note in `readme.txt` states both: domain
rejections now answer 4xx instead of the HTTP 500 every one of them used to
return, with public error codes unchanged; and `update-llms-txt` is now
annotated `destructive` (its HTTP method is provably unmoved, since it stays
non-idempotent). It is numbered as a patch rather than a minor only because
`0.9.0` is reserved for the complete Slice 5 feature; the minimum-version
bump to 7.1 is the one part of it that would otherwise argue for a minor,
and it is called out at the top of the changelog entry for that reason.

`0.8.3` is an internal-only patch: the redirect-provider foundation below
(port, registry, guard, Redirection adapter) lands under version control with
no Ability, capability, or MCP entry wired to it, so no behavior, permission,
or public contract changed. `0.9.0` remains reserved for the complete Slice 5
feature once its Abilities ship.
`0.7.0` completes the status workflow: transitions run against an
administrator-configured allowlist of ordered status pairs per post type (ADR
0024), empty until someone configures it, with `publish` and `future` behind
three further gates. This closes gap 8 below.

`0.7.1` is a patch with no ability, schema, or stored-value change. It makes
that allowlist practical to configure — five statuses give twenty ordered pairs
per content type, so the reference site's three types render sixty checkboxes —
by adding row, column, and whole-matrix bulk selection, and it warns before the
editorial preset discards a hand-built matrix. The toggles carry no form field,
so what the matrix submits is unchanged. It also fixes a packaging leak: the
maintainer notes file shipped inside every release artifact from 0.5.0 through
0.7.0.

`0.8.0` makes the plugin project its own abilities (see the section below).

**Decided 2026-08-14: next release is Slice 5 (permalinks and dual redirect
providers), not Slice 3.** See `docs/plan/EDITORIAL_OPERATIONS_ROADMAP.md`
"Release numbering" for the reordering rationale. Slice 3 (revision inspection
and recovery) and Slice 4 (media/featured image) are deferred one release each,
to `0.10.0` and `0.11.0`; neither is a dependency of Slice 5.

**Phase 5A (research/ADR) done the same day: ADR 0026 proposed.** Redirection
(John Godley) has a documented REST/PHP API and a permission-filter pair that
lets WPCB call it without `manage_options`; Yoast SEO Premium has no documented
API, only reverse-engineered internal classes, so its adapter is gated behind a
version-pinned compatibility fixture and does not ship first even though it
stays the preferred runtime provider once available. No Ability code exists yet
for Slice 5 — ADR 0026 must be accepted before `search-redirects`/
`create-redirect`/`update-permalink` and the rest of Phase 5A's candidate
Abilities are implemented.

**Redirect foundation landed 2026-08-14, still unreachable from any Ability.**
Domain values (`RedirectSourcePath`, `RedirectTargetUrl`, `RedirectStatusCode`,
`RedirectProviderStatus`, `RedirectRule`), the `RedirectProvider` port,
`RedirectProviderRegistry`/`NullRedirectProvider`, and the cross-cutting
`RedirectCandidateGuard` (reserved prefixes, live-content shadow, collision,
3-hop chain/loop bound) are built and unit-tested — see `docs/architecture/
CODE_MAP.md` "Redirect feature". `composer check` is green (PHPCS, max-level
PHPStan, 498 tests / 1,208 assertions). Not built: the Yoast Premium adapter,
`update`/`disable` on the port, any Ability/capability/feature flag/audit
event/MCP profile entry, and a permanent `tests/Integration` runtime verifier
for Redirection (today's pass was manual and left no repeatable fixture).

**Redirection adapter reconciled against a live install, same day.** Activated
Redirection 5.9.0 on Kormas Local (`docs/setup/VERIFICATION.md`'s designated
environment; restored to its original inactive state — no tables, no options —
afterward) and drove `RedirectionProvider` through a throwaway `rest_api_init`
route as an authenticated `wpcb_manage_redirects`-only principal. Reading its
actual source (not just `redirection.me`'s docs, which turned out wrong on two
of these) found and fixed four defects, all now covered by unit tests against
the corrected shapes:
1. `is_available()` used `array_key_exists()` against `get_namespaces()`,
   which returns a plain list, not a namespace-keyed array — always false.
2. `status()` read a `REDIRECTION_VERSION` constant that does not exist;
   fixed to read the `Version:` header via the real `REDIRECTION_FILE`
   constant.
3. `map_item_to_rule()` read a `status` string field that Redirection's own
   `to_json()` never returns — the real field is `enabled` (bool). Also:
   `POST /redirect` returns the same `{items, total}` list shape as `GET`,
   never the created item alone, and its `filterBy[url]` filter is a
   substring `LIKE`, not exact — both `search()` and `create()` now extract
   the exact match themselves.
4. `create()` sent no `group_id`, which the sanitizer defaults to `0` — an ID
   no fresh install has, so every create failed closed with "Invalid group".
   Fixed to target group `1` ("Redirections"), the default every install's
   database installer creates.

A fifth defect surfaced only after the others were fixed: `create()` never
trailing-slash-normalized its source the way `search()` does, so a redirect
created for `/x` on a trailing-slash site (`/%postname%/`) was unfindable by a
later `search('/x')` normalizing to `/x/`. Fixed by normalizing in `create()`
too. End-to-end proof: `search(missing) → null`, `create() → rule`,
`search(created) → same rule`, cleanup verified empty.

`0.8.1` closes the production ownership-migration gap. An inactive LLMagnet can
leave physical `llms.txt`, `llms-full.txt`, and `llms-docs` outputs in the web
root, where the first file wins before WordPress and the companions keep stale
content public. WPCB now detects all three and offers an explicit wp-admin-only
adoption action after its own configuration, snapshot, publication flag, and
pretty-permalink route are ready. Only those exact targets can move, all receive
one `.backup_YYYYmmdd_His` suffix, symlinks/type mismatches/collisions fail
closed, no target is deleted, and a partial multi-target failure is rolled back
best-effort. The action additionally requires native `activate_plugins`, rejects
multisite, records a redacted audit event, and is not an Ability or MCP tool.

The same patch makes route readiness and legacy companions visible in ownership
diagnostics, verifies the public endpoint before configuration exists, returns
post-write ownership with llms mutations, and corrects the update/regenerate
idempotency annotations. The full 22/22 runtime inventory and the static gate
(PHPCS, PHPStan, 425 tests / 1,126 assertions) passed on 2026-08-11.

`0.8.2` closes the circular UX prerequisite left in that
workflow. Both steps are now always visible. Step 1 creates a conservative
initial bridge configuration and snapshot from core site identity plus public
content types already allowed by Content Access Read policy; it accepts no
configuration fields and does not enable publication. Step 2 remains disabled
until its actual missing prerequisites are satisfied, which are listed
individually instead of hiding the action. The snapshot preparation requires a
nonce, `wpcb_manage_settings`, and `wpcb_manage_llms`; legacy adoption retains
its stronger plugin-management gate and exact-target filesystem constraints.
No Ability or MCP tool was added. PHPCS, maximum-level PHPStan, 429 tests / 1,137
assertions, and the full 22/22 runtime inventory pass on 2026-08-12.

Production evidence taken through Kormas Live MCP before the release: WPCB
0.8.0 was active with publication enabled but no stored config or artifact;
Yoast llms.txt was disabled; a 7,916-byte physical `/llms.txt` still owned the
path and failed the H1 requirement. LLMagnet's `/llms-docs/` layout is migration
input only. WPCB does not reproduce it; llms.txt v2 Markdown alternates would be
a separate anonymous-surface feature requiring a new ADR and leak matrix.

Recorded 2026-08-11, unscheduled and undecided: an in-editor AI schema assist —
one button that proposes structured data and writes only on an explicit accept,
built on the core AI Client rather than the experimental `WordPress/ai` plugin.
The domain layer for it already shipped; what it needs is a model call, an editor
surface, and a prompt-injection design that keeps model output out of the write
path. Requirements and the required mitigations are in the "Future backlog —
in-editor AI schema assist" section of `docs/plan/IMPLEMENTATION_PLAN.md`. No ADR
yet, so nothing may be implemented from it.

## Redirect abilities shipped into the tree — 2026-09-02

`search-redirects` and `create-redirect` are registered behind
`wpcb_redirects_enabled` (write also behind `wpcb_writes_enabled`) and the new
`wpcb_manage_redirects` capability. Verified on the live 7.1 reference site,
which runs Yoast Premium 28.0 with Redirection inactive: both abilities
register, the Yoast adapter is detected at 28.0, and `redirection` reports
configured-but-absent — the "no provider" versus "no redirects" distinction
working on real data. `tests/Integration/redirects-verification.php` is the
repeatable fixture; the inventory is now 25/25.

**A live probe found a real defect before release, and it was the important
kind.** Creating a redirect for `/` **succeeded**. The live-content shadow
guard relied on `url_to_postid()`, which answers `0` for the site root whether
the front page is a static page or the blog index — so the guard read the
busiest URL on the site as dead content. The rule was actually written to
Yoast's store during the probe and was removed immediately (both derived export
options confirmed empty afterwards). Fixed by handling the site root and every
public post-type archive explicitly, re-verified live on `/` and on
`/realizacje/`, and the verifier now asserts it. **Residual gap, stated in the
class rather than papered over:** term archives and other rewrite-driven routes
are still not resolved. Matching the rewrite rules does not help — with pretty
permalinks the generic `pagename` rule matches nearly every path, so a rule
match cannot tell a live route from a dead one; resolving each candidate
properly means running the query WordPress itself would run.

The same pass closed the capability gap recorded below: `SCHEMA_VERSION` went
to 12, so `maybe_upgrade()` re-runs `activate()` and grants
`wpcb_manage_redirects` on installs that are already active. Confirmed live —
`schema=12`, administrator holds the capability. Without the bump the
capability would have existed in code and on no role, making every check on it
false for everyone.

Reserved paths were widened while fixing the shadow guard, since the same probe
showed how much a redirect can shadow: core endpoints (`wp-includes/`,
`wp-login.php`, `wp-cron.php`, `wp-signup.php`, `wp-activate.php`,
`xmlrpc.php`, `wp-sitemap*`, `robots.txt`) and **this plugin's own**
`llms.txt`/`llms-full.txt` — a redirect over those would silently disable a
feature the same plugin serves.

Shape decisions, all from the two-plugin reality: `provider` is required on the
write and never inferred; a write to an unavailable provider is refused rather
than substituted; the read reports one entry per provider
(`claimed`/`free`/`not_representable`/`unavailable`) so one plugin's unreadable
rule never blanks out the other's readable answer, and neither refusal is ever
reported as `free`; and `held_by_multiple` names the routing hazard neither
plugin's own screen shows.

Per-provider Abilities were considered and rejected — they would double a
permanent public contract surface, two near-identically named tools are a known
way for an agent to pick the wrong one (here: "wrote the rule into the plugin
that loses"), and the cross-provider guard would still be needed, so the port
would not disappear, only stop being the Ability boundary.

Not built: `update`/`disable` on the port, and the ADR 0030 statistics port.

## Yoast Premium redirect adapter built — 2026-09-02, still unwired

`Infrastructure\Yoast\YoastPremiumRedirectProvider` implements the redirect
port against Premium 28.0, unblocked by ADR 0026's amendment. It calls the
manager in-process (classmap-autoloaded, no `is_admin()` guard) and never
touches `WPSEO_Redirect_Page`/`_Ajax`. Four deliberate choices:

- **It asserts Yoast's native `wpseo_manage_redirects` itself**, because the
  manager checks nothing. This is *not* the bridge gate — that stays with the
  Ability's `permission_callback`, as everywhere else in this plugin.
- **It never calls `WPSEO_Redirect_Validator`**, which issues a live outbound
  `wp_remote_head()` against the target. A redirect write must not silently
  make an HTTP request to a third party.
- **Writes go through `create_redirect()`**, which calls `save_redirects()` and
  so regenerates the two derived export options the front-end matcher reads.
  Writing the canonical option alone would create a rule that never fires.
- **A rule Premium holds but this plugin cannot express** (regex format, a
  `307`/`451` status, an off-site target) raises
  `RedirectRuleNotRepresentable`, never "no rule found". Answering "none"
  would let the guard create a duplicate for a path Premium already claims.

Two defects were found by writing the tests rather than after: prepending a
slash to Premium's off-site target manufactured the same-site path
`/https://elsewhere.example/x`, which the neutral target validator then
accepted as local; and Premium stores plain origins with **both** slashes
trimmed (`old-page`), unlike Redirection's `/old-page`, so the neutral form
has to be translated in both directions or every search misses its own write.

**Gap, deliberately not closed here: `wpcb_manage_redirects` is not registered
anywhere.** `RedirectionProvider` only names it inside Redirection's own
capability filters, so nothing broke; but no role holds it, so any
`current_user_can( 'wpcb_manage_redirects' )` is false for everyone including
administrators. Registering it touches `IntegrationCapability`, the installer's
grant list, `uninstall.php`, the admin surface and the phpcs allowlist — it is
an authorization change and belongs with the Abilities increment, not as a
drive-by inside an adapter commit.

## Slice 5 research corrected from plugin source — 2026-09-01

Both redirect backends were read from the source installed on the designated
environment, because ADR 0026's Yoast findings came from documentation and a
community gist. One of them was wrong.

**Yoast SEO Premium 28.0 does have a redirect REST API** (`yoast/v1/redirects`,
`/delete`, `/list`, `/update`, `/settings`, all behind
`wpseo_manage_redirects`), instantiated on every request, plus a fully
non-admin-callable manager class set and a `wp yoast redirect` CLI command set.
ADR 0026 is amended in place with the correction and with what it changes:
Decision 1's build order and Decision 3's fixture-gated internal-class path
both rested on Yoast having no callable API, and both are now open. Three
further source facts an adapter must respect: redirects live in three options,
of which two are derived caches the front end actually reads (so every write
must go through `save_redirects()`); the manager performs **no** capability
check, so this plugin is the only gate when calling in-process; and
`WPSEO_Redirect_Validator` issues a live outbound `wp_remote_head()` against
the target.

**Statistics are provider-asymmetric, and that is the load-bearing finding.**
Yoast Premium 28.0 and Free 28.4 collect no 404 or hit data at all — no table,
no option, no counter; the Search Console crawl-error screen is a stub whose
own copy says Google discontinued the API. Redirection 5.9.0 keeps
`{prefix}redirection_404` and `{prefix}redirection_logs` (one row per hit) plus
`last_count`/`last_access` per redirect, and its `GET /404` checks only
`redirection_cap_404_manage` — independent of redirect management, so 404 read
can be granted without any redirect write authority.

Consequences for design, all still undecided (no ADR yet, nothing implemented):

- Statistics cannot hang off `RedirectProvider`. A Yoast-backed site would
  report zero 404s, which is indistinguishable from a healthy site. It needs a
  separate port whose unavailable state is explicit.
- Redirection's `groupBy` — the only aggregation primitive, and the thing that
  makes "top 404s" cheap — is **not declared in any route's `args` schema**; it
  is read straight off `get_params()` in a plugin that states its REST API is
  not stable.
- **There is no date filter of any kind** on either log route. "404s in the
  last 7 days" is not expressible; the de facto window is the retention
  setting (`expire_404`, default 7 days, pruned daily by cron).
- Logging can be off and fails open: `expire_404 = -1` disables 404 logging,
  `ip_logging = 0` drops IPs, `track_hits = false` freezes counters. A query
  against a disabled log returns an empty list, not an error.
- 404 rows carry `ip`, `agent`, `referrer` and, with `log_header` on, request
  headers in `request_data`. Projecting rows through MCP would hand traffic
  logs to a model; an aggregate-only surface (`url` + `count` + the retention
  window) answers "where is a redirect missing" without any of them.

## MCP projection is owned by the plugin — 0.8.0, 2026-08-11

ADR 0025. Registering abilities was never enough to use them: the official
Adapter endpoint existed only because a site MU-plugin
(`isudev/wp-content-bridge-mcp-server`) called `create_server()` with a
hand-written `ABILITY_PROFILE` constant. Two costs, both realized.

**The list drifted silently.** A live install running 0.7.1 with writes enabled
projected 11 of 31 abilities. Nothing reported a problem, because a missing
profile entry is indistinguishable from a disabled feature area. The decisive
evidence was `update-service-schema` present while `get-service-schema` and
`preview-update-service-schema` — registered in the *same* call — were absent,
and `get-block-tree` absent while `get-content`, which shares its
`permission_callback` and its unconditional registration, was present. No gate
inside the plugin can produce that; only a name-keyed filter downstream can. The
same list existed in four places, and the `wp eval` copy in
`docs/setup/MCP_ADAPTER.md` was already missing the llms.txt and status
abilities.

**A fresh install had no usable path.** Anyone installing this plugin got 31
registered abilities and an instruction to write PHP.

Now `Adapter\Mcp\McpServerProvider` answers `mcp_adapter_init` and projects every
ability registered under `AbilityCategory::SLUG` in the current request.
Registration is the gate, and it already encodes configuration. There is no name
list left anywhere. `AbilityCategory::SLUG` replaced the per-class category
literals for the same reason: discovery keyed on a slug turns a drifted literal
into a silent dropout.

Boundaries kept: the Adapter is not bundled, not a dependency, and never
installed here; `mcp_adapter_init` only fires where the site added it; transport
and OAuth stay external (ADR 0010, ADR 0005). Projection widened, authorization
did not — capability, native capability, per-type policy, schema validation and
write safeguards are unchanged.

Observability was the other half of the fix. `get-diagnostics` gained
`mcp_projection` (`enabled`, `endpoint`, `projected_abilities`) from the same
discovery the projection uses, and
`tests/Integration/abilities-runtime-verification.php` asserts projection
parity, so the next added ability cannot silently miss the endpoint.

**Not verified at runtime yet:** the `create_server()` call itself. The
positional argument list mirrors Adapter v0.5.0 as documented and as the retired
MU-plugin used it, but nothing in this repo executes it — no adapter in
`vendor/`. Run the smoke suite against a site with the Adapter active before
trusting the endpoint, and delete the MU-plugin first (while present it wins at
the default priority and the provider declines at 20).

The miniOrange OAuth path never read `ABILITY_PROFILE` and is unaffected; its
per-principal NHI grant is still the gate there, and its unset-allowlist
fail-open is still a hazard worth stating in each threat model.

## Block-level edits — 0.5.0, 2026-08-07

`update-content` replaced the whole document, so an agent asked to change one
paragraph had to re-emit every block and drifted on the ones it was never meant
to touch. ADR 0022 replaces that with path addressing: `get-block-tree` returns
the structure as a flat list of nodes carrying their tree `path`, and
`update-block` / `update-block-attributes` change exactly one subtree. Blocks
the caller never sends cannot be damaged, because they are never re-parsed from
caller output. A single-block edit costs ~174 characters against ~12,000.

Every block write requires `expected_block_name` alongside `version_token`. The
token proves the document did not change; it does not prove the path points
where the caller believes, and an off-by-one would otherwise replace the wrong
block silently.

Verified on real content (a 77-node page): **all 77 paths round-trip
byte-identically**, a wrong `expected_block_name` and an out-of-range path both
fail closed without writing, and preview writes nothing.
`tests/Integration/block-edits-verification.php` holds eleven properties.

**A pre-existing data-corruption bug was found while verifying this and is
fixed here.** `wp_insert_post()`/`wp_update_post()` expect slashed data and call
`wp_unslash()`; `WordPressContentMutationRepository` passed raw input, so every
backslash written through `create-draft` or `update-content` was stripped —
shipped from 0.1.5 to 0.4.5. `serialize_block()` writes a quote inside attribute
JSON as a backslash-u escape, so any block with a quoted attribute was corrupted
by any bridge write to that post. Fixed by `slashed()`, regression-guarded by
assertion 11 of the new verifier. **No automatic repair exists** — the damaged
form is indistinguishable from deliberate text. A scan of the reference site on
2026-08-07 found zero affected rows, because bridge writes there only ever hit
disposable fixtures. **No `0.4.6` was cut**: the bridge has one operator writing
only against a development site.

Also in this release: recursive block-markup validation (an unregistered
*nested* block previously passed), and the removal of the deprecated `dry_run`
preview field promised in 0.4.5.

Entries below are dated and historical; read them as a log, not as current
state. Where a dated entry names a version that later moved, the "Release
numbering" table in `docs/plan/EDITORIAL_OPERATIONS_ROADMAP.md` is
authoritative.

## Milestone 5 security sign-off — 2026-08-07 (0.4.5 task 6)

Signed off against the write surface as it actually exists at 0.4.5, on
WordPress 7.0.2 / PHP 8.4 with Yoast Free 28.2, Local 15.8, and Schema Extended
0.3.0. All 18 runtime verifiers were run green on this date; the inventory and
commands are in `docs/setup/VERIFICATION.md`.

Write surface: `create-draft`, `update-content`, `update-seo`,
`update-service-schema`, `update-custom-schema`, `trash-content`,
`restore-trashed-content`. Read-only mirrors: `preview-update-content`,
`preview-update-seo`, `preview-update-service-schema`,
`preview-update-custom-schema`.

Each claim below names the verifier that evidences it. A claim without one is
not listed here — it is listed under "Gaps".

| Control | Evidence |
|---|---|
| Every write ability is unreachable until `wpcb_writes_enabled` is on; trash additionally needs `wpcb_trash_enabled` | `writes-foundation-verification` (flags default off), `trash-content-verification` and `restore-trashed-content-verification` (absent while the gate is off, present when on) |
| The dedicated `wpcb_*` capability, the native object capability, and the per-post-type policy are **independently** required | `writes-mutation-verification` (full matrix), `writes-seo-verification`, `restore-trashed-content-verification` (two-gate matrix plus per-type policy denial) |
| A stale `version_token` is rejected before any mutation | `writes-mutation-verification`, `writes-seo-verification`, `trash-content-verification`, `restore-trashed-content-verification`, `preview-verification`, `schema-service-verification`, `schema-custom-verification` |
| Exactly one audit row per attempt, recording field **names** and never values | `writes-seo-verification::verify_audit_redaction` — writes a deliberately identifiable string and asserts it is absent from `changed_fields`; `restore-trashed-content-verification` asserts `["status"]` exactly |
| Previews take no `AuditLog` dependency and cannot write | `preview-verification` — audit-row baseline unchanged, `post_modified_gmt` unchanged, no revision created, repeated calls byte-identical |
| A preview followed by the matching write yields exactly the previewed state | `preview-verification` |
| Writes never reach `publish` or `future` | `writes-mutation-verification` (no-publish invariant); `restore-trashed-content-verification` (a `publish` pre-trash status still restores to `draft`) |
| A rejected input leaves no partial write | `writes-seo-verification` — an invalid social-image ID leaves `_yoast_wpseo_title` untouched |
| Provider writes are verified by re-read, not by trusting the provider | `writes-seo-verification` (write/re-read parity); `schema-service-verification` and `schema-custom-verification` assert the **rendered front-end JSON-LD graph** rather than the provider's own re-read |
| Successful mutations invalidate only the affected post's cache; provider failure is contained after commit | `cache-invalidation-verification` |
| No response field discloses a server filesystem path | `block-patterns-verification` (ADR 0013, pattern `filePath`); `http-url-runtime-verification` (Open Graph image paths) |
| MCP projection intersects a closed profile with currently-registered abilities and grants no authority of its own | `abilities-runtime-verification` (closed-profile guard); `mcp-smoke-verification` as the least-privilege `wpcb-bridge-reader` principal |
| Bounds hold under load | `block-patterns-verification` (2 MiB, fails atomically); `http-url-runtime-verification`; 500-block fixture at 103,898 encoded bytes |

### Gaps

Named rather than asserted. None blocks 0.4.5; each is a real hole in the
evidence and should be read as such.

1. **`SchemaExtendedServiceSchemaWriter::rollback()` has no coverage at any
   level** — no unit test and no verifier. It restores metadata keys already
   written when a later key fails mid-write, so it only executes on a provider
   failure no test induces. The most security-relevant untested path in the
   write surface: a defect leaves a post with half-applied Service schema.
2. **Audit pruning is untested.** `WordPressAuditLog` prunes to 5,000 rows
   oldest-first on every write. Nothing verifies the cap, so a defect would
   silently discard audit rows — failing open on exactly the record used to
   reconstruct what an agent did.
3. **Concurrency is only verified serially.** Every check writes, then reuses
   the now-stale token. No verifier issues two simultaneous requests, so
   protection against a real lost update is argued from the token design, not
   demonstrated.
4. **`uninstall.php` has never been executed.** Added 2026-08-07 and verified
   only by static analysis and by confirming it ships in the ZIP. It removes
   capabilities from users directly, so a defect is destructive.
5. **LiteSpeed purge is verified against a simulated listener**, not the real
   plugin — LiteSpeed Cache is installed but inactive on the reference site.
   The hook contract is proven; integration with the actual plugin is not.
6. **Multisite is untested at runtime.** The settings page refuses multisite in
   code; only single-site has ever been exercised.
7. **External grant drift is undetected, and the external projection fails
   open.** The 2026-08-07 audit found the live miniOrange grant set contradicted
   this file in both directions — it granted `create-draft` to a read-only
   principal and omitted three reads. Worse, miniOrange treats an *unset*
   allowlist as unrestricted, so a principal with no grants configured is
   exposed every registered ability. Layered defense held in both cases because
   the WordPress capability check is independent, but nothing detects either
   condition automatically; both remain manual checks against site
   configuration outside this repository. See "Two MCP servers, one projection".
8. ~~**`wpcb_publish_enabled` is registered and consumed by nothing.**~~
   **Closed in 0.7.0.** `transition-content-status` now consumes it, together
   with `wpcb_publish_content` and `ContentOperation::TRANSITION_STATUS`, which
   were inert for the same reason. All three are exercised by
   `tests/Integration/status-workflow-verification.php`.
9. **`YoastSeoProvider::get()` returns the first-resolved post's meta for every
   subsequent post in the same request.** Found 2026-08-08 while verifying the
   llms.txt leak matrix. The cause is Yoast, not this adapter: raw
   `YoastSEO()->meta->for_post()` calls with no plugin code involved return
   the first post's `robots` *and* `title` for later, different posts, and
   setting `$GLOBALS['post']` with `setup_postdata()` between calls does not
   help. Reproduced on Yoast SEO Free 28.2 with Yoast Local 15.8.

   This shipped in 0.1.5 and was never noticed because every caller until now
   resolved a single post per request — `get-seo` is one post per MCP call.
   `WordPressLlmsSourceSelector::is_noindex()` was the first multi-post caller
   and leaked a `noindex` page into the public `/llms.txt` document as a
   result. That path was fixed by moving the decision onto Yoast's indexable
   data through the order-independent `SeoProvider::is_noindex()`; `get()`
   itself was deliberately left alone.

   So this is latent, not live: no remaining caller resolves more than one post
   per request. **Any future one must not use `get()` in a loop.** Clearing
   Yoast's private context-memoizer cache by reflection does fix `get()` and
   was rejected — a renamed property would make it fail silently open, which is
   the wrong failure mode for a filter that keeps content out of a public
   document. `tests/Integration/llms-txt-verification.php` is the regression.

## Current phase

**0.4.5 task 1 (`restore-trashed-content`) is code-complete on 2026-08-07.**
It is the mirror image of `TrashContent`/`TrashAbilities`: registered only
under the existing `wpcb_writes_enabled` + `wpcb_trash_enabled` gate (no new
flag), requires `wpcb_delete_content` plus native `delete_post`, the same
per-post-type Trash policy, and a current `version_token`; it requires the
target's current status to be exactly `trash` (the inverse of `TrashContent`'s
check), the non-enumerating failure for any other status. `RestoreInput`
(Domain) mirrors `TrashInput`; the existing `MutationResult` DTO is reused
rather than duplicated, since it already carries the resulting `status` the
contract requires. `ContentTrashRepository` gained an additive `untrash()`
method; `WordPressContentTrashRepository::untrash()` computes the safe restore
status from `_wp_trash_meta_status` (`draft`/`pending`/`private` only, `draft`
otherwise — a `publish`/`future` recorded status, or missing/unparseable meta,
all fall back to `draft`), forces that exact value through the
`wp_untrash_post_status` filter rather than trusting `wp_untrash_post()`'s own
default (documented as version-dependent), and verifies the effective status on
re-read before returning. No preview intent — it fails the roadmap's preview
justification test. The closed MCP profile grows to 21 entries (from 20).
`RestoreTrashedContentAbilities` is a new adapter file (not folded into
`TrashAbilities`) so the shipped `trash-content-verification.php` needed no
changes. New runtime verifier
`tests/Integration/restore-trashed-content-verification.php` covers
registration/annotations/schema strictness, the two-gate authorization matrix,
trash-to-draft restore with redacted audit, a `publish` pre-trash fixture
landing on `draft` (never `publish`/`future`), a stale-token conflict rejected
before any mutation, and per-type policy denial. The consuming site's
MU-plugin projection package (`isudev/wp-content-bridge-mcp-server`, separate
repository) may take the new ID with a version bump; that is optional hygiene
for the official-Adapter endpoint only, not a reachability blocker — see "Two
MCP servers, one projection". Tasks 2–8 of `docs/plan/RELEASE_0_4_5_PLAN.md`
remain outstanding and are explicitly out of scope for this change.

**0.4.5 task 2 (unify the preview response flag) is code-complete on
2026-08-07.** `ServiceSchemaPreviewResult` and `CustomSchemaPreviewResult`
(Domain) now emit `writes_performed: false` additively, alongside the existing
`dry_run: true` — neither field was removed. `AbilitySchemas::preview_service_schema_output()`
and `AbilitySchemas::preview_custom_schema_output()` add `writes_performed` as
a required boolean property (both schemas keep `additionalProperties: false`,
so the property definition was mandatory, not optional). All four preview
Abilities (`preview-update-content`, `preview-update-seo`,
`preview-update-service-schema`, `preview-update-custom-schema`) now share one
`writes_performed` read path. `docs/architecture/ABILITIES.md` documents
`dry_run` as deprecated on both remaining abilities, removal scheduled for
`0.5.0`. ADR 0019 and ADR 0020 each gained a second "Amended 2026-08-07" note
recording the addition without altering the original decisions. Existing unit
assertions covering the preview output shape
(`AbilitySchemasTest`, `ServiceSchemaReadPreviewTest`,
`CustomSchemaUseCasesTest`) were extended in place — no new test file. Static
quality: 247 tests / 648 assertions, PHPCS/PHPStan/PHPUnit all green.
`tests/Integration/schema-service-verification.php` and
`tests/Integration/schema-custom-verification.php` both `PASS` against the
LocalWP environment. Tasks 3–8 of `docs/plan/RELEASE_0_4_5_PLAN.md` remain
outstanding and are explicitly out of scope for this change.

**The post-0.3 editorial operations roadmap was accepted and extended on
2026-08-03.** It is recorded in
`docs/plan/EDITORIAL_OPERATIONS_ROADMAP.md`.

> **Superseded 2026-08-07 — version numbers only.** The scope described below
> stands; its release numbering does not. 0.4.0 shipped the previews alone,
> llms.txt moved to 0.5.0, status transitions to 0.6.0, and every later slice
> up one. 0.4.5 was inserted as a consolidation release. Three planned preview
> Abilities were also cut. See the "Release numbering" and "When a preview
> Ability is justified" sections of the roadmap.

Version 0.4.0 now contains two
sequential sub-slices: content/SEO preview followed by native `llms.txt` read,
preview, configuration, generation, and virtual
publication informed by the installed LLMagnet 3.4.3 implementation. LLMagnet
is research material only and will be removable with no runtime adapter,
classes, options, files, Abilities, cron, or licensing dependency in the
bridge. A physical artifact left by LLMagnet is a fail-closed ownership
conflict; migration/deactivation/deletion remains an explicit administrator
deployment step. Yoast SEO's own `llms.txt` toggle and physical-file ownership
are now an additional fail-closed conflict gate. The first release detects and
guides an administrator to disable Yoast manually; it never writes the raw
`wpseo` option. Optional automated handoff requires a separately accepted,
version-tested Yoast write contract plus administrator-only preview and
confirmation. The existing 0.5 status-transition target remains next. The
0.8 redirect slice now requires deterministic Yoast Premium-first selection
with Redirection fallback and never dual-writes. The targeted Gutenberg slice
distinguishes server structural validation from true editor-side `save()`
semantic validation. All slices retain separate read/preview/write intents,
least-privilege capabilities, optimistic concurrency, redacted audit, and
runtime/MCP release gates. No implementation has started.

**Slice 1A (content and SEO preview) is code-complete and runtime-verified for
0.4.0 on 2026-08-07.** `wp-content-bridge/preview-update-content` and
`wp-content-bridge/preview-update-seo` mirror `update-content` and
`update-seo` exactly, per ADR 0021: same validated input DTO, per-post-type
policy, optimistic-concurrency check, and (for content) block-markup
validation; neither takes an `AuditLog` dependency at all, so neither can
record a mutation audit row. Content preview round-trips block markup through
a new `BlockMarkupValidator::normalize()` method (`parse_blocks()`/
`serialize_blocks()` only, never content filters) and reads the post's current
title/content/excerpt through a new, additive `ContentSnapshotRepository`
port that `WordPressContentMutationRepository` also implements. SEO preview
normalizes every requested field exactly as `YoastSeoWriter::write()`
sanitizes it (including resolving social-image attachment IDs) through a new,
additive `SeoPreviewProvider` port that `YoastSeoWriter` also implements, but
never calls `WPSEO_Meta::set_value()`. Both new ports are additive to
existing interfaces, so no existing implementer or test double changed shape.
Preview responses report `writes_performed: false` — deliberately not
`dry_run`, which the roadmap reserves for the forbidden mode of a destructive
Ability. Both previews register only inside `MutationAbilities`, alongside the
writes they mirror, so they share `wpcb_writes_enabled` and the identical
capability/native-object permission callback automatically. The closed MCP
profile grew to 20 potential abilities. `composer check` is green: PHPCS 0
errors, PHPStan max-level 0 errors, PHPUnit 242 tests / 626 assertions. The new
`tests/Integration/preview-verification.php` passes against Yoast Free 28.2 on
Kormas local, proving repeated previews are deterministic and cause no
post/meta/revision/audit change, that a preview followed by the matching
write produces exactly the previewed state, and that stale tokens are
rejected before any mutation. `abilities-runtime-verification.php` (the
closed-profile guard) also passes. The consuming site's MU-plugin projection
package `isudev/wp-content-bridge-mcp-server` still needs a version bump to
include the two new IDs — that is the user's action in a different
repository, not part of this change.

**Bounded Custom Schema MCP support is code-complete for connector 0.3.0 on
2026-08-03.** Conditional `get-custom-schema`, `preview-update-custom-schema`, and
`update-custom-schema` Abilities use only Schema Extended 0.3.0's public
`Integration_API` contract version 1.0. Input is limited to `enabled` and a
100,000-byte JSON source; provider output is normalized to at most 20 nodes and
strict diagnostics. Preview is read-only and reports save/render eligibility.
Update reuses SEO policy, native authorization, optimistic concurrency,
redacted audit, and post-scoped cache invalidation. Full resolved graph checks
remain on the existing `get-url-seo` Ability. ADR 0020 records the bounded JSON
exception. The closed MCP profile now has 18 potential entries. Full
`composer check` is green: PHPCS, maximum-level PHPStan, and PHPUnit 234 tests /
596 assertions. Live WordPress registration and Yoast graph-merge checks remain
the release gate.

**Service schema read-before-write was code-complete on 2026-08-03.** Separate
`get-service-schema` and `preview-update-service-schema` Abilities now complement the
existing update. Get returns the independently saved provider configuration and
current token. Preview consumes the exact update input, policy, provider,
optimistic-concurrency, and sanitization paths but performs no metadata write,
mutation audit, or cache invalidation. Both are truthfully annotated read-only.
The raw MCP smoke test now inspects each targeted tool's own required-field
declaration, covering `post_id` and `version_token`. ADR 0019 records why dry
run is a separate semantic intent. The profile at that milestone had 15 potential
entries. PHPCS and maximum-level PHPStan are clean; PHPUnit passes 223 tests /
560 assertions. The Kormas source profile is updated to 0.2.1. Runtime
registration verification was attempted, but Local's database socket was not
available, so WordPress could not bootstrap.

The reported optional `post_id`/`version_token` display was traced outside the
bridge: miniOrange Secure MCP Server 1.3.1 removes the nested Ability `required`
list in `wrap_input_schema()`. WordPress.org 1.4.2 trunk still contains the same
code. The bridge source and contract tests already require the fields, and the
official-Adapter smoke test now asserts the raw MCP descriptor. No generated
third-party plugin file was patched; the OAuth projection remains an upstream
compatibility issue.

**Conditional structured Service writes are code-complete on 2026-08-03.**
`wp-content-bridge/update-service-schema` is registered only when global writes
are enabled and the standalone IsuDev Schema Extended plugin marker plus
compatible public `Meta_Fields` API are loaded. The provider-neutral contract
covers bounded Service name/type/description, typed service areas, brands, and
OfferCatalog entries; it never exposes arbitrary post meta or raw JSON-LD. The
write reuses `wpcb_manage_seo`, native `edit_post`, per-type Update SEO policy,
optimistic concurrency, redacted audit, and post-scoped cache invalidation.
The provider adapter pre-normalizes values, maps fixed metadata constants,
restores earlier keys best-effort after a later write failure, and returns an
effective configuration re-read. ADR 0017 records the optional provider
boundary. PHPCS and PHPStan are clean; PHPUnit passes 214 tests / 532
assertions. WordPress runtime graph verification with the standalone provider
active remains a release check. The profile at that milestone had 15 potential
entries and continues to intersect them with currently registered abilities.

**The critical `update-seo` gap is closed and runtime-verified on 2026-07-24.**
ADR 0016 extends the existing ability with independently merged
`robots_noarchive`, `robots_noimageindex`, and `robots_nosnippet` flags plus
`og_image_id` and `twitter_image_id`. Social overrides accept only readable
WordPress image attachment IDs; URLs are resolved internally, both Yoast ID and
URL values are written, `0` clears the pair, and all images are validated
before the first field write. Configured SEO re-reads expose the new flags and
attachment IDs under normalization schema 1.3. The complete unit suite is 200
tests / 493 assertions, PHPStan is clean, and the Kormas runtime write verifier
passes against Yoast Free 28.1, Premium 28.0, and Local 15.8, including merge,
clear, re-read, invalid-image atomicity, concurrency, authorization, and audit
coverage. The verifier now snapshots and restores local WPCB policy/options.
The managed `wpcb-bridge-reader` principal (user 87) was changed from
Subscriber to Editor while retaining its seven explicit WPCB capabilities.

**Version 0.2.0 MCP exposure is statically complete on 2026-07-21.** The plugin version,
readme changelog, official Adapter setup, and configurable MCP discovery smoke
profile now describe a closed profile of all 12 implemented abilities. Kormas
owns the official Adapter projection in a version-controlled Composer MU-plugin
that intersects this profile with abilities registered under current feature
flags. This does not alter authorization. The miniOrange OAuth endpoint has
separate principal-to-ability grants that still require explicit runtime update
and verification after the stopped Kormas Local database is available.
The plugin passes the PHP 8.2 release gate: PHPCS clean, PHPStan 0 errors, and
PHPUnit 197 tests / 474 assertions. The Kormas package passes PHP syntax and
PHPCS, Composer resolves it as version 0.2.0, and the obsolete local root MCP
file was removed to prevent duplicate server registration. Live discovery was
attempted but WordPress could not connect to the stopped database.

**The root README ability catalog was expanded on 2026-07-21.** It now gives a
standalone, factual description of every implemented ability, including its
registration gate, WPCB capability, main inputs and outputs, bounds, native
authorization, mutation safeguards, cache behavior, settings, MCP boundary,
and unsupported operations. Planned `transition-content-status` remains
clearly separated from the 12 abilities released in version 0.1.5 and projected
by the version 0.2.0 site profile.

**Version 0.1.5 trash slice and status-boundary decision are complete on
2026-07-21.** ADR 0015 replaces the never-released `publish-content` plan with
the future `transition-content-status` ability: an explicit transition graph,
with public/scheduled transitions additionally gated by the publication flag,
`wpcb_publish_content`, and native `publish_post`. Trash is deliberately a
separate `wp-content-bridge/trash-content` intent. It is off by default behind
the writes flag, its own trash flag, per-type read + trash policy,
`wpcb_delete_content`, native `delete_post`, optimistic concurrency, redacted
audit, and post-scoped cache invalidation. It fails closed when WordPress would
skip reversible trash, and rejects `trash`, `auto-draft`, and `inherit` source
states. Permanent deletion and restore are not exposed. The root README now
catalogs all 12 implemented abilities, and the settings surface includes all
seven managed capabilities plus the new policy and destructive switch. Full
`composer check` is green on the minimum supported PHP 8.2.30: PHPCS and
PHPStan clean, PHPUnit 197 tests / 474 assertions. Anonymous readonly test
fixtures that accidentally required PHP 8.3 were made PHP 8.2-compatible. The
Kormas runtime verifier was attempted but the Local
database remains stopped, so no fixture mutation ran.

Milestone 5 (writes) — **in progress.** Planned as four sequential plans; it
has since grown to nine (1, 2, 3, 3b, 3c, 3d, 4a, 4b, 4c).
**Plan 1 (writes foundation) is complete and merged** to `main` (merge commit
`ab4805f`). **Plan 2 (`create-draft` + `update-content`) is complete and
merged** to `main` (merge commit `28818ab`, pushed to origin). The plugin now
has its **first live, reachable write surface** — `wp-content-bridge/create-draft`
and `wp-content-bridge/update-content` — still **off by default** behind
`wpcb_writes_enabled` (an administrator must enable the flag, and per-post-type
Create/Update policy, before either ability is reachable at all; see
`docs/architecture/ABILITIES.md` for the delivered contract). 13 tasks executed
via subagent-driven development plus a final whole-branch review (opus) that
found and fixed one Important issue: `CreateDraft`/`UpdateContent` recorded a
second (failure) audit row if the audit sink itself threw after a success row
was already committed — fixed by moving the success-audit call outside the
`try`/`catch` (commit `bc47f8a`), with regression tests. `composer check` green
on merged `main` (120 tests / 309 assertions, PHPCS 0, PHPStan 0); the runtime
write verifier (`tests/Integration/writes-mutation-verification.php`) passes
the full authorization matrix, no-publish invariant, stale-version conflict,
revision-on-update, block round-trip, idempotent create, and audit-redaction
checks. The 0.2.0 site-infrastructure projection closes the official Adapter
discovery gap with an explicit 12-ability, registered-only profile. The
ChatGPT-facing miniOrange OAuth grants remain a separate pending runtime
configuration.

**Plan 3 (`update-seo`) base is merged** to `main` (merge commit `796e932`).
The current 0.1.3 worktree extends its fixed Yoast Free core-field allowlist
with normalized `keyphrase_synonyms` and `related_keyphrases` for compatible
Yoast Premium 28.x (ADR 0014), advances normalized SEO output to schema 1.2,
and extends the repeatable verifier. The current `composer check` baseline is
185 tests / 443
assertions. The verifier was retried on 2026-07-21, but WordPress could not
connect to the stopped Local database; no fixture mutation ran.

**Media P0 and post-scoped cache invalidation for version 0.1.3 are
code-complete in the current worktree.** Media adds
off-by-default `get-media` and `get-media-by-id` abilities, the dedicated
`wpcb_read_media` capability, native per-attachment authorization, deterministic
ID/same-site URL/filename lookup, strict object envelopes, normalized media
fields, and required nullable `featured_image_id` + `featured_image_url` content
summary fields. ADR 0011 owns the separate media policy. The current combined
worktree passes `composer check` (185 tests / 443 assertions); Kormas runtime
verification is pending only because the Local site/database is stopped.
Successful mutations now clear the
affected WordPress post cache and, when active, dispatch LiteSpeed Cache's
public post-scoped purge hook. Cache failures are contained after commit (ADR
0012); its Kormas runtime verifier is pending for the same reason.

**Plan 4a (`list-block-patterns`) is code-complete in the current 0.1.3
worktree.** The ability is independently off by default, requires
`wpcb_read_patterns` plus WordPress editor-level access, returns metadata by
default, and exposes optional complete markup under a 2 MiB bound. It uses the
current registry without remote loading and never exposes pattern filesystem
paths (ADR 0013). Static/unit quality is green (185 tests / 443 assertions).
The verifier was retried on 2026-07-21 and reached WordPress, but the stopped
Local database socket prevented bootstrap; no fixture mutation ran.

Milestone 4 Phase 1 — **complete** (ChatGPT-primary, read-only, Approach A per
ADR 0010). ChatGPT completed the five-ability read scenario live through the
official MCP Adapter (App-Password endpoint, `/wp-json/wpcb-mcp/mcp`) fronted
by an external OAuth 2.1 layer (miniOrange Secure MCP Connector,
`/wp-json/mosmcp/v1/mcp`), DCR + PKCE, token principal-bound to a WordPress
user. Codex/Gemini remain secondary/deferred, covered only by the
client-agnostic smoke suite. A still-open M4 thread (independent of writes):
staging stabilization with a real TLS certificate (the local run used a
cloudflared quick tunnel) plus a strict least-privilege re-consent.

Milestone 3 — complete. Premium/Local reads, bounded editorial context, and
the licensed Local multiple-location runtime matrix (primary + branch) are all
verified.

## Completed

- Version 0.1.2 adds single-site integration-principal capability management to
  the plugin settings page. An administrator can bind one existing dedicated
  non-administrator user and assign the closed operational WPCB capability
  allowlist; nonce, `wpcb_manage_settings`, `promote_users`, per-target
  `edit_user`, native `read`, prior-principal revocation, and multisite denial
  are enforced. `composer check` is green (141 tests / 351 assertions, PHPCS 0,
  PHPStan 0). The repeatable WordPress runtime verifier was added but could not
  be executed in this environment because the local WordPress database runtime
  was not running (`Error establishing a database connection`).
- Media P0 code-complete for 0.1.3: dedicated off-by-default policy and
  `wpcb_read_media`; strict object-envelope `get-media`; deterministic
  `get-media-by-id`; exact ID/same-site URL/filename lookup; normalized ID,
  title, filename, URL, ALT, caption, description, and MIME; and required
  `featured_image_id` + `featured_image_url` content-summary fields. ADR 0011,
  runtime verifier, settings UI, capability migration, contract tests, and the
  verified third-party comparison are included. `composer check` passes (155
  tests / 380 assertions); Kormas runtime remains pending while Local is stopped.
- Implemented post-scoped cache invalidation after every successful bridge
  mutation. The WordPress infrastructure subscriber clears the exact post's
  object cache and uses LiteSpeed Cache's public `litespeed_purge_post` hook
  only when present. It forbids caller-selected targets and full purges,
  contains provider exceptions after commit, and exposes bounded success/failure
  lifecycle hooks (ADR 0012).
- Implemented Plan 4a `list-block-patterns`: dedicated settings flag and
  integration capability, core-compatible native editor gate, transport-neutral
  access/catalog ports, deterministic filters/pagination, metadata-only default,
  optional bounded markup, strict schemas, unit coverage, and a disposable
  Kormas runtime verifier (ADR 0013).
- Captured provider-neutral redirect management as a future backlog item,
  including the required evaluation of Yoast Premium redirects versus a
  dedicated redirects plugin and the security gates for any write ability.
- Captured extended editorial operations in the future backlog: navigation
  menus, revision restoration, slug/permalink changes, explicit post-status
  transitions, trash/permanent deletion, author/date changes, and featured
  image/media upload management with separate authorization and safety gates.
- Fixed the GitHub release workflow to build with the project's minimum
  supported PHP version (8.2), so production-only Composer installation honors
  the root platform constraint without changing the lock file.
- Standalone repository scaffold.
- Composer autoloading and quality-tool configuration.
- Minimal activatable WordPress plugin bootstrap.
- Canonical AI-agent instructions.
- Initial SDD, ADRs, implementation plan, security model, and test strategy.
- Composer dependencies installed; PHPCS 80/80 files and maximum-level PHPStan
  pass; PHPUnit currently passes 74 tests with 195 assertions.
- Local Kormas integration is active through the ignored runtime symlink `public/content/plugins/wp-content-bridge`.
- The plugin is activated locally and its PSR-4 bootstrap has been verified.
- Domain-level per-post-type operation policy and dependencies.
- Option-backed content access repository and eligible post-type catalog.
- Settings API matrix protected by `wpcb_manage_settings`.
- ADR 0006, content access architecture, code map, and agent feature procedure.
- Unit policy contract coverage.
- Local WordPress verification: capability migration, eligible-type defaults, Settings API registration, and admin menu registration.
- Transport-neutral content query/summary/detail/result DTOs and repository port.
- WordPress content repository with native `read_post` enforcement.
- Read-only `search-content`, `get-content`, and safe diagnostics Abilities.
- Dedicated `wpcb_read_content` capability for administrators.
- LLMagnet 3.4.3 architectural comparison and principal-bound credential ADR.
- Local WordPress 7.0.1 verification: all three abilities register and validate;
  administrator search/detail/diagnostics succeed, anonymous access fails,
  disabled `realizacja` search fails, missing detail is non-enumerating, and
  raw/plain-text/relationship/concurrency output was exercised.
- Milestone 1B.1 bounded taxonomy filters: immutable filter value object,
  taxonomy discovery port, public/REST WordPress taxonomy adapter, strict
  Ability schema, all-effective-types validation, and bounded `AND`/`IN`
  WP_Query mapping.
- Local taxonomy verification: category term 1 returned post 1 for an explicit
  post search; using category across effective post and page types returned
  `wpcb_invalid_input` before querying.
- Repeatable WordPress authorization matrix covering anonymous, subscriber,
  owning/non-owning authors, editor, administrator, and least-privilege
  integration principals across published, draft, private, page, and opted-in
  CPT fixtures.
- Search authorization is applied before pagination; unreadable objects cannot
  leak through totals. Candidate scans are capped at 1,000 and disclose whether
  totals are exact.
- Detail responses report per-representation byte sizes and reject selected
  representations above 2 MiB with `wpcb_content_too_large`.
- Block-heavy runtime fixture verified 500 blocks: 99,500 representation bytes
  and 103,898 encoded response bytes.
- Runtime Abilities verification passes for discovery, strict schemas,
  annotations, anonymous denial, administrator execution, deterministic twin
  calls, REST discovery, and REST execution on WordPress 7.0.1.
- Milestone 1B exit gate is complete; evidence is in
  `docs/verification/ABILITIES_VERIFICATION.md`.
- Provider-neutral SEO domain model, explicit value states, provenance,
  completeness, bounded Schema graph, null provider, and provider registry.
- Strict same-origin SEO target validation and content-policy/native-object
  authorization before provider access.
- Read-only `wp-content-bridge/get-url-seo` Ability with an exclusive
  `post_id`/`url` selector and stable non-enumerating errors.
- Yoast Free 28.x adapter: documented Surfaces output for resolved metadata and
  Schema, plus a narrow version-gated configured post-meta allowlist.
- Safe SEO-provider status is included in diagnostics.
- Five-Ability runtime verification passes on WordPress 7.0.1, including strict
  schemas, anonymous denial, deterministic twin execution, stable SEO errors,
  REST discovery, and REST execution.
- The authorization matrix now covers SEO reads for author/editor/integration
  principals, policy-disabled objects, non-enumerating denial, and arbitrary
  post-meta leakage.
- Real-HTTP URL verification passes with a disposable least-privilege
  Application Password principal: post URLs canonicalize after authorization,
  external origins fail, and unavailable home/archive indexables return bounded
  explicit warnings.
- Yoast public-head parity passes for title, description, canonical, robots,
  Open Graph, Twitter, and normalized Schema.
- Disposable configured-value verification passes for explicit/inherited
  states, partial output without an indexable, and arbitrary-meta isolation.
- Milestone 2C keeps content and SEO as composable abilities (ADR 0008), so
  provider failure cannot break authoritative content reads.
- Milestone 2 exit gate is complete.
- Safe Yoast Premium 28.x and Local SEO 15.x module/version detection without
  exposing license or update state.
- Premium additional focus keyphrases normalized into bounded
  `keyphrase_details` with primary/additional roles and optional public scores;
  the backward-compatible `focus_keyphrases` list is retained.
- Local public business/location profiles derived only from Yoast's emitted
  Schema through recursive allowlists and bounded reference resolution.
- Single-location Kormas runtime verification passes with Yoast Free 28.0,
  Premium 28.0, and Local 15.8. A pure multi-location Schema contract test also
  passes, but a real multi-location fixture has not yet been exercised.
- SEO normalization schema 1.2, module-version diagnostics, Premium/Local leak
  tests, and updated Ability schemas.
- Bounded `wp-content-bridge/get-editorial-context` Ability with selectable
  `post_types`, `taxonomies`, `terms`, `authors`, `recent_content`, and
  `local_businesses` sections.
- Editorial context requires both configured READ and SEARCH access, reuses
  authorization-aware published-content search, exposes only authors observed
  in readable results, and obtains Local entities only from the normalized SEO
  provider contract.
- Editorial selection bounds: 20 post types, 20 taxonomies, 50 recent content
  items, 100 terms per taxonomy, and strict rejection of unavailable requested
  types/taxonomies.
- Editorial runtime verification passes for discovery, schema validation,
  policy denial, role/object authorization, deterministic twin execution,
  real HTTPS execution, Local public-profile projection, and sensitive-data
  leakage guards.
- Rendered-schema capture for Local multiple-location profiles: a bounded,
  same-origin `RenderedSchemaReader` port and WordPress adapter feed the
  `LocalSchemaProjector` from the target's public front-end JSON-LD, because the
  Yoast resolved meta surface omits branch (`parentOrganization`) schema. Wired
  into `YoastSeoProvider` for `local_businesses` only, with a Meta-surface
  fallback and explicit degraded warning (ADR 0009).
- Licensed Local multiple-location runtime matrix verified on Kormas local
  (Yoast Free/Premium/Local 28.0/28.0/15.8): primary organization profile and a
  non-primary branch with `parentOrganization`, branch address, geo, and hours,
  through both `get-url-seo` and `get-editorial-context` over real HTTPS, with
  bounds and private-option (`local_api_key`/`googlemaps_api_key`) leakage
  rejection. The fixture snapshots and restores the exact prior Local
  configuration.
- Current quality baseline: PHPCS 72/72 files, PHPStan 0 errors, PHPUnit 68
  tests with 165 assertions; all WordPress and HTTP runtime verifiers pass,
  including the new multiple-location verifier.
- ADR 0010: MCP transport and OAuth are external, principal-bound layers
  (Approach A), with a six-criterion evaluation gate for any OAuth candidate.
- OAuth candidate evaluation against the ADR 0010 gate
  (`docs/research/OAUTH_CANDIDATES.md`).
- Least-privilege bridge-reader fixture (`wpcb-bridge-reader`, capabilities
  `read` + `wpcb_read_content` only).
- Official MCP Adapter (`WordPress/mcp-adapter` v0.5.0) installed as site
  infrastructure, projecting exactly the five read abilities at
  `/wp-json/wpcb-mcp/mcp` (`docs/setup/MCP_ADAPTER.md`).
- Client-agnostic MCP smoke suite (`tests/Integration/mcp-smoke-verification.sh`)
  passes: discovery, schema retrieval, and execution of all five abilities via
  a disposable Application Password.
- ChatGPT connected live via miniOrange Secure MCP Connector
  (`miniorange-secure-mcp-server` v1.3.0) at `/wp-json/mosmcp/v1/mcp`: RFC
  8414/9728 discovery, RFC 7591 DCR, PKCE S256; token confirmed
  principal-bound to a WordPress user. Setup, self-test, and troubleshooting
  are in `docs/setup/CHATGPT_CONNECTOR.md`.
- Two live-audit defects fixed on this branch: `get-url-seo` no longer leaks
  the server filesystem path via Open Graph images
  (`src/Infrastructure/Yoast/YoastSeoProvider.php`); `get-diagnostics` no
  longer false-negatives on `mcp_adapter` detection
  (`src/Adapter/Abilities/ContentAbilities.php`).
- Write exposure closed: no `mosmcp/*` write grants remain on the site; writes
  stay globally blocked pending Milestones 5–7.
- Current quality baseline after M4 Phase 1: PHPCS 0 errors, PHPStan 0
  errors, PHPUnit 74 tests with 195 assertions (see `composer check` output
  referenced in the Task 7 report).
- Milestone 5 Plan 1 (writes foundation), merged `ab4805f`: `VersionToken`
  optimistic-concurrency primitive (Domain); typed Application failures
  `MutationConflict`/`InvalidBlockMarkup`/`SeoFieldUnsupported`; mutation ports
  `AuditEvent`/`AuditLog`/`BlockMarkupValidator`; `Installer` (schema v4) grants
  `wpcb_edit_content`/`wpcb_manage_seo`/`wpcb_publish_content`, registers master
  flags `wpcb_writes_enabled`/`wpcb_publish_enabled` (both false, non-autoloaded),
  and creates the capped `{prefix}wpcb_audit` table via `dbDelta`; redacting
  `WordPressAuditLog` (field names only + `do_action('wpcb_mutation')`). No write
  ability is registered — abilities appear only when their master flag is on.
- Quality baseline after M5 Plan 1: PHPCS 0 errors, PHPStan 0 errors, PHPUnit
  85 tests with 211 assertions; `writes-foundation-verification.php` PASS.
- Milestone 5 Plan 2 (`create-draft` + `update-content`), merged `28818ab`: the
  plugin's first live write surface. `TaxonomyAssignment`/`DraftInput`/
  `ContentUpdate`/`MutationResult` DTOs (Domain); `ContentMutationRepository`/
  `IdempotencyStore` ports, `MutationForbidden`/`MutationWriteFailed` typed
  failures, `CreateDraft`/`UpdateContent` use cases (Application) — each
  validates input, enforces per-post-type policy, checks block markup,
  performs the write, and records exactly one audit row per attempt (fixed to
  hold even if the audit sink itself throws, `bc47f8a`); `PhpBlockMarkupValidator`,
  `WordPressContentMutationRepository` (`wp_insert_post`/`wp_update_post` +
  revisions, never sets `publish`/`future`/`pending`), and
  `WordPressTransientIdempotencyStore` (per-user 24h transient) (Infrastructure);
  `MutationAbilities` (Adapter) registers both abilities — with capability +
  native-object permission callbacks and stable `WP_Error` mapping — only when
  `wpcb_writes_enabled` is on. Additive `version_token` field added to
  `get-content` output (`ContentDetail`, `WordPressContentRepository::get()`,
  `AbilitySchemas::get_output()`) — the one permitted read-ability touch.
  `Plugin.php` wires the write services behind the flag; the settings page
  gained the global "Enable content writes" checkbox. Runtime write verifier
  (`tests/Integration/writes-mutation-verification.php`) proves the
  authorization matrix (plugin cap, native cap, and policy independently
  required), the no-publish invariant, stale-version-conflict rejection,
  revision creation, block round-trip (valid survives, unregistered block
  rejected), idempotent create, and audit redaction. Quality baseline: PHPCS 0
  errors, PHPStan 0 errors, PHPUnit 120 tests with 309 assertions.

## Not implemented

- Premium fields beyond the normalized synonyms/related-keyphrase contract in
  ADR 0014.
- Per-target Yoast analysis scores. Yoast's documented score Abilities return
  recent-post lists without stable post IDs, so they cannot safely be joined to
  a requested object.
- Codex/Gemini manual walkthrough (secondary/deferred for Phase 1; covered
  only by the client-agnostic smoke suite).
- Strict least-privilege re-consent as `wpcb-bridge-reader` (Task 6's live
  ChatGPT consent was done as admin `dev` for exploration; re-run on staging
  with a real certificate).
- Controlled status transitions (`transition-content-status`) are not
  implemented. Public and scheduled transitions are part of that future
  contract; the old `publish-content` plan is superseded by ADR 0015.
  `list-block-patterns`, `update-seo`, `create-draft`, and `update-content` are
  implemented. `list-block-patterns` passed its runtime sign-off on
  2026-08-07 (`tests/Integration/block-patterns-verification.php`, 0.4.5
  task 3).
- Media P1 writes are not implemented: `update-media`, upload, featured-image
  assignment/removal, and remote import remain separately gated future work.
- Runtime verification of the current official Adapter profile and explicit
  miniOrange grants for the intended principal. The OAuth grant is site
  configuration and must not use a wildcard or enable unrelated `mosmcp/*`
  tools.
- Restore-from-trash. `trash-content` shipped in 0.1.5 as reversible, but no
  ability undoes it; recovery requires wp-admin. **Decided 2026-08-07:**
  `restore-trashed-content` is built in 0.4.5, pulled forward out of roadmap
  Slice 3 because the destructive half is already live. It must never reach
  `publish` or `future`; see `docs/plan/RELEASE_0_4_5_PLAN.md` task 1.
- A second verification environment. Runtime sign-off depends on one machine's
  Local instance and will continue to. **Decided 2026-08-07 (0.4.5 task 4):** a
  containerised environment was tried and rejected. It reproduces only the
  WordPress-core half — Yoast Premium/Local are licensed and Schema Extended is
  private, so neither can be committed — and green on it would read as coverage
  while a third of the surface went unchecked. The blackout's actual cause was
  the absence of a defined inventory, now `docs/setup/VERIFICATION.md`.
- Role-management UI beyond the capability grant.
- Agents API integration.

## Next action

Released state: **0.6.0**. Versions 0.2.0 through 0.6.0 all shipped.

**0.6.0 — Slice 1B (llms.txt) is complete**, all nine tasks of
`docs/plan/SLICE_LLMS_TXT_EXECUTION_PLAN.md`. It adds the plugin's first
unauthenticated public route, behind an off-by-default flag that leaves no
public surface at all while it is false. Its threat model is the
"unauthenticated public surface" section of `docs/architecture/SECURITY.md`.
The projection profile grew from 25 to 29 potential abilities.

Three defects were found by verification rather than by inspection, and each is
recorded where it will be read again:

- `Content-Length` was taken from the stored byte count instead of the bytes
  being written — two independently deserialized fields of one option.
- Registering the endpoint's query var publicly made the document reachable at
  every URL on the site, an unbounded set of cacheable duplicates. The handler
  now gates on `WP::$matched_rule`.
- A trigger arriving between ticks of a batched regeneration run was silently
  dropped, because the run self-reschedules onto the same cron hook. Withdrawn
  content could stay public indefinitely. See task 6's commit.

Two pre-existing defects surfaced while verifying and were fixed here: the
Yoast multi-post memoization behind the `noindex` leak (gap 9 above), and
`get-editorial-context` rejecting its own valid output because the schema
omitted `parentOrganization` while `LocalSchemaProjector` has always emitted it
— which broke exactly the multi-location case ADR 0009 exists for.

**0.7.0 — Slice 2 is complete**, all seven tasks of
`docs/plan/SLICE_STATUS_WORKFLOW_EXECUTION_PLAN.md`.

Two WordPress behaviours were measured rather than assumed, and one assumption
in the first draft of ADR 0024 was wrong before it was checked. `wp_update_post()`
asked for `future` with a past date stores `publish` — a bad `publish_at` puts
content live — but `publish` with a future date does **not** become `future`, so
there is no accidental path into scheduling. Implementation found two more: an
explicit `post_date`/`post_date_gmt` is ignored on update without `edit_date`,
and requesting `publish` on a post whose date is still in the future silently
leaves it at `future`.

Known limitation, recorded rather than hidden: the mutation repository's
read-back check detects a transition WordPress rewrote but does not roll it
back, so the post stays as stored while the caller is told the write failed. The
seventh gate keeps that path unreachable through the ability.

**Next release is `0.9.0` — Slice 3, revision inspection and recovery.**

**0.4.5 is complete.** All eight tasks of `docs/plan/RELEASE_0_4_5_PLAN.md` are
done: `restore-trashed-content`, unifying the preview response flag,
`list-block-patterns` runtime sign-off, the verification run book, retiring the
inert `wpcb_public_base_url` option (which surfaced the absence of any uninstall
routine), the Milestone 5 security sign-off, release packaging, and the release.
Nothing in it was breaking.

**Optional, different repository:** the consuming site's MU-plugin projection
package `isudev/wp-content-bridge-mcp-server` can take
`wp-content-bridge/restore-trashed-content` in its `ABILITY_PROFILE` with a
version bump, making the profile 21 entries.

This is hygiene, not a blocker — see "Two MCP servers, one projection" below.
An earlier draft of this entry claimed the ability was "unreachable over MCP"
until the bump. That is wrong and was corrected on 2026-08-07.

> **Superseded 2026-08-07 — version numbers only.** The two paragraphs below
> assigned `0.5.0` to Slice 1B. The block-edits slice took that number later the
> same day; llms.txt is now `0.6.0` and `transition-content-status` is `0.7.0`.
> The `dry_run` removal shipped in `0.5.0` as promised, just in a different
> release than the one anticipated here.

**Next release is `0.5.0` — Slice 1B (llms.txt).** It is the heaviest slice in
the roadmap, adds the plugin's first unauthenticated public route, and needs its
own threat model before any code. It also removes the deprecated `dry_run`
preview field, which is why it is a minor bump.

Slice 1B (llms.txt) is `0.5.0`, not `0.4.0`. That split was decided on
2026-08-07: Slice 1A was small, low-risk, and verified, and holding it behind
the heaviest slice in the roadmap would have delayed it for nothing. The full
renumbering is in the "Release numbering" table of
`docs/plan/EDITORIAL_OPERATIONS_ROADMAP.md`.

**Three planned preview Abilities were cut on 2026-08-07**:
`preview-transition-content-status`, `preview-update-media`, and
`preview-update-featured-image`. The roadmap now carries an explicit test for
when a preview is justified — it must answer something the caller cannot get
from the matching `get-*` read plus the payload it already holds. Echoing back
a sanitized string the caller just sent is not a preview. The test also records
that of the two shipped in 0.4.0, `preview-update-content` clears it
comfortably (block round-trip plus replace-not-merge warnings) while
`preview-update-seo` is close to an echo and is kept for symmetry rather than
because it earns its keep.

### Historical note

Before 0.4.0 the released state was **0.3.0**, tagged `v0.3.0` (`be3b177`).

**Verification environment restored on 2026-08-07 and all eleven PHP runtime
verifiers pass** against WordPress 7.0.2, PHP 8.4, Yoast Free 28.2, and Premium
28.0. The 2026-07-21 blocker was environment configuration, not a stopped
database: `DB_HOST` pointed at a stale Local socket path and `WP_HOME` had lost
its domain. Details, including the four drifted verifiers repaired during the
run and the one real schema defect found, are in the "Runtime verification
backlog" section of `docs/plan/IMPLEMENTATION_PLAN.md`.

Fourteen runtime verifiers now pass, including two written on 2026-08-07 for
the Service and Custom Schema slices, one for the 0.4.0 previews, and the MCP
discovery smoke against the official Adapter.

The MCP projection gap and the miniOrange grants were both closed on
2026-08-07; see "MCP exposure and grants" below. Remaining, all scheduled into
`0.4.5` (`docs/plan/RELEASE_0_4_5_PLAN.md`):

1. `restore-trashed-content` — task 1. Closes the live asymmetry where a
   connector can trash but not untrash.
2. Unify the preview response flag — task 2. The 0.3.0 previews report
   `dry_run: true`, the 0.4.0 previews report `writes_performed: false`. One
   concept, two names, opposite polarity. Fixed additively; `dry_run` is
   removed in `0.5.0`.
3. `list-block-patterns` runtime sign-off — task 3. **Done 2026-08-07.**
   `tests/Integration/block-patterns-verification.php` passes against the
   Kormas site: registration gate (absent while `wpcb_pattern_reads_enabled`
   is off, present when on), the capability/native-access authorization
   matrix, metadata-default with no filesystem-path leak (ADR 0013), the
   2 MiB complete-content bound, and deterministic filters/pagination.
4. Verification run book — task 4. **Done 2026-08-07.**
   `docs/setup/VERIFICATION.md` defines the full 18-verifier inventory, what
   each proves, its hardest dependency, the commands, and a dated log of
   complete runs. A containerised environment was tried and rejected; see
   "Not implemented".
5. Retire the inert `wpcb_public_base_url` option — task 5. **Done 2026-08-07.**
6. Milestone 5 security sign-off — task 6. **Done 2026-08-07**, recorded at the
   top of this file with eight named gaps.
7. Release packaging — task 7. Verified on 2026-08-07: 74 files under `docs/`
   and `.agents/` ship inside the production plugin ZIP, including the security
   model, known gaps, and notes about the consuming site's grants. The same
   task fixes the release trigger, which fires on any push touching the version
   line and on 2026-08-07 published a `v0.4.0` built from the rename commit
   alone, missing all of Slice 1A. That release was deleted and re-cut from
   `52cb2a2`; the current `v0.4.0` artifact is correct and was verified by
   listing the ZIP.

**Open manual step, not automatable from this repository:** the old root-owned
`cloudflared` service on the development machine still needs uninstalling. It
is a `sudo`-level operation outside the repo. The dev-only MU shim has already
been removed.

Slice 1B (llms.txt) is `0.5.0` and starts after 0.4.5. Slice 2
`transition-content-status` is `0.6.0`; it is not the next action and never
was 0.5.0 under the current numbering.

## MCP exposure and grants

Verified against the running site on 2026-08-07. **The official-Adapter half of
this section is superseded by 0.8.0** (ADR 0025): `ABILITY_PROFILE` and the
MU-plugin that held it are retired, and the plugin projects its own abilities by
category. The miniOrange half below still stands, and its distinction between the
two paths is exactly why the 0.7.1 investigation had to start by asking which
endpoint the client was on.

### Two MCP servers, one projection

Established by reading the consuming site's source on 2026-08-07, after the
question "why does stage/live work without the MU-plugin?". It does, and the
reason matters — earlier entries in this file blurred the two paths.

**Official MCP Adapter — `/wp-json/wpcb-mcp/mcp`.** The Adapter is a framework
only; the endpoint does not exist until something calls
`wp_register_mcp_server()`. That is the sole job of the site's MU-plugin
`isudev/wp-content-bridge-mcp-server`, whose `ABILITY_PROFILE` constant is the
projection, intersected per request with `wp_has_ability()`. No MU-plugin, no
endpoint — so on an install without it there is nothing to bump. This endpoint
is what `tests/Integration/mcp-smoke-verification.sh` targets.

**miniOrange Secure MCP Server — `/wp-json/mosmcp/v1/mcp`.** This is the OAuth
path ChatGPT connects through, and it **never reads `ABILITY_PROFILE`**. Its
`class-mcp-server.php` calls `wp_get_abilities()` against the WordPress core
registry, applies the `mosmcp_exposed_abilities` filter, then narrows to the
per-principal NHI allowlist. A newly registered ability therefore appears on
this path automatically, with no package release involved.

Consequence: a projection-package bump is hygiene for the smoke test, never a
reachability blocker for the connector. What governs the connector's reach is
the miniOrange grant, and destructive intents must stay out of the read-only
principal's grant deliberately — `restore-trashed-content` included.

**Fail-open worth knowing before 0.5.0:** miniOrange treats an unset allowlist
as unrestricted (`null !== self::$allowed_abilities` guards the filtering step),
so a principal with no grants configured sees every registered ability, bounded
only by WordPress capabilities. The capability layer holds — that is why the
misconfigured `create-draft` grant below could not have executed — but the
projection layer fails open, and Slice 1B's threat model must state that rather
than rediscover it.

### History

The official Adapter now projects all registered abilities. The site's
projection package was one release behind at 0.2.1 with 15 entries, so the three
Custom Schema abilities from 0.3.0 were **absent from the official-Adapter
endpoint** (not from MCP as a whole; the miniOrange path was unaffected). The
package was bumped to 0.3.0 and the smoke run confirmed 18/18.

The separate miniOrange per-principal grants for `wpcb-bridge-reader` did not
match the documented least-privilege profile in either direction. They granted
`create-draft` — a write intent on a read-only principal — while missing
`search-content`, `get-content`, and `get-url-seo`. The layered defense held
(user 87 has no role and only `wpcb_read_content`, so the native `edit_posts`
gate would have refused the call), but the grant contradicted this file's own
claim that no write grants remained. The write grant was removed and the three
missing reads added; the principal now holds exactly the five documented reads
plus `list-block-patterns`.

**Decision 2026-08-07: `list-block-patterns` stays in the `wpcb-bridge-reader`
grant set and its review is deferred to 0.4.5.** It is a genuine read, it is
independently gated behind `wpcb_pattern_reads_enabled` and
`wpcb_read_patterns`, and Plan 4a has never had a runtime sign-off. Revisiting
it now would block the 0.4.0 release on an unrelated verification; 0.4.5 should
either complete that sign-off or drop the grant.

**Resolved 2026-08-07 (0.4.5 task 3): the runtime sign-off is complete and
`list-block-patterns` stays in the grant.**
`tests/Integration/block-patterns-verification.php` passed against the
running Kormas site: the ability is absent while `wpcb_pattern_reads_enabled`
is off and present when it is on; a principal with native editor access but
without `wpcb_read_patterns` is denied, and granting exactly that capability
is what authorizes the same principal; metadata-only is the default and no
response field — metadata or complete-content — ever exposed the fixture's
`filePath`; requesting complete content over the 2 MiB bound failed
atomically with `wpcb_pattern_content_too_large` while metadata-only for the
same oversized pattern was unaffected; and repeated identical filter and
pagination calls returned byte-identical results. No product defect was
found; the ability was already correctly implemented.

Treat the earlier "no `mosmcp/*` write grants remain" note as unverified
history: it was written without a live check. External MCP allowlists remain
site infrastructure and must be updated only when new abilities are
intentionally exposed to a specific principal.

**0.4.5 task 5 done (2026-08-07).** Confirmed by repository-wide search that
`wpcb_public_base_url` was never read by plugin code — only by the
already-removed Kormas-only mu-plugin shim documented in
`docs/setup/CHATGPT_CONNECTOR.md`. Added `uninstall.php` (none existed before)
that deletes the option, and a bounded one-time cleanup in
`Installer::activate()` gated by bumping `SCHEMA_VERSION` to 9, so existing
installs shed the row on their next upgrade without a migration framework.
The root-owned `cloudflared` service removal remains the user's manual
`sudo` step, untouched here.

## WordPress 7.1 Abilities API — baseline bumped, no 7.1 API consumed yet (2026-09-01)

`docs/plan/WP_7_1_ABILITIES_ADOPTION_PLAN.md` is the plan. **Task 1 is done
(ADR 0027 accepted): WordPress 7.1 is the minimum**, stubs are on v7.1.0,
`AGENTS.md` forbids 7.0 back-compatibility branches, and `composer check` is
green (498 tests). Unreleased — no version bump; the plugin still consumes zero
7.1 API, so no behavior, schema, or contract changed.

**Task 2 is also done, and the full inventory ran: 23/23 green on WordPress 7.1**
(2026-09-01, Kormas Local bumped to 7.1 / PHP 8.4.6), including all three shell
verifiers and the MCP transport smoke. Nothing regressed on 7.1. `Tested up to:`
is now 7.1 and earned rather than asserted; see `docs/setup/VERIFICATION.md`.

New verifier: `tests/Integration/rest-input-coercion-verification.php`. It
refuses to run below 7.1 and pins the coercion contract measured in
`docs/architecture/ABILITIES.md`. **Core is far more conservative than the dev
note suggests**: coercion runs only on input `validate_input()` already accepts,
and falls back to the raw input on any sanitization error, so no bound moved and
`type: string` fields are never comma-split. The one real change is that a
numeric string now reaches a use case natively typed over REST, so requests that
previously failed inside our code now succeed — wider *input*, identical
authority. A direct `execute()` still refuses a string `post_id`, and that is
now asserted so a future core change cannot quietly move coercion into the
domain.

**Defect found by that run and fixed the same day (task 9): every domain
rejection answered HTTP 500.** None of the 86 `new WP_Error()` returns carried a
`status`, so core defaulted them all — an unknown `post_id`, a disallowed
`post_types`, an invalid URL selector. Agent clients read 500 as transient and
retry; monitoring reads routine refusal as an outage.

Fixed by `src/Adapter/Abilities/AbilityError.php`, now the **only** place an
ability's `WP_Error` is constructed. All 86 sites go through it; the status is
applied last so extra error data survives and no caller can override its own
status. Codes are unchanged — statuses are new public contract, catalogued in
`ABILITIES.md`. Two decisions not to relitigate: an absent optional provider is
**501, not 503** (nothing is overloaded, retrying will not help), and **404
deliberately conflates "does not exist" with "not visible to you"** so the
status cannot enumerate content — that one is in `SECURITY.md`, because the
obvious "improvement" to a 403 would open the channel.

`AbilityErrorTest` discovers the vocabulary from the source rather than
restating it, so a new error code without a status is a failing test instead of
a silent 500. The full inventory was re-run after the change (23/23) because it
touched every error path in the plugin.

**This is a client-visible change and needs a release note**: consumers that saw
500 now see 4xx.

**Tasks 3, 4 and 8 also done (2026-09-01).** Diagnostics gained
`minimum_wordpress_version` (read from the plugin header, so the requirement has
one source of truth) and `abilities_api_features.declarative_filtering` (probed
by reflecting `wp_get_abilities()`); diagnostics `schema_version` is 1.1 and
`abilities_api` stays a boolean, so the change is additive. Task 3 landed
**smaller than planned**: with 7.1 as the floor most feature entries would be
tautologies, and the two that would not be — `wp_ability_invoked` and
`meta.public` — cannot be probed at all, so inventing a version-derived probe
was rejected. `McpServerProvider::discover()` now filters declaratively and
**keeps** its category comparison; the reason is at the call site, and the
existing unit test turns out to be the fail-open assertion because the suite's
`wp_get_abilities()` stub declares no parameters, exactly like a WordPress
without the filter. The 7.1 execution filters we refuse, and why, are written
into `ABILITIES.md` so the next agent does not adopt them as an obvious win.

**Tasks 5, 6 and 7 are also done — the 7.1 adoption plan is complete.**

- **Task 5** — `src/Adapter/Abilities/AbilityMeta.php` is now the only source of
  registration metadata, replacing thirteen per-class helpers and eight inline
  literals, and all 31 abilities declare 7.1's `public` **alongside** an explicit
  `show_in_rest` (not instead of it: `CLOSED_PROFILE` asserts that surface).
  Verified rather than assumed: the 31 abilities' annotations were parsed before
  and after the refactor and compared — **zero differences**. Two real footguns
  died with the helpers: two same-named `write_meta()` taking different single
  booleans, and three names producing one identical array.
- **Task 6 (ADR 0028)** — `destructive` finally has a definition: *can lose
  content or configuration the client did not supply*. Under it, 30 of 31
  annotations were already right; exactly one changed (`update-llms-txt` →
  `true`, because its input is a complete replacement configuration). The audit's
  "broadly inconsistent" framing was wrong — the defect was the missing
  definition. **The real risk was the HTTP method**: core maps
  `destructive && idempotent` to DELETE, so an annotation edit can move an
  endpoint. Checked before changing, and verified live: method stayed POST, and
  no ability in this plugin sets that pair.
- **Task 7 (ADR 0029)** — invocation telemetry as an **off-by-default diagnostic
  mode**, closing the gap that started this: a `permission_callback` denial now
  leaves a trace. Off by default and *absent* when off (no listener attached).
  Bounded by construction — a 200-entry ring buffer, so it can never grow into
  or evict `wpcb_audit`. One database write per request, not per invocation
  (entries buffer and flush on `shutdown`), which is what lets an entry be
  created before the permission check and upgraded to `completed` after success.

Two things about telemetry that must not be forgotten when reading it:
`wp_after_execute_ability` fires **only on success** and no hook fires on the
failure paths, so `attempted` means only "did not complete" — a denial, invalid
input and an internal error are indistinguishable. And an entry is **not
evidence**: the hook fires before validation and authorization. `wpcb_audit`
remains the record of what happened; correlate, never merge.

Full inventory ran **five times** on 7.1 today, green each time, last at
**24/24** (see `docs/setup/VERIFICATION.md`). Every one of those changes touched
a cross-cutting path — error returns, ability registration, or execution itself
— which is why each got a whole-inventory run rather than a targeted one.

Anomaly recorded rather than hidden: the first invocation of
`abilities-runtime-verification` in the batch exited non-zero, then passed four
consecutive re-runs. It ran immediately after a verifier deleted its fixture
post; most plausibly a settling object cache, but it is unreproduced, so treat a
single unexplained failure there as worth re-running rather than as proven
flakiness.

Side effect worth remembering: bumping the stubs to v7.1.0 surfaced eight
pre-existing PHPStan errors at `level: max`, because the 7.1 stubs are more
precise, and forced `szepeviktor/phpstan-wordpress` to `^2.0.4` (v2.0.3
constrains the stubs to `^6.6.2`). Four were a real unguarded
`WP_Query::$posts` nullable; four were guards the new PHPDoc claims are
unreachable, kept behind `treatPhpDocTypesAsCertain: false` because WordPress
does not enforce its own PHPDoc and one set of those guards is the security
control in an origin comparison. See ADR 0027's consequences.

Unrelated, surfaced while resolving dependencies and **not fixed**:
`squizlabs/php_codesniffer` CVE-2026-67434 (high, OS command injection, fixed
in 3.13.6). Dev-only dependency.

Three findings from the audit behind it, recorded because they are true of
`0.8.3` regardless of whether that plan is executed:

- **A denial at `permission_callback` leaves no trace anywhere.** The
  `wpcb_audit` table only records denials that reached a use case
  (`MutationForbidden`, `TrashUnavailable`). Reads are not audited at all, by
  design. 7.1's `wp_ability_invoked` is the first hook that can observe this,
  and it must not write to `wpcb_audit` — that table prunes at 5,000 rows and
  read traffic would evict the mutation history.
- **`meta.public` in 7.1 is *not* our existing `mcp.public`.** Core reads
  top-level `$meta['public']`; ours is nested under `mcp` and read by the
  Adapter. Nothing to consolidate. All 31 registrations already set
  `show_in_rest => true` explicitly, so core's new fallback never fires and
  nothing is currently broken.
- **`wp_get_abilities( $args )` fails open on 7.0.** Extra arguments to a
  userland function are ignored, so the args-based category filter returns every
  registered ability — including other plugins' — on a 7.0 install.
  `McpServerProvider::narrow()` does not save us, because the discovered set is
  what widens. Any adoption keeps the explicit category comparison.

Blocked on the stubs: `php-stubs/wordpress-stubs` resolves to `v6.9.4`, whose
`wp_get_abilities(): array` takes no parameters, and PHPStan runs at
`level: max`.

## Guardrail

Do not start write abilities until Milestones 1–3 pass their security and contract acceptance criteria.

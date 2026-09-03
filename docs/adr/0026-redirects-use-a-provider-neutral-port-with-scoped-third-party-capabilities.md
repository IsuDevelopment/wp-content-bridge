# ADR 0026: Redirects use a provider-neutral port with scoped third-party capabilities

## Status

Accepted (2026-09-03), amended in place twice before acceptance (2026-08-14
runtime reconciliation, 2026-09-01 source reconciliation — both below).
Originally the Phase 5A research/ADR deliverable required by Slice 5 of
`docs/plan/EDITORIAL_OPERATIONS_ROADMAP.md` before any permalink or redirect
Ability is implemented.

**Accepted after the code it governs was built and verified against two live
engines, not before.** Acceptance was deliberately withheld while the two
questions the 2026-09-01 amendment reopened were still open; both are now
closed by observation rather than by argument, and the answers are recorded
here:

- **Decision 1's build order is spent, and its runtime half is superseded by
  decision 4.** Redirection was built and verified first, Yoast Premium
  second (`Infrastructure\Yoast\YoastPremiumRedirectProvider`, against
  Premium 28.0); both adapters now exist, so the ordering question no longer
  decides anything. The "Yoast Premium first, Redirection second, never both"
  *runtime preference* in decision 1 is **withdrawn**: decision 4's amendment
  removed implicit provider selection entirely, so there is no preference
  order left to hold. A write names its provider; a read spans all of them.
- **Decision 3 stands as written, for a reason that changed.** Yoast Premium
  does ship a redirect REST API, so the original justification ("no
  documented API exists") is void — but the in-process manager remains the
  path this plugin uses, because the REST route wraps the same manager behind
  a second permission model this plugin would then have to satisfy *and*
  scope, exactly the coupling decision 2 exists to avoid for Redirection. The
  compatibility gate decision 3 asked for is implemented as
  `YoastPremiumRedirectProvider::is_available()`, which probes
  `WPSEO_PREMIUM_VERSION` plus every class and method the adapter calls, and
  the pinned runtime fixture is `tests/Integration/redirects-verification.php`
  at Premium 28.0. Because the manager performs no capability check of its
  own, the adapter asserts native `wpseo_manage_redirects` itself.

Statistics remain out of scope here, as the 2026-09-01 amendment required:
they are ADR 0030's separate port.

What acceptance does **not** cover, because it was never built: `disable` as a
distinct operation (the port has `update` and `delete` instead), and the
`covered_by_core` `redirect_disposition` value, whose `_wp_old_slug`
precondition is still the open item recorded under Consequences.

**Runtime-reconciled 2026-08-14** against a live Redirection 5.9.0 install on
Kormas Local (the environment `docs/setup/VERIFICATION.md` designates for this
repo). The public-documentation-only version of `RedirectionProvider` had four
defects that only a live install exposed; all four are fixed and re-verified
end-to-end (`search` → `create` → `search` finds it → cleanup), see the class
docblock and `.agents/status.md` for the list. This does not verify the Yoast
Premium adapter (not built) or the `RedirectCandidateGuard` invariants under
real WordPress (still fake-only unit tests) — both remain open before any
Ability ships.

**Source-reconciled against Yoast SEO Premium 28.0 on 2026-09-01, and one of
this ADR's load-bearing research findings turned out to be wrong.** The
"Yoast SEO Premium Redirect Manager" subsection below was written from public
documentation and a community gist. Reading the plugin actually installed on
the designated environment
(`content/plugins/wordpress-seo-premium`, version 28.0, alongside Yoast SEO
Free 28.4) shows that **Yoast Premium does ship a redirect REST API** and an
in-process service layer that is registered on every request, not only on
admin screens. The corrected findings are in that subsection, marked. Two
consequences for the decisions below, both still open:

- Decision 1 ("build order is reversed") rested on Yoast having no callable
  API. It has one. The build order may no longer be justified on that ground.
- Decision 3 ("Yoast Premium is called through its internal classes behind a
  version fixture") describes a path that is no longer the best available one;
  the REST/service layer is preferable to the internal classes it names.

**Yoast Premium collects no 404 or redirect-hit data at all** (verified by
exhaustive search of both plugins, not by absence of documentation): the
Google Search Console crawl-error feature is a dead stub whose own view says
Google discontinued the API, and no table or option stores hits. Redirection
5.9.0 does keep both a 404 log and a redirect-hit log. Any redirect
*statistics* capability therefore cannot live behind the redirect port defined
here — a Yoast-backed site would report zero 404s, which is indistinguishable
from a healthy site. Statistics need a separate port with an explicit
unavailable state, and a separate ADR.

## Context

Slice 5 was reprioritized to the next release (`0.9.0+`, see the roadmap's
"Release numbering" section, decided 2026-08-14). The roadmap already fixed
part of the provider decision: Yoast SEO Premium Redirect Manager and the
Redirection plugin (John Godley) are the two supported backends, behind one
provider-neutral port, never dual-written, with no automatic fallback on a
provider error. What it left open for this ADR: whether that preference order
survives contact with each plugin's actual API surface, how a bounded
integration principal calls either plugin without acquiring `manage_options`,
and how collision, chain/loop, cache, and canonical checks are enforced
uniformly across two backends with different storage.

### Redirection (John Godley) — researched surface

- **REST API**, documented at `redirection.me/developer/rest-api/`:
  `/wp-json/redirection/v1/{redirect,group,log,404,setting}` plus bulk and
  import/export routes. The page states plainly: *"the API should not be
  considered stable"* — exactly the risk the roadmap flagged.
- **PHP API**, documented at `redirection.me/developer/php-api/`:
  `Red_Item::create()` plus `Red_Item_Sanitize`. Documented as *"subject to
  change. Use at your own risk"*, and the same page says the REST API is *"the
  preferred method of accessing Redirection."*
- **Permissions are genuinely granular as of 4.6+**
  (`redirection.me/developer/permissions/`), which is the load-bearing finding
  for this ADR:

  ```php
  add_filter( 'redirection_role', function ( $role ) {
      return 'edit_posts';
  } );

  add_filter( 'redirection_capability_check', function ( $capability, $permission_name ) {
      if ( in_array( $permission_name, array( 'redirection_cap_redirect_manage', 'redirection_cap_redirect_add' ), true ) ) {
          return $capability;
      }
      return 'manage_options';
  }, 10, 2 );
  ```

  Default access is `manage_options` for everything, but `redirection_role`
  sets the baseline capability and `redirection_capability_check` lets a
  caller answer per feature (`redirect_manage`, `redirect_add`,
  `redirect_delete`, and the group/log/404/admin equivalents).
- Source-path matching supports plain and regex, case-insensitivity and
  trailing-slash handling (schema changed in 4.0), and explicit query-string
  modes (match/ignore/pass-through, refined in 5.2). Groups are a first-class
  resource. `action_code` is a free integer field — the plugin does not itself
  bound it to an editorial-safe allowlist.

### Yoast SEO Premium Redirect Manager — researched surface

**Corrected 2026-09-01 from the source of Premium 28.0.** The two bullets
struck through below were the documentation-only findings and are wrong. They
are kept because they are the reason this ADR proposed a build order and a
fixture-gated internal-class path, and removing them would hide why.

- ~~**No REST API.** Nothing under `developer.yoast.com` documents a redirect
  REST namespace, and none was found.~~ **Wrong.** Premium 28.0 registers
  `yoast/v1/redirects` (POST, create), `yoast/v1/redirects/delete` (POST),
  `yoast/v1/redirects/list` (GET, `format=plain|regex`),
  `yoast/v1/redirects/update` (PUT) and `yoast/v1/redirects/settings`
  (GET/PUT) in `classes/premium-redirect-endpoint.php`, all behind
  `current_user_can( 'wpseo_manage_redirects' )`. A sixth route,
  `yoast/v1/redirects/undo-for-object`, is gated on `edit_post`/`edit_term`
  instead. The endpoint and its `WPSEO_Premium_Redirect_Service` are
  instantiated on every request in `WPSEO_Premium::__construct()`, and the
  routes attach on `rest_api_init`. Being undocumented, it carries no
  compatibility promise — but it is a real, intended, non-admin call path,
  which is not what this ADR assumed.
- ~~**No documented PHP API.**~~ Undocumented, but callable and not
  admin-gated. `WPSEO_Redirect_Manager` (`get_redirects`, `get_all_redirects`,
  `get_redirect`, `create_redirect`, `update_redirect`, `delete_redirects`,
  `save_redirects`, `export_redirects`), `WPSEO_Redirect_Option`,
  `WPSEO_Redirect`, `WPSEO_Redirect_Validator` and the exporters are all
  Composer-classmap-autoloaded, contain no `is_admin()` guard, and work from a
  REST, cron or CLI request. Only `WPSEO_Redirect_Page` and
  `WPSEO_Redirect_Ajax` are admin/AJAX-gated. There is also a
  `wp yoast redirect list/create/update/delete/has/follow` CLI command set.
- **Storage is options, and there are three of them.**
  `wpseo-premium-redirects-base` is canonical (`autoload = false`) and holds
  plain and regex together, distinguished by a `format` key.
  `wpseo-premium-redirects-export-plain` and
  `wpseo-premium-redirects-export-regex` are derived read-caches, and they are
  what the front-end matcher actually reads. **Writing the canonical option
  directly is therefore not enough** — the exports must be regenerated, so
  every write must go through the manager's `save_redirects()`.
- **The manager performs no capability check.** `current_user_can` appears
  only in the REST permission callback, the AJAX handlers and the submenu
  registration. Calling the manager in-process means this plugin's own layer
  is the only authorization gate — a stronger reason to prefer the REST
  routes, or to enforce the capability explicitly before touching the manager.
- **`WPSEO_Redirect_Validator` makes an outbound HTTP request.** Its
  accessible-target validation issues a live `wp_remote_head()` against the
  redirect target. That is a side effect an ability must not incur silently.
- **No 404 or hit data exists.** No table, no option, no counter, in Premium
  28.0 or Free 28.4. The Search Console crawl-error screen is a stub whose own
  copy states Google discontinued the API. Yoast cannot be a statistics
  provider.
- **Native capability is already granular**: `wpseo_manage_redirects` is a
  real, narrower-than-`manage_options` WordPress capability that Yoast itself
  auto-grants to the built-in Editor role and to its own "SEO editor" role.
  Unlike Redirection, no filter is needed to avoid `manage_options` — the
  capability already exists and is exactly what the roadmap anticipated
  ("require its native `wpseo_manage_redirects` capability in addition to
  bridge authority").
- Documented public filters exist only for **disabling** Yoast's own automatic
  redirect-on-slug-change behavior
  (`Yoast\WP\SEO\post_redirect_slug_change`,
  `Yoast\WP\SEO\term_redirect_slug_change`) and trash/slug-change
  notifications — useful later for coordinating with `update-permalink`, not
  for reading or writing redirects.
- Redirect types confirmed: plain and regex, HTTP codes 301/302/307/410/451.

### WordPress core baseline

`wp_old_slug_redirect()` fires only on an actual 404 with a resolvable query
var, only for non-hierarchical post types (hierarchical post types, including
`page`, are explicitly excluded), issues a 301, and never touches taxonomy term
slugs. It is not a provider and is not a substitute for either plugin, but its
existence means a manual permalink change on a non-hierarchical, previously
published post type may already 301 correctly with zero configuration — a
provider-created redirect is only load-bearing where core's old-slug lookup
does not apply (hierarchical types, taxonomy terms, or a slug changed more
than once).

## Decision

### 1. Runtime provider preference is unchanged; build order is reversed

**Withdrawn in part on acceptance (2026-09-03), see Status.** The runtime
preference below no longer applies: decision 4's amendment removed implicit
provider selection, so a write names its provider and there is no order to
prefer. The build order it decided is spent — both adapters are built.

The roadmap's product reasoning for preferring Yoast Premium when present — a
site that already pays for it gets one redirect UI, not two — is independent
of API stability and this ADR does not overturn it. **Runtime selection stays
Yoast Premium first, Redirection second, never both.**

What changes is **which adapter gets built and verified first**: Redirection,
because it has a real documented API surface (REST and PHP, both explicitly
labeled unstable but both *documented and versioned in a public changelog*)
and a permission model this plugin can use without granting `manage_options`.
Yoast Premium's adapter is gated behind an explicit compatibility fixture
(below) before it may register, exactly like the Schema Extended provider
(ADR 0017). If that fixture fails on the installed Premium version, the
Yoast Premium provider reports itself unavailable and the registry (see §4)
falls through to Redirection — this is ordinary optional-provider degradation,
not the forbidden "fallback write to the other provider after an error"; no
write is attempted against Yoast Premium in that case.

### 2. Redirection is called through a scoped capability filter, never `manage_options`

The infrastructure adapter dispatches an internal `WP_REST_Request` through
`rest_do_request()` against `redirection/v1` — no HTTP loopback, no nonce.
Immediately before that call it registers both `redirection_role` and
`redirection_capability_check`, returning `wpcb_manage_redirects` only for the
`redirection_cap_redirect_manage`/`redirection_cap_redirect_add`/
`redirection_cap_redirect_delete` permission names relevant to the specific
Ability being executed, and removes both filters in a `finally` block
immediately after the call returns. The filters are scoped to the single
in-process call, not the request lifetime, so they cannot widen access for an
unrelated Redirection admin-ajax call dispatched later in the same PHP
process. WPCB's own authorization (`wpcb_manage_redirects`, native
`edit_post`/`manage_options`-free object check, the disabled-by-default
feature flag, and the redirect-level concurrency token) still runs before the
filter is ever registered; the filter only prevents Redirection's own
permission check from separately demanding `manage_options` from the acting
principal.

### 3. Yoast Premium is called through its internal classes behind a version fixture

**Upheld on acceptance (2026-09-03) on different grounds, see Status:** a REST
API does exist, but using it would mean satisfying and scoping a second
permission model, which is the coupling decision 2 exists to avoid. The
fixture asked for below is `tests/Integration/redirects-verification.php`,
pinned at Premium 28.0.

Because no documented API exists, the adapter guards every call with
`class_exists()`/`method_exists()` on `WPSEO_Redirect`,
`WPSEO_Redirect_Option`, and `WPSEO_Redirect_Manager` and refuses to register
if any check fails — the same "available only if these exact symbols exist"
gate ADR 0017 uses for Schema Extended. Authorization requires native
`wpseo_manage_redirects` (already granular; no filter needed) plus
`wpcb_manage_redirects`. Before this adapter ships, add a runtime
compatibility fixture pinned to the specific Premium version(s) under test in
`docs/plan/TEST_PLAN.md`, and re-run it on every Premium version bump —
undocumented internals carry no deprecation notice, so drift is silent by
default.

### 4. One provider-neutral port; the caller names the provider; reads span all of them

**Amended 2026-09-01. The original text is below, and the part that selected a
provider implicitly is superseded.** Two facts drove this: sites commonly run
Redirection *and* Yoast Premium at the same time, and when they do **both
engines serve redirects at runtime** — Yoast's `Redirect_Handler` is gated on
`! is_admin()` and Redirection's module hooks the front end too, so whichever
attaches first wins. An implicit "first available provider" hides that.

- **Writes take a required, explicit `provider`.** There is no preference
  order and no guessing. On a two-plugin site the caller must state where the
  rule goes, because that choice decides whether the rule is the one that
  actually fires.
- **Reads span every available provider**, each returned rule labelled with
  its provider, plus an explicit marker when the same source exists in more
  than one. A single-provider read misreports exactly the sites this
  amendment is about.
- **Never dual-write still holds**: a write goes to exactly one named
  provider, and a provider error never triggers a fallback write to the other.
- Per-provider Abilities were considered and rejected: they would double a
  permanent public contract surface, two near-identically named tools are a
  known way for an agent to pick the wrong one (here meaning "wrote the rule
  into the plugin that loses"), and the cross-provider guard below would still
  be required, so the port would not disappear — it would only stop being the
  Ability boundary. Provider-specific features that cannot be expressed
  neutrally (Redirection groups and match types, Yoast's `307`/`451`) stay out
  of the neutral surface and may later get their own explicitly named
  Abilities when a concrete need exists.

Original text, superseded in part: mirror `SeoProviderRegistry`
(`src/Application/Seo/SeoProviderRegistry.php`): an ordered list of
`RedirectProvider` adapters plus a required null object,
selecting the first available provider. "Available" means the compatibility
gate in §2/§3 passes; it is evaluated fresh per request, not cached across
requests. `search-redirects`/`get-redirect`/`create-redirect`/
`update-redirect`/`disable-redirect` report which provider answered and its
version in every result, per the roadmap's existing diagnostics requirement.

### 5. Provider-neutral invariants enforced by the port, not by either adapter

A shared pre-check runs before any adapter's create/update call, so both
backends enforce identical editorial-safety rules regardless of their native
permissiveness:

- **Source scope (P0):** bounded, exact, non-regex, site-relative paths only.
  Both plugins support far more (regex, headers, cookies, IP, referrer); the
  port only ever sends a plain/exact match request to either adapter.
- **Normalization:** canonicalize the source path with WordPress's own
  permalink trailing-slash structure (`user_trailingslashit()`) before any
  collision check, since the two plugins' own trailing-slash handling is not
  guaranteed identical.
- **Collision — corrected 2026-09-01: query _every_ available provider, not
  the selected one.** The original wording checked only the active provider.
  On a site running both plugins that is unsound: the guard would report no
  collision, the rule would be written to the named provider, and the other
  plugin's existing rule for the same source could still be the one that
  fires. A source already claimed by *any* available provider is a collision,
  and the rejection names which provider holds it. A stale-token conflict on
  update follows the same rule as every other mutation in this plugin.
- **Live-content shadow guard:** reject a source path that is the current
  canonical permalink of a still-published, non-trashed object. A redirect is
  for a URL that no longer resolves to live content, not a way to intercept
  one that does; this is also what keeps a provider-created redirect out of
  the active XML sitemap's URL set.
- **Reserved-prefix denylist:** reject source paths under `wp-json/`,
  `wp-admin/`, `wp-content/`, `feed/`, and any registered REST/sitemap
  rewrite prefix, so a redirect can never shadow a core or plugin endpoint.
- **Chain/loop bound — also cross-provider (corrected 2026-09-01):** before
  create, resolve the target through the existing rules of **all** available
  providers (and, where applicable, `wp_old_slug_redirect()`) up to 3 hops. A
  chain can hop between backends — `/a → /b` in Yoast and `/b → /a` in
  Redirection is a loop that neither plugin can see on its own. A loop back to the source is rejected outright; a chain that
  would still not terminate within the bound is rejected as unresolvable
  rather than silently created.
- **HTTP status allowlist (P0):** `301`, `302`, `410` — the intersection that
  is unambiguous for editorial use and meaningful across both providers'
  documented codes. `307`/`451` are supported by both plugins but excluded
  from P0 pending an explicit product need; this narrows, and does not widen,
  the roadmap's "small common HTTP-status allowlist" language.
- **Cache:** a redirect tied to a permalink change reuses the post-scoped
  invalidation event (ADR 0012) for old and new URL; a standalone redirect
  invalidates only its bounded source path.
- **Canonical/post-write verification:** after create or update, the port
  issues one same-site, non-cached-bypassing check that the source now
  reports the accepted status/target before the Ability returns success —
  the same post-write read-back discipline every other write in this plugin
  already follows.

### 6. Permalink changes and redirect creation remain two separate, explicit writes

`update-permalink` never creates a redirect implicitly or atomically. It
reports a `redirect_disposition` of `none_created` (or, once WP core's
old-slug behavior is confirmed applicable for that object, `covered_by_core`)
alongside the old and new canonical URLs. A caller who wants a redirect calls
`create-redirect` separately, with its own token, capability, and audit event.
Reasons this ADR does not attempt coordination:

- The two writes touch unrelated storage (`wp_posts`/`wp_postmeta` versus a
  third-party plugin's own table/option) with no shared transaction boundary;
  a "coordinated" write could report success while only one side committed.
- It would make `update-permalink` implicitly depend on whichever redirect
  provider happens to be active, coupling a core-content Ability's success
  criteria to an optional provider's availability.
- It is the same posture the roadmap already requires everywhere else in this
  slice: reads, previews, and writes are separate intents, and "no redirect is
  created implicitly unless an accepted ADR defines an atomic or safely
  recoverable provider workflow" — this ADR is that opportunity, and it
  declines, because no atomic or safely recoverable cross-plugin workflow was
  found. A future ADR may revisit this once/if both providers expose an
  idempotent, re-checkable write.

## Consequences

- Slice 5 implementation starts with the Redirection adapter; the Yoast
  Premium adapter does not ship until its compatibility fixture exists and
  passes against a specifically identified Premium version.
- No integration principal is ever granted `manage_options` to manage
  redirects: Redirection is accessed through scoped capability filters,
  Yoast Premium through its own already-granular native capability.
- The five provider-neutral invariants in §5 live in application code shared
  by both adapters, not duplicated per provider and not left to either
  plugin's own (looser) validation.
- `update-permalink` and `create-redirect` remain independently authorizable,
  auditable, and reversible; a caller can change a slug without a redirect,
  or create a redirect without changing a slug, and neither operation's
  success depends on the other.
- If Redirection materially changes its REST/PHP API or permission filters in
  a future major version, only the Redirection adapter and its contract
  fixtures need to change — the port and both callers are unaffected. The
  same isolation applies if Yoast ever publishes an official redirect API,
  which would let the Yoast adapter drop its `class_exists()` guard in favor
  of a supported entry point.
- Open item for the executing plan, not this ADR: confirm whether
  `_wp_old_slug` remains the exact postmeta key core uses on the target WP
  version before `redirect_disposition: covered_by_core` is implemented, and
  scope taxonomy-term slug changes (not covered by core at all) as a later
  gap rather than silently assuming parity with post redirects.

## Sources consulted

- [Redirection REST API](https://redirection.me/developer/rest-api/)
- [Redirection PHP API](https://redirection.me/developer/php-api/)
- [Redirection permissions](https://redirection.me/developer/permissions/)
- [Redirection plugin, WordPress.org](https://wordpress.org/plugins/redirection/)
- [The redirect manager in Yoast SEO Premium](https://yoast.com/help/the-redirect-manager-in-yoast-seo-premium/)
- [Disabling automatic redirects and notifications, Yoast developer portal](https://developer.yoast.com/customization/yoast-seo-premium/disabling-automatic-redirects-notifications/)
- [Programmatically add a Yoast SEO Premium redirect (community gist)](https://gist.github.com/joshuadavidnelson/3b320988859efc054d0f7ec5aba61519) — unofficial, reverse-engineered; cited to document the only known write path, not as an authoritative API reference.
- [`wp_old_slug_redirect()`, developer.wordpress.org](https://developer.wordpress.org/reference/functions/wp_old_slug_redirect/)

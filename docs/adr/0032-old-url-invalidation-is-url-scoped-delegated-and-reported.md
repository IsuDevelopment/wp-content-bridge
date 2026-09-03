# ADR 0032: Old-URL invalidation after a slug change is URL-scoped, delegated, and reported

## Status

Accepted (2026-09-03). Narrows ADR 0012, which decided that invalidation is
post-scoped and event-driven; it does not overturn it. Closes the open item
recorded in `.agents/status.md` after `update-permalink` shipped in 0.9.0.

## Context

`update-permalink` changes one post's slug. Invalidation runs through
`wpcb_mutation` → `WordPressPostCacheInvalidator` → `clean_post_cache(
$post_id )`, so the new URL inherits it like every other write. **The old URL
does not.** A page-cache entry for the old URL is keyed by URL, not by post
ID, and nothing in the post-scoped path addresses it. The observable failure
is not a missing page: it is a *stale* one. Until the entry expires, the old
URL keeps serving the old rendering instead of the redirect or 404 the site
now intends — and the operator who just renamed a page sees the rename
"not take effect" for reasons no bridge output mentions.

The roadmap asks for "old+new bounded cache invalidation". This was recorded
rather than quietly patched, because the fix is cache-plugin-specific and
needed a decision about how far this plugin may reach into third-party
caches.

Three facts shape the decision:

1. **The old URL is known exactly once, in exactly one place.** The write that
   changes the slug has both URLs; nothing downstream does. `AuditEvent` is
   deliberately redacted to changed *field names* — no values, no URLs — so
   the `wpcb_mutation` path cannot learn the old URL without changing what
   this plugin's audit record contains.
2. **Cache plugins vary, and their internals are not contracts.** Some expose
   a public single-URL purge hook, some only a post-scoped one, some neither.
   Reading a cache plugin's options or calling its classes is the coupling
   ADR 0012 already refused.
3. **A purge this plugin cannot observe must not be reported as done.**
   Dispatching an action proves a listener ran, not that a cached page was
   dropped.

## Decision

### 1. The old URL is invalidated by the write that knows it, through a URL-scoped port

`UpdatePermalink` calls a `UrlCacheInvalidator` port with the old and new
canonical URLs. It does **not** travel through `wpcb_mutation`.

The alternative — putting URLs on the mutation event — was rejected because it
would make the audit sink, its stored rows, and every `wpcb_mutation`
subscriber a carrier of content values. The audit contract's redaction is a
security property (`SECURITY.md`), and widening it to solve a cache problem is
the wrong trade.

The port is an application-layer interface with a WordPress adapter, like every
other boundary here; domain code stays free of it.

### 2. Reach is limited to public, documented, single-URL hooks, dispatched only when someone is listening

The adapter may:

- dispatch a cache plugin's **public, documented, single-URL** purge hook, and
  only when `has_action()` says a listener is registered — the same discipline
  `WordPressPostCacheInvalidator` already uses for `litespeed_purge_post`;
- emit this plugin's own `wp_content_bridge_purge_urls` action so a site, host,
  or mu-plugin can bind whatever its stack actually uses.

The adapter may **not**: read a cache plugin's options, call its classes or
private functions, purge the whole site, purge anything outside the bounded URL
set below, or issue an HTTP request of its own to warm or purge a URL. A
site-wide flush as a "safe default" is specifically rejected: on a large site
it is a self-inflicted outage of the cache tier, triggered by an agent renaming
one page.

### 3. The URL set is exactly old and new, and nothing else

Not the archive, not the front page, not paginated lists, not anything that
links to the page. Expanding the set means guessing at a site's template graph,
and the guess is unbounded — every listing that ever included the post. Links
elsewhere are what `create-redirect` is for; this decision does not try to make
a rename invisible, only to stop the *renamed URL itself* from serving stale
content.

### 4. The result reports what was notified, and says when nothing was

`update-permalink` returns a `cache` object: the URLs it addressed, the
channels notified, and `delegated: true` when no channel beyond the plugin's
own action was available.

This is the point of the whole decision. A caller must not read "success" as
"the old URL is now cold", because on a site with an unbound page cache it is
not. Reporting `delegated` states plainly that the actual purge depends on
site-level glue that this plugin cannot see — which is the honest answer, and
is actionable, whereas silence is neither. Nothing here reports a purge as
*confirmed*: the plugin dispatches, it does not verify.

### 5. Best-effort, never fatal

The write has already committed when invalidation runs. A failing cache adapter
is contained and surfaced through the existing
`wp_content_bridge_cache_invalidation_failed` action; it never turns a
completed rename into a reported write failure. That rule is inherited from
ADR 0012 unchanged.

## Consequences

- A site whose page cache exposes a single-URL purge hook gets the old URL
  dropped automatically; every other site gets a documented action to bind and
  a result that says the purge was delegated.
- `update-permalink` output gains a `cache` object. Additive, so existing
  callers are unaffected.
- The audit event and its stored rows are unchanged, and still carry no URLs.
- The same port is available to any future write that invalidates a URL rather
  than a post. Nothing else needs it today, and no other ability is changed
  here.
- Not addressed, deliberately: CDN and reverse-proxy tiers above WordPress.
  They are not reachable through a WordPress hook, and a plugin that started
  making authenticated purge calls to a third-party edge would need
  credentials, which is a different ADR and a different threat model.

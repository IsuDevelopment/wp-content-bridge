# ADR 0030: Site error statistics are a separate, aggregate-only port

## Status

Accepted (2026-09-03), implemented in the same change. Prerequisite for any
statistics or monitoring Ability in Slice 5. Depends on ADR 0026 (redirect
port), accepted the same day, which was amended on 2026-09-01 after its Yoast
findings were corrected from plugin source.

Implemented as `Domain/Statistics/`, `Application/Statistics/`,
`Infrastructure/Redirection/RedirectionErrorStatisticsProvider`, and the
`wp-content-bridge/get-404-statistics` Ability behind the
`wpcb_read_error_statistics` capability and the off-by-default
`wpcb_error_statistics_enabled` switch. Two things about the built version are
worth recording here, because both are decisions this ADR did not make:

- **A fourth state, `forbidden`, joined the three in decision 2.** Decision 4
  requires the adapter to enforce the provider's own capability itself, since
  a direct table read never reaches the provider's check - and a denial then
  needed an answer. Reporting it as `unavailable` would send an operator to
  install a plugin that is already installed, which is the same
  indistinguishable-states defect the three states exist to prevent. The
  adapter *queries* Redirection's documented `redirection_capability_check`
  filter rather than registering it: registering it, the way the redirect
  adapter legitimately does around a call into Redirection's own code, would
  mean this plugin answering its own permission question. The consequence is
  that on a site which has not configured that filter the effective
  requirement is Redirection's default, so a non-administrator integration
  principal is refused - visibly, and fixable in Redirection's own vocabulary.
- **An off-by-default feature switch was added**, which this ADR does not
  require. Reads elsewhere in this plugin are ungated, but this one reads a
  third-party plugin's traffic log rather than the site's own content, so
  whether an agent may see it at all stays an explicit operator decision, as
  it already is for media and pattern reads. The switch never enables logging
  in any provider.

Decision 6 is moot as built and was not implemented: the aggregation is this
plugin's own `GROUP BY`, not Redirection's undeclared `groupBy` REST
parameter, so there is no silently-ignored primitive to probe. Its underlying
requirement is still enforced - a row that comes back without a positive count
fails the read instead of being reported as an observation, and per-hit rows
are never aggregated client-side.

## Context

Slice 5 lets an agent create redirects. That is only half of the operator's
actual question, which is *which* redirect is missing — and answering it needs
the site's 404 history, not the redirect list.

Both backends were read from source on the designated environment rather than
from documentation, because ADR 0026 had already been wrong once that way.

**Redirection 5.9.0 collects the data.** `{prefix}redirection_404` stores one
row per 404 hit (`url`, `domain`, `agent`, `referrer`, `ip`, `http_code`,
`request_method`, `request_data`); `{prefix}redirection_logs` does the same for
redirects that fired; and each redirect row carries `last_count` and
`last_access`. Aggregation exists in SQL — `GROUP BY url` with `COUNT(*)`,
ordered by count — reachable over REST as an undeclared `groupBy` parameter.
`GET /404` checks only `redirection_cap_404_manage`, which is independent of
`redirection_cap_redirect_manage`.

**Yoast SEO Premium 28.0 collects none of it.** No table, no option, no
counter, in Premium 28.0 or Free 28.4. The Search Console crawl-error screen is
a stub whose own copy states Google discontinued the API. Verified by
exhaustive search, not inferred from missing documentation.

Four properties of the available data shape this decision more than the data
itself does:

1. **Statistics availability does not follow redirect availability.** A site
   using Yoast as its redirect provider has full redirect read/write and zero
   statistics.
2. **A disabled log is indistinguishable from a clean site.** `expire_404 = -1`
   turns 404 logging off, `ip_logging = 0` drops IPs, `track_hits = false`
   freezes the per-redirect counters. A query against a disabled log returns an
   empty result set, not an error.
3. **There is no date filter.** Neither log route accepts a date, range, or
   `before`/`after` parameter; dates appear only in the pruning cron. The
   effective window is the retention setting (`expire_404`, default 7 days,
   pruned daily).
4. **The rows are personal data.** IP, user agent, referrer, and with
   `log_header` enabled the request headers.

## Decision

### 1. A separate port, not a capability on the redirect port

Statistics live behind their own port with its own provider selection and its
own registry. `RedirectProvider` gains no statistics method.

The reason is property 1. Hanging statistics off the redirect port forces a
Yoast-backed site to answer *something* for "top 404s", and the only available
answer is an empty list — which reads as "no problems". This is the same defect
class as ADR 0027's HTTP 500 on every rejection: a real state rendered
indistinguishable from an unrelated one.

### 2. Unavailable, disabled, and empty are three distinct results

Every statistics result reports which of these it is, and no two collapse:

- **unavailable** — no provider on the site collects this data (a Yoast-only
  site). Answered as an unsupported operation, never as zero.
- **disabled** — a provider is present but its logging is switched off, or the
  specific field is not being recorded. Reports the setting responsible.
- **measured** — the log is on, and the result is the observation, including a
  legitimately empty one.

A caller that cannot tell these apart cannot use the answer, so the
distinction is part of the schema, not an implementation detail.

### 3. Aggregate only. No per-hit rows, ever

The projected surface carries the grouping key and a count, and nothing else:
the requested path, how many times it was hit, and the retention window the
count covers. `ip`, `agent`, `referrer`, `request_data`, and any other
per-visitor field are not read into the domain, not stored, not returned, and
not projected through MCP.

This is not a default to be relaxed by a parameter. "Where is a redirect
missing" is fully answered by path and count; the per-visitor fields add
nothing to it and would hand a model the site's traffic logs. There is
deliberately no option to include them, because an option is a thing an agent
can be talked into setting.

### 4. `since` is supported, read from the table, and always reports truncation

Corrected 2026-09-01, before implementation. The first version of this decision
refused a `since` parameter on the grounds that the backend cannot filter by
date. That confused one API's limits with the data's limits: Redirection's REST
log routes accept no date, but the `{prefix}redirection_404` table has a
`created` column **with an index on it**, and this plugin runs in the same
process. `since` is cheap and correct.

Statistics are therefore read from the provider's table directly rather than
through its REST API. That is also the more durable choice: the aggregation this
port depends on is reachable in SQL, instead of resting on Redirection's
undeclared `groupBy` parameter in an API its author calls unstable. Two costs
follow and are accepted:

- The adapter couples to the provider's schema, so it probes the schema version
  and reports **unavailable** rather than issuing a query it cannot vouch for.
- A direct read bypasses the provider's own permission model, so the adapter
  enforces the provider capability (`redirection_cap_404_manage`) itself, in
  addition to the bridge capability. This is the same obligation ADR 0026's
  amendment records for Yoast's manager, which also checks nothing.

**Retention can truncate a `since` range, and the result must say so.** A
`since` older than `expire_404` returns less than was asked for, because the
daily cron already deleted the rest. Reported silently, a monitoring caller
would read the missing rows as 404s that stopped happening. Every result
therefore carries the retention window and an explicit signal when the
requested range extended beyond it.

### 5. Reading statistics is separate authority from writing redirects

A dedicated bridge capability for reading error statistics, distinct from
`wpcb_manage_redirects`. Redirection's own permission model already separates
these, so the separation costs nothing to honour and makes the useful grant —
diagnose without authority to change routing — expressible.

### 6. The aggregation primitive is treated as unstable and probed, not assumed

`groupBy` appears in no route's `args` schema; it is read from `get_params()`
in a plugin that states its REST API is not stable. The adapter verifies it
produced grouped output rather than assuming it, and reports statistics as
unavailable if it did not. A silently ignored `groupBy` returns ungrouped
rows — which must never be mistaken for counts, and must never be aggregated
client-side from per-hit rows, because that would mean reading the personal
data decision 3 forbids.

### What this is not

**An external monitoring agent is the primary consumer, not an out-of-scope
one.** The division is that the agent schedules and judges; this plugin
provides the reading. Polling is precisely why decision 4 matters — an agent
that cannot say `since` re-reads the same top-404 list on every pass and cannot
tell a new problem from one it already reported.

What stays outside the plugin:

- No scheduling of its own, no thresholds, no notifications. Whether 40 hits on
  one path is a problem is a judgement, and it depends on the site.
- **No long-term history.** Keeping one would mean outliving the provider's own
  pruning and becoming the durable store of the site's 404 record — a new
  persistence model and a new decision. An *aggregated* daily history (path,
  count, no personal data) would genuinely add a trend beyond the retention
  window and is a reasonable future feature; it needs its own ADR, not a
  paragraph in this one.
- Not a second audit trail. The audit table records what this plugin did;
  these are hits on the site by third parties.
- Not a write surface. Nothing here deletes, prunes, or resets a log or a
  counter, even though both backends can.
- Not a replacement for a log the operator turned off. Statistics do not
  enable logging, and no ability changes a provider's settings.

## Consequences

- A Yoast-only site gets redirect read/write and no statistics. This is
  reported, not worked around, and is the honest state.
- Requesting statistics with a provider present but logging disabled tells the
  operator which setting to change instead of returning a misleading zero.
- The plugin never sees a visitor IP, referrer, or user agent, so no retention,
  redaction, or export obligation attaches to it. That is a property of the
  code, not of a setting.
- "404s in the last 24 hours" is answerable; "404s in the last 90 days" is not,
  and says so instead of returning a quiet undercount. Retention remains the
  outer bound, and it is a site setting.
- Dead-redirect detection (`last_count = 0`) is available from the redirect
  rows themselves and carries no personal data, but it depends on `track_hits`,
  so it too has a **disabled** state.

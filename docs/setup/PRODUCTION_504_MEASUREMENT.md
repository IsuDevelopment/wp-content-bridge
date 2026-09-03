# Measuring the production 504s

One measurement, on one install, answering one question: **is the slowness in
this plugin's PHP, or above it?** Everything else about this issue has already
been measured; this is the only missing fact, and it cannot be obtained from a
development machine.

## What is already known

An 11-minute schema session on the production install was traced by its
operator to four MCP calls that each hung about two minutes and returned HTTP
504: full `get-content`, `get-content` with `plain_text` only,
`search-content`, and `get-url-seo`.

The same four, measured in-process on the reference site (2026-09-02):

| Ability | Reference time |
|---|---|
| `get-content` full (raw + rendered + plain_text, taxonomies + media + revision) | 177–326 ms |
| `get-content`, `plain_text` only | 159–198 ms |
| `search-content` | 89 ms |
| `get-url-seo` | 1,756 ms — the only genuinely slow read, and structurally so: it fetches the site's own URL to read the rendered JSON-LD graph, with a 5 s timeout |

Three of the four are two to three orders of magnitude away from a 120-second
timeout, and the fourth cannot reach it either. So the read path does not
explain the 504s — but "does not explain them on the reference site" is not
"does not cause them on production", and the difference is what this
measurement settles.

## Preconditions

- Shell access to the production install, with `wp` available.
- An MCP client already able to reach that install's endpoint, as the same
  principal the failing session used.
- Nothing needs to be enabled, installed, or changed. The probe reads only: it
  creates no fixtures, writes no options, and changes no content. That is why
  it is a probe rather than a verifier.

## Step 1 — in-process, no MCP

From the WordPress root:

```bash
wp eval 'require "/path/to/wp-content-bridge/tests/Integration/ability-timing-probe.php";'
```

To pin the post the reference numbers used, or to measure as a specific
principal rather than the administrator the probe adopts:

```bash
wp eval '$_ENV["WPCB_PROBE_POST_ID"]="123"; require ".../ability-timing-probe.php";'
wp --user=<integration-user-id> eval 'require ".../ability-timing-probe.php";'
```

It prints one row per ability, three runs each. Keep the output.

Two behaviours are deliberate and worth knowing before reading the table:
`wp eval` runs as user 0, where almost every ability refuses in well under a
millisecond — which would look like a fast install while measuring nothing — so
the probe adopts an administrator and says so. And it resolves abilities
through `wp_get_abilities()` rather than `wp_get_ability()`, because the latter
raises `_doing_it_wrong()` on an unregistered name and buries the numbers.

## Step 2 — the same abilities through that install's MCP endpoint

Request the same four shapes through the MCP endpoint, as the same principal,
and record wall-clock time per call:

| Call | Shape |
|---|---|
| `get-content` full | `representations: ["raw","rendered","plain_text"]`, `include: ["taxonomies","media","revision"]` |
| `get-content` minimal | `representations: ["plain_text"]` |
| `search-content` | `per_page: 10` |
| `get-url-seo` | the same post's permalink |

`tests/Integration/mcp-smoke-verification.sh` drives an MCP endpoint over
Streamable HTTP if a scripted client is easier than a manual one.

## Step 3 — read the split

Fill this in and the answer follows from it:

| Ability | In-process (step 1) | Through MCP (step 2) | Ratio |
|---|---|---|---|
| `get-content` full | | | |
| `get-content` minimal | | | |
| `search-content` | | | |
| `get-url-seo` | | | |

- **In-process fast, MCP slow** — the transport or the host. The suspects, in
  the order they are worth checking: the miniOrange OAuth MCP server, PHP-FPM
  and gateway timeouts, then the production database. Nothing in this plugin's
  read path is implicated, and optimizing it would be work aimed at the wrong
  layer.
- **Both slow** — an ability is genuinely slow *on that install*, and the
  per-ability rows say which. Compare the row against the reference column
  above: a read that is 100× its reference time is an install-specific
  condition (data volume, a plugin on a shared hook, a slow query), not a
  contract problem.
- **`get-url-seo` slow and the rest fast** — the loopback fetch. A host that
  blackholes requests to its own hostname pays the full timeout; check whether
  the site can reach its own URL from PHP at all.

## After the run

Record the table and the conclusion in `.agents/status.md`, and close the open
item there. A measurement that stays in someone's terminal has not been made,
because the next person will have to make it again.

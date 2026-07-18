# Milestone 4 Phase 1 — ChatGPT-first MCP read access — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let ChatGPT securely read the five existing WP Content Bridge abilities from the (staged) WordPress site through the official MCP Adapter fronted by an external, principal-bound OAuth 2.1 layer, with every call executing as a bound least-privilege WordPress user.

**Architecture:** MCP transport (official `WordPress/mcp-adapter`) and OAuth 2.1 are external, *configured not bundled* layers (AGENTS.md; ADR 0005). This plugin keeps its five read Abilities as the stable, transport-neutral contract; the OAuth/transport layer is replaceable. A dedicated "bridge reader" WordPress user is the identity the ChatGPT grant maps to. This is an infrastructure/config/documentation milestone: the one shipped code artifact is a client-agnostic MCP smoke script; the rest is an ADR, a candidate evaluation, provisioning/verification scripts, and setup/troubleshooting docs.

**Tech Stack:** PHP 8.2+, WordPress 7.0+, WP Abilities API, official `WordPress/mcp-adapter`, an external OAuth 2.1 plugin (evaluation candidates: miniOrange "OAuth AI Agent Connector for WordPress", Royal MCP), `cloudflared` tunnel, WP-CLI, bash + `curl` for smoke/verification, MCP Inspector (optional).

## Global Constraints

- PHP **8.2+**; WordPress **7.0+**; `declare(strict_types=1)` in every PHP file.
- WordPress Coding Standards; **long array syntax `array()`**; `composer check` (phpcs + phpstan + phpunit) must stay green.
- `WP_Error` only at WP adapter boundaries; domain/application layers throw typed exceptions.
- The MCP Adapter is **optional and must NOT be bundled or initialized by this plugin** (AGENTS.md). Installed/configured externally only.
- **Read-only Phase 1:** expose ONLY the five abilities `wp-content-bridge/search-content`, `get-content`, `get-url-seo`, `get-editorial-context`, `get-diagnostics`. Writes stay globally blocked.
- **Principal-bound:** every token/grant maps to a specific WordPress user; no ambient `user_id = 0` authority; MCP calls execute as that user so `ContentAccessManager` policy and native object capabilities apply unchanged. Scope may only reduce authority, never grant a missing capability (ADR 0007).
- **Secret hygiene:** no secrets, tokens, App Passwords, OAuth client secrets, or API keys are committed to git or written to logs. Verification scripts create disposable credentials and delete them on exit (existing `finally`/`trap` pattern).
- Runtime PHP verifiers run via `wp eval 'require "...";'` (NOT `wp eval-file` — it rejects `declare(strict_types=1)`).
- Local env is `kormas-isu.local` (Local by Flywheel), self-signed TLS, content dir `content/`. CLI `home_url` is malformed — build URLs from an explicit `WPCB_SITE_URL` env var, never CLI permalinks.
- Never dump values of Yoast `local_api_key`, `local_api_key_browser`, `googlemaps_api_key`, or any credential option.

**Repo root:** `/Users/lukaszbiedron/Other Projects/wp-content-bridge`
**WP root:** `/Users/lukaszbiedron/Local Sites/kormas-isu/app/public`
**Site URL:** `https://kormas-isu.local`

---

### Task 1: ADR 0010 — external, principal-bound MCP transport + OAuth

**Files:**
- Create: `docs/adr/0010-mcp-transport-and-oauth-are-external-principal-bound-layers.md`

**Interfaces:**
- Consumes: the approved spec `docs/superpowers/specs/2026-07-18-milestone-4-chatgpt-mcp-design.md` (Approach A, the six-criterion evaluation gate, B/C fallbacks).
- Produces: the canonical statement of the **evaluation gate** that Task 2 applies, and the decision Tasks 4–6 build on. Later docs reference it as "ADR 0010".

- [ ] **Step 1: Read the two most recent ADRs to match house format**

Run: `sed -n '1,40p' docs/adr/0009-capture-rendered-schema-for-local-multilocation.md docs/adr/0007-private-credentials-are-principal-bound.md`
Expected: confirms the `# NNNN. Title` / `## Status` / `## Context` / `## Decision` / `## Consequences` heading shape and Markdown style used across ADRs.

- [ ] **Step 2: Write ADR 0010**

Content must include, in the house ADR format:
- **Status:** Accepted (2026-07-19).
- **Context:** ChatGPT connector requires remote HTTPS MCP + OAuth 2.1 (protected-resource + AS metadata discovery, CIMD/DCR client registration, PKCE, server-side token verification; no static key/custom-header option). The official `WordPress/mcp-adapter` bridges Abilities→MCP but for self-hosted sites offers no OAuth (App Password/JWT only). So an OAuth layer must sit in front. References ADR 0005 (authentication owned by projection) and ADR 0007 (private credentials are principal-bound).
- **Decision:** Approach A — MCP transport and OAuth are external, configured (not bundled) layers. This plugin never bundles or initializes the MCP Adapter. Adopt an external OAuth layer only if it passes the evaluation gate.
- **Evaluation gate (verbatim from spec §"Evaluation gate"):** the six numbered criteria — (1) principal-bound, (2) executes as that user, (3) scope only reduces, (4) ChatGPT-correct OAuth, (5) secret hygiene, (6) read-only. State that failing criteria 1–3 rejects a candidate.
- **Fallbacks:** B = plugin-owned OAuth authorization server (large security surface; deferred to its own milestone + threat review), C = dedicated external gateway. Record why B/C are not Phase 1.
- **Consequences:** third-party dependency risk mitigated by keeping our Abilities as the stable contract; the OAuth/transport layer is replaceable; writes remain out of scope (Milestones 5–7).

- [ ] **Step 3: Lint the Markdown mentally + confirm no placeholders**

Run: `grep -niE "TBD|TODO|FIXME|xxx" docs/adr/0010-mcp-transport-and-oauth-are-external-principal-bound-layers.md`
Expected: no matches.

- [ ] **Step 4: Commit**

```bash
cd "/Users/lukaszbiedron/Other Projects/wp-content-bridge"
git add docs/adr/0010-mcp-transport-and-oauth-are-external-principal-bound-layers.md
git commit -m "docs(adr): 0010 external principal-bound MCP transport and OAuth"
```

---

### Task 2: Evaluate OAuth candidates against the ADR 0010 gate

**Files:**
- Create: `docs/research/OAUTH_CANDIDATES.md`

**Interfaces:**
- Consumes: the six-criterion evaluation gate from ADR 0010 (Task 1); the ChatGPT/LLMagnet findings in `docs/research/LLMAGNET_COMPARISON.md`.
- Produces: a per-candidate PASS/FAIL verdict per criterion and a single **chosen layer** (or "fall back to gateway C / defer B"). Task 4 and Task 6 configure whatever this task selects.

- [ ] **Step 1: Assemble the candidate list and evidence sources**

Candidates to assess (do not install yet — desk evaluation from official plugin pages, docs, and source where readable):
- miniOrange "OAuth AI Agent Connector for WordPress"
- Royal MCP
- (baseline reference only, not a candidate) LLMagnet's plugin-owned AS — already reviewed in `docs/research/LLMAGNET_COMPARISON.md`.

Use WebFetch/WebSearch on the vendors' documentation. Do NOT paste any license keys or secrets into the doc.

- [ ] **Step 2: Write the evaluation matrix**

`docs/research/OAUTH_CANDIDATES.md` must contain:
- A short intro: purpose (apply ADR 0010 gate), date (2026-07-19), that this is a desk evaluation not an endorsement.
- A table with one row per candidate and one column per gate criterion (1 principal-bound, 2 executes-as-user, 3 scope-only-reduces, 4 ChatGPT-correct OAuth, 5 secret hygiene, 6 read-only), each cell PASS / FAIL / UNKNOWN with a one-line evidence note.
- The decisive rule restated: any FAIL on criteria 1–3 rejects the candidate.
- A **Verdict** section naming the chosen layer, or recording that both fail 1–3 and we fall back to gateway (C) / defer plugin-owned AS (B). Include what still must be confirmed at install-time (criteria that are UNKNOWN from docs alone and must be re-checked live in Task 6).

- [ ] **Step 3: Consistency + placeholder scan**

Run: `grep -nEi "TBD|TODO|UNKNOWN" docs/research/OAUTH_CANDIDATES.md`
Expected: `UNKNOWN` may remain ONLY inside matrix cells that Task 6 will confirm live; no `TBD`/`TODO`. If the Verdict section itself is unresolved, that is a plan failure — pick the best-evidenced candidate to trial in Task 6 and record the live checks it must still pass.

- [ ] **Step 4: Commit**

```bash
cd "/Users/lukaszbiedron/Other Projects/wp-content-bridge"
git add docs/research/OAUTH_CANDIDATES.md
git commit -m "docs(research): evaluate OAuth candidates against ADR 0010 gate"
```

---

### Task 3: "Bridge reader" least-privilege user provisioning fixture

**Files:**
- Create: `tests/Integration/bridge-reader-fixture.php`

**Interfaces:**
- Consumes: nothing from earlier tasks (self-contained WP-CLI fixture). Follows the setup/teardown pattern of `tests/Integration/local-multilocation-fixture.php` (`getenv('WPCB_BRIDGE_MODE')` = `setup|teardown`).
- Produces: a WordPress user `wpcb-bridge-reader` holding ONLY `read` + `wpcb_read_content`, no other roles/caps. Task 5 (smoke) and Task 6 (ChatGPT connector) map their credential/grant to this user. Prints `user_id` and `user_login` on setup.

- [ ] **Step 1: Read the existing fixture to copy its shape**

Run: `sed -n '1,60p' tests/Integration/local-multilocation-fixture.php`
Expected: shows the `declare(strict_types=1)`, `getenv` mode switch, `WP_CLI::` output, and idempotent create/lookup pattern to mirror.

- [ ] **Step 2: Write the fixture**

Create `tests/Integration/bridge-reader-fixture.php` with `declare(strict_types=1);`:
- `setup`: if a user with login `wpcb-bridge-reader` exists, reuse it; else create it with a random password (NOT printed) and role `subscriber`, then **remove all role caps** and set exactly `read => true` and `wpcb_read_content => true` via `$user->add_cap()` after `$user->set_role('')`. Output the numeric `user_id` and `user_login` only.
- `teardown`: look up by login; if present, `wp_delete_user()` (reassign content to nobody). Output confirmation.
- Guard every WP function behind `function_exists()`/class checks as the existing fixtures do, and use long array syntax.
- Never echo the password or any capability secret.

- [ ] **Step 3: Run setup and assert the capability set**

```bash
cd "/Users/lukaszbiedron/Local Sites/kormas-isu/app/public"
WPCB_BRIDGE_MODE=setup wp eval 'require "/Users/lukaszbiedron/Other Projects/wp-content-bridge/tests/Integration/bridge-reader-fixture.php";'
wp user get wpcb-bridge-reader --field=roles
wp eval '$u = get_user_by("login","wpcb-bridge-reader"); echo (int)$u->has_cap("wpcb_read_content"), (int)$u->has_cap("read"), (int)$u->has_cap("edit_posts"), (int)$u->has_cap("manage_options");'
```
Expected: roles empty; the four flags print `1100` (has read-content + read; lacks edit_posts + manage_options).

- [ ] **Step 4: Run teardown and confirm removal**

```bash
cd "/Users/lukaszbiedron/Local Sites/kormas-isu/app/public"
WPCB_BRIDGE_MODE=teardown wp eval 'require "/Users/lukaszbiedron/Other Projects/wp-content-bridge/tests/Integration/bridge-reader-fixture.php";'
wp user get wpcb-bridge-reader --field=ID || echo "DELETED-OK"
```
Expected: prints `DELETED-OK` (user no longer exists).

- [ ] **Step 5: PHPCS the fixture, then commit**

```bash
cd "/Users/lukaszbiedron/Other Projects/wp-content-bridge"
composer lint -- tests/Integration/bridge-reader-fixture.php
git add tests/Integration/bridge-reader-fixture.php
git commit -m "test(integration): bridge-reader least-privilege user fixture"
```
Expected: PHPCS clean (or fix with `composer lint:fix` first), then committed.

---

### Task 4: MCP Adapter install + configuration recipe (five read abilities)

**Files:**
- Create: `docs/setup/MCP_ADAPTER.md`

**Interfaces:**
- Consumes: the five registered ability IDs (`wp-content-bridge/search-content`, `get-content`, `get-url-seo`, `get-editorial-context`, `get-diagnostics`); ADR 0010 (adapter is external, not bundled).
- Produces: a documented, reproducible way to expose exactly those five abilities as MCP tools over an HTTP endpoint, and the endpoint URL + auth mode (App Password for the smoke test) that Task 5 targets. Records the resolved MCP endpoint path.

- [ ] **Step 1: Confirm the abilities register and the adapter is separate**

```bash
cd "/Users/lukaszbiedron/Local Sites/kormas-isu/app/public"
wp eval 'do_action("wp_abilities_api_init"); foreach (array("search-content","get-content","get-url-seo","get-editorial-context","get-diagnostics") as $a) { echo "wp-content-bridge/$a=", (int) (function_exists("wp_get_ability") && wp_get_ability("wp-content-bridge/$a")), "\n"; }'
```
Expected: each line ends `=1` (all five abilities resolvable). If `wp_get_ability` is unavailable in this WP build, fall back to listing via the Abilities registry used in `ContentAbilities.php`.

- [ ] **Step 2: Install the official MCP Adapter as an external dependency (NOT in this repo)**

Install into the site, not the plugin repo — the adapter must never be bundled:
```bash
cd "/Users/lukaszbiedron/Local Sites/kormas-isu/app/public"
wp plugin install https://github.com/WordPress/mcp-adapter/releases/latest/download/mcp-adapter.zip --activate || echo "If no release zip, clone WordPress/mcp-adapter into content/plugins and activate manually"
wp plugin list --status=active | grep -i mcp
```
Expected: the MCP adapter appears active. Record its exact plugin slug in the doc.

- [ ] **Step 3: Create a site-level mu-plugin (in the SITE, not the repo) that registers an MCP server projecting only our five abilities**

Document (and place at `content/mu-plugins/wpcb-mcp-server.php` on the site) a small glue file that, on the adapter's server-init hook, registers one MCP server whose tool set is exactly our five ability IDs, App Password auth enabled, read-only. This file lives on the site because per AGENTS.md the plugin must not initialize the adapter. The doc must contain the full mu-plugin source (adapter API per its current README — verify method names at install time) and note it is site infra, kept out of the plugin repo.

- [ ] **Step 4: Verify the endpoint lists exactly five tools (App Password auth)**

```bash
cd "/Users/lukaszbiedron/Local Sites/kormas-isu/app/public"
wp user application-password create wpcb-bridge-reader wpcb-mcp-smoke --porcelain
# use the printed password below as APPPASS, then:
```
Then document the `curl` that POSTs a JSON-RPC `tools/list` to the resolved MCP endpoint (e.g. `https://kormas-isu.local/wp-json/<adapter-namespace>/mcp`) with `--user "wpcb-bridge-reader:APPPASS"` and `-k` (self-signed local). Expected: response lists exactly the five `wp-content-bridge/*` tools. Delete the App Password immediately after (`wp user application-password delete wpcb-bridge-reader --all`).

- [ ] **Step 5: Write `docs/setup/MCP_ADAPTER.md`**

Capture: adapter install steps, the mu-plugin glue source, the resolved endpoint URL/namespace, App Password auth for local smoke, the `tools/list` verification transcript (with the secret redacted), and an explicit note that the adapter + mu-plugin are site infrastructure excluded from the plugin package.

- [ ] **Step 6: Commit**

```bash
cd "/Users/lukaszbiedron/Other Projects/wp-content-bridge"
git add docs/setup/MCP_ADAPTER.md
git commit -m "docs(setup): official MCP Adapter recipe exposing five read abilities"
```

---

### Task 5: Client-agnostic MCP smoke suite

**Files:**
- Create: `tests/Integration/mcp-smoke-verification.sh`
- Test: the script IS the test (runs against the live MCP endpoint).

**Interfaces:**
- Consumes: the bridge-reader user (Task 3), the MCP endpoint URL/namespace (Task 4). Reads config from env: `WPCB_SITE_URL`, `WPCB_WP_ROOT`, `WPCB_MCP_PATH` (endpoint path, default the Task 4 namespace).
- Produces: a repeatable pass/fail smoke check: `initialize` → `tools/list` (asserts the five tools) → `tools/call` for each read ability with a minimal valid input, executed as the bridge reader via a disposable App Password. Exit 0 on success, non-zero on any failure. Mirrors the disposable-credential + `trap` cleanup style of `tests/Integration/http-url-runtime-verification.sh`.

- [ ] **Step 1: Read the existing HTTP verifier for the harness pattern**

Run: `sed -n '1,80p' tests/Integration/http-url-runtime-verification.sh`
Expected: shows the `set -euo pipefail`, env-var validation, disposable App Password creation, `trap ... EXIT` cleanup, and `curl -k` usage to copy.

- [ ] **Step 2: Write `tests/Integration/mcp-smoke-verification.sh`**

```bash
#!/usr/bin/env bash
# Client-agnostic MCP smoke check for WP Content Bridge read abilities.
# Verifies: initialize -> tools/list (five tools) -> tools/call for each,
# executed as the least-privilege bridge-reader user via a disposable
# Application Password. Not a substitute for the manual ChatGPT OAuth
# walkthrough (Task 6) — this validates transport + abilities projection.
set -euo pipefail

: "${WPCB_SITE_URL:?set WPCB_SITE_URL, e.g. https://kormas-isu.local}"
: "${WPCB_WP_ROOT:?set WPCB_WP_ROOT to the WordPress public root}"
WPCB_MCP_PATH="${WPCB_MCP_PATH:-/wp-json/wpcb-mcp/mcp}"  # match Task 4 namespace
BRIDGE_USER="wpcb-bridge-reader"
ENDPOINT="${WPCB_SITE_URL}${WPCB_MCP_PATH}"

fail() { echo "FAIL: $*" >&2; exit 1; }

# Ensure the bridge reader exists (idempotent setup via the fixture).
WPCB_BRIDGE_MODE=setup wp --path="$WPCB_WP_ROOT" eval \
  'require "'"$(cd "$(dirname "$0")" && pwd)"'/bridge-reader-fixture.php";' >/dev/null

# Disposable Application Password bound to the bridge reader.
APPPASS="$(wp --path="$WPCB_WP_ROOT" user application-password create "$BRIDGE_USER" wpcb-mcp-smoke --porcelain)"
cleanup() {
  wp --path="$WPCB_WP_ROOT" user application-password delete "$BRIDGE_USER" --all >/dev/null 2>&1 || true
}
trap cleanup EXIT

AUTH=(--user "${BRIDGE_USER}:${APPPASS}" -k -sS -H 'Content-Type: application/json' -H 'MCP-Protocol-Version: 2025-06-18')

rpc() { # $1 = json body
  curl "${AUTH[@]}" -X POST "$ENDPOINT" -d "$1"
}

echo "== initialize =="
INIT="$(rpc '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"wpcb-smoke","version":"1"}}}')"
echo "$INIT" | grep -q '"result"' || fail "initialize did not return a result: $INIT"

echo "== tools/list =="
LIST="$(rpc '{"jsonrpc":"2.0","id":2,"method":"tools/list","params":{}}')"
for tool in search-content get-content get-url-seo get-editorial-context get-diagnostics; do
  echo "$LIST" | grep -q "wp-content-bridge/$tool" || fail "tools/list missing wp-content-bridge/$tool"
done
echo "tools/list OK (five tools present)"

echo "== tools/call: get-diagnostics (no input) =="
DIAG="$(rpc '{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"wp-content-bridge/get-diagnostics","arguments":{}}}')"
echo "$DIAG" | grep -q '"result"' || fail "get-diagnostics call failed: $DIAG"

echo "== tools/call: search-content =="
SEARCH="$(rpc '{"jsonrpc":"2.0","id":4,"method":"tools/call","params":{"name":"wp-content-bridge/search-content","arguments":{"query":"the"}}}')"
echo "$SEARCH" | grep -q '"result"' || fail "search-content call failed: $SEARCH"

echo "== tools/call: get-editorial-context =="
EDITORIAL="$(rpc '{"jsonrpc":"2.0","id":5,"method":"tools/call","params":{"name":"wp-content-bridge/get-editorial-context","arguments":{}}}')"
echo "$EDITORIAL" | grep -q '"result"' || fail "get-editorial-context call failed: $EDITORIAL"

# get-content / get-url-seo need a real readable object id/url. Discover one
# published post id via WP-CLI so the smoke test is self-contained.
PID="$(wp --path="$WPCB_WP_ROOT" post list --post_type=post --post_status=publish --posts_per_page=1 --field=ID | head -n1)"
if [ -n "${PID:-}" ]; then
  echo "== tools/call: get-content (post $PID) =="
  GET="$(rpc '{"jsonrpc":"2.0","id":6,"method":"tools/call","params":{"name":"wp-content-bridge/get-content","arguments":{"id":'"$PID"',"representation":"plain"}}}')"
  echo "$GET" | grep -q '"result"' || fail "get-content call failed: $GET"
  echo "== tools/call: get-url-seo (post $PID) =="
  SEO="$(rpc '{"jsonrpc":"2.0","id":7,"method":"tools/call","params":{"name":"wp-content-bridge/get-url-seo","arguments":{"id":'"$PID"'}}}')"
  echo "$SEO" | grep -q '"result"' || fail "get-url-seo call failed: $SEO"
else
  echo "WARN: no published post found; skipped get-content/get-url-seo object calls"
fi

echo "ALL MCP SMOKE CHECKS PASSED"
```

Note: confirm the exact `tools/call` argument names (`id`, `query`, `representation`) against `AbilitySchemas` at implementation time; adjust the minimal inputs if the schema differs. Keep the assertions on `"result"` presence.

- [ ] **Step 3: Make executable and run it**

```bash
cd "/Users/lukaszbiedron/Other Projects/wp-content-bridge"
chmod +x tests/Integration/mcp-smoke-verification.sh
WPCB_SITE_URL=https://kormas-isu.local \
WPCB_WP_ROOT="/Users/lukaszbiedron/Local Sites/kormas-isu/app/public" \
WPCB_MCP_PATH="/wp-json/<adapter-namespace>/mcp" \
tests/Integration/mcp-smoke-verification.sh
```
Expected: ends with `ALL MCP SMOKE CHECKS PASSED`; the disposable App Password is deleted by the `trap` regardless of outcome.

- [ ] **Step 4: Confirm no secret leaked into output or repo**

Run: `git status --porcelain && grep -rniE "application-password|apppass|secret|bearer [A-Za-z0-9]" tests/Integration/mcp-smoke-verification.sh`
Expected: only the script itself is new/modified; the grep shows just the variable names/comments, no literal secret values.

- [ ] **Step 5: Commit**

```bash
cd "/Users/lukaszbiedron/Other Projects/wp-content-bridge"
git add tests/Integration/mcp-smoke-verification.sh
git commit -m "test(integration): client-agnostic MCP read smoke suite"
```

---

### Task 6: Tunnel + OAuth layer + ChatGPT connector setup guide & self-test

**Files:**
- Create: `docs/setup/CHATGPT_CONNECTOR.md`

**Interfaces:**
- Consumes: chosen OAuth layer (Task 2 verdict), MCP endpoint (Task 4), bridge-reader user (Task 3), smoke suite (Task 5).
- Produces: an end-to-end, reproduced setup guide + connection self-test + troubleshooting notes, captured from an actual working ChatGPT connection (or, if a criterion fails live, a recorded rejection per the gate and the fallback taken).

- [ ] **Step 1: Expose the site over public HTTPS via cloudflared**

Document starting a tunnel to `https://kormas-isu.local` and note forwarded-host/proto handling so OAuth discovery URLs resolve to the public hostname (the LLMagnet review flagged proxy-aware base URLs as essential). Capture the public URL used.

- [ ] **Step 2: Install + configure the chosen OAuth layer; confirm discovery docs live**

Fetch and record (redacting secrets):
```bash
curl -s https://<public-host>/.well-known/oauth-protected-resource | head
curl -s https://<public-host>/.well-known/oauth-authorization-server | head
```
Expected: both return valid JSON metadata (RFC 9728 / RFC 8414). Re-check the criteria that were UNKNOWN in Task 2: principal binding to the bridge reader, execute-as-user, scope-only-reduces, PKCE, server-side token verification. If any of criteria 1–3 fails live, STOP, record the failure in `docs/research/OAUTH_CANDIDATES.md`, and take the ADR 0010 fallback (gateway C / defer B) before continuing.

- [ ] **Step 3: Connect ChatGPT (developer mode) and approve consent as the bridge reader**

Document the exact connector URL entered, the OAuth consent screen, and that the grant maps to `wpcb-bridge-reader` (least privilege, no admin).

- [ ] **Step 4: Run the read scenario in ChatGPT and capture evidence**

Exercise all five: search-content, get-content, get-url-seo, get-editorial-context, get-diagnostics. Capture that only authorized content returns, per-user capabilities are enforced, no private/draft data leaks, and there is no ambient authority (e.g., a draft the bridge reader cannot read is not returned). Record the transcript/screenshots with any secrets redacted.

- [ ] **Step 5: Write the connection self-test + troubleshooting section**

In `docs/setup/CHATGPT_CONNECTOR.md` include: the tunnel command, OAuth layer config, discovery URLs, ChatGPT connect steps, the five-ability read walkthrough, a self-test checklist (discovery reachable → consent maps to bridge reader → each ability returns → private content withheld), and troubleshooting (wrong discovery host, cert errors, consent-as-wrong-user, empty tools/list) cross-referencing the Task 5 smoke script for transport isolation.

- [ ] **Step 6: Commit**

```bash
cd "/Users/lukaszbiedron/Other Projects/wp-content-bridge"
git add docs/setup/CHATGPT_CONNECTOR.md docs/research/OAUTH_CANDIDATES.md
git commit -m "docs(setup): ChatGPT connector setup guide, self-test, and troubleshooting"
```

---

### Task 7: Update milestone, test, and status docs to M4 Phase 1 reality

**Files:**
- Modify: `docs/plan/IMPLEMENTATION_PLAN.md:230-251` (Milestone 4 section)
- Modify: `docs/plan/TEST_PLAN.md` (Contract + Environment matrix + repeatable commands)
- Modify: `.agents/status.md`, `.continue-here.md`

**Interfaces:**
- Consumes: everything Tasks 1–6 produced (ADR 0010, chosen OAuth layer, endpoint, bridge reader, smoke script, connector guide).
- Produces: the authoritative record that M4 Phase 1 is ChatGPT-primary, read-only, Approach A, with the verification commands and exit-gate status.

- [ ] **Step 1: Rewrite the Milestone 4 deliverables/exit gate**

Edit `docs/plan/IMPLEMENTATION_PLAN.md` M4 to state: Phase 1 target is **ChatGPT** (primary), read-only; Approach A (external principal-bound OAuth + official adapter, ADR 0010); deliverables now = ADR 0010, OAuth candidate evaluation, bridge-reader fixture, MCP Adapter recipe, client-agnostic smoke suite, ChatGPT connector guide/self-test. Exit gate = ChatGPT completes the five-ability read scenario from a verified setup, principal-bound (no ambient authority), no credentials committed/logged. Note Codex/Gemini are secondary/deferred and writes are Milestones 5–7.

- [ ] **Step 2: Add the MCP smoke command to TEST_PLAN**

Add to `docs/plan/TEST_PLAN.md` the repeatable command block:
```bash
WPCB_SITE_URL=https://kormas-isu.local \
WPCB_WP_ROOT="/Users/lukaszbiedron/Local Sites/kormas-isu/app/public" \
WPCB_MCP_PATH="/wp-json/<adapter-namespace>/mcp" \
"/Users/lukaszbiedron/Other Projects/wp-content-bridge/tests/Integration/mcp-smoke-verification.sh"
```
and describe it under the Contract layer (MCP discovery/execution) and note the bridge-reader fixture under the authorization matrix. Update the Client row to mark ChatGPT as the verified Phase 1 client.

- [ ] **Step 3: Update status + continue-here**

Set `.agents/status.md` and `.continue-here.md` to: M4 Phase 1 complete/in-progress per actual result, next = stabilize on staging with a real cert, then Milestone 5 writes. Include the exact commands to re-run the smoke suite and the connector self-test.

- [ ] **Step 4: Confirm `composer check` still green (no PHP touched, but guard)**

```bash
cd "/Users/lukaszbiedron/Other Projects/wp-content-bridge"
composer check
```
Expected: PHPCS/PHPStan/PHPUnit all pass (unchanged counts; this task edits only Markdown).

- [ ] **Step 5: Commit**

```bash
cd "/Users/lukaszbiedron/Other Projects/wp-content-bridge"
git add docs/plan/IMPLEMENTATION_PLAN.md docs/plan/TEST_PLAN.md .agents/status.md .continue-here.md
git commit -m "docs: record Milestone 4 Phase 1 ChatGPT read access outcome"
```

---

## Self-Review

**Spec coverage:**
- Decision Approach A + evaluation gate → Task 1 (ADR 0010), Task 2 (applies gate).
- Least-privilege bridge reader → Task 3.
- Official MCP Adapter projecting five read abilities → Task 4.
- ChatGPT-correct OAuth (discovery, PKCE, server-side verification) → Task 6 (live) + Task 2 (desk).
- Phase 1 flow steps 1–7 → Task 6 steps 1–4 + Task 7 step 3 (staging note).
- Verification: client-agnostic smoke check → Task 5; manual ChatGPT walkthrough → Task 6; Codex secondary/deferred → noted in Task 7 (out of Phase 1 scope).
- Deliverables: ADR 0010 (T1), setup guide (T4+T6), self-test/troubleshooting (T6), IMPLEMENTATION_PLAN update (T7).
- Out of scope (writes, plugin-owned AS, Agents API) → enforced by read-only constraint + Task 7 wording. No task implements them.
- Risks (token binding, tunnel misconfig, third-party dependency) → Task 2 gate + Task 6 step 2 live re-check + Task 6 troubleshooting.

**Placeholder scan:** `<adapter-namespace>` and `<public-host>` are runtime-resolved values that MUST be filled in when Task 4/Task 6 run — they are inputs discovered live, not plan placeholders; each is flagged at the step that resolves it. No `TBD`/`TODO`/"implement later" in task bodies.

**Type consistency:** ability IDs used identically across Tasks 4/5/7 (`wp-content-bridge/search-content`, `get-content`, `get-url-seo`, `get-editorial-context`, `get-diagnostics`); the bridge user login `wpcb-bridge-reader` is consistent across Tasks 3/5/6; env vars `WPCB_SITE_URL`/`WPCB_WP_ROOT`/`WPCB_MCP_PATH` consistent across Tasks 5/7; `WPCB_BRIDGE_MODE` consistent Task 3/5.

#!/usr/bin/env bash
# Client-agnostic MCP smoke check for WP Content Bridge abilities.
#
# Verifies: initialize -> notifications/initialized -> tools/list (the
# expected projection profile) -> tools/call for the safe baseline read
# abilities with minimal valid input. Write and destructive abilities are
# discovery-checked only and are never executed by this smoke script.
#
# Transport: the official WordPress/mcp-adapter HttpTransport is Streamable
# HTTP / JSON-RPC 2.0 and is SESSION-based (see docs/setup/MCP_ADAPTER.md):
# `initialize` returns an `Mcp-Session-Id` response header that must be
# echoed on every subsequent request. Tool names hyphenate the ability id's
# `/` (e.g. `wp-content-bridge/get-content` -> `wp-content-bridge-get-content`)
# because MCP tool names cannot contain `/`.
set -euo pipefail

: "${WPCB_SITE_URL:?set WPCB_SITE_URL, e.g. https://kormas-isu.local}"
: "${WPCB_WP_ROOT:?set WPCB_WP_ROOT to the WordPress public root}"
WPCB_MCP_PATH="${WPCB_MCP_PATH:-/wp-json/wpcb-mcp/mcp}"  # match Task 4 namespace/route
WPCB_EXPECTED_TOOLS="${WPCB_EXPECTED_TOOLS:-search-content,get-content,get-url-seo,get-editorial-context,get-diagnostics}"
BRIDGE_USER="wpcb-bridge-reader"
ENDPOINT="${WPCB_SITE_URL}${WPCB_MCP_PATH}"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

for WPCB_COMMAND in wp curl; do
	command -v "$WPCB_COMMAND" >/dev/null || {
		echo "Missing required command: $WPCB_COMMAND" >&2
		exit 1
	}
done

fail() { echo "FAIL: $*" >&2; exit 1; }

# WPCB_WP_ROOT is the site's public document root, not the WordPress core
# directory (this site uses a Bedrock-style layout: core lives in
# public/wp/, config in public/wp-config.php). WP-CLI resolves this via the
# project's wp-cli.yml only when invoked from within the root — an explicit
# --path="$WPCB_WP_ROOT" bypasses that discovery and fails. So `cd` there and
# call `wp` bare, matching tests/Integration/http-url-runtime-verification.sh.
cd "$WPCB_WP_ROOT"

# Ensure the bridge reader exists (idempotent setup via the fixture).
WPCB_BRIDGE_MODE=setup wp eval \
	'require "'"$SCRIPT_DIR"'/bridge-reader-fixture.php";' >/dev/null 2>&1

HEADERS_FILE="$(mktemp)"

# Disposable Application Password bound to the bridge reader. Never printed,
# logged, or committed — only held in memory for the lifetime of this script.
# `2>/dev/null | tail -n1`: PHP's display_errors can splice deprecation
# notices into stdout ahead of --porcelain output on this install, so only
# the last line is trusted as the value.
APPPASS="$(wp user application-password create "$BRIDGE_USER" wpcb-mcp-smoke --porcelain 2>/dev/null | tail -n1)"
cleanup() {
	local WPCB_EXIT_CODE=$?
	wp user application-password delete "$BRIDGE_USER" --all >/dev/null 2>&1 || true
	rm -f "$HEADERS_FILE"
	trap - EXIT
	exit "$WPCB_EXIT_CODE"
}
trap cleanup EXIT

AUTH=(--user "${BRIDGE_USER}:${APPPASS}" -k -sS)
COMMON_HEADERS=(-H 'Content-Type: application/json' -H 'Accept: application/json, text/event-stream' -H 'MCP-Protocol-Version: 2025-06-18')
SESSION_ID=""

# Strips an SSE `data: ` framing prefix if the response came back as
# text/event-stream; passes plain JSON responses through unchanged.
extract_json() {
	local raw="$1"
	if grep -q '^data: ' <<<"$raw"; then
		grep '^data: ' <<<"$raw" | sed 's/^data: //' | tail -n1
	else
		echo "$raw"
	fi
}

# First call only: capture response headers to read the session id.
rpc_init() {
	curl "${AUTH[@]}" "${COMMON_HEADERS[@]}" -D "$HEADERS_FILE" -X POST "$ENDPOINT" -d "$1"
}

# Subsequent calls: echo the session id captured from initialize.
rpc() {
	curl "${AUTH[@]}" "${COMMON_HEADERS[@]}" -H "Mcp-Session-Id: $SESSION_ID" -X POST "$ENDPOINT" -d "$1"
}

notify() {
	curl "${AUTH[@]}" "${COMMON_HEADERS[@]}" -H "Mcp-Session-Id: $SESSION_ID" -X POST "$ENDPOINT" -d "$1" -o /dev/null
}

echo "== initialize =="
INIT_RAW="$(rpc_init '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"wpcb-smoke","version":"1"}}}')"
INIT="$(extract_json "$INIT_RAW")"
echo "$INIT" | grep -q '"result"' || fail "initialize did not return a result: $INIT"

SESSION_ID="$(grep -i '^Mcp-Session-Id:' "$HEADERS_FILE" | tail -n1 | awk -F': ' '{print $2}' | tr -d '\r\n ')"
[ -n "$SESSION_ID" ] || fail "initialize response did not carry an Mcp-Session-Id header"

echo "== notifications/initialized =="
notify '{"jsonrpc":"2.0","method":"notifications/initialized"}'

echo "== tools/list =="
LIST_RAW="$(rpc '{"jsonrpc":"2.0","id":2,"method":"tools/list","params":{}}')"
LIST="$(extract_json "$LIST_RAW")"

IFS=',' read -r -a EXPECTED_TOOLS <<<"$WPCB_EXPECTED_TOOLS"
[ "${#EXPECTED_TOOLS[@]}" -gt 0 ] || fail "WPCB_EXPECTED_TOOLS did not contain any tools"

for tool in "${EXPECTED_TOOLS[@]}"; do
	tool="${tool#wp-content-bridge/}"
	tool="${tool#wp-content-bridge-}"
	[ -n "$tool" ] || fail "WPCB_EXPECTED_TOOLS contains an empty tool name"
	echo "$LIST" | grep -q "wp-content-bridge-$tool" || fail "tools/list missing wp-content-bridge-$tool"
done
echo "tools/list OK (${#EXPECTED_TOOLS[@]} expected tools present)"

echo "== tools/call: get-diagnostics (no input) =="
DIAG_RAW="$(rpc '{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"wp-content-bridge-get-diagnostics","arguments":{}}}')"
DIAG="$(extract_json "$DIAG_RAW")"
echo "$DIAG" | grep -q '"result"' || fail "get-diagnostics call failed: $DIAG"

echo "== tools/call: search-content =="
SEARCH_RAW="$(rpc '{"jsonrpc":"2.0","id":4,"method":"tools/call","params":{"name":"wp-content-bridge-search-content","arguments":{"query":"the"}}}')"
SEARCH="$(extract_json "$SEARCH_RAW")"
echo "$SEARCH" | grep -q '"result"' || fail "search-content call failed: $SEARCH"

echo "== tools/call: get-editorial-context (optional input) =="
EDITORIAL_RAW="$(rpc '{"jsonrpc":"2.0","id":5,"method":"tools/call","params":{"name":"wp-content-bridge-get-editorial-context","arguments":{}}}')"
EDITORIAL="$(extract_json "$EDITORIAL_RAW")"
echo "$EDITORIAL" | grep -q '"result"' || fail "get-editorial-context call failed: $EDITORIAL"

# get-content / get-url-seo need a real readable object id. Discover one
# published post id via WP-CLI so the smoke test is self-contained. Argument
# names verified against src/Adapter/Abilities/AbilitySchemas.php: get_input()
# requires `post_id` (not `id`); seo_input() requires `post_id` OR `url`.
PID="$(wp post list --post_type=post --post_status=publish --posts_per_page=1 --field=ID 2>/dev/null | tail -n1)"
if [ -n "${PID:-}" ]; then
	echo "== tools/call: get-content (post $PID) =="
	GET_RAW="$(rpc '{"jsonrpc":"2.0","id":6,"method":"tools/call","params":{"name":"wp-content-bridge-get-content","arguments":{"post_id":'"$PID"'}}}')"
	GET="$(extract_json "$GET_RAW")"
	echo "$GET" | grep -q '"result"' || fail "get-content call failed: $GET"

	echo "== tools/call: get-url-seo (post $PID) =="
	SEO_RAW="$(rpc '{"jsonrpc":"2.0","id":7,"method":"tools/call","params":{"name":"wp-content-bridge-get-url-seo","arguments":{"post_id":'"$PID"'}}}')"
	SEO="$(extract_json "$SEO_RAW")"
	echo "$SEO" | grep -q '"result"' || fail "get-url-seo call failed: $SEO"
else
	echo "WARN: no published post found; skipped get-content/get-url-seo object calls"
fi

echo "ALL MCP SMOKE CHECKS PASSED"

#!/usr/bin/env bash
# Verifies Yoast Local multiple-location branch profiles through the SEO and
# editorial-context abilities over real HTTPS.
#
# This provisions a licensed multiple-location fixture (primary + branch),
# exercises get-url-seo and get-editorial-context as a disposable least-privilege
# Application Password principal, and asserts branch identity, bounds, and that no
# private Local option leaks. It always restores prior state and removes its
# fixtures, principal, and the test-only TLS filter.
#
# Never run against production. Local development only.

set -euo pipefail

WPCB_SITE_URL="${WPCB_SITE_URL:-https://kormas-isu.local}"
WPCB_WP_ROOT="${WPCB_WP_ROOT:-/Users/lukaszbiedron/Local Sites/kormas-isu/app/public}"
WPCB_FIXTURE="${WPCB_FIXTURE:-/Users/lukaszbiedron/Other Projects/wp-content-bridge/tests/Integration/local-multilocation-fixture.php}"
WPCB_CURL_TLS_ARGS=()
if [[ "${WPCB_ALLOW_INSECURE_TLS:-1}" == "1" ]]; then
	WPCB_CURL_TLS_ARGS+=( --insecure )
fi

for WPCB_COMMAND in wp curl jq openssl; do
	command -v "$WPCB_COMMAND" >/dev/null || {
		echo "Missing required command: $WPCB_COMMAND" >&2
		exit 1
	}
done

cd "$WPCB_WP_ROOT"

WPCB_MU_DIR="$(wp eval 'echo defined("WPMU_PLUGIN_DIR") ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . "/mu-plugins";' 2>/dev/null | tail -1)"
WPCB_TLS_MU="$WPCB_MU_DIR/wpcb-ml-test-sslverify.php"
WPCB_USER_ID=""
WPCB_FIXTURE_ACTIVE=""

cleanup() {
	local WPCB_EXIT_CODE=$?
	if [[ -n "$WPCB_FIXTURE_ACTIVE" ]]; then
		WPCB_ML_MODE=teardown wp eval "require '$WPCB_FIXTURE';" >/dev/null 2>&1 || true
	fi
	if [[ -n "$WPCB_USER_ID" ]]; then
		wp user delete "$WPCB_USER_ID" --yes >/dev/null 2>&1 || true
	fi
	rm -f "$WPCB_TLS_MU"
	trap - EXIT
	exit "$WPCB_EXIT_CODE"
}
trap cleanup EXIT

# Test-only: relax TLS verification for the same-host loopback fetch on the local
# self-signed certificate. Production keeps the default verified request.
if [[ "${WPCB_ALLOW_INSECURE_TLS:-1}" == "1" ]]; then
	mkdir -p "$WPCB_MU_DIR"
	printf '%s\n' '<?php' "add_filter( 'wpcb_seo_rendered_schema_sslverify', '__return_false' );" >"$WPCB_TLS_MU"
fi

WPCB_SETUP=$(WPCB_ML_MODE=setup wp eval "require '$WPCB_FIXTURE';" 2>/dev/null | grep '"status":"OK"' | tail -1)
[[ -n "$WPCB_SETUP" ]] || {
	echo "Fixture setup failed." >&2
	exit 1
}
WPCB_FIXTURE_ACTIVE="1"

WPCB_ORG=$(jq -r '.org_name' <<<"$WPCB_SETUP")
WPCB_SENTINEL=$(jq -r '.leak_sentinel' <<<"$WPCB_SETUP")
WPCB_PRIMARY_URL="$WPCB_SITE_URL$(jq -r '.primary_path' <<<"$WPCB_SETUP")"
WPCB_BRANCH_URL="$WPCB_SITE_URL$(jq -r '.branch_path' <<<"$WPCB_SETUP")"

WPCB_TOKEN="wpcbml$(date +%s)"
WPCB_USER_ID=$(wp user create "$WPCB_TOKEN" "$WPCB_TOKEN@example.invalid" \
	--role=subscriber --user_pass="$(openssl rand -hex 24)" --porcelain 2>/dev/null | tail -1)
wp user add-cap "$WPCB_USER_ID" wpcb_read_content >/dev/null 2>&1
WPCB_APP_PASSWORD=$(wp user application-password create "$WPCB_USER_ID" \
	wp-content-bridge-ml-verifier --porcelain 2>/dev/null | tail -1)

WPCB_SEO_URL="$WPCB_SITE_URL/wp-json/wp-abilities/v1/abilities/wp-content-bridge/get-url-seo/run"
WPCB_EDITORIAL_URL="$WPCB_SITE_URL/wp-json/wp-abilities/v1/abilities/wp-content-bridge/get-editorial-context/run"

run_seo() {
	curl "${WPCB_CURL_TLS_ARGS[@]}" --silent --show-error \
		--user "$WPCB_TOKEN:$WPCB_APP_PASSWORD" --get \
		--data-urlencode "input[url]=$1" "$WPCB_SEO_URL"
}

assert_no_leak() {
	if grep -q "$WPCB_SENTINEL" <<<"$1"; then
		echo "Private Local option leaked into a response." >&2
		exit 1
	fi
}

# Primary location: the organization profile is rendered from the front end.
WPCB_PRIMARY_RESPONSE=$(run_seo "$WPCB_PRIMARY_URL")
assert_no_leak "$WPCB_PRIMARY_RESPONSE"
jq -e --arg org "$WPCB_ORG" '
	.code == null
	and (.provenance.provider.modules | index("local")) != null
	and .resolved.local_businesses.source == "yoast.schema.local.rendered"
	and .resolved.local_businesses.state == "generated"
	and (.resolved.local_businesses.value | length) >= 1
	and (.resolved.local_businesses.value | length) <= 50
	and any(.resolved.local_businesses.value[];
		.name == $org and (.address.streetAddress | length) > 0 and (.geo.latitude != null))
' <<<"$WPCB_PRIMARY_RESPONSE" >/dev/null

# Branch location: a non-primary entity links to the parent via parentOrganization.
WPCB_BRANCH_RESPONSE=$(run_seo "$WPCB_BRANCH_URL")
assert_no_leak "$WPCB_BRANCH_RESPONSE"
jq -e --arg org "$WPCB_ORG" '
	.code == null
	and .resolved.local_businesses.source == "yoast.schema.local.rendered"
	and .resolved.local_businesses.state == "generated"
	and (.resolved.local_businesses.value | length) <= 50
	and any(.resolved.local_businesses.value[];
		.parentOrganization.name == $org
		and (.address.streetAddress | length) > 0
		and (.geo.latitude != null)
		and (.openingHoursSpecification | length) >= 1)
' <<<"$WPCB_BRANCH_RESPONSE" >/dev/null

# Editorial context still returns bounded local businesses without leakage.
WPCB_EDITORIAL_RESPONSE=$(curl "${WPCB_CURL_TLS_ARGS[@]}" --silent --show-error \
	--user "$WPCB_TOKEN:$WPCB_APP_PASSWORD" --get \
	--data-urlencode 'input[recent_limit]=5' \
	--data-urlencode 'input[terms_per_taxonomy]=5' \
	"$WPCB_EDITORIAL_URL")
assert_no_leak "$WPCB_EDITORIAL_RESPONSE"
jq -e '
	.code == null
	and (.sections | index("local_businesses")) != null
	and .context.local_businesses.state == "generated"
	and (.context.local_businesses.items | length) >= 1
	and (.context.local_businesses.items | length) <= 50
' <<<"$WPCB_EDITORIAL_RESPONSE" >/dev/null

# Degraded case: the home URL must not fail and must not leak.
WPCB_HOME_RESPONSE=$(run_seo "$WPCB_SITE_URL/")
assert_no_leak "$WPCB_HOME_RESPONSE"
jq -e '.code == null and .provenance.provider.detected == true' <<<"$WPCB_HOME_RESPONSE" >/dev/null

jq -n \
	--arg org "$WPCB_ORG" \
	--arg primary_url "$WPCB_PRIMARY_URL" \
	--arg branch_url "$WPCB_BRANCH_URL" \
	--argjson branch_entities "$(jq '.resolved.local_businesses.value | length' <<<"$WPCB_BRANCH_RESPONSE")" \
	'{
		status: "PASS",
		organization: $org,
		primary_url: $primary_url,
		branch_url: $branch_url,
		branch_source: "yoast.schema.local.rendered",
		branch_entities: $branch_entities,
		branch_parent_organization_verified: true,
		editorial_local_businesses: true,
		leakage_rejected: true
	}'

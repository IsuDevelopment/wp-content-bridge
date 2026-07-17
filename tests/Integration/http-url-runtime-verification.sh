#!/usr/bin/env bash
# Verifies URL-target behavior through a real HTTP request context.

set -euo pipefail

WPCB_SITE_URL="${WPCB_SITE_URL:-https://kormas-isu.local}"
WPCB_WP_ROOT="${WPCB_WP_ROOT:-/Users/lukaszbiedron/Local Sites/kormas-isu/app/public}"
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

WPCB_TOKEN="wpcbhttp$(date +%s)"
WPCB_USER_ID=""

cleanup() {
	local WPCB_EXIT_CODE=$?
	if [[ -n "$WPCB_USER_ID" ]]; then
		wp user delete "$WPCB_USER_ID" --yes >/dev/null 2>&1 || true
	fi
	trap - EXIT
	exit "$WPCB_EXIT_CODE"
}
trap cleanup EXIT

WPCB_USER_ID=$(wp user create "$WPCB_TOKEN" "$WPCB_TOKEN@example.invalid" \
	--role=subscriber --user_pass="$(openssl rand -hex 24)" --porcelain 2>/dev/null | tail -1)
wp user add-cap "$WPCB_USER_ID" wpcb_read_content >/dev/null 2>&1
WPCB_APP_PASSWORD=$(wp user application-password create "$WPCB_USER_ID" \
	wp-content-bridge-http-verifier --porcelain 2>/dev/null | tail -1)

if [[ -z "${WPCB_FIXTURE_POST_ID:-}" ]]; then
	WPCB_FIXTURE_POST_ID=$(wp eval '
		$admins = get_users( [ "role" => "administrator", "number" => 1, "fields" => "ids" ] );
		wp_set_current_user( (int) $admins[0] );
		$diagnostics = wp_get_ability( "wp-content-bridge/get-diagnostics" )->execute( null );
		$ids = get_posts( [
			"post_type" => $diagnostics["readable_post_types"],
			"post_status" => "publish",
			"posts_per_page" => 1,
			"fields" => "ids",
		] );
		echo (int) $ids[0];
	' 2>/dev/null | tail -1)
fi

WPCB_RUN_URL="$WPCB_SITE_URL/wp-json/wp-abilities/v1/abilities/wp-content-bridge/get-url-seo/run"
WPCB_EDITORIAL_RUN_URL="$WPCB_SITE_URL/wp-json/wp-abilities/v1/abilities/wp-content-bridge/get-editorial-context/run"
WPCB_POST_URL=$(curl "${WPCB_CURL_TLS_ARGS[@]}" --silent --show-error --location \
	--output /dev/null --write-out '%{url_effective}' "$WPCB_SITE_URL/?p=$WPCB_FIXTURE_POST_ID")

run_seo() {
	local WPCB_TARGET_URL="$1"
	curl "${WPCB_CURL_TLS_ARGS[@]}" --silent --show-error \
		--user "$WPCB_TOKEN:$WPCB_APP_PASSWORD" --get \
		--data-urlencode "input[url]=$WPCB_TARGET_URL" "$WPCB_RUN_URL"
}

WPCB_POST_RESPONSE=$(run_seo "$WPCB_POST_URL")
jq -e '
	.code == null
	and .provenance.provider.detected == true
	and (.provenance.provider.modules | sort) == ["local", "premium"]
	and .provenance.provider.module_versions.premium == "28.0"
	and .provenance.provider.module_versions.local == "15.8"
	and .provenance.completeness == "partial"
	and (.configured | has("title"))
	and (.resolved | has("canonical"))
	and (.schema_graph | length <= 200)
	and .resolved.local_businesses.state == "generated"
	and (.resolved.local_businesses.value | length > 0)
	and (tostring | contains("must-not-leak") | not)
' <<<"$WPCB_POST_RESPONSE" >/dev/null

WPCB_EDITORIAL_RESPONSE=$(curl "${WPCB_CURL_TLS_ARGS[@]}" --silent --show-error \
	--user "$WPCB_TOKEN:$WPCB_APP_PASSWORD" --get \
	--data-urlencode 'input[recent_limit]=5' \
	--data-urlencode 'input[terms_per_taxonomy]=5' \
	"$WPCB_EDITORIAL_RUN_URL")
jq -e '
	.code == null
	and (.sections | sort) == ["authors", "local_businesses", "post_types", "recent_content", "taxonomies", "terms"]
	and (.context.post_types | length) > 0
	and (.context.taxonomies | length) <= 20
	and (.context.recent_content | length) <= 5
	and (.context.authors | all(keys_unsorted - ["id", "display_name"] | length == 0))
	and .context.local_businesses.state == "generated"
	and (.context.local_businesses.items | length) > 0
	and .bounds.recent_content_limit == 5
	and .bounds.terms_per_taxonomy == 5
	and (tostring | test("user_email|user_login|license_key|must-not-leak") | not)
' <<<"$WPCB_EDITORIAL_RESPONSE" >/dev/null

WPCB_REST_BASE=$(WPCB_FIXTURE_POST_ID="$WPCB_FIXTURE_POST_ID" wp eval '
	$post_type = get_post_type( (int) getenv( "WPCB_FIXTURE_POST_ID" ) );
	$object = is_string( $post_type ) ? get_post_type_object( $post_type ) : null;
	if ( $object instanceof WP_Post_Type ) {
		echo is_string( $object->rest_base ) && $object->rest_base !== "" ? $object->rest_base : $object->name;
	}
' 2>/dev/null | tail -1)
WPCB_PUBLIC_RESPONSE=$(curl "${WPCB_CURL_TLS_ARGS[@]}" --silent --show-error \
	--user "$WPCB_TOKEN:$WPCB_APP_PASSWORD" \
	"$WPCB_SITE_URL/wp-json/wp/v2/$WPCB_REST_BASE/$WPCB_FIXTURE_POST_ID")
jq -e --argjson public "$WPCB_PUBLIC_RESPONSE" '
	def blanknull: if . == "" then null else . end;
	.public = $public
	| .public.yoast_head_json.title == .resolved.title.value
	and .public.yoast_head_json.description == .resolved.description.value
	and .public.yoast_head_json.canonical == .resolved.canonical.value
	and .public.yoast_head_json.robots == .resolved.robots.value
	and .public.yoast_head_json.og_type == .resolved.open_graph.value.type
	and .public.yoast_head_json.og_title == .resolved.open_graph.value.title
	and .public.yoast_head_json.og_description == .resolved.open_graph.value.description
	and .public.yoast_head_json.og_url == .resolved.open_graph.value.url
	and .public.yoast_head_json.og_site_name == .resolved.open_graph.value.site_name
	and (.public.yoast_head_json.twitter_card | blanknull) == .resolved.twitter.value.card
	and (.public.yoast_head_json.twitter_title | blanknull) == .resolved.twitter.value.title
	and (.public.yoast_head_json.twitter_description | blanknull) == .resolved.twitter.value.description
	and (.public.yoast_head_json.schema["@graph"] | walk(if type == "string" and . == "" then null else . end)) == .schema_graph
' <<<"$WPCB_POST_RESPONSE" >/dev/null

WPCB_HOME_RESPONSE=$(run_seo "$WPCB_SITE_URL/")
jq -e '
	.code == null
	and .provenance.provider.detected == true
	and (.provenance.completeness as $completeness | ["partial", "unavailable"] | index($completeness) != null)
' <<<"$WPCB_HOME_RESPONSE" >/dev/null

WPCB_EXTERNAL_RESPONSE=$(run_seo 'https://example.invalid/')
jq -e '.code == "wpcb_invalid_selector"' <<<"$WPCB_EXTERNAL_RESPONSE" >/dev/null

jq -n \
	--arg post_url "$WPCB_POST_URL" \
	--arg post_completeness "$(jq -r '.provenance.completeness' <<<"$WPCB_POST_RESPONSE")" \
	--arg home_completeness "$(jq -r '.provenance.completeness' <<<"$WPCB_HOME_RESPONSE")" \
	--arg external_code "$(jq -r '.code' <<<"$WPCB_EXTERNAL_RESPONSE")" \
	'{
		status: "PASS",
		post_url: $post_url,
		post_completeness: $post_completeness,
		public_head_parity: true,
		premium_local_modules: true,
		local_public_profile: true,
		editorial_context: true,
		home_completeness: $home_completeness,
		external_code: $external_code
	}'

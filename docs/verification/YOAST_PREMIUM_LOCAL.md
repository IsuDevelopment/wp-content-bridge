# Yoast Premium and Local SEO verification

Verified: 2026-07-17 on Kormas local.

## Environment

- WordPress 7.0.1
- Yoast SEO Free 28.0
- Yoast SEO Premium 28.0
- Yoast Local SEO 15.8
- Local modes exercised: single organization/location and multiple locations
  (primary organization + one branch)

The plugin is loaded from the standalone repository through the local ignored
symlink documented in `.continue-here.md`.

## Verified behavior

- provider diagnostics detect `premium` and `local` and expose only their safe
  public versions;
- Premium primary and additional keyphrases normalize into an ordered phrase
  list and detailed role/score objects;
- malformed, duplicate, and unknown Premium JSON members are ignored;
- Local public business data is derived from Yoast-emitted Schema only;
- nested address references, opening hours, and other allowlisted public data
  are resolved without exposing unknown nested members;
- HTTP output contains a non-empty public Local profile and remains consistent
  with public `yoast_head_json`;
- no arbitrary post-meta marker, raw option, license value, or provider-owned
  indexables data enters the response;
- all Abilities remain private and read-only.

## Automated evidence

```text
PHPCS:   72/72 files
PHPStan: 0 errors
PHPUnit: 68 tests, 165 assertions
Yoast configured runtime verifier: PASS
Abilities runtime verifier: PASS
HTTP URL runtime verifier: PASS
Local multiple-location runtime verifier: PASS
```

Repeatable commands are maintained in `.continue-here.md` and
`docs/plan/TEST_PLAN.md`.

## Compatibility boundary

The implemented Premium parser is claimed only for the tested 28.x envelope.
Synonyms are intentionally absent because no stable format has yet been proven.

Multiple-location mode is now verified at runtime (2026-07-17) on a licensed
Local 15.8 fixture with a primary organization and one non-primary branch. Key
findings:

- Yoast Local 15.8 emits the branch node (`#local-branch-organization` with
  `parentOrganization`, plus the branch's own `PostalAddress`, `GeoCoordinates`,
  and opening hours) **only during a front-end singular render**. The resolved
  meta surface (`for_url`/`for_post`) and the REST `yoast_head_json` for the
  locations custom post type do not contain it.
- Yoast uses `parentOrganization`, not schema.org `branchOf`. The projector
  allowlists both.
- To capture the branch profile through the abilities, the provider performs a
  bounded, same-origin loopback fetch of the target's public page and projects
  its `application/ld+json` graph (ADR 0009). This is used only for
  `local_businesses`; on failure it falls back to the resolved surface with an
  explicit warning.

The runtime matrix (`tests/Integration/local-multilocation-runtime-verification.sh`
with `tests/Integration/local-multilocation-fixture.php`) asserts, over real
HTTPS: primary organization profile; a branch entity with `parentOrganization`
resolving to the organization, branch address, geo, and hours; entity bounds;
and rejection of injected private-option sentinels (`local_api_key`,
`googlemaps_api_key`). The fixture snapshots and restores the exact prior
`wpseo_local`, `wpseo_titles`, and content-access policy, and deletes its
location posts.

Yoast's documented analysis-score Abilities describe recent-post collections
but do not return stable post IDs. WP Content Bridge therefore does not join
those scores to a requested target and reports per-target analysis as
unavailable instead of risking a false association.

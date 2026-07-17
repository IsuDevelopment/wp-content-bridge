# Yoast Premium and Local SEO verification

Verified: 2026-07-17 on Kormas local.

## Environment

- WordPress 7.0.1
- Yoast SEO Free 28.0
- Yoast SEO Premium 28.0
- Yoast Local SEO 15.8
- Local mode exercised: single organization/location

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
PHPCS:   61/61 files
PHPStan: 0 errors
PHPUnit: 54 tests, 130 assertions
Yoast configured runtime verifier: PASS
Abilities runtime verifier: PASS
HTTP URL runtime verifier: PASS
```

Repeatable commands are maintained in `.continue-here.md` and
`docs/plan/TEST_PLAN.md`.

## Compatibility boundary

The implemented Premium parser is claimed only for the tested 28.x envelope.
Synonyms are intentionally absent because no stable format has yet been proven.

The Local projector has a pure unit fixture for a multiple-location branch with
`branchOf`, referenced `PostalAddress`, `GeoCoordinates`, and opening hours.
That proves the provider-neutral projector contract, not the complete runtime
integration. A licensed WordPress fixture configured with multiple locations,
including primary and non-primary pages, is required before claiming that mode.

Yoast's documented analysis-score Abilities describe recent-post collections
but do not return stable post IDs. WP Content Bridge therefore does not join
those scores to a requested target and reports per-target analysis as
unavailable instead of risking a false association.

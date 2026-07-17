# Editorial context verification

Verified: 2026-07-17

## Status

PASS for the tested WordPress 7.0.1 single-site environment with Yoast Free
28.0, Premium 28.0, and Local 15.8 in single-location mode.

This evidence verifies `wp-content-bridge/get-editorial-context`. It does not
claim real Local multiple-location runtime compatibility; that fixture remains
the final Milestone 3 exit item.

## Contract verified

- Ability is registered and exposed through the WordPress Abilities REST
  projection.
- Annotations are `readonly=true`, `destructive=false`, and `idempotent=true`.
- Input and output schemas are strict and include bounded section, post-type,
  taxonomy, recent-content, and term selections.
- All six sections execute: post types, taxonomies, terms, observed authors,
  readable recent content, and normalized Local businesses.
- Explicit unavailable post types are rejected with `wpcb_invalid_input`.
- Anonymous access is denied; administrator and authorized least-privilege
  principals can execute.
- Disabling the configured content policy blocks editorial context even when
  the principal otherwise has native capabilities.
- Twin invocations produced identical response hashes.
- Author rows expose only `id` and `display_name` and are derived from readable
  recent results.
- Real HTTPS execution returned a normalized non-empty Local public profile.
- Leakage checks reject arbitrary post meta, injected markers, user email/login,
  Local license data, and other non-allowlisted values.

## Automated baseline

```text
PHPCS: 68/68 files
PHPStan: 0 errors
PHPUnit: 62 tests, 152 assertions
Abilities runtime: PASS, five abilities, REST discovery/run 200
Authorization matrix: PASS, six principals, eight objects
Real HTTPS verifier: PASS, editorial_context=true
```

## Commands

```bash
cd "/Users/lukaszbiedron/Other Projects/wp-content-bridge"
vendor/bin/phpcs --report=summary
vendor/bin/phpstan analyse --memory-limit=512M --no-progress
vendor/bin/phpunit --colors=never

cd "/Users/lukaszbiedron/Local Sites/kormas-isu/app/public"
wp eval 'require "/Users/lukaszbiedron/Other Projects/wp-content-bridge/tests/Integration/abilities-runtime-verification.php";'
wp eval 'require "/Users/lukaszbiedron/Other Projects/wp-content-bridge/tests/Integration/authorization-matrix.php";'

WPCB_SITE_URL=https://kormas-isu.local \
WPCB_WP_ROOT="/Users/lukaszbiedron/Local Sites/kormas-isu/app/public" \
"/Users/lukaszbiedron/Other Projects/wp-content-bridge/tests/Integration/http-url-runtime-verification.sh"
```

WP-CLI 2.11 emits PHP 8.4 deprecations from its bundled Runner/Mustache
dependencies. They are environment-tool warnings, not plugin warnings.

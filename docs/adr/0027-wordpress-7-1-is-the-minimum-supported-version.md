# ADR 0027: WordPress 7.1 is the minimum supported version

## Status

Accepted (2026-09-01). Task 1 of `docs/plan/WP_7_1_ABILITIES_ADOPTION_PLAN.md`.
Unreleased: the metadata is bumped in the working tree, the release that carries
it is not cut yet.

## Context

The plugin declared `Requires at least: 7.0` in `wp-content-bridge.php` and
`readme.txt` and consumed no 7.1 API. WordPress 7.0 shipped 2026-05-20 and 7.1
on 2026-08-19; 7.1's Abilities API additions are the first core release whose
new surface this plugin actually wants — declarative discovery through
`wp_get_abilities( $args )`, `meta.public`, and above all the `wp_ability_invoked`
action, which is the only vantage point from which a `permission_callback`
denial can be observed at all. Today such a denial leaves no trace anywhere.

Keeping 7.0 in support would mean consuming those APIs behind version guards.
That is not merely more code: the specific guard needed for discovery is a
live fail-open path. Extra arguments to a userland PHP function are silently
ignored, so `wp_get_abilities( array( 'category' => … ) )` executed on 7.0
returns *every registered ability on the site*, including other plugins'. A
guarded implementation therefore carries, permanently, a branch whose failure
mode is a widened MCP projection — the exact defect class ADR 0025 was written
to eliminate.

The deployment this work is for runs 7.1. AGENTS.md's reusability requirement
constrains what may go *into* the plugin (no site-specific paths, types, or
assumptions); it has never required supporting every WordPress version that a
hypothetical site might run.

## Decision

**WordPress 7.1 is the minimum supported version.** 7.0 is not supported and not
guarded for.

- `Requires at least: 7.1` in the plugin header and `readme.txt`.
- No runtime version check is added. WordPress enforces the header at install
  and update time; a second check in PHP would be a second source of truth.
- `php-stubs/wordpress-stubs` is pinned to `^7.1`, which required
  `szepeviktor/phpstan-wordpress` `^2.0.4` — v2.0.3 constrains the stubs to
  `^6.6.2` and cannot resolve against 7.x.
- 7.1 API is called directly, without `function_exists()` feature guards, in
  code paths reached only when the Abilities API itself is present. The existing
  `function_exists( 'wp_get_abilities' )` guard in `McpServerProvider` stays: it
  answers "is this install running an Abilities-capable WordPress at all",
  which is a different question and keeps an unexpected environment inert
  rather than fatal.
- **`Tested up to: 7.1`, earned rather than asserted.** Requiring a version is a
  constraint we impose; claiming to have tested one is a claim about work
  performed, and releases 0.1.3 through 0.3.0 shipping on static checks alone is
  this repository's record of what asserting it costs. The claim rests on the
  full runtime inventory — 23 of 23, including the MCP transport smoke — passing
  on WordPress 7.1 on 2026-09-01, with task 2's new coercion verifier among
  them. See `docs/setup/VERIFICATION.md`.

## Consequences

- Sites on 7.0 cannot install or update to the next release. They keep 0.8.3,
  which is unaffected.
- Bumping the stubs to v7.1.0 surfaced eight PHPStan errors at `level: max`,
  all pre-existing and all caused by the stubs becoming *more* precise. They
  resolved into two classes, handled differently on purpose:
  - **A real nullable that was being ignored.** `WP_Query::$posts` is typed
    `array<int|WP_Post>|null` in the 7.1 stubs, and four call sites iterated it
    unguarded. Each now iterates `$query->posts ?? array()` — a query that never
    populated its results has no posts, which is the correct reading, not a cast
    to silence an analyser.
  - **Four guards that the new stubs' PHPDoc claims are unreachable.** Three are
    in `WordPressRenderedSchemaReader::origin_parts()`, where the guard *is* the
    security control in an origin comparison, and one is in
    `WordPressMediaRepository`. WordPress does not enforce the types in its own
    PHPDoc, so `treatPhpDocTypesAsCertain: false` is now set in
    `phpstan.neon.dist` with the reason inline. The guards stay. This also made
    three pre-existing `@phpstan-ignore identical.alwaysFalse` comments in
    `WordPressContentMutationRepository` unnecessary — the defensive
    `0 === $id` checks against `wp_insert_post()` are now expressible without
    suppression, and the comments were removed.
- `composer check` is green after those changes: phpcs, PHPStan at `level: max`
  against the 7.1 stubs, and 498 tests / 1,208 assertions.
- A dev-only advisory surfaced while resolving dependencies:
  `squizlabs/php_codesniffer` CVE-2026-67434 (high, OS command injection,
  fixed in 3.13.6). It is unrelated to this decision and is not addressed here.
- The remaining tasks in the adoption plan may now assume 7.1 unconditionally.
  Task 4 nonetheless keeps its explicit category comparison after the
  args-based `wp_get_abilities()` call: the fail-open shape described above is
  cheap to defend against permanently and expensive to rediscover.

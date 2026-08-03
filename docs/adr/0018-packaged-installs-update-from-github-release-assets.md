# ADR 0018: Packaged installs update from GitHub release assets

- Status: Accepted
- Date: 2026-08-03

## Context

WP Content Bridge publishes a versioned GitHub release ZIP containing production
Composer dependencies. WordPress installations using that ZIP need normal
plugin-update discovery, while local Git checkouts, Composer path repositories,
and deployment-managed copies must not be overwritten by wp-admin.

GitHub's automatic source archives are not valid production packages for this
plugin because they do not contain `vendor/autoload.php`. Initializing a remote
checker on every front-end request would also add unnecessary work. The new
runtime dependency must remain infrastructure-only and must not affect Ability,
MCP, domain, or application contracts.

## Decision

Use `yahnis-elsts/plugin-update-checker` as a locked production Composer
dependency. `GitHubReleaseUpdateChecker` initializes it only during WordPress
admin or cron and enables GitHub release assets so updates install the uploaded
`wp-content-bridge.zip` artifact.

Registration fails closed when the factory is unavailable or the plugin
directory contains `.git`. Sites managed by Composer or deployment automation
can additionally define `WPCB_DISABLE_SELF_UPDATES` or return `false` from
`wp_content_bridge_self_updates_enabled`.

The main plugin file performs one infrastructure registration call after the
Composer autoloader is available. The existing release workflow installs
production dependencies before copying the plugin into the ZIP and excludes Git
metadata, tests, build output, and prior ZIP files.

## Consequences

- Packaged installations receive standard WordPress update notifications from
  canonical GitHub releases.
- Updates use a deployable artifact with locked production dependencies rather
  than a source archive.
- Local source worktrees cannot be overwritten by WordPress.
- Composer/deployment owners retain an explicit opt-out and remain responsible
  for their own update lifecycle.
- A production dependency is added, so Composer advisory review and release ZIP
  inventory are mandatory gates.
- The updater has no authority over content, SEO, Abilities, MCP credentials, or
  business data.

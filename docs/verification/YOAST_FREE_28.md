# Yoast Free 28.x compatibility evidence

## Supported read envelope

- Provider detection and version reporting.
- Post and same-origin URL resolution through the documented Yoast Surfaces API.
- Resolved title, description, canonical, robots, Open Graph, Twitter, and
  provider-emitted Schema graph.
- Version-gated configured values from an explicit post-meta allowlist.
- Explicit/inherited value state and partial/unavailable warnings.

## Deliberate limitations

- SEO, readability, and inclusive-language scores are not claimed because the
  documented Surfaces API does not expose them.
- Premium additional keyphrases and Local SEO normalized profiles are Milestone
  3 and require licensed fixtures.
- Provider indexables tables and raw option/meta dumps are never public inputs
  to the normalized response.

## Evidence status

Local Kormas currently runs WordPress 7.0.1 and Yoast SEO 28.0. A post-ID
execution returned the Yoast provider, configured and resolved field envelopes,
four Schema nodes, and the expected analysis limitation warning.

The repeatable four-Ability verifier passes for discovery, schema validation,
anonymous denial, administrator execution, deterministic twin calls, stable
errors, and REST projection. The authorization matrix also passes for
author/editor/integration object visibility, the content-type policy gate, and
arbitrary post-meta leakage.

The real-HTTP verifier passes with a disposable least-privilege principal:
an existing post URL is authorized, canonicalized to its post selector, and
returns partial configured/resolved data plus four Schema nodes; an external
origin returns `wpcb_invalid_selector`; homepage and archive-like targets remain
safe URL lookups. On the current local database, homepage and archive URL
indexables are unavailable and return an explicit unavailable document with
reindex guidance rather than failing or fabricating output.

The disposable configured-value fixture passes for an explicit title, explicit
focus keyphrase, inherited description, partial output without an indexable,
and arbitrary-meta non-disclosure. Public-head parity passes for title,
description, canonical, robots, Open Graph, Twitter, and the normalized Schema
graph. This closes the Yoast Free 28.x Milestone 2 compatibility envelope.

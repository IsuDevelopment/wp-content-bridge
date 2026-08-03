# SEO provider architecture

## Objective

Return enough SEO context to analyze and edit content without coupling the public contract to Yoast database internals.

## Normalized SEO document

```text
SeoDocument
├── configured
│   ├── title
│   ├── description
│   ├── focus_keyphrases
│   ├── keyphrase_details
│   ├── keyphrase_synonyms
│   ├── related_keyphrases
│   ├── canonical
│   ├── robots
│   ├── social
│   ├── schema_types
│   └── cornerstone
├── resolved
│   ├── title
│   ├── description
│   ├── canonical
│   ├── robots
│   ├── open_graph
│   ├── twitter
│   ├── local_businesses
│   └── other_public_meta
├── analysis
│   ├── seo
│   ├── readability
│   └── inclusive_language
├── schema_graph
└── provenance
```

Every configured value records whether it is explicit, inherited, generated, unsupported, or unavailable.

## Provider contract

The initial port should cover:

- provider identity/capabilities;
- get SEO for post;
- get SEO for URL;
- get public organization/location profile when supported;
- update normalized fields when supported;
- return compatibility warnings.

The content application layer must accept a null provider.

## Yoast read strategy

Priority order:

1. Documented resolved output (`yoast_head_json` or the equivalent documented presentation surface).
2. Public Yoast Abilities for analysis scores where their selector/output is sufficient.
3. Documented Yoast services/surfaces for configured values.
4. A narrowly scoped, version-tested post-meta allowlist only for required configured editor values that have no public API.

Do not query or mutate Yoast indexables tables directly. They are provider-owned derived storage.

The current adapter uses Yoast's documented `YoastSEO()->meta->for_post()` and
`for_url()` Surfaces accessors for resolved fields and the Schema graph. Yoast's
documented score Abilities return bounded recent-post lists but no stable post
ID. They therefore cannot be safely joined to the requested target; per-target
analysis remains unavailable with an explicit warning.

A same-site URL that WordPress resolves to a post is canonicalized to a post
selector only after content-policy and native-object authorization. This makes
staging/domain migrations resilient to stale Yoast URL indexables and enables
configured editor values for the object. Home, archives, and legitimate 404
targets remain URL selectors and use `for_url()`.

Configured editor values are read only for tested Yoast 28.x releases from a
narrow post-meta allowlist: title, description, canonical, primary focus
keyphrase, robots, social overrides, Schema page/article types, and cornerstone.
Absent keys are represented as inherited, not as an explicit empty value.

When Premium 28.x is detected, its tested additional-keyphrase and positional
synonym JSON are parsed through a bounded allowlist. `focus_keyphrases` remains
the simple ordered list; `keyphrase_details` adds `primary`/`additional` roles
and an optional score in the 0–100 range. `keyphrase_synonyms` returns the
normalized primary synonyms, while `related_keyphrases` returns each related
phrase with its bounded synonyms and optional score. Unknown JSON members and
malformed items are discarded.

The write adapter exposes only normalized `keyphrase_synonyms` and
`related_keyphrases` lists, never the positional provider JSON. It is gated to
the tested Premium 28.x envelope and preserves scores/synonyms for retained
related phrases (ADR 0014).

Advanced robots writes expose only `robots_noarchive`, `robots_noimageindex`,
and `robots_nosnippet` booleans and merge explicitly requested changes with the
current Yoast value. Social image overrides accept readable WordPress image
attachment IDs only, resolve their URLs internally, and write Yoast's paired
URL/ID fields. Zero clears an override. Raw robots strings and caller-supplied
image URLs remain outside the contract (ADR 0016).

These configured-output additions advance the normalization schema from 1.2
to 1.3. Existing 1.2 keys retain their meanings.

Schema is capped at 200 nodes and 1 MiB after normalization. Warnings are capped
at 50. Arbitrary Yoast meta, WordPress options, indexables rows, provider objects,
and secrets are outside the public document.

## Yoast Free

Initial compatibility target:

- effective title/description/canonical/robots;
- Open Graph and Twitter output;
- Schema graph;
- primary focus keyphrase;
- supported analysis scores;
- configured title/description/canonical/robots/cornerstone/social fields where stable and tested.

## Yoast Premium

Premium capability is feature-detected. Additional keyphrases and primary
synonyms are available through the licensed-version-tested read/write adapter.
Redirects and internal-link suggestions remain separate concerns.

The tested compatibility envelope is Free 28.0 + Premium 28.0. Provider status
reports safe module slugs and versions. It never reports licensing, update
credentials, subscriptions, or raw Premium metadata.

Redirect management and internal-link suggestions are explicitly out of MVP and require separate abilities/security review.

## Yoast Local SEO

Local SEO extends resolved Schema. The canonical MVP output is the provider-generated Schema graph, preserving `LocalBusiness`, `Place`, `PostalAddress`, `GeoCoordinates`, opening hours, service areas, phone numbers, and relationships such as `branchOf` where publicly emitted.

A normalized public organization/location profile may be derived from this graph. Raw Local SEO options are never returned because they can contain internal configuration and are not the effective page output.

The current Local 15.x projector detects `Place`/`LocalBusiness` semantics and
recursively allowlists public scalar fields plus address, coordinates, opening
hours, images, `parentOrganization`, `branchOf`, and public `@id` references. It
is capped at 50 local entities and 100 scalar list values.

Kormas verifies Local 15.8 in both single-location and multiple-location modes.
Yoast Local emits the branch node (`parentOrganization`, branch address, geo,
hours) only during a front-end render; the resolved meta surface omits it. For
`local_businesses` the provider therefore captures the graph from the target's
public page through a bounded, same-origin, cached loopback fetch
(`RenderedSchemaReader`), falling back to the resolved surface with a warning on
failure. See ADR 0009.

## Write strategy

SEO writes are introduced only after read compatibility is proven. A write
adapter:

- accepts normalized fields only;
- validates provider support per field;
- uses provider-supported setters/hooks where available;
- updates only an explicit allowlist otherwise;
- triggers provider reindexing through documented mechanisms;
- re-reads resolved output and reports discrepancies;
- never writes provider indexables tables directly.

### Structured Service provider

`wp-content-bridge/update-service-schema` is a separate semantic write from
Yoast editor SEO. Its public contract describes `Service`, typed `areaServed`,
`brand`, and `hasOfferCatalog`, while the infrastructure adapter maps those
fields to the standalone IsuDev Schema Extended plugin's public `Meta_Fields`
API. The application service depends only on `ServiceSchemaWriter`; it does not
know the plugin class, metadata keys, Gutenberg sidebar, or Yoast graph-piece
implementation.

The Ability is registered only when content writes are enabled and WordPress
has loaded both the standalone plugin marker and compatible API class. It is not
registered merely because matching files or an autoloadable class exist. The
provider's supported-post-type filter remains authoritative. Effective values
are re-read after the write, while the public Schema graph itself remains
observable through `get-url-seo`. See ADR 0017.

## Compatibility reporting

Each response includes provider name/version, detected modules, safe
`module_versions`, supported features, completeness, warnings, and normalization
schema version 1.3. Claims remain scoped to the exact licensed fixtures tested.

# ADR 0031: Media upload fetches one image from a validated remote URL

## Status

Accepted (2026-09-02). Follows ADR 0028's annotation vocabulary and the
featured-image write shipped the same day.

## Context

`update-featured-image` assigns an attachment that already exists. That covers
the common case, but an operating agent also needs to *bring an image in* —
otherwise every new hero image is a manual wp-admin step and the bridge cannot
replace a general-purpose connector.

Upload is not a bigger version of assignment. It is a different risk class, and
three specific things make it so:

1. **The bytes come from outside.** Whatever validates them is the only thing
   standing between a remote party and a file inside `wp-content/uploads`, served
   from the site's own origin.
2. **If the bytes are fetched by URL, the site makes an outbound request on a
   caller's instruction.** That is server-side request forgery unless the URL is
   constrained — and the highest-value SSRF target on a hosted WordPress is
   `169.254.169.254`, the cloud metadata endpoint.
3. **An upload is not naturally idempotent.** A retried assignment converges;
   a retried upload produces a second attachment. A transport that times out
   without the caller knowing whether the write landed — which is exactly the
   transport behaviour observed in production — turns every retry into a
   duplicate.

There is no existing decision covering any of this. `update-featured-image`
deliberately refused to handle files at all and deferred the question here.

## Decision

### 1. One source: a remote URL. Not inline bytes.

`create-media` takes `source_url` and fetches it. It does **not** accept
base64-encoded bytes in the ability input.

Two reasons, in order of weight. First, an agent that has an image almost always
has it as a URL — a stock library, a generated-image endpoint, a client-supplied
link — so inline bytes would be a second code path serving a rarer case. Second,
inline bytes are the worst possible payload shape for this transport: a 1 MB
image is roughly 1.4 MB of base64 inside a JSON tool call, and the production
transport already fails at 120 seconds on requests orders of magnitude smaller
than that. Shipping a route whose realistic payload we expect to time out would
be shipping a defect.

This is additive later. An optional `data` field can be added to the same
ability without changing the contract, and everything in decisions 3 and 4
applies unchanged to bytes from either source. We are not deciding that inline
upload is wrong, only that it is not first.

### 2. The URL is validated by WordPress's own allowlist, and we say what that
does not cover.

The fetch uses `wp_safe_remote_get()`, which sets `reject_unsafe_urls`, which
runs `wp_http_validate_url()`. We use core's implementation rather than writing
our own IP filter, because core's is maintained, is applied again to **every
redirect target** (`WP_Http::validate_redirects()`), and already covers what a
hand-rolled check gets wrong: loopback, `10/8`, `172.16/12`, `192.168/16`,
`169.254/16` — the metadata range — `100.64/10` CGNAT, multicast, the reserved
top of the space, the TEST-NET blocks, embedded credentials, and any port
outside 80/443/8080.

Three residual gaps, recorded because pretending they are closed is worse than
having them:

- **DNS rebinding.** `wp_http_validate_url()` resolves the host to check it,
  then the request resolves the host again. A name answering with a public
  address on the first lookup and a private one on the second defeats the check.
  This is not fixable from userland PHP without pinning the resolved address into
  the socket, which the WordPress HTTP API does not expose. The mitigation is
  that this operation is authenticated, capability-gated, off by default, and
  audited — it is not an anonymous SSRF primitive.
- **Same-host URLs skip every IP check.** Core exempts a URL whose host equals
  the site's `home` host. On a site behind a private address that means the
  site can be made to fetch itself. Accepted: the caller already has authorized
  read access to that site's content.
- **IPv6 is not range-checked.** Core's check is IPv4-only. In practice IPv6 is
  refused rather than waved through — a literal `[::1]` host fails the
  `strpbrk( $host, ':#?[]' )` check, and an AAAA-only hostname fails
  `gethostbyname()` — so the effect is refusal, not bypass. We do not add IPv6
  support on top; if core gains range checks we inherit them.

The scheme allowlist is `https` and `http`. `https` alone would be cleaner, but
plenty of internal asset hosts are plain HTTP and the IP-range check, not the
scheme, is what carries the security here.

### 3. The file type is decided by the bytes, never by the caller.

The declared URL, its extension, and the response `Content-Type` are all treated
as untrusted hints. The stored type comes from `wp_check_filetype_and_ext()`
against the downloaded file, which for images calls `wp_get_image_mime()` and
reads the actual magic bytes.

The allowlist is **raster images only**: JPEG, PNG, GIF, WebP, AVIF. Nothing
else, and specifically:

- **No SVG.** An SVG is an XML document that can carry script, served from the
  site's origin. WordPress excludes it from core uploads for this reason and so
  do we.
- **No PDF, no video, no audio, no archives.** Not because they are unsafe in
  themselves, but because the reason this ability exists is images. A wider
  allowlist would need its own argument, and does not have one yet.

If the sniffed type is not in the allowlist the upload is refused and the
temporary file is deleted, whether or not the extension looked fine. A caller
cannot widen this: the allowlist is not an input parameter.

A byte ceiling applies before and after the fetch — the declared
`Content-Length` is checked when present, and the downloaded size is checked
regardless, because `Content-Length` is a claim.

### 4. Retries converge, via the existing idempotency store.

`create-media` takes a required `idempotency_key` and resolves it through the
same `IdempotencyStore` that `create-draft` uses, scoped per user. A repeated
call with the same key returns the attachment the first call created and
performs no fetch and no write.

This is required, not optional. `create-draft` made it optional and could afford
to: a duplicate draft is visible and free to delete. A duplicate upload consumes
storage, generates every registered image size again, and is invisible until
someone opens the media library. Given a transport that has been observed
returning 504 after doing the work, an agent that cannot safely retry is an
agent that either duplicates or gives up.

### 5. Its own flag, its own capability, and it never publishes into content.

Registration requires media reads, `wpcb_writes_enabled`, **and** a separate
`wpcb_media_uploads_enabled`, which is off by default and distinct from
`wpcb_media_writes_enabled`. Assigning an existing image and importing a new
file from the internet are different grants, and an operator who allowed the
first has not allowed the second.

Execution requires the new `wpcb_upload_media` plugin capability **and** native
`upload_files`. The plugin capability is separate from `wpcb_edit_content`
because an integration principal that may edit text is not thereby a principal
that may put files on the server.

`create-media` only creates the attachment. It does not attach it to a post, set
it as a featured image, or insert it into content. Combining upload with
placement would mean a single call that both fetches remote bytes and publishes
them into a page; keeping them separate means the placement step still runs
through `update-featured-image`'s own policy, capability, and version-token
checks.

### 6. Annotations: not destructive, not idempotent-by-nature.

`readonly: false`, `destructive: false`, `idempotent: false`.

Per ADR 0028, destructive means the operation can lose content the client did
not supply. Creating an attachment loses nothing. It is annotated
non-idempotent because the *operation* is not — replaying it without a key would
create a second attachment — even though decision 4 makes any properly-formed
call safe to retry. Annotating it `idempotent: true` would tell a client that
retrying blind is safe, and blind retries are exactly what the key exists to
prevent.

## Consequences

- Bringing an image in requires it to be reachable at a URL. An operator with
  local-only files still uses wp-admin, until and unless an inline `data` field
  is added.
- The site makes outbound requests on caller instruction. That is inherent to
  this feature; the flag being off by default is what keeps sites that do not
  want it from having it.
- Uploaded images keep their EXIF, including GPS coordinates where present.
  WordPress does not strip it and neither do we; stripping would be a separate
  decision with its own trade-off (it also discards orientation and colour
  profile). Recorded as a known property, not an oversight.
- `wpcb_upload_media` is a new capability, so it needs a schema-version bump to
  reach existing installs through `maybe_upgrade()`. An install that never
  re-activates never grants it, and the ability then refuses — which is the
  correct failure direction.

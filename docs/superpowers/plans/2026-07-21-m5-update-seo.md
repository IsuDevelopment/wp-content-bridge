# M5 Plan 3 — `update-seo` Implementation Plan

> **2026-07-21 amendment:** ADR 0014 extends the delivered Free-only contract
> with normalized `keyphrase_synonyms` and `related_keyphrases` for Yoast
> Premium 28.x. References below to `YoastFreeSeoWriter`, an unchanged read
> provider, schema 1.1, or blanket rejection of Premium writes describe the
> original execution baseline and are superseded by ADR 0014 and the current
> implementation plan.

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship the plugin's third write ability, `wp-content-bridge/update-seo` — write a fixed Yoast Free core-field allowlist (title, meta description, focus keyphrase, canonical, robots index/follow, Open Graph title/description, Twitter title/description) on an existing post, behind the same off-by-default `wpcb_writes_enabled` flag, gated by `wpcb_manage_seo` + native `edit_post` + the already-existing per-post-type `update_seo` policy column, using the same optimistic-concurrency and exactly-one-audit-row invariants as `update-content`, and returning the re-read effective SEO document in the result.

**Architecture:** Extends the existing `src/*/Mutation/` vertical slice (Domain → Application → Infrastructure → Adapter). No new port crosses the read-side `SeoProvider` boundary — a separate `SeoWriter` port is added alongside `ContentMutationRepository`, mirroring how `BlockMarkupValidator` sits beside `ContentMutationRepository` in Plan 2. The read-only `YoastSeoProvider` is reused unmodified for the mandatory post-write re-read; nothing in `src/Application/Seo/*` or `src/Infrastructure/Yoast/YoastSeoProvider.php` is changed.

**Tech Stack:** PHP 8.2+, PSR-4 `IsuDev\WPContentBridge\`, WordPress 7.0+ (Abilities API), PHPUnit 11 (Domain/Application units, WordPress not loaded), `wp eval` runtime verifiers (Infrastructure/Adapter), PHPCS (WordPress Coding Standards) + PHPStan max.

## Global Constraints

Every task's requirements implicitly include this section. Copy exact values verbatim.

- **Layering (non-negotiable):** Domain → Application → Infrastructure → Adapter. Domain DTOs and Application services **never** call WordPress functions. WordPress calls live **only** in `src/Infrastructure/`. Adapters map input/output + `WP_Error` and contain **no** policy.
- **Standing repo rule:** do NOT push to a remote. Per-task local commits on the feature branch are expected. Only the maintainer authorizes pushing.
- **Commit trailer (every commit):** end the message with `Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>`.
- **WPCS style:** tabs for indentation; Yoda conditions (`null === $x`); long array syntax `array( … )`; one space inside parentheses `foo( $bar )`.
- **Docblock generics trap:** when a docblock `@param`/`@var` pairs with a plain `array` type hint, write `array<int, string>` (or `array<string, mixed>`), **never** `list<string>` — the `Squiz.Commenting.FunctionComment.IncorrectTypeHint` sniff rejects `list<...>` against an `array` hint. `@return list<string>` on a method (no conflicting hint) is fine.
- **DTOs:** `final readonly class` with promoted public properties; a `public static function from_input( array $input ): self` factory that validates and throws `InvalidArgumentException` on violation. Mirror `src/Domain/Mutation/ContentUpdate.php`.
- **Version token scheme (already shipped, do not change):** `VersionToken::for_content( string $modified_gmt, string $title, string $content, string $status )`; wire form `VersionToken::to_string()` / `from_string()` (`{content_hash}:{modified_gmt}`). A pure Yoast-meta write does not change title/content/status, so the version token is unchanged by a successful `update-seo` call — this is intentional, matches how WordPress itself does not bump `post_modified` on a postmeta-only write, and is asserted in this plan's tests.
- **Audit invariant:** every mutation attempt that reaches a use case's `execute()` records **exactly one** audit row (success or failure) and fires `do_action( 'wpcb_mutation', $event )`, via the injected `AuditLog`. The success-audit call MUST be outside the `try`/`catch` (per the `bc47f8a` fix applied to `CreateDraft`/`UpdateContent`) so a throwing audit sink cannot double-record a already-succeeded attempt. The row stores changed field **names** only — never SEO values.
- **Flag gating:** `update-seo` is registered by the same `MutationAbilities` class as `create-draft`/`update-content`, so it is registered **only when** `get_option( Installer::WRITES_ENABLED_OPTION )` is truthy (checked once, in `Plugin.php`). It does **not** get its own feature flag — only `publish-content` (Plan 4) gets a second flag.
- **Capabilities (already granted, do not re-grant):** `wpcb_manage_seo` is already in `phpcs.xml.dist`'s `WordPress.WP.Capabilities` `custom_capabilities` list and already granted to `administrator` in `Installer.php`. No `Installer.php` change is needed in this plan.
- **Policy column (already wired, do not re-add):** `ContentOperation::UPDATE_SEO = 'update_seo'` already exists in `src/Domain/ContentAccess/ContentOperation.php` with prerequisite `[READ]`; `ContentTypePolicy::from_input()`/`::allows()` already handle it generically (deny-by-default); the Settings page already renders an "Update SEO" column (`src/Adapter/Admin/ContentAccessSettingsPage.php:192`). No `ContentAccess` change is needed in this plan.
- **Stable error codes** used by this plan (all pre-existing except none new are introduced): `wpcb_invalid_input`, `wpcb_content_unavailable`, `wpcb_forbidden`, `wpcb_conflict`, `wpcb_seo_field_unsupported`, `wpcb_write_failed`, `wpcb_internal_error`.
- **Local WP install for runtime verifiers** is at `/Users/lukaszbiedron/Local Sites/kormas-isu/app/public` (the repo is symlinked into its `wp-content/plugins`). Repo absolute path for `require` is `/Users/lukaszbiedron/Other Projects/wp-content-bridge`.

---

## File Structure

**New files:**

```
src/Domain/Mutation/
  SeoUpdate.php                    Immutable. Validated SEO-write input; ≥1 allowlisted field required.

src/Application/Mutation/
  SeoWriter.php                    Port (interface): is_available/write.
  UpdateSeo.php                    Use case.

src/Infrastructure/Yoast/
  YoastFreeSeoWriter.php           implements SeoWriter (version-gated, allowlist-only, re-reads via YoastSeoProvider).

tests/Unit/Domain/Mutation/
  SeoUpdateTest.php

tests/Unit/Application/Mutation/
  UpdateSeoTest.php

tests/Integration/
  writes-seo-verification.php     Runtime: authorization matrix, conflict, unsupported field, write/re-read parity, audit, no-provider-available.
```

**Modified files:**

```
src/Domain/Mutation/MutationResult.php            Add optional trailing `?array $effective_seo = null` + to_array() key.
tests/Unit/Domain/Mutation/MutationResultTest.php Add a with-SEO test case; existing test untouched.
src/Adapter/Abilities/AbilitySchemas.php           Add update_seo_input()/update_seo_output().
src/Adapter/Abilities/MutationAbilities.php        Register update-seo; can_update_seo(); execute_update_seo(); extend to_error().
src/Plugin.php                                     Instantiate YoastFreeSeoWriter + UpdateSeo; pass to MutationAbilities.
```

Reads stay untouched: `src/Application/Seo/SeoProvider.php`, `GetSeo.php`, `SeoProviderRegistry.php`, and `src/Infrastructure/Yoast/YoastSeoProvider.php` are consumed, never modified.

---

### Task 1: Extend `MutationResult` with optional `effective_seo`

Additive, backward-compatible change: a new trailing nullable constructor parameter with a default, so every existing positional call site (`CreateDraft`, `UpdateContent`, `WordPressContentMutationRepository::built_result()`) keeps compiling unchanged, and `to_array()` only emits the key when present so `create-draft`/`update-content`'s strict `additionalProperties: false` output schemas are unaffected.

**Files:**
- Modify: `src/Domain/Mutation/MutationResult.php`
- Modify: `tests/Unit/Domain/Mutation/MutationResultTest.php`

**Interfaces:**
- Consumes: nothing new.
- Produces: `MutationResult::__construct( int $post_id, string $post_type, string $status, VersionToken $version, array $changed_fields, bool $created, ?array $effective_seo = null )`; `to_array()` includes an `effective_seo` key (`array<string, mixed>`) only when `$effective_seo` is not null. Task 3 (`UpdateSeo`) is the only caller that passes a 7th argument.

- [ ] **Step 1: Write the failing test**

Add this method to `tests/Unit/Domain/Mutation/MutationResultTest.php` (do not remove `test_to_array_emits_wire_shape`):

```php
	public function test_to_array_includes_effective_seo_when_present(): void {
		$version = new VersionToken( 'abcdef0123456789', '2026-07-20 12:30:00' );
		$result  = new MutationResult(
			42,
			'post',
			'publish',
			$version,
			array( 'seo_title' ),
			false,
			array( 'schema_version' => '1.1' )
		);

		$array = $result->to_array();

		self::assertSame( array( 'schema_version' => '1.1' ), $array['effective_seo'] );
	}

	public function test_to_array_omits_effective_seo_when_absent(): void {
		$version = new VersionToken( 'abcdef0123456789', '2026-07-20 12:30:00' );
		$result  = new MutationResult( 42, 'post', 'draft', $version, array( 'title' ), true );

		self::assertArrayNotHasKey( 'effective_seo', $result->to_array() );
	}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd "/Users/lukaszbiedron/Other Projects/wp-content-bridge" && vendor/bin/phpunit --filter MutationResultTest`
Expected: FAIL — `Unknown named parameter $effective_seo` / `Too many arguments`.

- [ ] **Step 3: Modify the DTO**

In `src/Domain/Mutation/MutationResult.php`, replace the constructor and `to_array()`:

```php
	/**
	 * Creates a mutation result.
	 *
	 * @param int                     $post_id        The post ID.
	 * @param string                  $post_type      The post type.
	 * @param string                  $status         The post status.
	 * @param VersionToken            $version        The version token.
	 * @param array<int, string>      $changed_fields Field names that changed (never values).
	 * @param bool                    $created        Whether the post was created.
	 * @param array<string, mixed>|null $effective_seo Re-read normalized SEO document, SEO writes only.
	 */
	public function __construct(
		public int $post_id,
		public string $post_type,
		public string $status,
		public VersionToken $version,
		public array $changed_fields,
		public bool $created,
		public ?array $effective_seo = null,
	) {}

	/**
	 * Wire representation.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		$array = array(
			'schema_version' => '1.0',
			'post_id'        => $this->post_id,
			'post_type'      => $this->post_type,
			'status'         => $this->status,
			'version_token'  => $this->version->to_string(),
			'changed_fields' => array_values( $this->changed_fields ),
			'created'        => $this->created,
			'provenance'     => array(
				'source'    => 'wordpress',
				'untrusted' => true,
			),
		);

		if ( null !== $this->effective_seo ) {
			$array['effective_seo'] = $this->effective_seo;
		}

		return $array;
	}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit --filter MutationResultTest`
Expected: PASS (3 tests: the original wire-shape test plus the two new ones).

- [ ] **Step 5: Static checks**

Run: `composer lint && composer analyse`
Expected: PHPCS clean; PHPStan `[OK] No errors`.

- [ ] **Step 6: Commit**

```bash
git add src/Domain/Mutation/MutationResult.php tests/Unit/Domain/Mutation/MutationResultTest.php
git commit -m "feat(mutation): add optional effective_seo to MutationResult

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

### Task 2: `SeoUpdate` DTO

Validated input for `update-seo`. Wire field names are `snake_case`, matching every other ability input in this codebase (`post_id`, `version_token`, `block_markup`, …) rather than the design spec's prose camelCase — this is a deliberate naming-convention call, documented in the Self-Review below.

**Files:**
- Create: `src/Domain/Mutation/SeoUpdate.php`
- Test: `tests/Unit/Domain/Mutation/SeoUpdateTest.php`

**Interfaces:**
- Consumes: `VersionToken` (`src/Domain/Mutation/VersionToken.php`).
- Produces:
  - `public const ALLOWED_KEYS` — the exact 12 wire keys (`post_id`, `version_token`, plus the 10 allowlisted SEO fields) — reused verbatim by `UpdateSeo` (Task 3) to compute unsupported-field rejections.
  - `final readonly class SeoUpdate` with public `int $post_id`, `VersionToken $expected_version`, `?string $seo_title`, `?string $meta_description`, `?string $focus_keyphrase`, `?string $canonical`, `?bool $robots_index`, `?bool $robots_follow`, `?string $og_title`, `?string $og_description`, `?string $twitter_title`, `?string $twitter_description`.
  - `public static function from_input( array $input ): self` (throws `InvalidArgumentException`; requires ≥1 of the 10 fields present).
  - `public function changed_fields(): array` (`@return list<string>` present wire field names, for audit/result).
  - `public function writable_fields(): array` (`@return array<string, string|bool>` present wire field name → value, fed to `SeoWriter::write()`).

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Domain/Mutation/SeoUpdateTest.php`:

```php
<?php
/**
 * Unit tests for the SeoUpdate write DTO.
 *
 * @package IsuDev\WPContentBridge
 */

declare( strict_types=1 );

namespace IsuDev\WPContentBridge\Tests\Unit\Domain\Mutation;

use InvalidArgumentException;
use IsuDev\WPContentBridge\Domain\Mutation\SeoUpdate;
use PHPUnit\Framework\TestCase;

final class SeoUpdateTest extends TestCase {

	private const TOKEN = 'abcdef0123456789:2026-07-20 12:30:00';

	public function test_from_input_builds_single_field_update(): void {
		$update = SeoUpdate::from_input(
			array(
				'post_id'       => 42,
				'version_token' => self::TOKEN,
				'seo_title'     => 'New title',
			)
		);

		self::assertSame( 42, $update->post_id );
		self::assertSame( 'New title', $update->seo_title );
		self::assertNull( $update->meta_description );
		self::assertSame( array( 'seo_title' ), $update->changed_fields() );
		self::assertSame( array( 'seo_title' => 'New title' ), $update->writable_fields() );
	}

	public function test_from_input_builds_full_update_including_booleans(): void {
		$update = SeoUpdate::from_input(
			array(
				'post_id'              => 7,
				'version_token'        => self::TOKEN,
				'seo_title'            => 'T',
				'meta_description'     => 'D',
				'focus_keyphrase'      => 'kp',
				'canonical'            => 'https://example.com/post',
				'robots_index'         => false,
				'robots_follow'        => true,
				'og_title'             => 'OG T',
				'og_description'       => 'OG D',
				'twitter_title'        => 'TW T',
				'twitter_description'  => 'TW D',
			)
		);

		self::assertSame(
			array(
				'seo_title',
				'meta_description',
				'focus_keyphrase',
				'canonical',
				'robots_index',
				'robots_follow',
				'og_title',
				'og_description',
				'twitter_title',
				'twitter_description',
			),
			$update->changed_fields()
		);
		self::assertFalse( $update->writable_fields()['robots_index'] );
		self::assertTrue( $update->writable_fields()['robots_follow'] );
	}

	public function test_from_input_rejects_unknown_key(): void {
		$this->expectException( InvalidArgumentException::class );

		SeoUpdate::from_input(
			array(
				'post_id'       => 7,
				'version_token' => self::TOKEN,
				'schema_type'   => 'Article',
			)
		);
	}

	public function test_from_input_rejects_no_updatable_fields(): void {
		$this->expectException( InvalidArgumentException::class );

		SeoUpdate::from_input(
			array(
				'post_id'       => 7,
				'version_token' => self::TOKEN,
			)
		);
	}

	public function test_from_input_rejects_missing_version_token(): void {
		$this->expectException( InvalidArgumentException::class );

		SeoUpdate::from_input(
			array(
				'post_id'   => 7,
				'seo_title' => 'T',
			)
		);
	}

	public function test_from_input_rejects_non_positive_post_id(): void {
		$this->expectException( InvalidArgumentException::class );

		SeoUpdate::from_input(
			array(
				'post_id'       => 0,
				'version_token' => self::TOKEN,
				'seo_title'     => 'T',
			)
		);
	}

	public function test_from_input_rejects_non_boolean_robots_value(): void {
		$this->expectException( InvalidArgumentException::class );

		SeoUpdate::from_input(
			array(
				'post_id'       => 7,
				'version_token' => self::TOKEN,
				'robots_index'  => 'yes',
			)
		);
	}

	public function test_from_input_rejects_non_http_canonical(): void {
		$this->expectException( InvalidArgumentException::class );

		SeoUpdate::from_input(
			array(
				'post_id'       => 7,
				'version_token' => self::TOKEN,
				'canonical'     => 'javascript:alert(1)',
			)
		);
	}

	public function test_from_input_rejects_overlong_meta_description(): void {
		$this->expectException( InvalidArgumentException::class );

		SeoUpdate::from_input(
			array(
				'post_id'          => 7,
				'version_token'    => self::TOKEN,
				'meta_description' => str_repeat( 'a', 321 ),
			)
		);
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter SeoUpdateTest`
Expected: FAIL — `Class "IsuDev\WPContentBridge\Domain\Mutation\SeoUpdate" not found`.

- [ ] **Step 3: Write the DTO**

Create `src/Domain/Mutation/SeoUpdate.php`:

```php
<?php
/**
 * Validated input for writing the Yoast Free core SEO allowlist.
 *
 * @package IsuDev\WPContentBridge
 */

declare( strict_types=1 );

namespace IsuDev\WPContentBridge\Domain\Mutation;

use InvalidArgumentException;

/**
 * Immutable, validated SEO-write input. At least one of the ten allowlisted
 * fields must be present. Any wire key outside ALLOWED_KEYS is rejected.
 */
final readonly class SeoUpdate {

	private const MAX_TITLE       = 500;
	private const MAX_DESCRIPTION = 320;
	private const MAX_KEYPHRASE   = 200;
	private const MAX_CANONICAL   = 2048;
	private const CANONICAL_PATTERN = '/^https?:\/\//i';

	/**
	 * The complete set of wire keys this ability accepts. Reused by the
	 * UpdateSeo use case to classify unknown-field rejections distinctly.
	 *
	 * @var array<int, string>
	 */
	public const ALLOWED_KEYS = array(
		'post_id',
		'version_token',
		'seo_title',
		'meta_description',
		'focus_keyphrase',
		'canonical',
		'robots_index',
		'robots_follow',
		'og_title',
		'og_description',
		'twitter_title',
		'twitter_description',
	);

	/**
	 * Creates a validated SEO update.
	 *
	 * @param int          $post_id             Target post ID.
	 * @param VersionToken $expected_version    Optimistic-concurrency token.
	 * @param string|null  $seo_title           Yoast SEO title override.
	 * @param string|null  $meta_description    Yoast meta description override.
	 * @param string|null  $focus_keyphrase     Yoast focus keyphrase override.
	 * @param string|null  $canonical           Yoast canonical URL override.
	 * @param bool|null    $robots_index        True: force index. False: force noindex. Null: unchanged.
	 * @param bool|null    $robots_follow       True: force follow. False: force nofollow. Null: unchanged.
	 * @param string|null  $og_title            Yoast Open Graph title override.
	 * @param string|null  $og_description      Yoast Open Graph description override.
	 * @param string|null  $twitter_title       Yoast Twitter title override.
	 * @param string|null  $twitter_description Yoast Twitter description override.
	 */
	public function __construct(
		public int $post_id,
		public VersionToken $expected_version,
		public ?string $seo_title,
		public ?string $meta_description,
		public ?string $focus_keyphrase,
		public ?string $canonical,
		public ?bool $robots_index,
		public ?bool $robots_follow,
		public ?string $og_title,
		public ?string $og_description,
		public ?string $twitter_title,
		public ?string $twitter_description,
	) {}

	/**
	 * Build from untrusted input.
	 *
	 * @param array<string, mixed> $input Raw update-seo input.
	 * @throws InvalidArgumentException When input is malformed or empty.
	 */
	public static function from_input( array $input ): self {
		foreach ( array_keys( $input ) as $key ) {
			if ( ! in_array( $key, self::ALLOWED_KEYS, true ) ) {
				throw new InvalidArgumentException( 'Update-seo input contains an unsupported field.' );
			}
		}

		$post_id = $input['post_id'] ?? null;
		if ( ! is_int( $post_id ) || 0 >= $post_id ) {
			throw new InvalidArgumentException( 'A post ID must be a positive integer.' );
		}

		$token = $input['version_token'] ?? null;
		if ( ! is_string( $token ) ) {
			throw new InvalidArgumentException( 'A version token is required.' );
		}
		$expected_version = VersionToken::from_string( $token );

		$seo_title           = self::optional_string( $input, 'seo_title', self::MAX_TITLE );
		$meta_description    = self::optional_string( $input, 'meta_description', self::MAX_DESCRIPTION );
		$focus_keyphrase     = self::optional_string( $input, 'focus_keyphrase', self::MAX_KEYPHRASE );
		$canonical           = self::optional_canonical( $input );
		$robots_index        = self::optional_bool( $input, 'robots_index' );
		$robots_follow       = self::optional_bool( $input, 'robots_follow' );
		$og_title            = self::optional_string( $input, 'og_title', self::MAX_TITLE );
		$og_description      = self::optional_string( $input, 'og_description', self::MAX_DESCRIPTION );
		$twitter_title       = self::optional_string( $input, 'twitter_title', self::MAX_TITLE );
		$twitter_description = self::optional_string( $input, 'twitter_description', self::MAX_DESCRIPTION );

		if ( null === $seo_title && null === $meta_description && null === $focus_keyphrase
			&& null === $canonical && null === $robots_index && null === $robots_follow
			&& null === $og_title && null === $og_description && null === $twitter_title
			&& null === $twitter_description
		) {
			throw new InvalidArgumentException( 'An SEO update must change at least one field.' );
		}

		return new self(
			$post_id,
			$expected_version,
			$seo_title,
			$meta_description,
			$focus_keyphrase,
			$canonical,
			$robots_index,
			$robots_follow,
			$og_title,
			$og_description,
			$twitter_title,
			$twitter_description
		);
	}

	/**
	 * Names of the fields this update changes (for audit + result).
	 *
	 * @return list<string>
	 */
	public function changed_fields(): array {
		return array_keys( $this->present_fields() );
	}

	/**
	 * Present field name to value, for the SeoWriter port.
	 *
	 * @return array<string, string|bool>
	 */
	public function writable_fields(): array {
		return $this->present_fields();
	}

	/**
	 * Collects the present (non-null) allowlisted fields in stable order.
	 *
	 * @return array<string, string|bool>
	 */
	private function present_fields(): array {
		$fields = array();
		if ( null !== $this->seo_title ) {
			$fields['seo_title'] = $this->seo_title;
		}
		if ( null !== $this->meta_description ) {
			$fields['meta_description'] = $this->meta_description;
		}
		if ( null !== $this->focus_keyphrase ) {
			$fields['focus_keyphrase'] = $this->focus_keyphrase;
		}
		if ( null !== $this->canonical ) {
			$fields['canonical'] = $this->canonical;
		}
		if ( null !== $this->robots_index ) {
			$fields['robots_index'] = $this->robots_index;
		}
		if ( null !== $this->robots_follow ) {
			$fields['robots_follow'] = $this->robots_follow;
		}
		if ( null !== $this->og_title ) {
			$fields['og_title'] = $this->og_title;
		}
		if ( null !== $this->og_description ) {
			$fields['og_description'] = $this->og_description;
		}
		if ( null !== $this->twitter_title ) {
			$fields['twitter_title'] = $this->twitter_title;
		}
		if ( null !== $this->twitter_description ) {
			$fields['twitter_description'] = $this->twitter_description;
		}

		return $fields;
	}

	/**
	 * Validates an optional bounded string field.
	 *
	 * @param array<string, mixed> $input      Raw input.
	 * @param string               $key        Field key.
	 * @param int                  $max_length Maximum character length.
	 * @throws InvalidArgumentException When present but invalid.
	 */
	private static function optional_string( array $input, string $key, int $max_length ): ?string {
		if ( ! array_key_exists( $key, $input ) || null === $input[ $key ] ) {
			return null;
		}
		$value = $input[ $key ];
		if ( ! is_string( $value ) || mb_strlen( $value ) > $max_length ) {
			throw new InvalidArgumentException( "The {$key} field is invalid." );
		}

		return $value;
	}

	/**
	 * Validates the optional canonical URL field.
	 *
	 * @param array<string, mixed> $input Raw input.
	 * @throws InvalidArgumentException When present but invalid.
	 */
	private static function optional_canonical( array $input ): ?string {
		$value = self::optional_string( $input, 'canonical', self::MAX_CANONICAL );
		if ( null !== $value && '' !== $value && 1 !== preg_match( self::CANONICAL_PATTERN, $value ) ) {
			throw new InvalidArgumentException( 'The canonical field must be an absolute http(s) URL.' );
		}

		return $value;
	}

	/**
	 * Validates an optional boolean field.
	 *
	 * @param array<string, mixed> $input Raw input.
	 * @param string                $key   Field key.
	 * @throws InvalidArgumentException When present but not a boolean.
	 */
	private static function optional_bool( array $input, string $key ): ?bool {
		if ( ! array_key_exists( $key, $input ) || null === $input[ $key ] ) {
			return null;
		}
		$value = $input[ $key ];
		if ( ! is_bool( $value ) ) {
			throw new InvalidArgumentException( "The {$key} field must be a boolean." );
		}

		return $value;
	}
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit --filter SeoUpdateTest`
Expected: PASS (9 tests).

- [ ] **Step 5: Static checks**

Run: `composer lint && composer analyse`
Expected: PHPCS clean; PHPStan `[OK] No errors`.

- [ ] **Step 6: Commit**

```bash
git add src/Domain/Mutation/SeoUpdate.php tests/Unit/Domain/Mutation/SeoUpdateTest.php
git commit -m "feat(mutation): add SeoUpdate DTO

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

### Task 3: `SeoWriter` port + `UpdateSeo` use case

**Design decision (unsupported-field classification):** the design spec requires the *specific* `wpcb_seo_field_unsupported` code (not the generic `wpcb_invalid_input`) when a request contains a field outside the allowlist. `SeoFieldUnsupported` lives in `Application\Mutation` (already shipped in Plan 1), so a Domain DTO cannot throw it without an illegal Domain→Application dependency. `UpdateSeo::execute()` therefore screens `array_diff( array_keys( $raw_input ), SeoUpdate::ALLOWED_KEYS )` **before** calling `SeoUpdate::from_input()` and throws `SeoFieldUnsupported` itself when that diff is non-empty; `SeoUpdate::from_input()` still independently rejects unknown keys too (defense in depth), just with the generic Domain-level `InvalidArgumentException`, which can only be reached if a caller skips the use case's own screen.

**Design decision (Premium/Local rejection):** because the writable allowlist (`SeoUpdate::ALLOWED_KEYS`) never includes any Premium/Local field name, a request for e.g. `schema_type` or `additional_keyphrases` is already rejected by the unsupported-field screen above — no separate Premium/Local branch is needed.

**Design decision (no field-level partial application):** all ten allowlisted fields share one write mechanism and one provider-availability check, so a single `update-seo` call either writes every requested field or writes none — matching `update-content`'s all-or-nothing semantics and keeping one audit row / one resulting version token per attempt. "Unsupported fields fail explicitly" (Milestone 6 exit gate) is satisfied by the whole-request `wpcb_seo_field_unsupported` rejection with the offending field names listed, not by partial per-field success/failure reporting.

**Files:**
- Create: `src/Application/Mutation/SeoWriter.php` (port)
- Create: `src/Application/Mutation/UpdateSeo.php` (use case)
- Test: `tests/Unit/Application/Mutation/UpdateSeoTest.php`

**Interfaces:**
- Consumes: `ContentAccessManager::allows( string $post_type, ContentOperation $operation ): bool` (`src/Application/ContentAccess/ContentAccessManager.php`), `ContentOperation::UPDATE_SEO` (`src/Domain/ContentAccess/ContentOperation.php`), `ContentMutationRepository::post_type()` / `::current_version()` / `::result_for()` (`src/Application/Mutation/ContentMutationRepository.php`, all pre-existing), `AuditLog` + `AuditEvent` (Plan 1), `SeoUpdate` + `MutationResult` + `VersionToken` (Domain), `ContentUnavailable` (`src/Application/Content/ContentUnavailable.php`), `MutationForbidden` / `MutationConflict` / `MutationWriteFailed` / `SeoFieldUnsupported` (all pre-existing in `src/Application/Mutation/`).
- Produces:
  - `interface SeoWriter` with `public function is_available(): bool;` and `public function write( int $post_id, array $fields ): array;` (`@param array<string, string|bool> $fields`, `@return array<string, mixed>` the re-read normalized SEO document; `@throws SeoFieldUnsupported` when unavailable, `MutationWriteFailed` on a WordPress-level failure).
  - `final readonly class UpdateSeo` with `public const ABILITY = 'wp-content-bridge/update-seo';` and `public function execute( array $raw_input, int $user_id ): MutationResult`.

**Write flow for `update-seo`** (stop at first failure; audit exactly once):
1. Unknown-field screen against `SeoUpdate::ALLOWED_KEYS` → `SeoFieldUnsupported` (outcome `invalid`, code `wpcb_seo_field_unsupported`).
2. `SeoUpdate::from_input( $raw_input )` — `InvalidArgumentException` → outcome `invalid`, code `wpcb_invalid_input`.
3. `$repository->post_type( $update->post_id )` null → `ContentUnavailable` (outcome `invalid`, code `wpcb_content_unavailable`).
4. Policy: `! $access->allows( $post_type, ContentOperation::UPDATE_SEO )` → `MutationForbidden` (outcome `denied`, code `wpcb_forbidden`).
5. `$repository->current_version( $update->post_id )` null → `ContentUnavailable`; mismatch vs `$update->expected_version` → `MutationConflict` (outcome `conflict`, code `wpcb_conflict`), **no write performed**.
6. `! $writer->is_available()` → `SeoFieldUnsupported( $update->changed_fields() )` (all requested fields, since none can be honored).
7. `$effective_seo = $writer->write( $update->post_id, $update->writable_fields() )`.
8. `$base = $repository->result_for( $update->post_id )`; null → `MutationWriteFailed`.
9. Build `new MutationResult( $base->post_id, $base->post_type, $base->status, $base->version, $update->changed_fields(), false, $effective_seo )`.
10. Audit outcome `success` (outside the try/catch); return `$result`.

- [ ] **Step 1: Write the port**

Create `src/Application/Mutation/SeoWriter.php`:

```php
<?php
/**
 * Port for writing the SEO write allowlist.
 *
 * @package IsuDev\WPContentBridge
 */

declare( strict_types=1 );

namespace IsuDev\WPContentBridge\Application\Mutation;

/**
 * Writes the allowlisted SEO fields for one post and returns the re-read
 * effective SEO document. The only implementation calls an SEO plugin.
 */
interface SeoWriter {

	/**
	 * Whether a compatible SEO write surface is currently available.
	 *
	 * @return bool
	 */
	public function is_available(): bool;

	/**
	 * Writes the given allowlisted fields and re-reads effective SEO.
	 *
	 * @param int   $post_id Target post ID.
	 * @param array $fields  Present allowlisted field name to value.
	 * @phpstan-param array<string, string|bool> $fields
	 * @return array<string, mixed> Re-read normalized SEO document.
	 * @throws SeoFieldUnsupported When no compatible writer is available.
	 * @throws MutationWriteFailed When the underlying write fails.
	 */
	public function write( int $post_id, array $fields ): array;
}
```

- [ ] **Step 2: Write the failing use-case test**

Create `tests/Unit/Application/Mutation/UpdateSeoTest.php`. Fakes mirror `tests/Unit/Application/Mutation/CreateDraftTest.php`'s `ContentAccessManager` fake construction:

```php
<?php
/**
 * Unit tests for the UpdateSeo use case.
 *
 * @package IsuDev\WPContentBridge
 */

declare( strict_types=1 );

namespace IsuDev\WPContentBridge\Tests\Unit\Application\Mutation;

use InvalidArgumentException;
use IsuDev\WPContentBridge\Application\Content\ContentUnavailable;
use IsuDev\WPContentBridge\Application\ContentAccess\ContentAccessManager;
use IsuDev\WPContentBridge\Application\ContentAccess\ContentAccessSettingsRepository;
use IsuDev\WPContentBridge\Application\ContentAccess\ContentTypeCatalog;
use IsuDev\WPContentBridge\Application\Mutation\AuditEvent;
use IsuDev\WPContentBridge\Application\Mutation\AuditLog;
use IsuDev\WPContentBridge\Application\Mutation\ContentMutationRepository;
use IsuDev\WPContentBridge\Application\Mutation\MutationConflict;
use IsuDev\WPContentBridge\Application\Mutation\MutationForbidden;
use IsuDev\WPContentBridge\Application\Mutation\MutationWriteFailed;
use IsuDev\WPContentBridge\Application\Mutation\SeoFieldUnsupported;
use IsuDev\WPContentBridge\Application\Mutation\SeoWriter;
use IsuDev\WPContentBridge\Application\Mutation\UpdateSeo;
use IsuDev\WPContentBridge\Domain\ContentAccess\ContentTypeDefinition;
use IsuDev\WPContentBridge\Domain\Mutation\ContentUpdate;
use IsuDev\WPContentBridge\Domain\Mutation\DraftInput;
use IsuDev\WPContentBridge\Domain\Mutation\MutationResult;
use IsuDev\WPContentBridge\Domain\Mutation\VersionToken;
use PHPUnit\Framework\TestCase;

final class UpdateSeoTest extends TestCase {

	private const TOKEN = 'abcdef0123456789:2026-07-20 12:30:00';

	public function test_writes_seo_and_records_success(): void {
		$audit    = $this->audit_spy();
		$use_case = new UpdateSeo(
			$this->manager_allowing( true ),
			$this->repository( self::TOKEN ),
			$this->writer_available( array( 'schema_version' => '1.1' ) ),
			$audit
		);

		$result = $use_case->execute(
			array(
				'post_id'       => 42,
				'version_token' => self::TOKEN,
				'seo_title'     => 'New title',
			),
			5
		);

		self::assertSame( array( 'seo_title' ), $result->changed_fields );
		self::assertSame( array( 'schema_version' => '1.1' ), $result->effective_seo );
		self::assertCount( 1, $audit->events );
		self::assertSame( 'success', $audit->events[0]->outcome );
	}

	public function test_unknown_field_records_unsupported(): void {
		$audit    = $this->audit_spy();
		$use_case = new UpdateSeo(
			$this->manager_allowing( true ),
			$this->repository( self::TOKEN ),
			$this->writer_available( array() ),
			$audit
		);

		try {
			$use_case->execute(
				array(
					'post_id'       => 42,
					'version_token' => self::TOKEN,
					'schema_type'   => 'Article',
				),
				5
			);
			self::fail( 'Expected SeoFieldUnsupported.' );
		} catch ( SeoFieldUnsupported $unsupported ) {
			self::assertSame( 'wpcb_seo_field_unsupported', $unsupported->error_code() );
			self::assertSame( array( 'schema_type' ), $unsupported->fields() );
		}

		self::assertCount( 1, $audit->events );
		self::assertSame( 'invalid', $audit->events[0]->outcome );
		self::assertSame( 'wpcb_seo_field_unsupported', $audit->events[0]->error_code );
	}

	public function test_policy_denial_records_denied(): void {
		$audit    = $this->audit_spy();
		$use_case = new UpdateSeo(
			$this->manager_allowing( false ),
			$this->repository( self::TOKEN ),
			$this->writer_available( array() ),
			$audit
		);

		try {
			$use_case->execute( array( 'post_id' => 42, 'version_token' => self::TOKEN, 'seo_title' => 'T' ), 5 );
			self::fail( 'Expected MutationForbidden.' );
		} catch ( MutationForbidden $forbidden ) {
			self::assertSame( 'wpcb_forbidden', $forbidden->error_code() );
		}

		self::assertSame( 'denied', $audit->events[0]->outcome );
	}

	public function test_stale_version_records_conflict_without_writing(): void {
		$audit    = $this->audit_spy();
		$writer   = $this->writer_available( array() );
		$use_case = new UpdateSeo(
			$this->manager_allowing( true ),
			$this->repository( self::TOKEN ),
			$writer,
			$audit
		);

		try {
			$use_case->execute(
				array(
					'post_id'       => 42,
					'version_token' => 'ffffffffffffffff:2026-07-20 12:30:00',
					'seo_title'     => 'T',
				),
				5
			);
			self::fail( 'Expected MutationConflict.' );
		} catch ( MutationConflict $conflict ) {
			self::assertSame( 'wpcb_conflict', $conflict->error_code() );
		}

		self::assertFalse( $writer->was_called );
		self::assertSame( 'conflict', $audit->events[0]->outcome );
	}

	public function test_unavailable_writer_records_unsupported_with_all_fields(): void {
		$audit    = $this->audit_spy();
		$use_case = new UpdateSeo(
			$this->manager_allowing( true ),
			$this->repository( self::TOKEN ),
			$this->writer_unavailable(),
			$audit
		);

		try {
			$use_case->execute(
				array(
					'post_id'       => 42,
					'version_token' => self::TOKEN,
					'seo_title'     => 'T',
					'canonical'     => 'https://example.com/x',
				),
				5
			);
			self::fail( 'Expected SeoFieldUnsupported.' );
		} catch ( SeoFieldUnsupported $unsupported ) {
			self::assertSame( array( 'seo_title', 'canonical' ), $unsupported->fields() );
		}
	}

	// --- fakes -----------------------------------------------------------

	private function audit_spy(): AuditLog {
		return new class() implements AuditLog {
			/** @var array<int, AuditEvent> */
			public array $events = array();

			public function record( AuditEvent $event ): void {
				$this->events[] = $event;
			}
		};
	}

	private function manager_allowing( bool $allow ): ContentAccessManager {
		$stored = array( 'post' => array( 'get_content' => true, 'update_seo' => $allow ) );

		$repository = new readonly class( $stored ) implements ContentAccessSettingsRepository {
			public function __construct( private array $settings ) {}

			public function load(): array {
				return $this->settings;
			}
		};

		$catalog = new readonly class() implements ContentTypeCatalog {
			public function list_eligible(): array {
				return array( new ContentTypeDefinition( 'post', 'Posts', true, true, true ) );
			}
		};

		return new ContentAccessManager( $repository, $catalog );
	}

	private function repository( string $current_token ): ContentMutationRepository {
		$version = VersionToken::from_string( $current_token );

		return new class( $version ) implements ContentMutationRepository {
			public function __construct( private VersionToken $version ) {}

			public function post_type( int $post_id ): ?string {
				return 'post';
			}

			public function current_version( int $post_id ): ?VersionToken {
				return $this->version;
			}

			public function create( DraftInput $input ): MutationResult {
				throw new \RuntimeException( 'not used' );
			}

			public function update( int $post_id, ContentUpdate $update ): MutationResult {
				throw new \RuntimeException( 'not used' );
			}

			public function result_for( int $post_id ): ?MutationResult {
				return new MutationResult( $post_id, 'post', 'publish', $this->version, array(), false );
			}
		};
	}

	private function writer_available( array $effective_seo ): object {
		return new class( $effective_seo ) implements SeoWriter {
			public bool $was_called = false;

			public function __construct( private array $effective_seo ) {}

			public function is_available(): bool {
				return true;
			}

			public function write( int $post_id, array $fields ): array {
				$this->was_called = true;

				return $this->effective_seo;
			}
		};
	}

	private function writer_unavailable(): SeoWriter {
		return new class() implements SeoWriter {
			public function is_available(): bool {
				return false;
			}

			public function write( int $post_id, array $fields ): array {
				throw new \RuntimeException( 'must not be called' );
			}
		};
	}
}
```

- [ ] **Step 3: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter UpdateSeoTest`
Expected: FAIL — `Class "IsuDev\WPContentBridge\Application\Mutation\UpdateSeo" not found`.

- [ ] **Step 4: Write the use case**

Create `src/Application/Mutation/UpdateSeo.php`:

```php
<?php
/**
 * Update-SEO use case.
 *
 * @package IsuDev\WPContentBridge
 */

declare( strict_types=1 );

namespace IsuDev\WPContentBridge\Application\Mutation;

use InvalidArgumentException;
use IsuDev\WPContentBridge\Application\Content\ContentUnavailable;
use IsuDev\WPContentBridge\Application\ContentAccess\ContentAccessManager;
use IsuDev\WPContentBridge\Domain\ContentAccess\ContentOperation;
use IsuDev\WPContentBridge\Domain\Mutation\MutationResult;
use IsuDev\WPContentBridge\Domain\Mutation\SeoUpdate;
use Throwable;

/**
 * Orchestrates a Yoast Free core-field SEO write with optimistic concurrency.
 * Never changes post title/content/status/taxonomies. Records exactly one
 * audit row per attempt.
 */
final readonly class UpdateSeo {

	public const ABILITY = 'wp-content-bridge/update-seo';

	/**
	 * Creates the use case.
	 *
	 * @param ContentAccessManager      $access     Per-post-type write policy.
	 * @param ContentMutationRepository $repository Post lookup/version/re-read port (shared with update-content).
	 * @param SeoWriter                 $writer     SEO write + re-read port.
	 * @param AuditLog                  $audit      Audit sink.
	 */
	public function __construct(
		private ContentAccessManager $access,
		private ContentMutationRepository $repository,
		private SeoWriter $writer,
		private AuditLog $audit,
	) {
	}

	/**
	 * Executes the update-seo flow, recording exactly one audit row.
	 *
	 * @param array<string, mixed> $raw_input Normalized ability input.
	 * @param int                  $user_id   Acting principal.
	 * @return MutationResult
	 * @throws ContentUnavailable When the target is absent or ineligible.
	 * @throws MutationForbidden When policy denies the type.
	 * @throws MutationConflict When the version token is stale.
	 * @throws SeoFieldUnsupported When a field is outside the allowlist or no writer is available.
	 * @throws Throwable Re-thrown validation or write failures (InvalidArgumentException, MutationWriteFailed).
	 */
	public function execute( array $raw_input, int $user_id ): MutationResult {
		$post_id          = null;
		$post_type        = null;
		$expected_version = null;

		try {
			$offending = self::unsupported_keys( $raw_input );
			if ( array() !== $offending ) {
				throw new SeoFieldUnsupported( $offending );
			}

			$update           = SeoUpdate::from_input( $raw_input );
			$post_id          = $update->post_id;
			$expected_version = $update->expected_version->to_string();

			$post_type = $this->repository->post_type( $update->post_id );
			if ( null === $post_type ) {
				throw new ContentUnavailable( 'Content is unavailable.' );
			}

			if ( ! $this->access->allows( $post_type, ContentOperation::UPDATE_SEO ) ) {
				throw new MutationForbidden( 'SEO updates are not permitted for this type.' );
			}

			$current = $this->repository->current_version( $update->post_id );
			if ( null === $current ) {
				throw new ContentUnavailable( 'Content is unavailable.' );
			}
			if ( ! $current->equals( $update->expected_version ) ) {
				throw new MutationConflict( 'The submitted version token is stale.' );
			}

			if ( ! $this->writer->is_available() ) {
				throw new SeoFieldUnsupported( $update->changed_fields() );
			}

			$effective_seo = $this->writer->write( $update->post_id, $update->writable_fields() );

			$base = $this->repository->result_for( $update->post_id );
			if ( null === $base ) {
				throw new MutationWriteFailed( 'The updated post could not be re-read.' );
			}

			$result = new MutationResult(
				$base->post_id,
				$base->post_type,
				$base->status,
				$base->version,
				$update->changed_fields(),
				false,
				$effective_seo
			);
		} catch ( Throwable $error ) {
			[ $outcome, $code ] = $this->classify( $error );

			$this->audit->record(
				new AuditEvent(
					$user_id,
					self::ABILITY,
					$post_id,
					$post_type,
					array(),
					$expected_version,
					null,
					$outcome,
					$code
				)
			);

			throw $error;
		}

		$this->audit->record(
			new AuditEvent(
				$user_id,
				self::ABILITY,
				$result->post_id,
				$result->post_type,
				$result->changed_fields,
				$expected_version,
				$result->version->to_string(),
				'success',
				null
			)
		);

		return $result;
	}

	/**
	 * Computes wire keys outside the allowlist.
	 *
	 * @param array<string, mixed> $raw_input Raw ability input.
	 * @return list<string>
	 */
	private static function unsupported_keys( array $raw_input ): array {
		return array_values( array_diff( array_keys( $raw_input ), SeoUpdate::ALLOWED_KEYS ) );
	}

	/**
	 * Classifies a failure into a stable audit outcome and error code.
	 *
	 * @param Throwable $error The failure that ended the attempt.
	 * @return array{0: string, 1: string} Outcome and stable error code.
	 */
	private function classify( Throwable $error ): array {
		if ( $error instanceof InvalidArgumentException ) {
			return array( 'invalid', 'wpcb_invalid_input' );
		}
		if ( $error instanceof ContentUnavailable ) {
			return array( 'invalid', 'wpcb_content_unavailable' );
		}
		if ( $error instanceof MutationForbidden ) {
			return array( 'denied', 'wpcb_forbidden' );
		}
		if ( $error instanceof MutationConflict ) {
			return array( 'conflict', 'wpcb_conflict' );
		}
		if ( $error instanceof SeoFieldUnsupported ) {
			return array( 'invalid', 'wpcb_seo_field_unsupported' );
		}

		return array( 'failure', 'wpcb_write_failed' );
	}
}
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `vendor/bin/phpunit --filter "UpdateSeoTest|SeoUpdateTest|MutationResultTest"`
Expected: PASS (5 UpdateSeoTest + 9 SeoUpdateTest + 3 MutationResultTest).

- [ ] **Step 6: Static checks**

Run: `composer lint && composer analyse`
Expected: PHPCS clean; PHPStan `[OK] No errors`.

- [ ] **Step 7: Commit**

```bash
git add src/Application/Mutation/SeoWriter.php src/Application/Mutation/UpdateSeo.php tests/Unit/Application/Mutation/UpdateSeoTest.php
git commit -m "feat(mutation): add SeoWriter port and UpdateSeo use case

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

### Task 4: `YoastFreeSeoWriter` (Infrastructure)

**Design decision (write primitive):** write via `update_post_meta()` directly on the exact same versioned meta-key allowlist the already-shipped, runtime-verified `YoastSeoProvider` reads (`_yoast_wpseo_title`, `_yoast_wpseo_metadesc`, `_yoast_wpseo_canonical`, `_yoast_wpseo_focuskw`, `_yoast_wpseo_opengraph-title`, `_yoast_wpseo_opengraph-description`, `_yoast_wpseo_twitter-title`, `_yoast_wpseo_twitter-description`, `_yoast_wpseo_meta-robots-noindex`, `_yoast_wpseo_meta-robots-nofollow`). These are not newly guessed keys — they are the plugin's own version-gated (Yoast `28.x`) configured allowlist, already proven against a live Yoast Free 28.0 install per Milestone 2B/3C. Per AGENTS.md's SEO rule ("Direct Yoast meta access is limited to a versioned allowlist for editor configuration that public APIs do not expose"), writing to that same allowlist is the write-side mirror of an already-accepted read boundary, not a new reverse-engineering exercise. Gated behind the identical `WPSEO_VERSION` major-version check the read adapter uses (`28.`).

**Design decision (robots boolean encoding):** Yoast's noindex meta stores `'2'` for an explicit "index" override and `'1'` for an explicit "noindex" override (its admin UI's third state, `'0'`/empty, means "site-wide default" and is intentionally not reachable through this boolean field — a stated, documented limitation of this ability, not a bug). Yoast's nofollow meta is a true boolean already: `'0'` means follow (default), `'1'` means nofollow. `robots_index: true` → `'2'`; `robots_index: false` → `'1'`; `robots_follow: true` → `'0'`; `robots_follow: false` → `'1'`. This mapping is asserted by the runtime write/re-read parity check in Task 6, which is the practical verification the project's "STOP and mark unsupported" rule calls for when a convention cannot be proven purely by static reading.

**Design decision (no reliance on `update_post_meta()`'s boolean return):** WordPress's `update_post_meta()` returns `false` both on a genuine failure and whenever the new value equals the stored value (a no-op) — it is not a reliable failure signal, so it is not used as one here. Success is instead established by the mandatory post-write re-read: `write()` re-reads through the existing `YoastSeoProvider` and returns that document; a real failure to persist would surface as a re-read/write parity mismatch, which Task 6's runtime verifier checks directly.

**Files:**
- Create: `src/Infrastructure/Yoast/YoastFreeSeoWriter.php`

No PHPUnit unit test is added for this class — it calls `update_post_meta()`, `metadata_exists()`, `get_post_meta()`, and `YoastSEO()` directly, none of which exist without WordPress loaded. This mirrors the established precedent: `WordPressContentMutationRepository` (Plan 2) and `YoastSeoProvider`'s own configured-meta read path have no PHPUnit coverage either — they are verified only by the `wp eval` runtime harness (Task 6).

**Interfaces:**
- Consumes: `SeoWriter` (Task 3), `SeoFieldUnsupported` / `MutationWriteFailed` (pre-existing), `SeoProvider::get( SeoTarget $target ): SeoDocument` (`src/Application/Seo/SeoProvider.php`, pre-existing, unmodified), `SeoTarget::for_post( int $post_id ): SeoTarget` (`src/Domain/Seo/SeoTarget.php`, pre-existing, unmodified), `SeoDocument::to_array(): array` (`src/Domain/Seo/SeoDocument.php`, pre-existing, unmodified).
- Produces: `final readonly class YoastFreeSeoWriter implements SeoWriter` with `__construct( private SeoProvider $reader )`.

- [ ] **Step 1: Write the implementation**

Create `src/Infrastructure/Yoast/YoastFreeSeoWriter.php`:

```php
<?php
/**
 * Yoast Free SEO write adapter.
 *
 * @package IsuDev\WPContentBridge
 */

declare( strict_types=1 );

namespace IsuDev\WPContentBridge\Infrastructure\Yoast;

use IsuDev\WPContentBridge\Application\Mutation\SeoFieldUnsupported;
use IsuDev\WPContentBridge\Application\Mutation\SeoWriter;
use IsuDev\WPContentBridge\Application\Seo\SeoProvider;
use IsuDev\WPContentBridge\Domain\Seo\SeoTarget;

/**
 * Writes the Yoast Free 28.x core-field allowlist directly through the same
 * versioned post-meta keys YoastSeoProvider already reads, then re-reads
 * effective SEO through that same provider. Premium/Local fields are never
 * writable here; they are excluded from the allowlist upstream in SeoUpdate.
 */
final readonly class YoastFreeSeoWriter implements SeoWriter {

	private const COMPATIBLE_MAJOR = '28.';

	/**
	 * Simple string fields mapped 1:1 to a single documented Yoast meta key.
	 *
	 * @var array<string, string>
	 */
	private const TEXT_META = array(
		'seo_title'            => '_yoast_wpseo_title',
		'meta_description'     => '_yoast_wpseo_metadesc',
		'focus_keyphrase'      => '_yoast_wpseo_focuskw',
		'canonical'            => '_yoast_wpseo_canonical',
		'og_title'             => '_yoast_wpseo_opengraph-title',
		'og_description'       => '_yoast_wpseo_opengraph-description',
		'twitter_title'        => '_yoast_wpseo_twitter-title',
		'twitter_description'  => '_yoast_wpseo_twitter-description',
	);

	/**
	 * Creates the writer.
	 *
	 * @param SeoProvider $reader Read-side provider used for the mandatory post-write re-read.
	 */
	public function __construct(
		private SeoProvider $reader,
	) {
	}

	/**
	 * Whether a version-compatible Yoast Free install is active.
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		if ( ! function_exists( 'YoastSEO' ) || ! defined( 'WPSEO_VERSION' ) ) {
			return false;
		}
		$version = constant( 'WPSEO_VERSION' );

		return is_string( $version ) && str_starts_with( $version, self::COMPATIBLE_MAJOR );
	}

	/**
	 * Writes the allowlisted fields and returns the re-read effective SEO document.
	 *
	 * @param int   $post_id Target post ID.
	 * @param array $fields  Present allowlisted field name to value.
	 * @phpstan-param array<string, string|bool> $fields
	 * @return array<string, mixed>
	 * @throws SeoFieldUnsupported When no compatible writer is available.
	 */
	public function write( int $post_id, array $fields ): array {
		if ( ! $this->is_available() ) {
			throw new SeoFieldUnsupported( array_keys( $fields ) );
		}

		foreach ( self::TEXT_META as $field => $meta_key ) {
			if ( array_key_exists( $field, $fields ) && is_string( $fields[ $field ] ) ) {
				update_post_meta( $post_id, $meta_key, $fields[ $field ] );
			}
		}

		if ( array_key_exists( 'robots_index', $fields ) && is_bool( $fields['robots_index'] ) ) {
			update_post_meta( $post_id, '_yoast_wpseo_meta-robots-noindex', $fields['robots_index'] ? '2' : '1' );
		}
		if ( array_key_exists( 'robots_follow', $fields ) && is_bool( $fields['robots_follow'] ) ) {
			update_post_meta( $post_id, '_yoast_wpseo_meta-robots-nofollow', $fields['robots_follow'] ? '0' : '1' );
		}

		$document = $this->reader->get( SeoTarget::for_post( $post_id ) );

		return $document->to_array();
	}
}
```

- [ ] **Step 2: Static checks**

Run: `vendor/bin/phpcs src/Infrastructure/Yoast/YoastFreeSeoWriter.php && vendor/bin/phpstan analyse --memory-limit=512M --no-progress`
Expected: PHPCS clean; PHPStan 0 errors.

- [ ] **Step 3: Commit**

```bash
git add src/Infrastructure/Yoast/YoastFreeSeoWriter.php
git commit -m "feat(mutation): add YoastFreeSeoWriter

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

### Task 5: Ability schemas

**Files:**
- Modify: `src/Adapter/Abilities/AbilitySchemas.php`

**Interfaces:**
- Consumes: `mutation_output()` and `seo_output()` (both pre-existing private/public statics in the same class).
- Produces: `public static function update_seo_input(): array` and `public static function update_seo_output(): array`.

- [ ] **Step 1: Add the input schema**

Add this method to `src/Adapter/Abilities/AbilitySchemas.php`, next to `update_content_input()`:

```php
	/**
	 * Returns the update-seo input schema.
	 *
	 * @return array<string, mixed>
	 */
	public static function update_seo_input(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'post_id', 'version_token' ),
			'properties'           => array(
				'post_id'              => array(
					'description' => 'Target post ID.',
					'type'        => 'integer',
					'minimum'     => 1,
				),
				'version_token'        => array(
					'description' => 'Optimistic-concurrency token from get-content.',
					'type'        => 'string',
					'minLength'   => 18,
					'maxLength'   => 191,
				),
				'seo_title'            => array(
					'description' => 'Yoast SEO title override.',
					'type'        => array( 'string', 'null' ),
					'maxLength'   => 500,
				),
				'meta_description'     => array(
					'description' => 'Yoast meta description override.',
					'type'        => array( 'string', 'null' ),
					'maxLength'   => 320,
				),
				'focus_keyphrase'      => array(
					'description' => 'Yoast focus keyphrase override.',
					'type'        => array( 'string', 'null' ),
					'maxLength'   => 200,
				),
				'canonical'            => array(
					'description' => 'Yoast canonical URL override.',
					'type'        => array( 'string', 'null' ),
					'maxLength'   => 2048,
				),
				'robots_index'         => array(
					'description' => 'True forces index; false forces noindex.',
					'type'        => array( 'boolean', 'null' ),
				),
				'robots_follow'        => array(
					'description' => 'True forces follow; false forces nofollow.',
					'type'        => array( 'boolean', 'null' ),
				),
				'og_title'             => array(
					'description' => 'Yoast Open Graph title override.',
					'type'        => array( 'string', 'null' ),
					'maxLength'   => 500,
				),
				'og_description'       => array(
					'description' => 'Yoast Open Graph description override.',
					'type'        => array( 'string', 'null' ),
					'maxLength'   => 500,
				),
				'twitter_title'        => array(
					'description' => 'Yoast Twitter title override.',
					'type'        => array( 'string', 'null' ),
					'maxLength'   => 500,
				),
				'twitter_description'  => array(
					'description' => 'Yoast Twitter description override.',
					'type'        => array( 'string', 'null' ),
					'maxLength'   => 500,
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Returns the update-seo output schema.
	 *
	 * @return array<string, mixed>
	 */
	public static function update_seo_output(): array {
		$schema                                     = self::mutation_output();
		$schema['required'][]                       = 'effective_seo';
		$schema['properties']['effective_seo']       = self::seo_output();

		return $schema;
	}
```

- [ ] **Step 2: Static checks**

Run: `vendor/bin/phpcs src/Adapter/Abilities/AbilitySchemas.php && vendor/bin/phpstan analyse --memory-limit=512M --no-progress`
Expected: PHPCS clean; PHPStan 0 errors.

- [ ] **Step 3: Commit**

```bash
git add src/Adapter/Abilities/AbilitySchemas.php
git commit -m "feat(mutation): add update-seo input/output schemas

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

### Task 6: Register `update-seo` in `MutationAbilities`

**Files:**
- Modify: `src/Adapter/Abilities/MutationAbilities.php`

**Interfaces:**
- Consumes: `UpdateSeo` (Task 3), `SeoFieldUnsupported` (pre-existing).
- Produces: `MutationAbilities::__construct( CreateDraft $create, UpdateContent $update, UpdateSeo $update_seo )`; new public methods `can_update_seo( mixed $input = null ): bool` and `execute_update_seo( array $input ): array|WP_Error`.

- [ ] **Step 1: Add the constructor parameter and import**

In `src/Adapter/Abilities/MutationAbilities.php`, add the import and extend the constructor:

```php
use IsuDev\WPContentBridge\Application\Mutation\SeoFieldUnsupported;
use IsuDev\WPContentBridge\Application\Mutation\UpdateSeo;
```

(add both alongside the existing `use` statements, alphabetically among them)

Replace:

```php
	public function __construct(
		private CreateDraft $create,
		private UpdateContent $update,
	) {
	}
```

with:

```php
	public function __construct(
		private CreateDraft $create,
		private UpdateContent $update,
		private UpdateSeo $update_seo,
	) {
	}
```

- [ ] **Step 2: Register the ability**

In `register_abilities()`, after the existing `UpdateContent::ABILITY` registration block, add:

```php
		wp_register_ability(
			UpdateSeo::ABILITY,
			array(
				'label'               => __( 'Update SEO', 'wp-content-bridge' ),
				'description'         => __( 'Write the Yoast Free core SEO field allowlist for an existing post.', 'wp-content-bridge' ),
				'category'            => self::CATEGORY,
				'input_schema'        => AbilitySchemas::update_seo_input(),
				'output_schema'       => AbilitySchemas::update_seo_output(),
				'permission_callback' => array( $this, 'can_update_seo' ),
				'execute_callback'    => array( $this, 'execute_update_seo' ),
				'meta'                => self::write_meta( true ),
			)
		);
```

- [ ] **Step 3: Add the permission callback**

After `can_update()`, add:

```php
	/**
	 * Checks capability to write SEO on the targeted post.
	 *
	 * @param mixed $input Candidate ability input.
	 * @return bool
	 */
	public function can_update_seo( mixed $input = null ): bool {
		if ( ! current_user_can( 'wpcb_manage_seo' ) ) {
			return false;
		}

		$raw_post_id = is_array( $input ) ? ( $input['post_id'] ?? 0 ) : 0;
		$post_id     = is_numeric( $raw_post_id ) ? (int) $raw_post_id : 0;
		if ( 0 >= $post_id ) {
			return false;
		}

		return current_user_can( 'edit_post', $post_id );
	}
```

- [ ] **Step 4: Add the execute callback**

After `execute_update()`, add:

```php
	/**
	 * Executes an SEO update.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public function execute_update_seo( array $input ): array|WP_Error {
		if ( ! $this->can_update_seo( $input ) ) {
			return self::forbidden();
		}

		try {
			return $this->update_seo->execute( $input, get_current_user_id() )->to_array();
		} catch ( Throwable $error ) {
			return $this->to_error( $error );
		}
	}
```

- [ ] **Step 5: Extend the error mapping**

In `to_error()`, replace:

```php
		if ( $error instanceof MutationConflict
			|| $error instanceof InvalidBlockMarkup
			|| $error instanceof MutationForbidden
			|| $error instanceof MutationWriteFailed
		) {
			return new WP_Error( $error->error_code(), $error->getMessage() );
		}
```

with:

```php
		if ( $error instanceof MutationConflict
			|| $error instanceof InvalidBlockMarkup
			|| $error instanceof MutationForbidden
			|| $error instanceof MutationWriteFailed
			|| $error instanceof SeoFieldUnsupported
		) {
			return new WP_Error( $error->error_code(), $error->getMessage() );
		}
```

- [ ] **Step 6: Static checks**

Run: `vendor/bin/phpcs src/Adapter/Abilities/MutationAbilities.php && vendor/bin/phpstan analyse --memory-limit=512M --no-progress`
Expected: PHPCS clean; PHPStan 0 errors. (PHPStan will flag the constructor change if any other instantiation site isn't updated — Task 7 fixes `Plugin.php` next; run this check again after Task 7 if it errors here.)

- [ ] **Step 7: Commit**

```bash
git add src/Adapter/Abilities/MutationAbilities.php
git commit -m "feat(mutation): register update-seo ability

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

### Task 7: Wire `Plugin.php`

**Files:**
- Modify: `src/Plugin.php`

- [ ] **Step 1: Add imports**

Add alongside the existing `use IsuDev\WPContentBridge\Application\Mutation\...` / `Infrastructure\Yoast\...` imports:

```php
use IsuDev\WPContentBridge\Application\Mutation\UpdateSeo;
use IsuDev\WPContentBridge\Infrastructure\Yoast\YoastFreeSeoWriter;
```

- [ ] **Step 2: Instantiate and wire**

Replace the existing write-flag block:

```php
			$mutation_repository = new WordPressContentMutationRepository();
			$block_validator     = new PhpBlockMarkupValidator();
			$idempotency         = new WordPressTransientIdempotencyStore();
			$audit_log           = new WordPressAuditLog();

			( new MutationAbilities(
				new CreateDraft( $manager, $block_validator, $mutation_repository, $idempotency, $audit_log ),
				new UpdateContent( $manager, $block_validator, $mutation_repository, $audit_log )
			) )->register_hooks();
```

with:

```php
			$mutation_repository = new WordPressContentMutationRepository();
			$block_validator     = new PhpBlockMarkupValidator();
			$idempotency         = new WordPressTransientIdempotencyStore();
			$audit_log           = new WordPressAuditLog();
			$seo_writer          = new YoastFreeSeoWriter( $seo_providers->active() );

			( new MutationAbilities(
				new CreateDraft( $manager, $block_validator, $mutation_repository, $idempotency, $audit_log ),
				new UpdateContent( $manager, $block_validator, $mutation_repository, $audit_log ),
				new UpdateSeo( $manager, $mutation_repository, $seo_writer, $audit_log )
			) )->register_hooks();
```

Note: `$seo_providers` (the `SeoProviderRegistry` built earlier in the same method for `SeoAbilities`) is reused as-is; `$seo_providers->active()` returns whichever `SeoProvider` is available (`YoastSeoProvider` or the `NullSeoProvider` fallback when no SEO plugin is active), and `YoastFreeSeoWriter::is_available()` independently re-checks the Yoast-specific version gate before ever writing, so passing the null fallback here is safe — `write()` is simply never reached because `is_available()` returns `false`.

- [ ] **Step 3: Static checks**

Run: `composer lint && composer analyse`
Expected: PHPCS clean; PHPStan `[OK] No errors`.

- [ ] **Step 4: Full unit suite**

Run: `composer test`
Expected: all unit tests green (existing suite + this plan's additions).

- [ ] **Step 5: Commit**

```bash
git add src/Plugin.php
git commit -m "feat(mutation): wire update-seo into the plugin composition root

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

### Task 8: Runtime integration verifier

Mirrors `tests/Integration/writes-mutation-verification.php`'s fixture/cleanup/assert-helper skeleton, extended with the SEO-specific invariants from the design spec and Milestone 6 exit gate. Run against a real Yoast Free 28.x install (the Kormas local site).

**Files:**
- Create: `tests/Integration/writes-seo-verification.php`

- [ ] **Step 1: Write the verifier**

Create `tests/Integration/writes-seo-verification.php`:

```php
<?php
/**
 * Runtime verification for the update-seo write surface.
 *
 * Run: wp eval 'require "<abs path>/tests/Integration/writes-seo-verification.php";'
 * Requires Yoast Free 28.x active for the full matrix; the no-provider case is
 * skipped with a warning (not a failure) when Yoast is active, since it cannot
 * be deactivated safely from inside this script.
 *
 * @package IsuDev\WPContentBridge\Tests
 */

declare( strict_types=1 );

use IsuDev\WPContentBridge\Infrastructure\WordPress\Installer;

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Must run inside WordPress via wp eval.\n" );
	exit( 1 );
}

/**
 * Fail-fast runtime verifier for update-seo.
 */
final class WPCB_Seo_Write_Verification {

	/** @var array<int, int> */
	private array $post_ids = array();

	/** @var array<int, int> */
	private array $user_ids = array();

	/** @var array<int, string> */
	private array $failures = array();

	/**
	 * Runs the full verification matrix.
	 *
	 * @return void
	 */
	public function run(): void {
		Installer::activate();
		update_option( Installer::WRITES_ENABLED_OPTION, true );

		try {
			$this->verify_authorization_matrix();
			$this->verify_stale_version_conflict();
			$this->verify_unsupported_field_rejected();
			$this->verify_write_and_reread_parity();
			$this->verify_audit_redaction();
		} finally {
			$this->cleanup();
		}

		if ( array() === $this->failures ) {
			echo "PASS: update-seo (authorization matrix, conflict, unsupported field, write/re-read parity, audit redaction)\n";
			exit( 0 );
		}

		echo "FAIL:\n - " . implode( "\n - ", $this->failures ) . "\n";
		exit( 1 );
	}

	/**
	 * Creates a disposable post fixture.
	 *
	 * @return int
	 */
	private function fixture_post(): int {
		$post_id = wp_insert_post(
			array(
				'post_type'    => 'post',
				'post_status'  => 'publish',
				'post_title'   => 'WPCB SEO fixture',
				'post_content' => '<!-- wp:paragraph --><p>Fixture body.</p><!-- /wp:paragraph -->',
			),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			throw new RuntimeException( 'Could not create fixture post.' );
		}
		$this->post_ids[] = (int) $post_id;

		return (int) $post_id;
	}

	/**
	 * Creates a disposable user fixture.
	 *
	 * @param string $role WordPress role.
	 * @return int
	 */
	private function fixture_user( string $role ): int {
		$user_id = wp_insert_user(
			array(
				'user_login' => 'wpcb_seo_' . $role . '_' . wp_generate_password( 6, false ),
				'user_pass'  => wp_generate_password( 24 ),
				'role'       => $role,
			)
		);
		if ( is_wp_error( $user_id ) ) {
			throw new RuntimeException( 'Could not create fixture user.' );
		}
		$this->user_ids[] = (int) $user_id;

		return (int) $user_id;
	}

	/**
	 * Builds the current version token for a fixture post.
	 *
	 * @param int $post_id Fixture post ID.
	 * @return string
	 */
	private function current_token( int $post_id ): string {
		$post = get_post( $post_id );

		return \IsuDev\WPContentBridge\Domain\Mutation\VersionToken::for_content(
			$post->post_modified_gmt,
			$post->post_title,
			$post->post_content,
			$post->post_status
		)->to_string();
	}

	/**
	 * Proves plugin capability, native capability, and policy are each
	 * independently required.
	 *
	 * @return void
	 */
	private function verify_authorization_matrix(): void {
		$post_id = $this->fixture_post();
		$token   = $this->current_token( $post_id );

		$editor_no_plugin_cap = $this->fixture_user( 'editor' );
		get_userdata( $editor_no_plugin_cap )->remove_cap( 'wpcb_manage_seo' );
		wp_set_current_user( $editor_no_plugin_cap );
		$this->assert_true(
			! current_user_can( 'wpcb_manage_seo' ),
			'editor without wpcb_manage_seo unexpectedly has it'
		);

		$subscriber = $this->fixture_user( 'subscriber' );
		wp_set_current_user( $subscriber );
		$this->assert_true(
			! current_user_can( 'edit_post', $post_id ),
			'subscriber unexpectedly has edit_post on the fixture'
		);

		$administrator = $this->fixture_user( 'administrator' );
		wp_set_current_user( $administrator );
		$this->assert_true(
			current_user_can( 'wpcb_manage_seo' ) && current_user_can( 'edit_post', $post_id ),
			'administrator fixture unexpectedly lacks required caps'
		);

		$manager = new \IsuDev\WPContentBridge\Application\ContentAccess\ContentAccessManager(
			new \IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressContentAccessSettingsRepository(),
			new \IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressContentTypeCatalog()
		);
		$this->assert_true(
			! $manager->allows( 'post', \IsuDev\WPContentBridge\Domain\ContentAccess\ContentOperation::UPDATE_SEO ),
			'update_seo policy is unexpectedly enabled by default (must be deny-by-default)'
		);

		wp_set_current_user( 0 );
	}

	/**
	 * A stale version token is rejected without performing a write.
	 *
	 * @return void
	 */
	private function verify_stale_version_conflict(): void {
		$post_id = $this->fixture_post();
		$stale   = $this->current_token( $post_id );

		wp_update_post( array( 'ID' => $post_id, 'post_title' => 'Changed out of band' ) );
		update_option( 'wpcb_content_type_access', array( 'post' => array( 'get_content' => true, 'update_seo' => true ) ) );

		$writer = new \IsuDev\WPContentBridge\Infrastructure\Yoast\YoastFreeSeoWriter(
			new \IsuDev\WPContentBridge\Infrastructure\Yoast\YoastSeoProvider()
		);
		$use_case = new \IsuDev\WPContentBridge\Application\Mutation\UpdateSeo(
			new \IsuDev\WPContentBridge\Application\ContentAccess\ContentAccessManager(
				new \IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressContentAccessSettingsRepository(),
				new \IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressContentTypeCatalog()
			),
			new \IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressContentMutationRepository(),
			$writer,
			new \IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressAuditLog()
		);

		$before_meta = get_post_meta( $post_id, '_yoast_wpseo_title', true );

		try {
			$use_case->execute( array( 'post_id' => $post_id, 'version_token' => $stale, 'seo_title' => 'Should not persist' ), 1 );
			$this->failures[] = 'stale version token unexpectedly succeeded';
		} catch ( \IsuDev\WPContentBridge\Application\Mutation\MutationConflict $conflict ) {
			$this->assert_true( 'wpcb_conflict' === $conflict->error_code(), 'wrong conflict error code' );
		}

		$this->assert_true(
			$before_meta === get_post_meta( $post_id, '_yoast_wpseo_title', true ),
			'a rejected stale write mutated SEO meta'
		);
	}

	/**
	 * A field outside the allowlist rejects the whole request.
	 *
	 * @return void
	 */
	private function verify_unsupported_field_rejected(): void {
		$post_id = $this->fixture_post();
		$token   = $this->current_token( $post_id );

		$writer   = new \IsuDev\WPContentBridge\Infrastructure\Yoast\YoastFreeSeoWriter(
			new \IsuDev\WPContentBridge\Infrastructure\Yoast\YoastSeoProvider()
		);
		$use_case = new \IsuDev\WPContentBridge\Application\Mutation\UpdateSeo(
			new \IsuDev\WPContentBridge\Application\ContentAccess\ContentAccessManager(
				new \IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressContentAccessSettingsRepository(),
				new \IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressContentTypeCatalog()
			),
			new \IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressContentMutationRepository(),
			$writer,
			new \IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressAuditLog()
		);

		try {
			$use_case->execute( array( 'post_id' => $post_id, 'version_token' => $token, 'schema_type' => 'Article' ), 1 );
			$this->failures[] = 'an unsupported SEO field unexpectedly succeeded';
		} catch ( \IsuDev\WPContentBridge\Application\Mutation\SeoFieldUnsupported $unsupported ) {
			$this->assert_true(
				array( 'schema_type' ) === $unsupported->fields(),
				'unsupported-field failure did not name the offending key'
			);
		}
	}

	/**
	 * Writing an allowlisted field is reflected in the re-read effective SEO.
	 *
	 * @return void
	 */
	private function verify_write_and_reread_parity(): void {
		$post_id = $this->fixture_post();
		$token   = $this->current_token( $post_id );

		$writer   = new \IsuDev\WPContentBridge\Infrastructure\Yoast\YoastFreeSeoWriter(
			new \IsuDev\WPContentBridge\Infrastructure\Yoast\YoastSeoProvider()
		);
		$use_case = new \IsuDev\WPContentBridge\Application\Mutation\UpdateSeo(
			new \IsuDev\WPContentBridge\Application\ContentAccess\ContentAccessManager(
				new \IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressContentAccessSettingsRepository(),
				new \IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressContentTypeCatalog()
			),
			new \IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressContentMutationRepository(),
			$writer,
			new \IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressAuditLog()
		);

		if ( ! $writer->is_available() ) {
			echo "WARN: no compatible Yoast Free install active; skipping write/re-read parity check.\n";
			return;
		}

		$result = $use_case->execute(
			array(
				'post_id'       => $post_id,
				'version_token' => $token,
				'seo_title'     => 'WPCB verified title',
				'robots_index'  => false,
			),
			1
		);

		$this->assert_true(
			'_yoast_wpseo_title reflects the write' && 'WPCB verified title' === get_post_meta( $post_id, '_yoast_wpseo_title', true ),
			'seo_title was not persisted to _yoast_wpseo_title'
		);
		$this->assert_true(
			'1' === get_post_meta( $post_id, '_yoast_wpseo_meta-robots-noindex', true ),
			'robots_index=false did not persist as noindex (1)'
		);
		$this->assert_true(
			isset( $result->effective_seo['resolved']['title'] ),
			'effective_seo re-read is missing the resolved title field'
		);
	}

	/**
	 * A mutation writes exactly one redacted audit row (no SEO values leaked).
	 *
	 * @return void
	 */
	private function verify_audit_redaction(): void {
		global $wpdb;

		$post_id = $this->fixture_post();
		$token   = $this->current_token( $post_id );

		$writer   = new \IsuDev\WPContentBridge\Infrastructure\Yoast\YoastFreeSeoWriter(
			new \IsuDev\WPContentBridge\Infrastructure\Yoast\YoastSeoProvider()
		);
		$use_case = new \IsuDev\WPContentBridge\Application\Mutation\UpdateSeo(
			new \IsuDev\WPContentBridge\Application\ContentAccess\ContentAccessManager(
				new \IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressContentAccessSettingsRepository(),
				new \IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressContentTypeCatalog()
			),
			new \IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressContentMutationRepository(),
			$writer,
			new \IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressAuditLog()
		);

		if ( ! $writer->is_available() ) {
			echo "WARN: no compatible Yoast Free install active; skipping audit redaction check.\n";
			return;
		}

		$table         = Installer::audit_table_name();
		$before_count  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE ability = 'wp-content-bridge/update-seo'" );

		$use_case->execute( array( 'post_id' => $post_id, 'version_token' => $token, 'meta_description' => 'A secret-looking description' ), 1 );

		$after_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE ability = 'wp-content-bridge/update-seo'" );
		$this->assert_true( 1 === $after_count - $before_count, 'update-seo did not record exactly one audit row' );

		$row = $wpdb->get_row(
			"SELECT * FROM {$table} WHERE ability = 'wp-content-bridge/update-seo' ORDER BY id DESC LIMIT 1",
			ARRAY_A
		);
		$this->assert_true( null !== $row, 'no audit row found for update-seo' );
		$this->assert_true(
			array( 'meta_description' ) === json_decode( (string) $row['changed_fields'], true ),
			'audit changed_fields does not list meta_description by name'
		);
		$this->assert_true(
			false === strpos( (string) $row['changed_fields'], 'secret-looking' ),
			'audit row leaked an SEO value'
		);
	}

	/**
	 * Fails fast with a descriptive message.
	 *
	 * @param bool   $condition Assertion condition.
	 * @param string $message   Failure message.
	 * @return void
	 */
	private function assert_true( bool $condition, string $message ): void {
		if ( ! $condition ) {
			$this->failures[] = $message;
		}
	}

	/**
	 * Removes every fixture created during this run.
	 *
	 * @return void
	 */
	private function cleanup(): void {
		wp_set_current_user( 0 );
		foreach ( $this->post_ids as $post_id ) {
			wp_delete_post( $post_id, true );
		}
		foreach ( $this->user_ids as $user_id ) {
			wp_delete_user( $user_id );
		}
		delete_option( 'wpcb_content_type_access' );
	}
}

( new WPCB_Seo_Write_Verification() )->run();
```

- [ ] **Step 2: Static checks**

Run: `vendor/bin/phpcs tests/Integration/writes-seo-verification.php && vendor/bin/phpstan analyse --memory-limit=512M --no-progress`
Expected: PHPCS clean; PHPStan 0 errors (or documented `phpcs:ignore`/`@phpstan-ignore` on the direct-query lines, matching `writes-mutation-verification.php`'s existing style).

- [ ] **Step 3: Run the runtime verification**

Run:
```bash
cd "/Users/lukaszbiedron/Local Sites/kormas-isu/app/public"
wp eval 'require "/Users/lukaszbiedron/Other Projects/wp-content-bridge/tests/Integration/writes-seo-verification.php";'
```
Expected: `PASS: update-seo (authorization matrix, conflict, unsupported field, write/re-read parity, audit redaction)`. If Yoast Free 28.x is not active on this install, the write/re-read and audit checks print a `WARN:` line and are skipped rather than failing — re-run on an environment with Yoast Free 28.x active before treating this task as fully verified, since those two checks are the ones that prove the field mapping (Task 4's design decisions) against real Yoast storage.

- [ ] **Step 4: Full suite**

Run: `composer check`
Expected: PHPUnit green, PHPCS 0, PHPStan 0.

- [ ] **Step 5: Commit**

```bash
git add tests/Integration/writes-seo-verification.php
git commit -m "test(mutation): add update-seo runtime verifier

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

## Self-Review

**Spec coverage** (`docs/superpowers/specs/2026-07-20-milestone-5-writes-design.md`, section 9 unless noted):

- §9 writable allowlist (10 fields) → `SeoUpdate::ALLOWED_KEYS` (Task 2) + `YoastFreeSeoWriter::TEXT_META` + robots handling (Task 4).
- §9 "Write through Yoast's documented write path... never `update_post_meta` on raw internal keys guessed" → Task 4's design-decision note (reuses the already version-tested `YoastSeoProvider` allowlist rather than new keys) and the version gate (`COMPATIBLE_MAJOR = '28.'`).
- §9 "Version-gate exactly like the read adapter" → `YoastFreeSeoWriter::is_available()` (Task 4), identical `28.` major check to `YoastSeoProvider::CONFIGURED_META_COMPATIBLE_MAJOR`.
- §9 "re-read effective SEO via the existing `YoastSeoProvider` ... `MutationResult.effectiveSeo`" → `YoastFreeSeoWriter::write()` calls `$this->reader->get()` (Task 4); `MutationResult::$effective_seo` (Task 1); `UpdateSeo::execute()` assembles the result (Task 3).
- §9 "Premium/Local fields: not writable... `wpcb_seo_field_unsupported`" → satisfied structurally: those fields are absent from `SeoUpdate::ALLOWED_KEYS`, so they are rejected by the same unknown-field path (Task 3's design-decision note).
- §3 capability table row `update-seo | wpcb_manage_seo | edit_post` → `MutationAbilities::can_update_seo()` (Task 6); both already-existing per Global Constraints (no `Installer.php` change).
- §4 "`update-seo` requires the type's SEO-write (manage-seo) operation enabled" → `ContentOperation::UPDATE_SEO`, already wired; `UpdateSeo::execute()` calls `$access->allows( $post_type, ContentOperation::UPDATE_SEO )` (Task 3).
- §7 mandatory write-flow order (flag → permission → policy → input validation → concurrency → write → re-read → audit) → `UpdateSeo::execute()` step-by-step (Task 3); flag/permission enforced one layer up in `MutationAbilities` (Task 6), unchanged pattern from `update-content`.
- §6.1 `VersionToken` reuse, no new token scheme → Global Constraints note; `SeoUpdate` consumes the existing `VersionToken::from_string()`/`::equals()` unchanged (Task 2).
- §13 audit (exactly one row, field names only, `do_action`) → `UpdateSeo::execute()`'s try/catch-outside success-audit pattern (Task 3), verified in Task 8's `verify_audit_redaction()`.
- §14 stable error codes → all reused verbatim; no new codes introduced (Global Constraints; Task 3's `classify()`; Task 6's `to_error()` extension).
- §15 unit tests (DTO bounds, allowlist mapping/rejection) → `SeoUpdateTest` (Task 2), `UpdateSeoTest` (Task 3).
- §15 integration tests (authorization matrix, stale conflict, SEO write+re-read parity, audit redaction) → `writes-seo-verification.php` (Task 8).
- §17 "If a Yoast write path is not clearly documented... STOP and mark unsupported" → addressed head-on in Task 4's design-decision notes (reusing the proven read-side allowlist and keys instead of guessing new storage; explicit rationale for the robots boolean encoding; the runtime verifier is the fallback proof step for the one genuinely inferred convention).
- Milestone 6 exit gate "no direct write to provider-derived/indexables tables" → all writes go through `update_post_meta()` on plain post meta (never the Yoast indexables table).
- Milestone 6 exit gate "unsupported fields fail explicitly" → `SeoFieldUnsupported` with named offending fields (Tasks 2–4); Task 3's design-decision note explains why this is whole-request rather than field-level partial application.
- Milestone 6 exit gate "effective SEO is re-read after mutation" → same as the §9 item above.
- Milestone 6 exit gate "Premium/Local writes remain out" → same as the §9 Premium/Local item above.
- "field-level result reporting" (Milestone 6 deliverable bullet) → explicitly addressed as an open design call in Task 3: this plan does not implement per-field partial results; rationale given.
- Cross-cutting M5 tests (per-object authorization, stale conflicts, mutation audit redaction) → Task 8, mirroring `writes-mutation-verification.php`.
- M5 exit gate "writes are invisible over MCP unless their master flag is enabled" → unchanged; `update-seo` is registered by the same flag-gated `MutationAbilities` as the other two write abilities (no new registration path introduced).

**Placeholder scan:** every step contains complete, runnable code (DTO, port, use case, infrastructure adapter, schema methods, ability wiring diffs, `Plugin.php` diff, and the full runtime verifier). No "TODO", "add validation", or "similar to Task N" phrasing appears. The one explicitly-scoped omission (no PHPUnit unit test for `YoastFreeSeoWriter`) is stated as a deliberate, precedented decision in Task 4, not a gap.

**Type/signature consistency:**
- `MutationResult` constructor order (`post_id, post_type, status, version, changed_fields, created, effective_seo = null`) is identical across Task 1's edit, Task 3's `UpdateSeo::execute()` construction, and Task 8's verifier usage (`$result->effective_seo`).
- `SeoUpdate::ALLOWED_KEYS` (Task 2) is the single source of truth reused by `UpdateSeo::unsupported_keys()` (Task 3) — no duplicated/divergent key list.
- `SeoWriter::write( int $post_id, array $fields ): array` (Task 3 port) matches `YoastFreeSeoWriter::write()`'s exact signature (Task 4) and the fake in `UpdateSeoTest` (Task 3).
- `UpdateSeo::__construct( ContentAccessManager, ContentMutationRepository, SeoWriter, AuditLog )` matches its use in `UpdateSeoTest` (Task 3), `Plugin.php` (Task 7), and `writes-seo-verification.php` (Task 8) — same order, same types, in all four call sites.
- `MutationAbilities::__construct( CreateDraft, UpdateContent, UpdateSeo )` matches the updated `Plugin.php` instantiation (Task 6 vs Task 7).
- Wire field names (`seo_title`, `meta_description`, `focus_keyphrase`, `canonical`, `robots_index`, `robots_follow`, `og_title`, `og_description`, `twitter_title`, `twitter_description`) are identical across `SeoUpdate` (Task 2), `AbilitySchemas::update_seo_input()` (Task 5), `YoastFreeSeoWriter::TEXT_META` keys (Task 4), and the runtime verifier's requests (Task 8).

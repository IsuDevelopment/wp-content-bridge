# M5 Plan 2 — `create-draft` + `update-content` Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship the plugin's first live write surface — two Abilities, `wp-content-bridge/create-draft` (create a `draft` post/page/CPT) and `wp-content-bridge/update-content` (update title/content/excerpt/taxonomies of an existing post) — behind the off-by-default `wpcb_writes_enabled` flag, with optimistic concurrency, block-markup validation, idempotent draft creation, WordPress revisions, and a redacted audit row per attempt.

**Architecture:** A `src/*/Mutation/` vertical slice mirroring the existing read slice (`src/*/Content/`). Domain DTOs validate input; Application use cases orchestrate the mandatory write flow and own audit; Infrastructure holds the only WordPress calls; the Adapter maps I/O + `WP_Error` and registers abilities only when the master flag is on. Plan 1 already delivered `VersionToken`, the `MutationConflict`/`InvalidBlockMarkup`/`SeoFieldUnsupported` failures, the `AuditEvent`/`AuditLog`/`BlockMarkupValidator` ports, `WordPressAuditLog`, and the Installer caps/flags/audit-table — this plan consumes them.

**Tech Stack:** PHP 8.2+, PSR-4 `IsuDev\WPContentBridge\`, WordPress 7.0+ (Abilities API), PHPUnit 11 (Domain/Application units, WP not loaded), `wp eval` runtime verifiers (Infrastructure/Adapter), PHPCS (WordPress Coding Standards) + PHPStan max.

## Global Constraints

Every task's requirements implicitly include this section. Copy exact values verbatim.

- **Layering (non-negotiable):** Domain → Application → Infrastructure → Adapter. Domain DTOs and Application services **never** call WordPress functions. WordPress calls live **only** in `src/Infrastructure/`. Adapters map input/output + `WP_Error` and contain **no** policy.
- **Standing repo rule:** do NOT push to a remote. Per-task local commits on the feature branch are expected. Only the maintainer authorizes pushing.
- **Commit trailer (every commit):** end the message with `Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>`.
- **WPCS style:** tabs for indentation; Yoda conditions (`null === $x`); long array syntax `array( … )`; one space inside parentheses `foo( $bar )`; short-ternary / null-coalescing allowed.
- **Docblock generics trap:** when a docblock `@param`/`@var` pairs with a plain `array` type hint, write `array<int, string>` (or `array<string, mixed>`), **never** `list<string>` — the `Squiz.Commenting.FunctionComment.IncorrectTypeHint` sniff rejects `list<...>` against an `array` hint. `@return list<string>` on a method (no conflicting hint) is fine.
- **DTOs:** `final readonly class` with promoted public properties; a `public static function from_input( … ): self` factory that validates and throws `InvalidArgumentException` on violation. Mirror `src/Domain/Content/TaxonomyFilter.php` and `src/Domain/Content/ContentQuery.php`.
- **Version token scheme:** the ONLY token is `VersionToken::for_content( string $modified_gmt, string $title, string $content, string $status )`. Wire form is `VersionToken::to_string()` / `from_string()` (`{content_hash}:{modified_gmt}`).
- **Status invariant:** neither `create-draft` nor `update-content` may ever set post status to `publish` (or `future`/`pending`). `create-draft` always writes `draft`; `update-content` never touches status. This is asserted in the runtime verifier.
- **Audit invariant:** every mutation attempt that reaches a use case's `execute()` records **exactly one** audit row (success or failure) and fires `do_action( 'wpcb_mutation', $event )` (both via the injected `AuditLog`). The row stores changed field **names** only — never titles, content, excerpts, term values, or any secret.
- **Flag gating:** `MutationAbilities` is registered **only when** `get_option( Installer::WRITES_ENABLED_OPTION )` is truthy. An ability that is not registered is invisible to MCP discovery.
- **Stable error codes** (string literals, created in the adapter or via `error_code()` on typed failures): `wpcb_invalid_input`, `wpcb_conflict`, `wpcb_invalid_blocks`, `wpcb_forbidden`, `wpcb_content_unavailable`, `wpcb_write_failed`, `wpcb_internal_error`.
- **Local WP install for runtime verifiers** is at `/Users/lukaszbiedron/Local Sites/kormas-isu/app/public` (the repo is symlinked into its `wp-content/plugins`). Repo absolute path for `require` is `/Users/lukaszbiedron/Other Projects/wp-content-bridge`.

---

## File Structure

**New files:**

```
src/Domain/Mutation/
  TaxonomyAssignment.php    Immutable. Validated taxonomy+term-IDs write input (mirrors Content/TaxonomyFilter).
  DraftInput.php            Immutable. Validated new-post input. Status is always draft (no status field).
  ContentUpdate.php         Immutable. Validated existing-post update. No status field; ≥1 updatable field.
  MutationResult.php        Immutable. Outcome DTO returned to the adapter.

src/Application/Mutation/
  ContentMutationRepository.php   Port (interface): post_type/current_version/create/update/result_for.
  IdempotencyStore.php            Port (interface): find/remember.
  MutationForbidden.php           Typed failure → wpcb_forbidden (policy denial).
  MutationWriteFailed.php         Typed failure → wpcb_write_failed (WP write error).
  CreateDraft.php                 Use case.
  UpdateContent.php               Use case.

src/Infrastructure/WordPress/
  PhpBlockMarkupValidator.php           implements BlockMarkupValidator (parse_blocks round-trip + registry).
  WordPressContentMutationRepository.php implements ContentMutationRepository (wp_insert_post/wp_update_post).
  WordPressTransientIdempotencyStore.php implements IdempotencyStore (per-user transient, 24h TTL).

src/Adapter/Abilities/
  MutationAbilities.php     Registers + projects create-draft & update-content.

tests/Unit/Domain/Mutation/
  TaxonomyAssignmentTest.php
  DraftInputTest.php
  ContentUpdateTest.php
  MutationResultTest.php

tests/Unit/Application/Mutation/
  CreateDraftTest.php
  UpdateContentTest.php

tests/Integration/
  writes-mutation-verification.php   Runtime write authorization matrix + conflict + revision + block round-trip + audit + flag-off + no-publish-status.
```

**Modified files:**

```
phpcs.xml.dist                                    Add 3 write caps to custom_capabilities.
src/Domain/Content/ContentDetail.php              Add ?VersionToken $version_token param + to_array() field.
src/Infrastructure/WordPress/WordPressContentRepository.php  Build the VersionToken in get().
src/Adapter/Abilities/AbilitySchemas.php          Add create-draft/update-content input+output schemas + version_token on get_output.
src/Adapter/Abilities/ContentAbilities.php        (only if get_output needs no code change — schema is static) — no logic change expected.
src/Plugin.php                                    Instantiate write services; register MutationAbilities only when flag on.
src/Adapter/Admin/ContentAccessSettingsPage.php   Add the global wpcb_writes_enabled checkbox (option + field + sanitize).
tests/Unit/Domain/Content/ContentDetailTest.php   (if it exists) update for the new constructor param.
```

Reads stay otherwise untouched. Do not add write methods to `WordPressContentRepository` beyond the `version_token` build, and do not add write callbacks to `ContentAbilities`.

---

### Task 1: Prep — capability allowlist + `TaxonomyAssignment` DTO

**Files:**
- Modify: `phpcs.xml.dist:17-24`
- Create: `src/Domain/Mutation/TaxonomyAssignment.php`
- Test: `tests/Unit/Domain/Mutation/TaxonomyAssignmentTest.php`

**Interfaces:**
- Consumes: nothing (Domain leaf).
- Produces: `final readonly class TaxonomyAssignment` with public `string $taxonomy`, `array $term_ids` (`@var array<int, int>` non-empty list of positive ints) and `public static function from_input( mixed $input ): self` (throws `InvalidArgumentException`). `DraftInput` and `ContentUpdate` consume `TaxonomyAssignment[]`.

- [ ] **Step 1: Extend the PHPCS capability allowlist**

In `phpcs.xml.dist`, replace the `WordPress.WP.Capabilities` block (currently listing only `wpcb_manage_settings` and `wpcb_read_content`) with:

```xml
	<rule ref="WordPress.WP.Capabilities">
		<properties>
			<property name="custom_capabilities" type="array">
				<element value="wpcb_manage_settings" />
				<element value="wpcb_read_content" />
				<element value="wpcb_edit_content" />
				<element value="wpcb_manage_seo" />
				<element value="wpcb_publish_content" />
			</property>
		</properties>
	</rule>
```

- [ ] **Step 2: Write the failing test**

Create `tests/Unit/Domain/Mutation/TaxonomyAssignmentTest.php`:

```php
<?php
/**
 * Unit tests for the TaxonomyAssignment write DTO.
 *
 * @package IsuDev\WPContentBridge
 */

declare( strict_types=1 );

namespace IsuDev\WPContentBridge\Tests\Unit\Domain\Mutation;

use InvalidArgumentException;
use IsuDev\WPContentBridge\Domain\Mutation\TaxonomyAssignment;
use PHPUnit\Framework\TestCase;

final class TaxonomyAssignmentTest extends TestCase {

	public function test_from_input_accepts_valid_assignment(): void {
		$assignment = TaxonomyAssignment::from_input(
			array(
				'taxonomy' => 'category',
				'term_ids' => array( 3, 3, 7 ),
			)
		);

		self::assertSame( 'category', $assignment->taxonomy );
		self::assertSame( array( 3, 7 ), $assignment->term_ids );
	}

	public function test_from_input_rejects_unknown_key(): void {
		$this->expectException( InvalidArgumentException::class );

		TaxonomyAssignment::from_input(
			array(
				'taxonomy' => 'category',
				'term_ids' => array( 1 ),
				'extra'    => true,
			)
		);
	}

	public function test_from_input_rejects_bad_taxonomy_name(): void {
		$this->expectException( InvalidArgumentException::class );

		TaxonomyAssignment::from_input(
			array(
				'taxonomy' => 'Not A Taxonomy!',
				'term_ids' => array( 1 ),
			)
		);
	}

	public function test_from_input_rejects_empty_term_ids(): void {
		$this->expectException( InvalidArgumentException::class );

		TaxonomyAssignment::from_input(
			array(
				'taxonomy' => 'category',
				'term_ids' => array(),
			)
		);
	}

	public function test_from_input_rejects_non_positive_term_id(): void {
		$this->expectException( InvalidArgumentException::class );

		TaxonomyAssignment::from_input(
			array(
				'taxonomy' => 'category',
				'term_ids' => array( 0 ),
			)
		);
	}
}
```

- [ ] **Step 3: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter TaxonomyAssignmentTest`
Expected: FAIL — `Error: Class "IsuDev\WPContentBridge\Domain\Mutation\TaxonomyAssignment" not found`.

- [ ] **Step 4: Write the DTO**

Create `src/Domain/Mutation/TaxonomyAssignment.php`. Mirror `src/Domain/Content/TaxonomyFilter.php` (same regex, same 1–100 bound, dedupe, positive-int validation):

```php
<?php
/**
 * Validated taxonomy + term assignment for write operations.
 *
 * @package IsuDev\WPContentBridge
 */

declare( strict_types=1 );

namespace IsuDev\WPContentBridge\Domain\Mutation;

use InvalidArgumentException;

/**
 * Immutable taxonomy assignment (taxonomy name + a bounded list of term IDs).
 *
 * Mirrors the read-side TaxonomyFilter shape but expresses intent to assign
 * terms on a write. Validity of the taxonomy against the target post type is
 * enforced later, in Infrastructure.
 */
final readonly class TaxonomyAssignment {

	private const TAXONOMY_PATTERN = '/^[a-z0-9_-]{1,32}$/';
	private const MAX_TERMS        = 100;

	/**
	 * @param array<int, int> $term_ids Non-empty list of unique positive term IDs.
	 */
	public function __construct(
		public string $taxonomy,
		public array $term_ids,
	) {}

	/**
	 * Build from untrusted input.
	 *
	 * @param mixed $input Raw assignment.
	 * @throws InvalidArgumentException When the assignment is malformed.
	 */
	public static function from_input( mixed $input ): self {
		if ( ! is_array( $input ) ) {
			throw new InvalidArgumentException( 'A taxonomy assignment must be an object.' );
		}

		$allowed = array( 'taxonomy', 'term_ids' );
		foreach ( array_keys( $input ) as $key ) {
			if ( ! in_array( $key, $allowed, true ) ) {
				throw new InvalidArgumentException( 'A taxonomy assignment contains an unsupported field.' );
			}
		}

		$taxonomy = $input['taxonomy'] ?? null;
		if ( ! is_string( $taxonomy ) || 1 !== preg_match( self::TAXONOMY_PATTERN, $taxonomy ) ) {
			throw new InvalidArgumentException( 'A taxonomy name is invalid.' );
		}

		$raw_terms = $input['term_ids'] ?? null;
		if ( ! is_array( $raw_terms ) || array() === $raw_terms ) {
			throw new InvalidArgumentException( 'A taxonomy assignment requires at least one term ID.' );
		}

		$term_ids = array();
		foreach ( $raw_terms as $term_id ) {
			if ( ! is_int( $term_id ) || 0 >= $term_id ) {
				throw new InvalidArgumentException( 'Term IDs must be positive integers.' );
			}
			$term_ids[] = $term_id;
		}

		$term_ids = array_values( array_unique( $term_ids ) );
		if ( count( $term_ids ) > self::MAX_TERMS ) {
			throw new InvalidArgumentException( 'A taxonomy assignment allows at most 100 term IDs.' );
		}

		return new self( $taxonomy, $term_ids );
	}
}
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `vendor/bin/phpunit --filter TaxonomyAssignmentTest`
Expected: PASS (5 tests).

- [ ] **Step 6: Static checks**

Run: `composer lint && composer analyse`
Expected: PHPCS clean; PHPStan `[OK] No errors`.

- [ ] **Step 7: Commit**

```bash
git add phpcs.xml.dist src/Domain/Mutation/TaxonomyAssignment.php tests/Unit/Domain/Mutation/TaxonomyAssignmentTest.php
git commit -m "feat(mutation): add write cap allowlist and TaxonomyAssignment DTO

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 2: `DraftInput` DTO

**Files:**
- Create: `src/Domain/Mutation/DraftInput.php`
- Test: `tests/Unit/Domain/Mutation/DraftInputTest.php`

**Interfaces:**
- Consumes: `TaxonomyAssignment` (Task 1).
- Produces: `final readonly class DraftInput` with public `string $post_type`, `string $title`, `string $block_markup`, `?string $excerpt`, `array $taxonomies` (`@var array<int, TaxonomyAssignment>`), `?string $idempotency_key`; and `public static function from_input( array $input ): self`. **No status field** (status is always `draft`).

Bounds: `post_type` matches `/^[a-z0-9_-]{1,20}$/`; `title` required, trimmed, non-empty, ≤ 500 chars; `block_markup` optional string, ≤ 500000 bytes, default `''`; `excerpt` optional, ≤ 2000 chars; `taxonomies` optional array of `TaxonomyAssignment`; `idempotency_key` optional, `/^[A-Za-z0-9_.\-]{1,128}$/`.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Domain/Mutation/DraftInputTest.php`:

```php
<?php
/**
 * Unit tests for the DraftInput write DTO.
 *
 * @package IsuDev\WPContentBridge
 */

declare( strict_types=1 );

namespace IsuDev\WPContentBridge\Tests\Unit\Domain\Mutation;

use InvalidArgumentException;
use IsuDev\WPContentBridge\Domain\Mutation\DraftInput;
use IsuDev\WPContentBridge\Domain\Mutation\TaxonomyAssignment;
use PHPUnit\Framework\TestCase;

final class DraftInputTest extends TestCase {

	public function test_from_input_builds_minimal_draft(): void {
		$draft = DraftInput::from_input(
			array(
				'post_type' => 'post',
				'title'     => '  Hello  ',
			)
		);

		self::assertSame( 'post', $draft->post_type );
		self::assertSame( 'Hello', $draft->title );
		self::assertSame( '', $draft->block_markup );
		self::assertNull( $draft->excerpt );
		self::assertSame( array(), $draft->taxonomies );
		self::assertNull( $draft->idempotency_key );
	}

	public function test_from_input_builds_full_draft(): void {
		$draft = DraftInput::from_input(
			array(
				'post_type'       => 'page',
				'title'           => 'Title',
				'block_markup'    => '<!-- wp:paragraph --><p>Hi</p><!-- /wp:paragraph -->',
				'excerpt'         => 'Summary',
				'taxonomies'      => array(
					array(
						'taxonomy' => 'category',
						'term_ids' => array( 5 ),
					),
				),
				'idempotency_key' => 'abc-123',
			)
		);

		self::assertSame( 'page', $draft->post_type );
		self::assertSame( 'Summary', $draft->excerpt );
		self::assertCount( 1, $draft->taxonomies );
		self::assertContainsOnlyInstancesOf( TaxonomyAssignment::class, $draft->taxonomies );
		self::assertSame( 'abc-123', $draft->idempotency_key );
	}

	public function test_from_input_rejects_empty_title(): void {
		$this->expectException( InvalidArgumentException::class );

		DraftInput::from_input(
			array(
				'post_type' => 'post',
				'title'     => '   ',
			)
		);
	}

	public function test_from_input_rejects_overlong_title(): void {
		$this->expectException( InvalidArgumentException::class );

		DraftInput::from_input(
			array(
				'post_type' => 'post',
				'title'     => str_repeat( 'a', 501 ),
			)
		);
	}

	public function test_from_input_rejects_bad_post_type(): void {
		$this->expectException( InvalidArgumentException::class );

		DraftInput::from_input(
			array(
				'post_type' => 'Not Valid',
				'title'     => 'Title',
			)
		);
	}

	public function test_from_input_rejects_unknown_key(): void {
		$this->expectException( InvalidArgumentException::class );

		DraftInput::from_input(
			array(
				'post_type' => 'post',
				'title'     => 'Title',
				'status'    => 'publish',
			)
		);
	}

	public function test_from_input_rejects_bad_idempotency_key(): void {
		$this->expectException( InvalidArgumentException::class );

		DraftInput::from_input(
			array(
				'post_type'       => 'post',
				'title'           => 'Title',
				'idempotency_key' => 'bad key with spaces',
			)
		);
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter DraftInputTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Write the DTO**

Create `src/Domain/Mutation/DraftInput.php`:

```php
<?php
/**
 * Validated input for creating a new draft.
 *
 * @package IsuDev\WPContentBridge
 */

declare( strict_types=1 );

namespace IsuDev\WPContentBridge\Domain\Mutation;

use InvalidArgumentException;

/**
 * Immutable, validated new-post input. Status is always draft; there is no
 * status field on this DTO by design.
 */
final readonly class DraftInput {

	private const POST_TYPE_PATTERN = '/^[a-z0-9_-]{1,20}$/';
	private const KEY_PATTERN       = '/^[A-Za-z0-9_.\-]{1,128}$/';
	private const MAX_TITLE         = 500;
	private const MAX_EXCERPT       = 2000;
	private const MAX_MARKUP_BYTES  = 500000;

	/**
	 * @param array<int, TaxonomyAssignment> $taxonomies Bounded taxonomy assignments.
	 */
	public function __construct(
		public string $post_type,
		public string $title,
		public string $block_markup,
		public ?string $excerpt,
		public array $taxonomies,
		public ?string $idempotency_key,
	) {}

	/**
	 * Build from untrusted input.
	 *
	 * @param array<string, mixed> $input Raw create-draft input.
	 * @throws InvalidArgumentException When input is malformed.
	 */
	public static function from_input( array $input ): self {
		$allowed = array( 'post_type', 'title', 'block_markup', 'excerpt', 'taxonomies', 'idempotency_key' );
		foreach ( array_keys( $input ) as $key ) {
			if ( ! in_array( $key, $allowed, true ) ) {
				throw new InvalidArgumentException( 'Create-draft input contains an unsupported field.' );
			}
		}

		$post_type = $input['post_type'] ?? null;
		if ( ! is_string( $post_type ) || 1 !== preg_match( self::POST_TYPE_PATTERN, $post_type ) ) {
			throw new InvalidArgumentException( 'A post type is invalid.' );
		}

		$title = $input['title'] ?? null;
		if ( ! is_string( $title ) ) {
			throw new InvalidArgumentException( 'A title is required.' );
		}
		$title = trim( $title );
		if ( '' === $title ) {
			throw new InvalidArgumentException( 'A title must not be empty.' );
		}
		if ( mb_strlen( $title ) > self::MAX_TITLE ) {
			throw new InvalidArgumentException( 'A title must be at most 500 characters.' );
		}

		$block_markup = $input['block_markup'] ?? '';
		if ( ! is_string( $block_markup ) ) {
			throw new InvalidArgumentException( 'Block markup must be a string.' );
		}
		if ( strlen( $block_markup ) > self::MAX_MARKUP_BYTES ) {
			throw new InvalidArgumentException( 'Block markup exceeds the size limit.' );
		}

		$excerpt = null;
		if ( array_key_exists( 'excerpt', $input ) && null !== $input['excerpt'] ) {
			$candidate = $input['excerpt'];
			if ( ! is_string( $candidate ) || mb_strlen( $candidate ) > self::MAX_EXCERPT ) {
				throw new InvalidArgumentException( 'An excerpt is invalid.' );
			}
			$excerpt = $candidate;
		}

		$taxonomies = array();
		if ( array_key_exists( 'taxonomies', $input ) && null !== $input['taxonomies'] ) {
			if ( ! is_array( $input['taxonomies'] ) ) {
				throw new InvalidArgumentException( 'Taxonomies must be an array.' );
			}
			foreach ( $input['taxonomies'] as $assignment ) {
				$taxonomies[] = TaxonomyAssignment::from_input( $assignment );
			}
		}

		$idempotency_key = null;
		if ( array_key_exists( 'idempotency_key', $input ) && null !== $input['idempotency_key'] ) {
			$candidate = $input['idempotency_key'];
			if ( ! is_string( $candidate ) || 1 !== preg_match( self::KEY_PATTERN, $candidate ) ) {
				throw new InvalidArgumentException( 'An idempotency key is invalid.' );
			}
			$idempotency_key = $candidate;
		}

		return new self( $post_type, $title, $block_markup, $excerpt, $taxonomies, $idempotency_key );
	}
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit --filter DraftInputTest`
Expected: PASS (7 tests).

- [ ] **Step 5: Static checks**

Run: `composer lint && composer analyse`
Expected: PHPCS clean; PHPStan `[OK] No errors`.

- [ ] **Step 6: Commit**

```bash
git add src/Domain/Mutation/DraftInput.php tests/Unit/Domain/Mutation/DraftInputTest.php
git commit -m "feat(mutation): add DraftInput DTO

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 3: `ContentUpdate` DTO

**Files:**
- Create: `src/Domain/Mutation/ContentUpdate.php`
- Test: `tests/Unit/Domain/Mutation/ContentUpdateTest.php`

**Interfaces:**
- Consumes: `TaxonomyAssignment` (Task 1), `VersionToken` (`src/Domain/Mutation/VersionToken.php`, Plan 1).
- Produces: `final readonly class ContentUpdate` with public `int $post_id`, `VersionToken $expected_version`, `?string $title`, `?string $block_markup`, `?string $excerpt`, `?array $taxonomies` (`@var array<int, TaxonomyAssignment>|null`); `public static function from_input( array $input ): self`. **No status field.** At least one of title/block_markup/excerpt/taxonomies must be present, else `InvalidArgumentException`. A method `public function changed_fields(): array` returning the present field names (`@return list<string>`) for audit/result.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Domain/Mutation/ContentUpdateTest.php`:

```php
<?php
/**
 * Unit tests for the ContentUpdate write DTO.
 *
 * @package IsuDev\WPContentBridge
 */

declare( strict_types=1 );

namespace IsuDev\WPContentBridge\Tests\Unit\Domain\Mutation;

use InvalidArgumentException;
use IsuDev\WPContentBridge\Domain\Mutation\ContentUpdate;
use PHPUnit\Framework\TestCase;

final class ContentUpdateTest extends TestCase {

	private const TOKEN = 'abcdef0123456789:2026-07-20 12:30:00';

	public function test_from_input_builds_title_only_update(): void {
		$update = ContentUpdate::from_input(
			array(
				'post_id'          => 42,
				'version_token'    => self::TOKEN,
				'title'            => 'New title',
			)
		);

		self::assertSame( 42, $update->post_id );
		self::assertSame( 'New title', $update->title );
		self::assertNull( $update->block_markup );
		self::assertNull( $update->taxonomies );
		self::assertSame( array( 'title' ), $update->changed_fields() );
		self::assertSame( self::TOKEN, $update->expected_version->to_string() );
	}

	public function test_changed_fields_lists_every_present_field(): void {
		$update = ContentUpdate::from_input(
			array(
				'post_id'       => 7,
				'version_token' => self::TOKEN,
				'title'         => 'T',
				'block_markup'  => '',
				'excerpt'       => 'E',
				'taxonomies'    => array(
					array(
						'taxonomy' => 'category',
						'term_ids' => array( 1 ),
					),
				),
			)
		);

		self::assertSame(
			array( 'title', 'content', 'excerpt', 'taxonomies' ),
			$update->changed_fields()
		);
	}

	public function test_from_input_rejects_no_updatable_fields(): void {
		$this->expectException( InvalidArgumentException::class );

		ContentUpdate::from_input(
			array(
				'post_id'       => 7,
				'version_token' => self::TOKEN,
			)
		);
	}

	public function test_from_input_rejects_missing_version_token(): void {
		$this->expectException( InvalidArgumentException::class );

		ContentUpdate::from_input(
			array(
				'post_id' => 7,
				'title'   => 'T',
			)
		);
	}

	public function test_from_input_rejects_non_positive_post_id(): void {
		$this->expectException( InvalidArgumentException::class );

		ContentUpdate::from_input(
			array(
				'post_id'       => 0,
				'version_token' => self::TOKEN,
				'title'         => 'T',
			)
		);
	}

	public function test_from_input_rejects_status_field(): void {
		$this->expectException( InvalidArgumentException::class );

		ContentUpdate::from_input(
			array(
				'post_id'       => 7,
				'version_token' => self::TOKEN,
				'status'        => 'publish',
			)
		);
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter ContentUpdateTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Write the DTO**

Create `src/Domain/Mutation/ContentUpdate.php`. Note the field name mapping: the wire field `block_markup` records as the audit/result field name `content`.

```php
<?php
/**
 * Validated input for updating an existing post.
 *
 * @package IsuDev\WPContentBridge
 */

declare( strict_types=1 );

namespace IsuDev\WPContentBridge\Domain\Mutation;

use InvalidArgumentException;

/**
 * Immutable, validated existing-post update. Carries no status field; updates
 * never change post status.
 */
final readonly class ContentUpdate {

	private const MAX_TITLE        = 500;
	private const MAX_EXCERPT      = 2000;
	private const MAX_MARKUP_BYTES = 500000;

	/**
	 * @param array<int, TaxonomyAssignment>|null $taxonomies Bounded assignments, or null when unchanged.
	 */
	public function __construct(
		public int $post_id,
		public VersionToken $expected_version,
		public ?string $title,
		public ?string $block_markup,
		public ?string $excerpt,
		public ?array $taxonomies,
	) {}

	/**
	 * Build from untrusted input.
	 *
	 * @param array<string, mixed> $input Raw update-content input.
	 * @throws InvalidArgumentException When input is malformed or empty.
	 */
	public static function from_input( array $input ): self {
		$allowed = array( 'post_id', 'version_token', 'title', 'block_markup', 'excerpt', 'taxonomies' );
		foreach ( array_keys( $input ) as $key ) {
			if ( ! in_array( $key, $allowed, true ) ) {
				throw new InvalidArgumentException( 'Update-content input contains an unsupported field.' );
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

		$title = null;
		if ( array_key_exists( 'title', $input ) && null !== $input['title'] ) {
			$candidate = $input['title'];
			if ( ! is_string( $candidate ) ) {
				throw new InvalidArgumentException( 'A title is invalid.' );
			}
			$candidate = trim( $candidate );
			if ( '' === $candidate || mb_strlen( $candidate ) > self::MAX_TITLE ) {
				throw new InvalidArgumentException( 'A title is invalid.' );
			}
			$title = $candidate;
		}

		$block_markup = null;
		if ( array_key_exists( 'block_markup', $input ) && null !== $input['block_markup'] ) {
			$candidate = $input['block_markup'];
			if ( ! is_string( $candidate ) || strlen( $candidate ) > self::MAX_MARKUP_BYTES ) {
				throw new InvalidArgumentException( 'Block markup is invalid.' );
			}
			$block_markup = $candidate;
		}

		$excerpt = null;
		if ( array_key_exists( 'excerpt', $input ) && null !== $input['excerpt'] ) {
			$candidate = $input['excerpt'];
			if ( ! is_string( $candidate ) || mb_strlen( $candidate ) > self::MAX_EXCERPT ) {
				throw new InvalidArgumentException( 'An excerpt is invalid.' );
			}
			$excerpt = $candidate;
		}

		$taxonomies = null;
		if ( array_key_exists( 'taxonomies', $input ) && null !== $input['taxonomies'] ) {
			if ( ! is_array( $input['taxonomies'] ) ) {
				throw new InvalidArgumentException( 'Taxonomies must be an array.' );
			}
			$taxonomies = array();
			foreach ( $input['taxonomies'] as $assignment ) {
				$taxonomies[] = TaxonomyAssignment::from_input( $assignment );
			}
		}

		if ( null === $title && null === $block_markup && null === $excerpt && null === $taxonomies ) {
			throw new InvalidArgumentException( 'An update must change at least one field.' );
		}

		return new self( $post_id, $expected_version, $title, $block_markup, $excerpt, $taxonomies );
	}

	/**
	 * Names of the fields this update changes (for audit + result).
	 *
	 * @return list<string>
	 */
	public function changed_fields(): array {
		$fields = array();
		if ( null !== $this->title ) {
			$fields[] = 'title';
		}
		if ( null !== $this->block_markup ) {
			$fields[] = 'content';
		}
		if ( null !== $this->excerpt ) {
			$fields[] = 'excerpt';
		}
		if ( null !== $this->taxonomies ) {
			$fields[] = 'taxonomies';
		}

		return $fields;
	}
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit --filter ContentUpdateTest`
Expected: PASS (6 tests).

- [ ] **Step 5: Static checks**

Run: `composer lint && composer analyse`
Expected: PHPCS clean; PHPStan `[OK] No errors`.

- [ ] **Step 6: Commit**

```bash
git add src/Domain/Mutation/ContentUpdate.php tests/Unit/Domain/Mutation/ContentUpdateTest.php
git commit -m "feat(mutation): add ContentUpdate DTO

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 4: `MutationResult` DTO

**Files:**
- Create: `src/Domain/Mutation/MutationResult.php`
- Test: `tests/Unit/Domain/Mutation/MutationResultTest.php`

**Interfaces:**
- Consumes: `VersionToken` (Plan 1).
- Produces: `final readonly class MutationResult` with public `int $post_id`, `string $post_type`, `string $status`, `VersionToken $version`, `array $changed_fields` (`@var array<int, string>`), `bool $created`; and `public function to_array(): array` emitting `schema_version`, `post_id`, `post_type`, `status`, `version_token` (string form), `changed_fields`, `created`, plus `provenance => array( 'source' => 'wordpress', 'untrusted' => true )`. (SEO `effective_seo` is intentionally NOT part of this DTO in Plan 2; Plan 3 extends it.)

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Domain/Mutation/MutationResultTest.php`:

```php
<?php
/**
 * Unit tests for the MutationResult DTO.
 *
 * @package IsuDev\WPContentBridge
 */

declare( strict_types=1 );

namespace IsuDev\WPContentBridge\Tests\Unit\Domain\Mutation;

use IsuDev\WPContentBridge\Domain\Mutation\MutationResult;
use IsuDev\WPContentBridge\Domain\Mutation\VersionToken;
use PHPUnit\Framework\TestCase;

final class MutationResultTest extends TestCase {

	public function test_to_array_emits_wire_shape(): void {
		$version = new VersionToken( 'abcdef0123456789', '2026-07-20 12:30:00' );
		$result  = new MutationResult( 42, 'post', 'draft', $version, array( 'title', 'content' ), true );

		$array = $result->to_array();

		self::assertSame( '1.0', $array['schema_version'] );
		self::assertSame( 42, $array['post_id'] );
		self::assertSame( 'post', $array['post_type'] );
		self::assertSame( 'draft', $array['status'] );
		self::assertSame( 'abcdef0123456789:2026-07-20 12:30:00', $array['version_token'] );
		self::assertSame( array( 'title', 'content' ), $array['changed_fields'] );
		self::assertTrue( $array['created'] );
		self::assertSame(
			array(
				'source'    => 'wordpress',
				'untrusted' => true,
			),
			$array['provenance']
		);
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter MutationResultTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Write the DTO**

Create `src/Domain/Mutation/MutationResult.php`:

```php
<?php
/**
 * Outcome of a successful content mutation.
 *
 * @package IsuDev\WPContentBridge
 */

declare( strict_types=1 );

namespace IsuDev\WPContentBridge\Domain\Mutation;

/**
 * Immutable result returned by write use cases to the adapter.
 */
final readonly class MutationResult {

	/**
	 * @param array<int, string> $changed_fields Field names that changed (never values).
	 */
	public function __construct(
		public int $post_id,
		public string $post_type,
		public string $status,
		public VersionToken $version,
		public array $changed_fields,
		public bool $created,
	) {}

	/**
	 * Wire representation.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
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
	}
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit --filter MutationResultTest`
Expected: PASS (1 test).

- [ ] **Step 5: Static checks**

Run: `composer lint && composer analyse`
Expected: PHPCS clean; PHPStan `[OK] No errors`.

- [ ] **Step 6: Commit**

```bash
git add src/Domain/Mutation/MutationResult.php tests/Unit/Domain/Mutation/MutationResultTest.php
git commit -m "feat(mutation): add MutationResult DTO

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 5: Application ports + typed failures + `CreateDraft` use case

**Files:**
- Create: `src/Application/Mutation/ContentMutationRepository.php` (port)
- Create: `src/Application/Mutation/IdempotencyStore.php` (port)
- Create: `src/Application/Mutation/MutationForbidden.php` (failure)
- Create: `src/Application/Mutation/MutationWriteFailed.php` (failure)
- Create: `src/Application/Mutation/CreateDraft.php` (use case)
- Test: `tests/Unit/Application/Mutation/CreateDraftTest.php`

**Interfaces:**
- Consumes: `ContentAccessManager` (`src/Application/ContentAccess/ContentAccessManager.php`, method `allows( string $post_type, ContentOperation $operation ): bool`), `ContentOperation` (`src/Domain/ContentAccess/ContentOperation.php`, cases `CREATE`, `UPDATE`), `BlockMarkupValidator` (Plan 1 port, `validate( string $markup ): array`), `AuditLog` + `AuditEvent` (Plan 1), `DraftInput` + `MutationResult` + `VersionToken` (Domain).
- Produces:
  - `interface ContentMutationRepository` with:
    - `public function post_type( int $post_id ): ?string;`
    - `public function current_version( int $post_id ): ?VersionToken;`
    - `public function create( DraftInput $input ): MutationResult;`  (always `created = true`, status `draft`)
    - `public function update( int $post_id, ContentUpdate $update ): MutationResult;`  (`created = false`)
    - `public function result_for( int $post_id ): ?MutationResult;`  (rebuild result for an existing post; `created = false`, `changed_fields = array()`)
  - `interface IdempotencyStore` with `public function find( int $user_id, string $key ): ?int;` and `public function remember( int $user_id, string $key, int $post_id ): void;`
  - `final class MutationForbidden extends RuntimeException` with `public function error_code(): string { return 'wpcb_forbidden'; }`
  - `final class MutationWriteFailed extends RuntimeException` with `public function error_code(): string { return 'wpcb_write_failed'; }`
  - `final readonly class CreateDraft` with `public function execute( array $raw_input, int $user_id ): MutationResult` (constructs the DTO internally so invalid input is audited).

**Write flow for `create-draft`** (stop at first failure; audit exactly once):
1. `DraftInput::from_input( $raw_input )` — `InvalidArgumentException` → outcome `invalid`, code `wpcb_invalid_input`.
2. Policy: `! $access->allows( $draft->post_type, ContentOperation::CREATE )` → throw `MutationForbidden` (outcome `denied`, code `wpcb_forbidden`).
3. Idempotency: if `$draft->idempotency_key` present and `$store->find( $user_id, $key )` returns an id whose `$repository->result_for( $id )` is non-null → return that result (`created = false`); audit outcome `success`.
4. Block validation (only if `'' !== $draft->block_markup`): `$reasons = $validator->validate( $draft->block_markup )`; non-empty → `throw new InvalidBlockMarkup( $reasons )` (outcome `invalid`, code `wpcb_invalid_blocks`).
5. Write: `$result = $repository->create( $draft )` (repo throws `MutationWriteFailed` on WP error → outcome `failure`).
6. If key present: `$store->remember( $user_id, $key, $result->post_id )`.
7. Audit outcome `success`; return `$result`.

- [ ] **Step 1: Write the ports and failures**

Create `src/Application/Mutation/ContentMutationRepository.php`:

```php
<?php
/**
 * Port for persisting content mutations.
 *
 * @package IsuDev\WPContentBridge
 */

declare( strict_types=1 );

namespace IsuDev\WPContentBridge\Application\Mutation;

use IsuDev\WPContentBridge\Domain\Mutation\ContentUpdate;
use IsuDev\WPContentBridge\Domain\Mutation\DraftInput;
use IsuDev\WPContentBridge\Domain\Mutation\MutationResult;
use IsuDev\WPContentBridge\Domain\Mutation\VersionToken;

/**
 * Persists new and updated content. The only implementation calls WordPress.
 */
interface ContentMutationRepository {

	/**
	 * Post type of an existing, eligible object, or null when absent/ineligible.
	 */
	public function post_type( int $post_id ): ?string;

	/**
	 * Current version token for an existing object, or null when absent.
	 */
	public function current_version( int $post_id ): ?VersionToken;

	/**
	 * Create a new draft. Always returns a result with created = true.
	 *
	 * @throws MutationWriteFailed When WordPress rejects the write.
	 */
	public function create( DraftInput $input ): MutationResult;

	/**
	 * Apply an update to an existing post. Returns created = false.
	 *
	 * @throws MutationWriteFailed When WordPress rejects the write.
	 */
	public function update( int $post_id, ContentUpdate $update ): MutationResult;

	/**
	 * Rebuild a result for an already-existing post (idempotent replay).
	 * Returns created = false with empty changed_fields, or null if absent.
	 */
	public function result_for( int $post_id ): ?MutationResult;
}
```

Create `src/Application/Mutation/IdempotencyStore.php`:

```php
<?php
/**
 * Port for per-user idempotency-key persistence.
 *
 * @package IsuDev\WPContentBridge
 */

declare( strict_types=1 );

namespace IsuDev\WPContentBridge\Application\Mutation;

/**
 * Maps a user-scoped idempotency key to a created post ID for a bounded TTL.
 */
interface IdempotencyStore {

	/**
	 * Post ID previously created for this user + key, or null.
	 */
	public function find( int $user_id, string $key ): ?int;

	/**
	 * Record that this user + key created the given post ID.
	 */
	public function remember( int $user_id, string $key, int $post_id ): void;
}
```

Create `src/Application/Mutation/MutationForbidden.php`:

```php
<?php
/**
 * Raised when per-post-type write policy denies a mutation.
 *
 * @package IsuDev\WPContentBridge
 */

declare( strict_types=1 );

namespace IsuDev\WPContentBridge\Application\Mutation;

use RuntimeException;

/**
 * Policy-level denial (distinct from capability denial, which the adapter's
 * permission callback handles before the use case runs).
 */
final class MutationForbidden extends RuntimeException {

	public function error_code(): string {
		return 'wpcb_forbidden';
	}
}
```

Create `src/Application/Mutation/MutationWriteFailed.php`:

```php
<?php
/**
 * Raised when WordPress rejects a content write.
 *
 * @package IsuDev\WPContentBridge
 */

declare( strict_types=1 );

namespace IsuDev\WPContentBridge\Application\Mutation;

use RuntimeException;

/**
 * Infrastructure-level write failure surfaced to the adapter as wpcb_write_failed.
 */
final class MutationWriteFailed extends RuntimeException {

	public function error_code(): string {
		return 'wpcb_write_failed';
	}
}
```

- [ ] **Step 2: Write the failing test**

Create `tests/Unit/Application/Mutation/CreateDraftTest.php`. Build fakes via anonymous classes implementing the ports (mirror `tests/Unit/Application/ContentAccess/ContentAccessManagerTest.php`). Includes a spy `AuditLog` to assert exactly-one-row-per-attempt:

```php
<?php
/**
 * Unit tests for the CreateDraft use case.
 *
 * @package IsuDev\WPContentBridge
 */

declare( strict_types=1 );

namespace IsuDev\WPContentBridge\Tests\Unit\Application\Mutation;

use IsuDev\WPContentBridge\Application\ContentAccess\ContentAccessManager;
use IsuDev\WPContentBridge\Application\ContentAccess\ContentAccessSettingsRepository;
use IsuDev\WPContentBridge\Application\ContentAccess\ContentTypeCatalog;
use IsuDev\WPContentBridge\Application\Mutation\AuditEvent;
use IsuDev\WPContentBridge\Application\Mutation\AuditLog;
use IsuDev\WPContentBridge\Application\Mutation\BlockMarkupValidator;
use IsuDev\WPContentBridge\Application\Mutation\ContentMutationRepository;
use IsuDev\WPContentBridge\Application\Mutation\CreateDraft;
use IsuDev\WPContentBridge\Application\Mutation\IdempotencyStore;
use IsuDev\WPContentBridge\Application\Mutation\InvalidBlockMarkup;
use IsuDev\WPContentBridge\Application\Mutation\MutationForbidden;
use IsuDev\WPContentBridge\Domain\ContentAccess\ContentTypeDefinition;
use IsuDev\WPContentBridge\Domain\Mutation\ContentUpdate;
use IsuDev\WPContentBridge\Domain\Mutation\DraftInput;
use IsuDev\WPContentBridge\Domain\Mutation\MutationResult;
use IsuDev\WPContentBridge\Domain\Mutation\VersionToken;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CreateDraftTest extends TestCase {

	public function test_creates_draft_and_records_success(): void {
		$audit = $this->audit_spy();
		$use_case = new CreateDraft(
			$this->manager_allowing_create( true ),
			$this->passing_validator(),
			$this->creating_repository(),
			$this->empty_store(),
			$audit
		);

		$result = $use_case->execute(
			array(
				'post_type' => 'post',
				'title'     => 'Hello',
			),
			5
		);

		self::assertTrue( $result->created );
		self::assertSame( 'draft', $result->status );
		self::assertCount( 1, $audit->events );
		self::assertSame( 'success', $audit->events[0]->outcome );
	}

	public function test_policy_denial_throws_and_records_denied(): void {
		$audit = $this->audit_spy();
		$use_case = new CreateDraft(
			$this->manager_allowing_create( false ),
			$this->passing_validator(),
			$this->creating_repository(),
			$this->empty_store(),
			$audit
		);

		try {
			$use_case->execute( array( 'post_type' => 'post', 'title' => 'Hi' ), 5 );
			self::fail( 'Expected MutationForbidden.' );
		} catch ( MutationForbidden $forbidden ) {
			self::assertSame( 'wpcb_forbidden', $forbidden->error_code() );
		}

		self::assertCount( 1, $audit->events );
		self::assertSame( 'denied', $audit->events[0]->outcome );
	}

	public function test_invalid_input_records_invalid(): void {
		$audit = $this->audit_spy();
		$use_case = new CreateDraft(
			$this->manager_allowing_create( true ),
			$this->passing_validator(),
			$this->creating_repository(),
			$this->empty_store(),
			$audit
		);

		$this->expectException( InvalidArgumentException::class );

		try {
			$use_case->execute( array( 'post_type' => 'post' ), 5 );
		} finally {
			self::assertCount( 1, $audit->events );
			self::assertSame( 'invalid', $audit->events[0]->outcome );
			self::assertSame( 'wpcb_invalid_input', $audit->events[0]->error_code );
		}
	}

	public function test_invalid_blocks_records_invalid(): void {
		$audit = $this->audit_spy();
		$use_case = new CreateDraft(
			$this->manager_allowing_create( true ),
			$this->failing_validator( array( 'block 0: unregistered' ) ),
			$this->creating_repository(),
			$this->empty_store(),
			$audit
		);

		$this->expectException( InvalidBlockMarkup::class );

		try {
			$use_case->execute(
				array(
					'post_type'    => 'post',
					'title'        => 'Hi',
					'block_markup' => '<!-- wp:acme/nope /-->',
				),
				5
			);
		} finally {
			self::assertCount( 1, $audit->events );
			self::assertSame( 'invalid', $audit->events[0]->outcome );
			self::assertSame( 'wpcb_invalid_blocks', $audit->events[0]->error_code );
		}
	}

	public function test_idempotent_replay_returns_existing_without_creating(): void {
		$audit = $this->audit_spy();
		$repository = $this->replay_repository( 99 );
		$store = $this->store_with( 5, 'key-1', 99 );
		$use_case = new CreateDraft(
			$this->manager_allowing_create( true ),
			$this->passing_validator(),
			$repository,
			$store,
			$audit
		);

		$result = $use_case->execute(
			array(
				'post_type'       => 'post',
				'title'           => 'Hi',
				'idempotency_key' => 'key-1',
			),
			5
		);

		self::assertFalse( $result->created );
		self::assertSame( 99, $result->post_id );
		self::assertCount( 1, $audit->events );
		self::assertSame( 'success', $audit->events[0]->outcome );
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

	private function manager_allowing_create( bool $allow ): ContentAccessManager {
		$stored = $allow
			? array( 'post' => array( 'get_content' => true, 'create_draft' => true ) )
			: array( 'post' => array( 'get_content' => true, 'create_draft' => false ) );

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

	private function passing_validator(): BlockMarkupValidator {
		return new class() implements BlockMarkupValidator {
			public function validate( string $markup ): array {
				return array();
			}
		};
	}

	/**
	 * @param array<int, string> $reasons Failure reasons to return.
	 */
	private function failing_validator( array $reasons ): BlockMarkupValidator {
		return new class( $reasons ) implements BlockMarkupValidator {
			/** @param array<int, string> $reasons */
			public function __construct( private array $reasons ) {}

			public function validate( string $markup ): array {
				return $this->reasons;
			}
		};
	}

	private function creating_repository(): ContentMutationRepository {
		return new class() implements ContentMutationRepository {
			public function post_type( int $post_id ): ?string {
				return 'post';
			}

			public function current_version( int $post_id ): ?VersionToken {
				return new VersionToken( 'abcdef0123456789', '2026-07-20 00:00:00' );
			}

			public function create( DraftInput $input ): MutationResult {
				return new MutationResult(
					10,
					$input->post_type,
					'draft',
					new VersionToken( 'abcdef0123456789', '2026-07-20 00:00:00' ),
					array( 'title' ),
					true
				);
			}

			public function update( int $post_id, ContentUpdate $update ): MutationResult {
				throw new \LogicException( 'not used' );
			}

			public function result_for( int $post_id ): ?MutationResult {
				return null;
			}
		};
	}

	private function replay_repository( int $existing_id ): ContentMutationRepository {
		return new class( $existing_id ) implements ContentMutationRepository {
			public function __construct( private int $existing_id ) {}

			public function post_type( int $post_id ): ?string {
				return 'post';
			}

			public function current_version( int $post_id ): ?VersionToken {
				return new VersionToken( 'abcdef0123456789', '2026-07-20 00:00:00' );
			}

			public function create( DraftInput $input ): MutationResult {
				throw new \LogicException( 'create must not be called on replay' );
			}

			public function update( int $post_id, ContentUpdate $update ): MutationResult {
				throw new \LogicException( 'not used' );
			}

			public function result_for( int $post_id ): ?MutationResult {
				if ( $post_id !== $this->existing_id ) {
					return null;
				}

				return new MutationResult(
					$this->existing_id,
					'post',
					'draft',
					new VersionToken( 'abcdef0123456789', '2026-07-20 00:00:00' ),
					array(),
					false
				);
			}
		};
	}

	private function empty_store(): IdempotencyStore {
		return new class() implements IdempotencyStore {
			public function find( int $user_id, string $key ): ?int {
				return null;
			}

			public function remember( int $user_id, string $key, int $post_id ): void {}
		};
	}

	private function store_with( int $user_id, string $key, int $post_id ): IdempotencyStore {
		return new class( $user_id, $key, $post_id ) implements IdempotencyStore {
			public function __construct(
				private int $user_id,
				private string $key,
				private int $post_id
			) {}

			public function find( int $user_id, string $key ): ?int {
				return ( $user_id === $this->user_id && $key === $this->key ) ? $this->post_id : null;
			}

			public function remember( int $user_id, string $key, int $post_id ): void {}
		};
	}
}
```

- [ ] **Step 3: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter CreateDraftTest`
Expected: FAIL — `CreateDraft` not found.

- [ ] **Step 4: Write the use case**

Create `src/Application/Mutation/CreateDraft.php`:

```php
<?php
/**
 * Create-draft use case.
 *
 * @package IsuDev\WPContentBridge
 */

declare( strict_types=1 );

namespace IsuDev\WPContentBridge\Application\Mutation;

use InvalidArgumentException;
use IsuDev\WPContentBridge\Application\ContentAccess\ContentAccessManager;
use IsuDev\WPContentBridge\Domain\ContentAccess\ContentOperation;
use IsuDev\WPContentBridge\Domain\Mutation\DraftInput;
use IsuDev\WPContentBridge\Domain\Mutation\MutationResult;
use Throwable;

/**
 * Orchestrates draft creation: validate, authorize (policy), idempotency,
 * block validation, write, audit. Capability checks live in the adapter's
 * permission callback; this use case enforces per-post-type policy and records
 * exactly one audit row per attempt.
 */
final readonly class CreateDraft {

	public const ABILITY = 'wp-content-bridge/create-draft';

	public function __construct(
		private ContentAccessManager $access,
		private BlockMarkupValidator $validator,
		private ContentMutationRepository $repository,
		private IdempotencyStore $idempotency,
		private AuditLog $audit,
	) {}

	/**
	 * @param array<string, mixed> $raw_input Normalized ability input.
	 * @throws InvalidArgumentException When input is malformed.
	 * @throws MutationForbidden When policy denies the type.
	 * @throws InvalidBlockMarkup When block markup is invalid.
	 * @throws MutationWriteFailed When WordPress rejects the write.
	 */
	public function execute( array $raw_input, int $user_id ): MutationResult {
		$post_type = is_string( $raw_input['post_type'] ?? null ) ? $raw_input['post_type'] : null;

		try {
			$draft     = DraftInput::from_input( $raw_input );
			$post_type = $draft->post_type;

			if ( ! $this->access->allows( $draft->post_type, ContentOperation::CREATE ) ) {
				throw new MutationForbidden( 'Content creation is not permitted for this type.' );
			}

			if ( null !== $draft->idempotency_key ) {
				$existing_id = $this->idempotency->find( $user_id, $draft->idempotency_key );
				if ( null !== $existing_id ) {
					$replayed = $this->repository->result_for( $existing_id );
					if ( null !== $replayed ) {
						$this->record_success( $user_id, $replayed, null );
						return $replayed;
					}
				}
			}

			if ( '' !== $draft->block_markup ) {
				$reasons = $this->validator->validate( $draft->block_markup );
				if ( array() !== $reasons ) {
					throw new InvalidBlockMarkup( $reasons );
				}
			}

			$result = $this->repository->create( $draft );

			if ( null !== $draft->idempotency_key ) {
				$this->idempotency->remember( $user_id, $draft->idempotency_key, $result->post_id );
			}

			$this->record_success( $user_id, $result, null );

			return $result;
		} catch ( Throwable $error ) {
			$this->record_failure( $user_id, $post_type, $error );
			throw $error;
		}
	}

	private function record_success( int $user_id, MutationResult $result, ?string $expected_version ): void {
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
	}

	private function record_failure( int $user_id, ?string $post_type, Throwable $error ): void {
		[ $outcome, $code ] = $this->classify( $error );

		$this->audit->record(
			new AuditEvent(
				$user_id,
				self::ABILITY,
				null,
				$post_type,
				array(),
				null,
				null,
				$outcome,
				$code
			)
		);
	}

	/**
	 * @return array{0: string, 1: string} Outcome and stable error code.
	 */
	private function classify( Throwable $error ): array {
		if ( $error instanceof InvalidArgumentException ) {
			return array( 'invalid', 'wpcb_invalid_input' );
		}
		if ( $error instanceof MutationForbidden ) {
			return array( 'denied', 'wpcb_forbidden' );
		}
		if ( $error instanceof InvalidBlockMarkup ) {
			return array( 'invalid', 'wpcb_invalid_blocks' );
		}
		if ( $error instanceof MutationWriteFailed ) {
			return array( 'failure', 'wpcb_write_failed' );
		}

		return array( 'failure', 'wpcb_write_failed' );
	}
}
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `vendor/bin/phpunit --filter CreateDraftTest`
Expected: PASS (5 tests).

- [ ] **Step 6: Static checks**

Run: `composer lint && composer analyse`
Expected: PHPCS clean; PHPStan `[OK] No errors`.

- [ ] **Step 7: Commit**

```bash
git add src/Application/Mutation/ContentMutationRepository.php src/Application/Mutation/IdempotencyStore.php src/Application/Mutation/MutationForbidden.php src/Application/Mutation/MutationWriteFailed.php src/Application/Mutation/CreateDraft.php tests/Unit/Application/Mutation/CreateDraftTest.php
git commit -m "feat(mutation): add mutation ports, failures, and CreateDraft use case

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 6: `UpdateContent` use case

**Files:**
- Create: `src/Application/Mutation/UpdateContent.php`
- Test: `tests/Unit/Application/Mutation/UpdateContentTest.php`

**Interfaces:**
- Consumes: `ContentAccessManager` (`allows(..., ContentOperation::UPDATE)`), `BlockMarkupValidator`, `ContentMutationRepository` (`post_type`, `current_version`, `update`), `AuditLog`/`AuditEvent`, `ContentUpdate`/`MutationResult`/`VersionToken`, `MutationConflict` (Plan 1), `MutationForbidden`, `MutationWriteFailed`, and `ContentUnavailable` (`src/Application/Content/ContentUnavailable.php`, reused for not-found/ineligible).
- Produces: `final readonly class UpdateContent` with `public const ABILITY = 'wp-content-bridge/update-content';` and `public function execute( array $raw_input, int $user_id ): MutationResult`.

**Write flow for `update-content`:**
1. `ContentUpdate::from_input( $raw_input )` — `InvalidArgumentException` → `invalid`/`wpcb_invalid_input`.
2. `$post_type = $repository->post_type( $update->post_id )`; `null === $post_type` → throw `ContentUnavailable` (outcome `invalid`, code `wpcb_content_unavailable`).
3. Policy: `! $access->allows( $post_type, ContentOperation::UPDATE )` → `MutationForbidden` (`denied`/`wpcb_forbidden`).
4. Concurrency: `$current = $repository->current_version( $update->post_id )`; `null === $current` → `ContentUnavailable`; `! $current->equals( $update->expected_version )` → `MutationConflict` (`conflict`/`wpcb_conflict`, no write).
5. Block validation only if `null !== $update->block_markup && '' !== $update->block_markup`.
6. `$result = $repository->update( $update->post_id, $update )`.
7. Audit `success` (expected_version = the incoming token string); return.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Application/Mutation/UpdateContentTest.php`. Reuse the fake idioms from Task 5. Cover: happy path (success + audit success), stale token → `MutationConflict` and NO `update()` call (assert via a repository flag), policy denial, not-found (`ContentUnavailable`), invalid input.

```php
<?php
/**
 * Unit tests for the UpdateContent use case.
 *
 * @package IsuDev\WPContentBridge
 */

declare( strict_types=1 );

namespace IsuDev\WPContentBridge\Tests\Unit\Application\Mutation;

use IsuDev\WPContentBridge\Application\Content\ContentUnavailable;
use IsuDev\WPContentBridge\Application\ContentAccess\ContentAccessManager;
use IsuDev\WPContentBridge\Application\ContentAccess\ContentAccessSettingsRepository;
use IsuDev\WPContentBridge\Application\ContentAccess\ContentTypeCatalog;
use IsuDev\WPContentBridge\Application\Mutation\AuditEvent;
use IsuDev\WPContentBridge\Application\Mutation\AuditLog;
use IsuDev\WPContentBridge\Application\Mutation\BlockMarkupValidator;
use IsuDev\WPContentBridge\Application\Mutation\ContentMutationRepository;
use IsuDev\WPContentBridge\Application\Mutation\MutationConflict;
use IsuDev\WPContentBridge\Application\Mutation\MutationForbidden;
use IsuDev\WPContentBridge\Application\Mutation\UpdateContent;
use IsuDev\WPContentBridge\Domain\ContentAccess\ContentTypeDefinition;
use IsuDev\WPContentBridge\Domain\Mutation\ContentUpdate;
use IsuDev\WPContentBridge\Domain\Mutation\DraftInput;
use IsuDev\WPContentBridge\Domain\Mutation\MutationResult;
use IsuDev\WPContentBridge\Domain\Mutation\VersionToken;
use PHPUnit\Framework\TestCase;

final class UpdateContentTest extends TestCase {

	private const CURRENT = 'abcdef0123456789:2026-07-20 00:00:00';
	private const STALE   = '0000000000000000:2026-07-19 00:00:00';

	public function test_updates_and_records_success(): void {
		$audit = $this->audit_spy();
		$repository = $this->repository( self::CURRENT );
		$use_case = new UpdateContent(
			$this->manager_allowing_update( true ),
			$this->passing_validator(),
			$repository,
			$audit
		);

		$result = $use_case->execute(
			array(
				'post_id'       => 42,
				'version_token' => self::CURRENT,
				'title'         => 'New',
			),
			5
		);

		self::assertFalse( $result->created );
		self::assertCount( 1, $audit->events );
		self::assertSame( 'success', $audit->events[0]->outcome );
		self::assertSame( self::CURRENT, $audit->events[0]->expected_version );
	}

	public function test_stale_token_conflicts_and_does_not_write(): void {
		$audit = $this->audit_spy();
		$repository = $this->repository( self::CURRENT );
		$use_case = new UpdateContent(
			$this->manager_allowing_update( true ),
			$this->passing_validator(),
			$repository,
			$audit
		);

		try {
			$use_case->execute(
				array(
					'post_id'       => 42,
					'version_token' => self::STALE,
					'title'         => 'New',
				),
				5
			);
			self::fail( 'Expected MutationConflict.' );
		} catch ( MutationConflict $conflict ) {
			self::assertSame( 'wpcb_conflict', $conflict->error_code() );
		}

		self::assertFalse( $repository->updated, 'update() must not run on conflict.' );
		self::assertCount( 1, $audit->events );
		self::assertSame( 'conflict', $audit->events[0]->outcome );
	}

	public function test_missing_post_is_unavailable(): void {
		$audit = $this->audit_spy();
		$use_case = new UpdateContent(
			$this->manager_allowing_update( true ),
			$this->passing_validator(),
			$this->repository( null ),
			$audit
		);

		$this->expectException( ContentUnavailable::class );

		try {
			$use_case->execute(
				array(
					'post_id'       => 42,
					'version_token' => self::CURRENT,
					'title'         => 'New',
				),
				5
			);
		} finally {
			self::assertCount( 1, $audit->events );
			self::assertSame( 'invalid', $audit->events[0]->outcome );
			self::assertSame( 'wpcb_content_unavailable', $audit->events[0]->error_code );
		}
	}

	public function test_policy_denial_is_forbidden(): void {
		$audit = $this->audit_spy();
		$use_case = new UpdateContent(
			$this->manager_allowing_update( false ),
			$this->passing_validator(),
			$this->repository( self::CURRENT ),
			$audit
		);

		$this->expectException( MutationForbidden::class );

		try {
			$use_case->execute(
				array(
					'post_id'       => 42,
					'version_token' => self::CURRENT,
					'title'         => 'New',
				),
				5
			);
		} finally {
			self::assertSame( 'denied', $audit->events[0]->outcome );
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

	private function manager_allowing_update( bool $allow ): ContentAccessManager {
		$stored = array(
			'post' => array(
				'get_content'    => true,
				'update_content' => $allow,
			),
		);

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

	private function passing_validator(): BlockMarkupValidator {
		return new class() implements BlockMarkupValidator {
			public function validate( string $markup ): array {
				return array();
			}
		};
	}

	/**
	 * @param string|null $current_token Serialized current token, or null for a missing post.
	 */
	private function repository( ?string $current_token ): ContentMutationRepository {
		return new class( $current_token ) implements ContentMutationRepository {
			public bool $updated = false;

			public function __construct( private ?string $current_token ) {}

			public function post_type( int $post_id ): ?string {
				return null === $this->current_token ? null : 'post';
			}

			public function current_version( int $post_id ): ?VersionToken {
				return null === $this->current_token ? null : VersionToken::from_string( $this->current_token );
			}

			public function create( DraftInput $input ): MutationResult {
				throw new \LogicException( 'not used' );
			}

			public function update( int $post_id, ContentUpdate $update ): MutationResult {
				$this->updated = true;

				return new MutationResult(
					$post_id,
					'post',
					'draft',
					new VersionToken( 'fedcba9876543210', '2026-07-20 01:00:00' ),
					$update->changed_fields(),
					false
				);
			}

			public function result_for( int $post_id ): ?MutationResult {
				return null;
			}
		};
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter UpdateContentTest`
Expected: FAIL — `UpdateContent` not found.

- [ ] **Step 3: Write the use case**

Create `src/Application/Mutation/UpdateContent.php`:

```php
<?php
/**
 * Update-content use case.
 *
 * @package IsuDev\WPContentBridge
 */

declare( strict_types=1 );

namespace IsuDev\WPContentBridge\Application\Mutation;

use InvalidArgumentException;
use IsuDev\WPContentBridge\Application\Content\ContentUnavailable;
use IsuDev\WPContentBridge\Application\ContentAccess\ContentAccessManager;
use IsuDev\WPContentBridge\Domain\ContentAccess\ContentOperation;
use IsuDev\WPContentBridge\Domain\Mutation\ContentUpdate;
use IsuDev\WPContentBridge\Domain\Mutation\MutationResult;
use Throwable;

/**
 * Orchestrates an update to an existing post with optimistic concurrency. Never
 * changes post status. Records exactly one audit row per attempt.
 */
final readonly class UpdateContent {

	public const ABILITY = 'wp-content-bridge/update-content';

	public function __construct(
		private ContentAccessManager $access,
		private BlockMarkupValidator $validator,
		private ContentMutationRepository $repository,
		private AuditLog $audit,
	) {}

	/**
	 * @param array<string, mixed> $raw_input Normalized ability input.
	 * @throws InvalidArgumentException When input is malformed.
	 * @throws ContentUnavailable When the target is absent or ineligible.
	 * @throws MutationForbidden When policy denies the type.
	 * @throws MutationConflict When the version token is stale.
	 * @throws InvalidBlockMarkup When block markup is invalid.
	 * @throws MutationWriteFailed When WordPress rejects the write.
	 */
	public function execute( array $raw_input, int $user_id ): MutationResult {
		$post_id          = null;
		$post_type        = null;
		$expected_version = null;

		try {
			$update           = ContentUpdate::from_input( $raw_input );
			$post_id          = $update->post_id;
			$expected_version = $update->expected_version->to_string();

			$post_type = $this->repository->post_type( $update->post_id );
			if ( null === $post_type ) {
				throw new ContentUnavailable( 'Content is unavailable.' );
			}

			if ( ! $this->access->allows( $post_type, ContentOperation::UPDATE ) ) {
				throw new MutationForbidden( 'Content updates are not permitted for this type.' );
			}

			$current = $this->repository->current_version( $update->post_id );
			if ( null === $current ) {
				throw new ContentUnavailable( 'Content is unavailable.' );
			}
			if ( ! $current->equals( $update->expected_version ) ) {
				throw new MutationConflict( 'The submitted version token is stale.' );
			}

			if ( null !== $update->block_markup && '' !== $update->block_markup ) {
				$reasons = $this->validator->validate( $update->block_markup );
				if ( array() !== $reasons ) {
					throw new InvalidBlockMarkup( $reasons );
				}
			}

			$result = $this->repository->update( $update->post_id, $update );

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
	}

	/**
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
		if ( $error instanceof InvalidBlockMarkup ) {
			return array( 'invalid', 'wpcb_invalid_blocks' );
		}

		return array( 'failure', 'wpcb_write_failed' );
	}
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit --filter UpdateContentTest`
Expected: PASS (4 tests).

- [ ] **Step 5: Static checks**

Run: `composer lint && composer analyse`
Expected: PHPCS clean; PHPStan `[OK] No errors`.

- [ ] **Step 6: Commit**

```bash
git add src/Application/Mutation/UpdateContent.php tests/Unit/Application/Mutation/UpdateContentTest.php
git commit -m "feat(mutation): add UpdateContent use case

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 7: `PhpBlockMarkupValidator` (Infrastructure)

**Files:**
- Create: `src/Infrastructure/WordPress/PhpBlockMarkupValidator.php`
- Runtime verifier: extend `tests/Integration/writes-mutation-verification.php` in Task 13 (this task adds a small standalone check block; the full verifier is Task 13).

**Interfaces:**
- Consumes: WordPress core `parse_blocks()`, `serialize_blocks()`, `WP_Block_Type_Registry`.
- Produces: `final class PhpBlockMarkupValidator implements BlockMarkupValidator` (`validate( string $markup ): array`, `@return list<string>` bounded reasons; empty = valid).

**Validation rules (spec §8):** empty string is valid; non-empty must parse to ≥1 block; every parsed top-level block with a non-null `blockName` must be a **registered** type (`WP_Block_Type_Registry::get_instance()->is_registered()`); freeform blocks (`blockName === null`) are allowed only if their `innerHTML` contains no `<!-- wp:` delimiter fragment; round-trip guard: the top-level block-name sequence of `parse_blocks( serialize_blocks( parse_blocks( $markup ) ) )` must equal that of `parse_blocks( $markup )`. Cap reasons at 20. Never include the raw markup in a reason.

Note: this class calls WordPress functions, so it is **not** unit-tested under phpunit (WP is not loaded there). It is exercised by the runtime verifier (Task 13). This task ships the class + static checks only.

- [ ] **Step 1: Write the validator**

Create `src/Infrastructure/WordPress/PhpBlockMarkupValidator.php`:

```php
<?php
/**
 * Block-markup validator backed by WordPress core block parsing.
 *
 * @package IsuDev\WPContentBridge
 */

declare( strict_types=1 );

namespace IsuDev\WPContentBridge\Infrastructure\WordPress;

use IsuDev\WPContentBridge\Application\Mutation\BlockMarkupValidator;
use WP_Block_Type_Registry;

/**
 * Basic parse round-trip validation. Deeper per-attribute schema validation is
 * intentionally deferred to a later milestone.
 */
final class PhpBlockMarkupValidator implements BlockMarkupValidator {

	private const MAX_REASONS = 20;

	/**
	 * @return list<string> Bounded failure reasons; empty means valid.
	 */
	public function validate( string $markup ): array {
		if ( '' === trim( $markup ) ) {
			return array();
		}

		$reasons = array();
		$blocks  = parse_blocks( $markup );

		$meaningful = array_filter(
			$blocks,
			static function ( array $block ): bool {
				if ( null !== $block['blockName'] ) {
					return true;
				}

				return '' !== trim( (string) ( $block['innerHTML'] ?? '' ) );
			}
		);

		if ( array() === $meaningful ) {
			$reasons[] = 'Markup contains no blocks.';
			return $reasons;
		}

		$registry = WP_Block_Type_Registry::get_instance();

		foreach ( $blocks as $index => $block ) {
			$name = $block['blockName'];

			if ( null === $name ) {
				$inner = (string) ( $block['innerHTML'] ?? '' );
				if ( str_contains( $inner, '<!-- wp:' ) ) {
					$reasons[] = sprintf( 'Block %d: stray block delimiter in freeform content.', $index );
				}
				continue;
			}

			if ( ! $registry->is_registered( $name ) ) {
				$reasons[] = sprintf( 'Block %d: unregistered block type.', $index );
			}

			if ( count( $reasons ) >= self::MAX_REASONS ) {
				break;
			}
		}

		if ( count( $reasons ) < self::MAX_REASONS && ! $this->round_trips( $blocks ) ) {
			$reasons[] = 'Markup does not survive a parse/serialize round-trip.';
		}

		return array_slice( array_values( $reasons ), 0, self::MAX_REASONS );
	}

	/**
	 * True when re-parsing the serialized blocks yields the same top-level name sequence.
	 *
	 * @param array<int, array<string, mixed>> $blocks Parsed blocks.
	 */
	private function round_trips( array $blocks ): bool {
		$reparsed = parse_blocks( serialize_blocks( $blocks ) );

		return $this->names( $blocks ) === $this->names( $reparsed );
	}

	/**
	 * @param array<int, array<string, mixed>> $blocks Parsed blocks.
	 * @return list<string|null>
	 */
	private function names( array $blocks ): array {
		return array_values(
			array_map(
				static fn ( array $block ): ?string => $block['blockName'],
				$blocks
			)
		);
	}
}
```

- [ ] **Step 2: Static checks**

Run: `composer lint && composer analyse`
Expected: PHPCS clean; PHPStan `[OK] No errors`. (`parse_blocks`, `serialize_blocks`, `WP_Block_Type_Registry` resolve via the WordPress stubs.)

- [ ] **Step 3: Smoke-check against the live install**

Run:

```bash
cd "/Users/lukaszbiedron/Local Sites/kormas-isu/app/public" && wp eval '
require "/Users/lukaszbiedron/Other Projects/wp-content-bridge/vendor/autoload.php";
$v = new IsuDev\WPContentBridge\Infrastructure\WordPress\PhpBlockMarkupValidator();
echo "empty=" . wp_json_encode( $v->validate( "" ) ) . "\n";
echo "valid=" . wp_json_encode( $v->validate( "<!-- wp:paragraph --><p>Hi</p><!-- /wp:paragraph -->" ) ) . "\n";
echo "bad=" . wp_json_encode( $v->validate( "<!-- wp:acme/nope /-->" ) ) . "\n";
'
```

Expected: `empty=[]`; `valid=[]`; `bad=["Block 0: unregistered block type."]`.

- [ ] **Step 4: Commit**

```bash
git add src/Infrastructure/WordPress/PhpBlockMarkupValidator.php
git commit -m "feat(mutation): add PhpBlockMarkupValidator

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 8: `WordPressContentMutationRepository` (Infrastructure)

**Files:**
- Create: `src/Infrastructure/WordPress/WordPressContentMutationRepository.php`

**Interfaces:**
- Consumes: WordPress core `get_post`, `get_post_type_object`, `wp_insert_post`, `wp_update_post`, `wp_set_object_terms`, `is_wp_error`. `VersionToken::for_content`.
- Produces: `final class WordPressContentMutationRepository implements ContentMutationRepository`.

**Behavior:**
- `post_type( int $post_id )`: `$post = get_post( $post_id )`; return `$post instanceof WP_Post ? $post->post_type : null`.
- `current_version( int $post_id )`: `$post = get_post( $post_id )`; null → null; else `VersionToken::for_content( $post->post_modified_gmt, $post->post_title, $post->post_content, $post->post_status )`.
- `create( DraftInput $input )`: build `$args = array( 'post_type' => $input->post_type, 'post_status' => 'draft', 'post_title' => $input->title, 'post_content' => $input->block_markup, 'post_excerpt' => (string) $input->excerpt )`; `$id = wp_insert_post( $args, true )`; `is_wp_error( $id ) || 0 === $id` → `throw new MutationWriteFailed(...)`. Apply taxonomies via `$this->apply_taxonomies( $id, $input->taxonomies )`. Then `return $this->result_for_created( $id, true, $this->created_field_names( $input ) )`.
- `update( int $post_id, ContentUpdate $update )`: build `$args = array( 'ID' => $post_id )` adding only present fields (`post_title`, `post_content`, `post_excerpt`); **never** set `post_status`. `$result = wp_update_post( $args, true )`; error/0 → `MutationWriteFailed`. Apply taxonomies if `null !== $update->taxonomies`. Return a fresh `MutationResult` (created = false, changed_fields = `$update->changed_fields()`).
- `result_for( int $post_id )`: `get_post`; null → null; else `MutationResult` with `created = false`, `changed_fields = array()`.
- `apply_taxonomies( int $post_id, array $taxonomies )`: for each `TaxonomyAssignment`, `wp_set_object_terms( $post_id, $assignment->term_ids, $assignment->taxonomy, false )` (replace). On `WP_Error` throw `MutationWriteFailed`.
- Helper `built_result( WP_Post $post, bool $created, array $changed_fields ): MutationResult` builds the token via `VersionToken::for_content(...)`.
- `created_field_names( DraftInput $input ): array` returns `['title']` plus `'content'` if markup non-empty, `'excerpt'` if excerpt set, `'taxonomies'` if any.

Runtime-verified in Task 13. This task ships the class + static checks + a `wp eval` smoke create.

- [ ] **Step 1: Write the repository**

Create `src/Infrastructure/WordPress/WordPressContentMutationRepository.php`:

```php
<?php
/**
 * WordPress-backed content mutation repository.
 *
 * @package IsuDev\WPContentBridge
 */

declare( strict_types=1 );

namespace IsuDev\WPContentBridge\Infrastructure\WordPress;

use IsuDev\WPContentBridge\Application\Mutation\ContentMutationRepository;
use IsuDev\WPContentBridge\Application\Mutation\MutationWriteFailed;
use IsuDev\WPContentBridge\Domain\Mutation\ContentUpdate;
use IsuDev\WPContentBridge\Domain\Mutation\DraftInput;
use IsuDev\WPContentBridge\Domain\Mutation\MutationResult;
use IsuDev\WPContentBridge\Domain\Mutation\TaxonomyAssignment;
use IsuDev\WPContentBridge\Domain\Mutation\VersionToken;
use WP_Post;

/**
 * The only place create/update actually touch WordPress. Never publishes.
 */
final class WordPressContentMutationRepository implements ContentMutationRepository {

	public function post_type( int $post_id ): ?string {
		$post = get_post( $post_id );

		return $post instanceof WP_Post ? $post->post_type : null;
	}

	public function current_version( int $post_id ): ?VersionToken {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return null;
		}

		return VersionToken::for_content(
			$post->post_modified_gmt,
			$post->post_title,
			$post->post_content,
			$post->post_status
		);
	}

	public function create( DraftInput $input ): MutationResult {
		$id = wp_insert_post(
			array(
				'post_type'    => $input->post_type,
				'post_status'  => 'draft',
				'post_title'   => $input->title,
				'post_content' => $input->block_markup,
				'post_excerpt' => (string) $input->excerpt,
			),
			true
		);

		if ( is_wp_error( $id ) || 0 === $id ) {
			throw new MutationWriteFailed( 'WordPress rejected the new draft.' );
		}

		$this->apply_taxonomies( (int) $id, $input->taxonomies );

		return $this->built_result( (int) $id, true, $this->created_field_names( $input ) );
	}

	public function update( int $post_id, ContentUpdate $update ): MutationResult {
		$args = array( 'ID' => $post_id );
		if ( null !== $update->title ) {
			$args['post_title'] = $update->title;
		}
		if ( null !== $update->block_markup ) {
			$args['post_content'] = $update->block_markup;
		}
		if ( null !== $update->excerpt ) {
			$args['post_excerpt'] = $update->excerpt;
		}

		$result = wp_update_post( $args, true );
		if ( is_wp_error( $result ) || 0 === $result ) {
			throw new MutationWriteFailed( 'WordPress rejected the update.' );
		}

		if ( null !== $update->taxonomies ) {
			$this->apply_taxonomies( $post_id, $update->taxonomies );
		}

		return $this->built_result( $post_id, false, $update->changed_fields() );
	}

	public function result_for( int $post_id ): ?MutationResult {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return null;
		}

		return $this->built_result( $post_id, false, array() );
	}

	/**
	 * @param array<int, TaxonomyAssignment> $taxonomies Assignments to apply (replace mode).
	 * @throws MutationWriteFailed When WordPress rejects a term assignment.
	 */
	private function apply_taxonomies( int $post_id, array $taxonomies ): void {
		foreach ( $taxonomies as $assignment ) {
			$result = wp_set_object_terms( $post_id, $assignment->term_ids, $assignment->taxonomy, false );
			if ( is_wp_error( $result ) ) {
				throw new MutationWriteFailed( 'WordPress rejected a taxonomy assignment.' );
			}
		}
	}

	/**
	 * @param array<int, string> $changed_fields Names of changed fields.
	 * @throws MutationWriteFailed When the freshly written post cannot be re-read.
	 */
	private function built_result( int $post_id, bool $created, array $changed_fields ): MutationResult {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			throw new MutationWriteFailed( 'The written post could not be re-read.' );
		}

		return new MutationResult(
			$post_id,
			$post->post_type,
			$post->post_status,
			VersionToken::for_content(
				$post->post_modified_gmt,
				$post->post_title,
				$post->post_content,
				$post->post_status
			),
			$changed_fields,
			$created
		);
	}

	/**
	 * @return array<int, string> Field names set on create.
	 */
	private function created_field_names( DraftInput $input ): array {
		$fields = array( 'title' );
		if ( '' !== $input->block_markup ) {
			$fields[] = 'content';
		}
		if ( null !== $input->excerpt ) {
			$fields[] = 'excerpt';
		}
		if ( array() !== $input->taxonomies ) {
			$fields[] = 'taxonomies';
		}

		return $fields;
	}
}
```

- [ ] **Step 2: Static checks**

Run: `composer lint && composer analyse`
Expected: PHPCS clean; PHPStan `[OK] No errors`.

- [ ] **Step 3: Smoke-check create against the live install**

Run:

```bash
cd "/Users/lukaszbiedron/Local Sites/kormas-isu/app/public" && wp eval '
require "/Users/lukaszbiedron/Other Projects/wp-content-bridge/vendor/autoload.php";
$repo = new IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressContentMutationRepository();
$draft = IsuDev\WPContentBridge\Domain\Mutation\DraftInput::from_input( array( "post_type" => "post", "title" => "WPCB smoke draft" ) );
$r = $repo->create( $draft );
echo "status=" . $r->status . " created=" . var_export( $r->created, true ) . "\n";
wp_delete_post( $r->post_id, true );
echo "OK\n";
'
```

Expected: `status=draft created=true` then `OK`.

- [ ] **Step 4: Commit**

```bash
git add src/Infrastructure/WordPress/WordPressContentMutationRepository.php
git commit -m "feat(mutation): add WordPressContentMutationRepository

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 9: `WordPressTransientIdempotencyStore` (Infrastructure)

**Files:**
- Create: `src/Infrastructure/WordPress/WordPressTransientIdempotencyStore.php`

**Interfaces:**
- Consumes: WordPress `get_transient`, `set_transient`. `IdempotencyStore` port.
- Produces: `final class WordPressTransientIdempotencyStore implements IdempotencyStore` with `public function __construct( private int $ttl = DAY_IN_SECONDS )`.

**Behavior:** transient name = `'wpcb_idem_' . $user_id . '_' . md5( $key )` (user-scoped so keys never collide across users). `find`: `$value = get_transient( $name )`; return `is_int($value) && $value > 0 ? $value : ( is_numeric($value) ? (int) $value : null )` — simplest: store an int, return `false === $value ? null : (int) $value`. `remember`: `set_transient( $name, $post_id, $this->ttl )`.

- [ ] **Step 1: Write the store**

Create `src/Infrastructure/WordPress/WordPressTransientIdempotencyStore.php`:

```php
<?php
/**
 * Transient-backed idempotency store, scoped per user.
 *
 * @package IsuDev\WPContentBridge
 */

declare( strict_types=1 );

namespace IsuDev\WPContentBridge\Infrastructure\WordPress;

use IsuDev\WPContentBridge\Application\Mutation\IdempotencyStore;

/**
 * Maps a user-scoped idempotency key to a created post ID for a bounded TTL.
 */
final class WordPressTransientIdempotencyStore implements IdempotencyStore {

	public function __construct( private int $ttl = DAY_IN_SECONDS ) {}

	public function find( int $user_id, string $key ): ?int {
		$value = get_transient( $this->name( $user_id, $key ) );
		if ( false === $value ) {
			return null;
		}

		$post_id = (int) $value;

		return 0 < $post_id ? $post_id : null;
	}

	public function remember( int $user_id, string $key, int $post_id ): void {
		set_transient( $this->name( $user_id, $key ), $post_id, $this->ttl );
	}

	private function name( int $user_id, string $key ): string {
		return 'wpcb_idem_' . $user_id . '_' . md5( $key );
	}
}
```

- [ ] **Step 2: Static checks**

Run: `composer lint && composer analyse`
Expected: PHPCS clean; PHPStan `[OK] No errors`.

- [ ] **Step 3: Commit**

```bash
git add src/Infrastructure/WordPress/WordPressTransientIdempotencyStore.php
git commit -m "feat(mutation): add transient idempotency store

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 10: Additive `version_token` on `get-content`

**Files:**
- Modify: `src/Domain/Content/ContentDetail.php`
- Modify: `src/Infrastructure/WordPress/WordPressContentRepository.php` (the `get()` method, around `:117-160`)
- Modify: `src/Adapter/Abilities/AbilitySchemas.php` (`get_output()`, `:200`)
- Test: `tests/Unit/Domain/Content/ContentDetailTest.php` (create if absent; otherwise extend)

**Interfaces:**
- Consumes: `VersionToken::for_content`.
- Produces: `ContentDetail` gains a trailing constructor param `?VersionToken $version_token = null` and its `to_array()` emits `'version_token' => $this->version_token?->to_string()`. The `get-content` output schema gains an optional string `version_token`.

This is the one permitted additive touch to a read ability (spec §6.1). It does not change existing behavior.

- [ ] **Step 1: Write/extend the failing test**

Create or extend `tests/Unit/Domain/Content/ContentDetailTest.php` with a case asserting `to_array()['version_token']`:

```php
	public function test_to_array_includes_version_token_when_present(): void {
		$summary = new \IsuDev\WPContentBridge\Domain\Content\ContentSummary(
			42, 'post', 'draft', 'Title', 'title', null, 'Excerpt', 1, null, '2026-07-20 00:00:00'
		);
		$detail = new \IsuDev\WPContentBridge\Domain\Content\ContentDetail(
			$summary,
			array(),
			array(),
			null,
			new \IsuDev\WPContentBridge\Domain\Mutation\VersionToken( 'abcdef0123456789', '2026-07-20 00:00:00' )
		);

		self::assertSame( 'abcdef0123456789:2026-07-20 00:00:00', $detail->to_array()['version_token'] );
	}
```

If the test file does not already exist, create it with the standard header (namespace `IsuDev\WPContentBridge\Tests\Unit\Domain\Content`, `final class ContentDetailTest extends TestCase`) and this single method.

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter ContentDetailTest`
Expected: FAIL — constructor does not accept a 5th argument / `version_token` key absent.

- [ ] **Step 3: Extend `ContentDetail`**

In `src/Domain/Content/ContentDetail.php`, add the import `use IsuDev\WPContentBridge\Domain\Mutation\VersionToken;`, add a trailing constructor parameter `public ?VersionToken $version_token = null,` (after `?string $concurrency_token`), and in `to_array()` add `'version_token' => $this->version_token?->to_string(),` alongside the existing `concurrency_token` entry.

- [ ] **Step 4: Populate it in the repository**

In `src/Infrastructure/WordPress/WordPressContentRepository.php` `get()`, where the `ContentDetail` is constructed, build and pass the token:

```php
$version_token = VersionToken::for_content(
	$post->post_modified_gmt,
	$post->post_title,
	$post->post_content,
	$post->post_status
);
```

Add `use IsuDev\WPContentBridge\Domain\Mutation\VersionToken;` and pass `$version_token` as the new final constructor argument to `new ContentDetail( … )`.

- [ ] **Step 5: Extend the output schema**

In `src/Adapter/Abilities/AbilitySchemas.php` `get_output()`, add an optional property (not in `required`) inside `properties`:

```php
			'version_token'   => array(
				'description' => 'Opaque optimistic-concurrency token to pass to update-content.',
				'type'        => array( 'string', 'null' ),
			),
```

- [ ] **Step 6: Run tests + static checks**

Run: `composer test && composer lint && composer analyse`
Expected: all green (the full unit suite still passes; PHPCS/PHPStan clean).

- [ ] **Step 7: Runtime verify the field appears**

Run:

```bash
cd "/Users/lukaszbiedron/Local Sites/kormas-isu/app/public" && wp eval '
require "/Users/lukaszbiedron/Other Projects/wp-content-bridge/tests/Integration/abilities-runtime-verification.php";
' 2>&1 | tail -5
```

Expected: the existing abilities verifier still prints PASS (the additive field does not break it). If it emits the get-content payload, confirm a `version_token` key is present.

- [ ] **Step 8: Commit**

```bash
git add src/Domain/Content/ContentDetail.php src/Infrastructure/WordPress/WordPressContentRepository.php src/Adapter/Abilities/AbilitySchemas.php tests/Unit/Domain/Content/ContentDetailTest.php
git commit -m "feat(content): expose version_token on get-content output

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 11: `MutationAbilities` adapter + write schemas

**Files:**
- Create: `src/Adapter/Abilities/MutationAbilities.php`
- Modify: `src/Adapter/Abilities/AbilitySchemas.php` (add `create_draft_input/output`, `update_content_input/output`)

**Interfaces:**
- Consumes: `CreateDraft`, `UpdateContent` use cases; WordPress `wp_register_ability`, `wp_register_ability_category` (via the shared `wp_abilities_api_init` hook), `current_user_can`, `get_post_type_object`, `get_current_user_id`, `WP_Error`.
- Produces: `final readonly class MutationAbilities` with `register_hooks(): void`.

**Ability registration** mirrors `ContentAbilities`:
- Category `'wp-content-bridge'` is already registered by `ContentAbilities`; `MutationAbilities` only registers abilities on `wp_abilities_api_init` (do not re-register the category).
- `create-draft`: `input_schema => AbilitySchemas::create_draft_input()`, `output_schema => AbilitySchemas::create_draft_output()`, `permission_callback => array($this,'can_create')`, `execute_callback => array($this,'execute_create')`, `meta => self::write_meta( false )`.
- `update-content`: analogous with `can_update` / `execute_update`, `meta => self::write_meta( true )`.

**Permission callbacks:**
- `can_create( mixed $input = null ): bool` — `current_user_can('wpcb_edit_content')` AND, for the input `post_type`, `current_user_can( $post_type_object->cap->create_posts ?? 'edit_posts' )`. Missing/unknown type → false.
- `can_update( mixed $input = null ): bool` — `current_user_can('wpcb_edit_content')` AND `$post_id > 0` AND `current_user_can('edit_post', $post_id)`.

**Execute callbacks** map thrown failures to `WP_Error` using each exception's `error_code()` where available, else literal codes (mirror `ContentAbilities::execute_get`):

```php
} catch ( InvalidArgumentException $e ) {
	return new WP_Error( 'wpcb_invalid_input', $e->getMessage() );
} catch ( ContentUnavailable ) {
	return new WP_Error( 'wpcb_content_unavailable', __( 'Content is unavailable.', 'wp-content-bridge' ) );
} catch ( MutationConflict | InvalidBlockMarkup | MutationForbidden | MutationWriteFailed $e ) {
	return new WP_Error( $e->error_code(), $e->getMessage() );
} catch ( Throwable ) {
	return self::internal_error();
}
```

`write_meta( bool $destructive )` returns `array( 'annotations' => array( 'readonly' => false, 'destructive' => $destructive, 'idempotent' => false ), 'show_in_rest' => true, 'mcp' => array( 'public' => true ) )`.

- [ ] **Step 1: Add the write schemas**

In `src/Adapter/Abilities/AbilitySchemas.php`, add four public static methods. Follow the verbatim `get_input()`/`search_output()` style (`additionalProperties => false`, explicit types, bounded strings). A shared private `taxonomy_assignment_schema()` helper keeps the two inputs DRY:

```php
	public static function create_draft_input(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'post_type', 'title' ),
			'properties'           => array(
				'post_type'       => array(
					'description' => 'Target post type slug.',
					'type'        => 'string',
					'pattern'     => '^[a-z0-9_-]{1,20}$',
				),
				'title'           => array(
					'description' => 'Post title.',
					'type'        => 'string',
					'minLength'   => 1,
					'maxLength'   => 500,
				),
				'block_markup'    => array(
					'description' => 'Gutenberg block markup for the post body.',
					'type'        => 'string',
					'maxLength'   => 500000,
					'default'     => '',
				),
				'excerpt'         => array(
					'description' => 'Optional excerpt.',
					'type'        => array( 'string', 'null' ),
					'maxLength'   => 2000,
				),
				'taxonomies'      => self::taxonomy_assignment_schema(),
				'idempotency_key' => array(
					'description' => 'Optional client key to make creation idempotent for 24h.',
					'type'        => array( 'string', 'null' ),
					'pattern'     => '^[A-Za-z0-9_.\\-]{1,128}$',
				),
			),
			'additionalProperties' => false,
		);
	}

	public static function update_content_input(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'post_id', 'version_token' ),
			'properties'           => array(
				'post_id'       => array(
					'description' => 'Target post ID.',
					'type'        => 'integer',
					'minimum'     => 1,
				),
				'version_token' => array(
					'description' => 'Optimistic-concurrency token from get-content.',
					'type'        => 'string',
					'minLength'   => 18,
					'maxLength'   => 191,
				),
				'title'         => array(
					'description' => 'Replacement title.',
					'type'        => array( 'string', 'null' ),
					'minLength'   => 1,
					'maxLength'   => 500,
				),
				'block_markup'  => array(
					'description' => 'Replacement block markup.',
					'type'        => array( 'string', 'null' ),
					'maxLength'   => 500000,
				),
				'excerpt'       => array(
					'description' => 'Replacement excerpt.',
					'type'        => array( 'string', 'null' ),
					'maxLength'   => 2000,
				),
				'taxonomies'    => self::taxonomy_assignment_schema(),
			),
			'additionalProperties' => false,
		);
	}

	public static function create_draft_output(): array {
		return self::mutation_output();
	}

	public static function update_content_output(): array {
		return self::mutation_output();
	}

	private static function mutation_output(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'schema_version', 'post_id', 'post_type', 'status', 'version_token', 'changed_fields', 'created', 'provenance' ),
			'properties'           => array(
				'schema_version' => array( 'type' => 'string' ),
				'post_id'        => array( 'type' => 'integer' ),
				'post_type'      => array( 'type' => 'string' ),
				'status'         => array( 'type' => 'string' ),
				'version_token'  => array( 'type' => 'string' ),
				'changed_fields' => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				'created'        => array( 'type' => 'boolean' ),
				'provenance'     => self::provenance(),
			),
			'additionalProperties' => false,
		);
	}

	private static function taxonomy_assignment_schema(): array {
		return array(
			'description' => 'Optional taxonomy assignments (replace mode).',
			'type'        => array( 'array', 'null' ),
			'maxItems'    => 50,
			'items'       => array(
				'type'                 => 'object',
				'required'             => array( 'taxonomy', 'term_ids' ),
				'properties'           => array(
					'taxonomy' => array(
						'type'    => 'string',
						'pattern' => '^[a-z0-9_-]{1,32}$',
					),
					'term_ids' => array(
						'type'     => 'array',
						'minItems' => 1,
						'maxItems' => 100,
						'items'    => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
					),
				),
				'additionalProperties' => false,
			),
		);
	}
```

- [ ] **Step 2: Write the adapter**

Create `src/Adapter/Abilities/MutationAbilities.php`:

```php
<?php
/**
 * Registers and projects the write abilities.
 *
 * @package IsuDev\WPContentBridge
 */

declare( strict_types=1 );

namespace IsuDev\WPContentBridge\Adapter\Abilities;

use InvalidArgumentException;
use IsuDev\WPContentBridge\Application\Content\ContentUnavailable;
use IsuDev\WPContentBridge\Application\Mutation\CreateDraft;
use IsuDev\WPContentBridge\Application\Mutation\InvalidBlockMarkup;
use IsuDev\WPContentBridge\Application\Mutation\MutationConflict;
use IsuDev\WPContentBridge\Application\Mutation\MutationForbidden;
use IsuDev\WPContentBridge\Application\Mutation\MutationWriteFailed;
use IsuDev\WPContentBridge\Application\Mutation\UpdateContent;
use Throwable;
use WP_Error;

/**
 * Adapter for create-draft and update-content. Contains no policy — it maps
 * input/output and errors, and enforces capability gates in its permission
 * callbacks.
 */
final readonly class MutationAbilities {

	private const CATEGORY = 'wp-content-bridge';

	public function __construct(
		private CreateDraft $create,
		private UpdateContent $update,
	) {}

	public function register_hooks(): void {
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
	}

	public function register_abilities(): void {
		wp_register_ability(
			CreateDraft::ABILITY,
			array(
				'label'               => __( 'Create draft', 'wp-content-bridge' ),
				'description'         => __( 'Create a new draft post, page, or custom post type.', 'wp-content-bridge' ),
				'category'            => self::CATEGORY,
				'input_schema'        => AbilitySchemas::create_draft_input(),
				'output_schema'       => AbilitySchemas::create_draft_output(),
				'permission_callback' => array( $this, 'can_create' ),
				'execute_callback'    => array( $this, 'execute_create' ),
				'meta'                => self::write_meta( false ),
			)
		);

		wp_register_ability(
			UpdateContent::ABILITY,
			array(
				'label'               => __( 'Update content', 'wp-content-bridge' ),
				'description'         => __( 'Update the title, content, excerpt, or taxonomies of an existing post.', 'wp-content-bridge' ),
				'category'            => self::CATEGORY,
				'input_schema'        => AbilitySchemas::update_content_input(),
				'output_schema'       => AbilitySchemas::update_content_output(),
				'permission_callback' => array( $this, 'can_update' ),
				'execute_callback'    => array( $this, 'execute_update' ),
				'meta'                => self::write_meta( true ),
			)
		);
	}

	public function can_create( mixed $input = null ): bool {
		if ( ! current_user_can( 'wpcb_edit_content' ) ) {
			return false;
		}

		$post_type = is_array( $input ) && is_string( $input['post_type'] ?? null ) ? $input['post_type'] : '';
		$object    = get_post_type_object( $post_type );
		if ( null === $object ) {
			return false;
		}

		$capability = $object->cap->create_posts ?? $object->cap->edit_posts ?? 'edit_posts';

		return current_user_can( $capability );
	}

	public function can_update( mixed $input = null ): bool {
		if ( ! current_user_can( 'wpcb_edit_content' ) ) {
			return false;
		}

		$post_id = is_array( $input ) ? (int) ( $input['post_id'] ?? 0 ) : 0;
		if ( 0 >= $post_id ) {
			return false;
		}

		return current_user_can( 'edit_post', $post_id );
	}

	/**
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public function execute_create( array $input ): array|WP_Error {
		if ( ! $this->can_create( $input ) ) {
			return self::forbidden();
		}

		try {
			return $this->create->execute( $input, get_current_user_id() )->to_array();
		} catch ( Throwable $error ) {
			return $this->to_error( $error );
		}
	}

	/**
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public function execute_update( array $input ): array|WP_Error {
		if ( ! $this->can_update( $input ) ) {
			return self::forbidden();
		}

		try {
			return $this->update->execute( $input, get_current_user_id() )->to_array();
		} catch ( Throwable $error ) {
			return $this->to_error( $error );
		}
	}

	private function to_error( Throwable $error ): WP_Error {
		if ( $error instanceof InvalidArgumentException ) {
			return new WP_Error( 'wpcb_invalid_input', $error->getMessage() );
		}
		if ( $error instanceof ContentUnavailable ) {
			return new WP_Error( 'wpcb_content_unavailable', __( 'Content is unavailable.', 'wp-content-bridge' ) );
		}
		if ( $error instanceof MutationConflict
			|| $error instanceof InvalidBlockMarkup
			|| $error instanceof MutationForbidden
			|| $error instanceof MutationWriteFailed
		) {
			return new WP_Error( $error->error_code(), $error->getMessage() );
		}

		return self::internal_error();
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function write_meta( bool $destructive ): array {
		return array(
			'annotations'  => array(
				'readonly'    => false,
				'destructive' => $destructive,
				'idempotent'  => false,
			),
			'show_in_rest' => true,
			'mcp'          => array( 'public' => true ),
		);
	}

	private static function forbidden(): WP_Error {
		return new WP_Error( 'wpcb_forbidden', __( 'You are not permitted to perform this write.', 'wp-content-bridge' ) );
	}

	private static function internal_error(): WP_Error {
		return new WP_Error( 'wpcb_internal_error', __( 'An unexpected error occurred.', 'wp-content-bridge' ) );
	}
}
```

- [ ] **Step 3: Static checks**

Run: `composer lint && composer analyse`
Expected: PHPCS clean; PHPStan `[OK] No errors`. (`wp_register_ability` may need a stub — if PHPStan reports it undefined, add a minimal `function wp_register_ability( string $id, array $args ): void {}` to `stubs/yoast.stub.php`'s sibling or a new `stubs/abilities.stub.php` referenced in `phpstan.neon.dist`. Check whether existing `ContentAbilities` already analyses clean; if so the stub already exists — reuse it.)

- [ ] **Step 4: Commit**

```bash
git add src/Adapter/Abilities/MutationAbilities.php src/Adapter/Abilities/AbilitySchemas.php
git commit -m "feat(mutation): add MutationAbilities adapter and write schemas

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 12: Wire `Plugin.php` (flag-gated) + settings checkbox

**Files:**
- Modify: `src/Plugin.php` (service block `:61-97`)
- Modify: `src/Adapter/Admin/ContentAccessSettingsPage.php`

**Interfaces:**
- Consumes: everything from Tasks 5–11; `Installer::WRITES_ENABLED_OPTION`.
- Produces: `MutationAbilities` registered only when `get_option( Installer::WRITES_ENABLED_OPTION )` is truthy; a global "Enable content writes" checkbox on the settings page persisting `wpcb_writes_enabled`.

- [ ] **Step 1: Wire the write services in `Plugin.php`**

In the service block of `src/Plugin.php`, after the read abilities are wired, add:

```php
		if ( get_option( Installer::WRITES_ENABLED_OPTION ) ) {
			$mutation_repository = new WordPressContentMutationRepository();
			$block_validator     = new PhpBlockMarkupValidator();
			$idempotency         = new WordPressTransientIdempotencyStore();
			$audit_log           = new WordPressAuditLog();

			( new MutationAbilities(
				new CreateDraft( $manager, $block_validator, $mutation_repository, $idempotency, $audit_log ),
				new UpdateContent( $manager, $block_validator, $mutation_repository, $audit_log )
			) )->register_hooks();
		}
```

Add the corresponding `use` imports at the top of `Plugin.php` (`Installer`, `WordPressContentMutationRepository`, `PhpBlockMarkupValidator`, `WordPressTransientIdempotencyStore`, `WordPressAuditLog`, `MutationAbilities`, `CreateDraft`, `UpdateContent`). Confirm `Installer` is already imported (it is used for `maybe_upgrade()`).

- [ ] **Step 2: Add the global writes-enabled checkbox to the settings page**

In `src/Adapter/Admin/ContentAccessSettingsPage.php`:
- In `register_setting()`, register a second option:

```php
		register_setting(
			self::OPTION_GROUP,
			Installer::WRITES_ENABLED_OPTION,
			array(
				'type'              => 'boolean',
				'default'           => false,
				'sanitize_callback' => static fn ( mixed $value ): bool => (bool) $value,
				'show_in_rest'      => false,
			)
		);
```

- Add a `add_settings_field` (or render inline in the existing page callback) that outputs a single checkbox bound to `Installer::WRITES_ENABLED_OPTION`, using the hidden-input + checkbox pattern already in the file:

```php
		<tr>
			<th scope="row"><?php esc_html_e( 'Content writes', 'wp-content-bridge' ); ?></th>
			<td>
				<input type="hidden" name="<?php echo esc_attr( Installer::WRITES_ENABLED_OPTION ); ?>" value="0">
				<label>
					<input type="checkbox" name="<?php echo esc_attr( Installer::WRITES_ENABLED_OPTION ); ?>" value="1" <?php checked( (bool) get_option( Installer::WRITES_ENABLED_OPTION ) ); ?>>
					<?php esc_html_e( 'Enable create-draft and update-content abilities (master switch, off by default).', 'wp-content-bridge' ); ?>
				</label>
			</td>
		</tr>
```

Add `use IsuDev\WPContentBridge\Infrastructure\WordPress\Installer;` if not present.

- [ ] **Step 3: Static checks + full unit suite**

Run: `composer check` (PHPCS + PHPStan + PHPUnit)
Expected: all green.

- [ ] **Step 4: Runtime — abilities invisible when flag off, visible when on**

Run:

```bash
cd "/Users/lukaszbiedron/Local Sites/kormas-isu/app/public" && wp eval '
require "/Users/lukaszbiedron/Other Projects/wp-content-bridge/vendor/autoload.php";
update_option( "wpcb_writes_enabled", false );
do_action( "wp_abilities_api_init" );
echo "flag_off create=" . var_export( (bool) wp_get_ability( "wp-content-bridge/create-draft" ), true ) . "\n";
'
```

Expected: `flag_off create=false` (ability not registered when the flag is off; note the plugin reads the flag at boot, so this check reflects the boot-time state — see the verifier in Task 13 for the toggled-on path via a fresh request). Document that toggling requires a fresh request/boot.

- [ ] **Step 5: Commit**

```bash
git add src/Plugin.php src/Adapter/Admin/ContentAccessSettingsPage.php
git commit -m "feat(mutation): wire write abilities behind the writes-enabled flag

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 13: Runtime write verifier (authorization matrix + invariants)

**Files:**
- Create: `tests/Integration/writes-mutation-verification.php`

**Interfaces:**
- Consumes: the full wired stack; `Installer`, `WordPressAuditLog`, `wp_get_ability`, disposable-fixture idioms from `tests/Integration/authorization-matrix.php`.
- Produces: a procedural (or single-class) verifier that prints `PASS`/`FAIL` and `exit(0|1)`, mirroring `writes-foundation-verification.php` + `authorization-matrix.php`.

**Checks (each a labelled assertion):**
1. **Flag on registers writes:** with `wpcb_writes_enabled` on, re-run boot/`do_action('wp_abilities_api_init')`; `wp_get_ability('wp-content-bridge/create-draft')` and `update-content` are non-null. With the flag off, both are null.
2. **Authorization matrix** (enable `create_draft`+`update_content` policy for the fixture type first): for anonymous, subscriber (no cap), author-with-cap (own vs others' post), editor-with-cap, admin — assert `check_permissions()`/`execute()` outcomes prove plugin cap, native cap, and policy are each independently required.
3. **create-draft happy path:** admin creates a draft; assert result `status === 'draft'`, `created === true`, a `version_token` is present, and the stored post status is `draft` (never `publish`).
4. **No-publish invariant:** attempt is made with any input; assert the created/updated post status is never `publish`/`future`/`pending`.
5. **Stale-version conflict:** read a fixture post's `version_token`; edit the post out-of-band (`wp_update_post`) so the token goes stale; call `update-content` with the OLD token; assert `WP_Error` code `wpcb_conflict` AND the post content is unchanged from the out-of-band edit.
6. **Revision created on update:** count revisions before/after a successful update; assert it increased.
7. **Block round-trip:** create a draft with valid paragraph markup; read it back via `get-content`; assert the block sequence is intact. Create with `<!-- wp:acme/nope /-->`; assert `wpcb_invalid_blocks`.
8. **Idempotent create:** two `create-draft` calls with the same `idempotency_key`; assert the same `post_id` and the second returns `created === false`; assert only one post exists.
9. **Audit redaction:** after a successful mutation, read the newest `{prefix}wpcb_audit` row; assert `changed_fields` is a JSON array of names, `outcome === 'success'`, and no column contains the post title/content/excerpt values.
10. **Cleanup:** delete all fixture posts/users, unregister the fixture type, restore options (`wpcb_writes_enabled`, `wpcb_content_type_access`) to prior values, prune fixture audit rows.

- [ ] **Step 1: Write the verifier**

Create `tests/Integration/writes-mutation-verification.php`. Copy the class skeleton, disposable-user/post/type fixtures, `assert_*` helpers, and `try/finally { cleanup() }` structure from `tests/Integration/authorization-matrix.php`; drive write abilities via `wp_set_current_user()` + `wp_get_ability(...)->check_permissions(...)` / `->execute(...)`. Read audit rows with `$wpdb->get_row( $wpdb->prepare( "SELECT * FROM %i ORDER BY id DESC LIMIT 1", Installer::audit_table_name() ) )`. Guard the top with `ABSPATH` and top-of-file `// phpcs:disable` for CLI/DB rules exactly as the existing verifiers do. Save prior option values in `run()` and restore them in `cleanup()`. Print `wp_json_encode( array( 'status' => 'PASS'|'FAIL', 'failures' => $failures ) )` and `exit( array() === $failures ? 0 : 1 )`.

(Full code follows the exact idioms in `authorization-matrix.php` — the implementer copies that file's structure. Do not invent new assertion helpers; reuse its `assert_true`/`assert_false`/`assert_not_error` pattern.)

- [ ] **Step 2: Lint the verifier**

Run: `composer lint`
Expected: PHPCS clean (the verifier is in the `tests` scope; PHPStan does not analyse `tests/`).

- [ ] **Step 3: Run the verifier against the live install**

Run:

```bash
cd "/Users/lukaszbiedron/Local Sites/kormas-isu/app/public" && wp eval '
require "/Users/lukaszbiedron/Other Projects/wp-content-bridge/tests/Integration/writes-mutation-verification.php";
'
```

Expected: final line `{"status":"PASS","failures":[]}` and exit 0.

- [ ] **Step 4: Full check**

Run: `composer check`
Expected: PHPCS clean · PHPStan 0 errors · PHPUnit all pass.

- [ ] **Step 5: Commit**

```bash
git add tests/Integration/writes-mutation-verification.php
git commit -m "test(mutation): add runtime write authorization + invariant verifier

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Self-Review (author checklist — completed)

**Spec coverage:** create-draft (§1, §6.2, §10) → Tasks 2,5,8,9,11,12; update-content (§1, §6.3) → Tasks 3,6,8,11,12; VersionToken on get-content (§6.1) → Task 10; block validation (§8) → Task 7; audit per attempt (§13) → Tasks 5,6 (use-case owned) verified in Task 13; policy wiring (§4) → Tasks 5,6 via existing `ContentOperation::CREATE/UPDATE`; flag gating (§2) → Task 12; capabilities (§3) → Task 1 (phpcs) + Task 11 (callbacks); schemas + error codes (§14) → Task 11; testing + exit gate (§15) → Tasks 5,6 units + Task 13 runtime. Out-of-scope items (update-seo, publish, list-block-patterns) correctly deferred to Plans 3–4.

**Placeholder scan:** every code step ships complete code except Task 13's verifier body, which is explicitly delegated to copying `authorization-matrix.php`'s concrete structure (the checks are fully enumerated) — acceptable because the pattern file is real and named.

**Type consistency:** `ContentMutationRepository` methods (`post_type`/`current_version`/`create`/`update`/`result_for`) are identical across Tasks 5, 6, 8, and the test fakes. `VersionToken::for_content($modified_gmt,$title,$content,$status)` and constructor `(content_hash, modified_gmt)` match Plan 1. `AuditEvent` positional args match Plan 1's 9-parameter order. `changed_fields()` returns `content` (not `block_markup`) consistently in `ContentUpdate` (Task 3) and `created_field_names` (Task 8).

---

## Notes for the executor

- **Do not push.** Per-task local commits only; the maintainer pushes after review.
- Infrastructure/Adapter tasks (7, 8, 9, 11, 13) cannot be unit-tested under phpunit (WP not loaded) — their gates are `composer lint`/`analyse` + the named `wp eval` smoke/verifier. Domain/Application tasks (1–6, 10) are TDD under phpunit.
- If `wp_register_ability` / `wp_get_ability` are undefined to PHPStan, check how `ContentAbilities` already analyses clean and reuse that stub mechanism; only add a stub file if one does not already exist.
- If the live install's `wp` CLI cannot see the plugin (symlink), confirm the plugin is active (`wp plugin list`) before running verifiers.
- The two toggled-registration checks depend on boot-time flag reads; the verifier documents that a flag change needs a fresh request to change ability registration.

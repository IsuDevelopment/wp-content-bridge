# M5 Writes — Plan 1: Foundation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the shared write-phase foundation — the concurrency primitive, the mutation port/failure contracts, and the capability/flag/audit installer wiring — so the write abilities (Plans 2–4) drop onto a tested base.

**Architecture:** New vertical slice `*/Mutation/` mirroring the existing read layers (Domain → Application → Infrastructure → Adapter). This plan delivers the pure Domain concurrency token, the Application-layer port interfaces + typed failures, and the Infrastructure installer + audit-log storage. No write ability is registered yet; reads are untouched.

**Tech Stack:** PHP 8.2+, WordPress 7.0.1, WordPress Abilities API, Composer PSR-4, PHPUnit 11, PHPStan (max), PHPCS (WordPress standard).

## Global Constraints

- PHP `>=8.2`; target WordPress 7.0.1. (verbatim from `composer.json`)
- Namespace root `IsuDev\WPContentBridge\` → `src/`; tests `IsuDev\WPContentBridge\Tests\` → `tests/`.
- Coding standard: WordPress (WPCS) — tabs for indent, Yoda conditions, long-array `array()` syntax, one space inside parentheses. Run `composer lint` (phpcs) and `composer lint:fix` (phpcbf).
- Static analysis: `composer analyse` (PHPStan) must report 0 errors.
- Layer rule: **no WordPress function calls in `src/Domain` or `src/Application`.** WordPress calls live only in `src/Infrastructure`. Ability adapters map I/O + `WP_Error` only.
- Unit tests (PHPUnit) cover Domain + Application only, using fakes for ports. Infrastructure that calls WordPress is verified by a runtime script executed via `wp eval` (pattern: `tests/Integration/*-verification.php`).
- DTOs are `final readonly` and validate in a `from_input(mixed): self` / factory that throws `InvalidArgumentException` on violation.
- Writes ship **off by default**. This plan adds no user-callable write ability.
- **Do not commit or push without explicit maintainer authorization.**

---

### Task 1: `VersionToken` concurrency primitive (Domain)

The optimistic-concurrency token. Pure value object; no WordPress. Serialized as
`{content_hash}:{modified_gmt}` — the 16-char hex hash is fixed-length and
first, so parsing is unambiguous even though `modified_gmt` contains colons.

**Files:**
- Create: `src/Domain/Mutation/VersionToken.php`
- Test: `tests/Unit/Domain/Mutation/VersionTokenTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces:
  - `VersionToken::__construct(string $content_hash, string $modified_gmt)`
  - `VersionToken::for_content(string $modified_gmt, string $title, string $content, string $status): self`
  - `VersionToken::from_string(string $value): self` (throws `InvalidArgumentException`)
  - `VersionToken::to_string(): string`
  - `VersionToken::equals(VersionToken $other): bool`
  - public readonly `string $content_hash`, `string $modified_gmt`

- [ ] **Step 1: Write the failing test**

```php
<?php
/**
 * Version token tests.
 *
 * @package IsuDev\WPContentBridge\Tests
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Tests\Unit\Domain\Mutation;

use InvalidArgumentException;
use IsuDev\WPContentBridge\Domain\Mutation\VersionToken;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the concurrency token round-trips and compares safely.
 */
final class VersionTokenTest extends TestCase {

	/**
	 * The wire form is hash-first so the colon-bearing timestamp is parseable.
	 */
	public function test_round_trips_through_string_form(): void {
		$token  = VersionToken::for_content( '2026-07-20 12:30:00', 'Title', '<!-- wp:paragraph --><p>Hi</p><!-- /wp:paragraph -->', 'draft' );
		$parsed = VersionToken::from_string( $token->to_string() );

		self::assertSame( $token->content_hash, $parsed->content_hash );
		self::assertSame( '2026-07-20 12:30:00', $parsed->modified_gmt );
		self::assertTrue( $token->equals( $parsed ) );
	}

	/**
	 * Any change to title, content, or status changes the hash.
	 */
	public function test_hash_changes_when_content_changes(): void {
		$base    = VersionToken::for_content( '2026-07-20 12:30:00', 'Title', 'Body', 'draft' );
		$body    = VersionToken::for_content( '2026-07-20 12:30:00', 'Title', 'Body edited', 'draft' );
		$status  = VersionToken::for_content( '2026-07-20 12:30:00', 'Title', 'Body', 'publish' );

		self::assertFalse( $base->equals( $body ) );
		self::assertFalse( $base->equals( $status ) );
	}

	/**
	 * Two posts modified at different times never compare equal.
	 */
	public function test_differs_when_modified_time_differs(): void {
		$a = VersionToken::for_content( '2026-07-20 12:30:00', 'T', 'B', 'draft' );
		$b = VersionToken::for_content( '2026-07-20 12:31:00', 'T', 'B', 'draft' );

		self::assertFalse( $a->equals( $b ) );
	}

	/**
	 * Malformed token strings are rejected.
	 *
	 * @param string $value Malformed token.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'malformed_tokens' )]
	public function test_rejects_malformed_tokens( string $value ): void {
		$this->expectException( InvalidArgumentException::class );

		VersionToken::from_string( $value );
	}

	/**
	 * Provides malformed token strings.
	 *
	 * @return iterable<string, array{string}>
	 */
	public static function malformed_tokens(): iterable {
		yield 'empty' => array( '' );
		yield 'no separator' => array( 'abcdef0123456789' );
		yield 'short hash' => array( 'abc:2026-07-20 12:30:00' );
		yield 'non hex hash' => array( 'ZZZZZZZZZZZZZZZZ:2026-07-20 12:30:00' );
		yield 'missing timestamp' => array( 'abcdef0123456789:' );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd "/Users/lukaszbiedron/Other Projects/wp-content-bridge" && vendor/bin/phpunit --filter VersionTokenTest`
Expected: FAIL — `Class "IsuDev\WPContentBridge\Domain\Mutation\VersionToken" not found`.

- [ ] **Step 3: Write minimal implementation**

```php
<?php
/**
 * Optimistic-concurrency version token.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Domain\Mutation;

use InvalidArgumentException;

/**
 * Immutable content version token used to reject stale writes.
 */
final readonly class VersionToken {

	/**
	 * Creates a token.
	 *
	 * @param string $content_hash 16-char lowercase hex digest.
	 * @param string $modified_gmt WordPress `post_modified_gmt` value.
	 */
	public function __construct(
		public string $content_hash,
		public string $modified_gmt,
	) {
	}

	/**
	 * Derives a token from the fields that must not change under a stale write.
	 *
	 * @param string $modified_gmt WordPress `post_modified_gmt` value.
	 * @param string $title        Post title.
	 * @param string $content      Raw post content.
	 * @param string $status       Post status.
	 * @return self
	 */
	public static function for_content( string $modified_gmt, string $title, string $content, string $status ): self {
		$hash = substr( hash( 'sha256', $content . '|' . $title . '|' . $status ), 0, 16 );

		return new self( $hash, $modified_gmt );
	}

	/**
	 * Parses the wire form `{content_hash}:{modified_gmt}`.
	 *
	 * @param string $value Serialized token.
	 * @return self
	 * @throws InvalidArgumentException When the shape is invalid.
	 */
	public static function from_string( string $value ): self {
		if ( strlen( $value ) < 18 || ':' !== $value[16] ) {
			throw new InvalidArgumentException( 'A version token is malformed.' );
		}

		$hash         = substr( $value, 0, 16 );
		$modified_gmt = substr( $value, 17 );

		if ( 1 !== preg_match( '/^[0-9a-f]{16}$/', $hash ) || '' === $modified_gmt ) {
			throw new InvalidArgumentException( 'A version token is malformed.' );
		}

		return new self( $hash, $modified_gmt );
	}

	/**
	 * Serializes to the wire form.
	 *
	 * @return string
	 */
	public function to_string(): string {
		return $this->content_hash . ':' . $this->modified_gmt;
	}

	/**
	 * Compares two tokens.
	 *
	 * @param VersionToken $other Token to compare against.
	 * @return bool
	 */
	public function equals( VersionToken $other ): bool {
		return $this->content_hash === $other->content_hash
			&& $this->modified_gmt === $other->modified_gmt;
	}
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter VersionTokenTest`
Expected: PASS (5+ tests).

- [ ] **Step 5: Lint, analyse, commit**

```bash
vendor/bin/phpcs src/Domain/Mutation/VersionToken.php tests/Unit/Domain/Mutation/VersionTokenTest.php
vendor/bin/phpstan analyse --memory-limit=512M --no-progress
# Do NOT commit without maintainer authorization. When authorized:
git add src/Domain/Mutation/VersionToken.php tests/Unit/Domain/Mutation/VersionTokenTest.php
git commit -m "feat(mutation): add VersionToken concurrency primitive"
```

---

### Task 2: Typed mutation failures (Application)

Small typed exceptions the use cases throw and the ability adapters map to stable
`WP_Error` codes. Each carries a stable `error_code()`.

**Files:**
- Create: `src/Application/Mutation/MutationConflict.php`
- Create: `src/Application/Mutation/InvalidBlockMarkup.php`
- Create: `src/Application/Mutation/SeoFieldUnsupported.php`
- Test: `tests/Unit/Application/Mutation/MutationFailuresTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces:
  - `MutationConflict extends \RuntimeException` with `error_code(): string` → `'wpcb_conflict'`
  - `InvalidBlockMarkup extends \RuntimeException` with `error_code(): string` → `'wpcb_invalid_blocks'`, and `__construct(list<string> $reasons)`, `reasons(): list<string>`
  - `SeoFieldUnsupported extends \RuntimeException` with `error_code(): string` → `'wpcb_seo_field_unsupported'`, and `__construct(list<string> $fields)`, `fields(): list<string>`

- [ ] **Step 1: Write the failing test**

```php
<?php
/**
 * Mutation failure contract tests.
 *
 * @package IsuDev\WPContentBridge\Tests
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Tests\Unit\Application\Mutation;

use IsuDev\WPContentBridge\Application\Mutation\InvalidBlockMarkup;
use IsuDev\WPContentBridge\Application\Mutation\MutationConflict;
use IsuDev\WPContentBridge\Application\Mutation\SeoFieldUnsupported;
use PHPUnit\Framework\TestCase;

/**
 * Verifies stable error codes and carried detail.
 */
final class MutationFailuresTest extends TestCase {

	public function test_conflict_exposes_stable_code(): void {
		self::assertSame( 'wpcb_conflict', ( new MutationConflict( 'stale' ) )->error_code() );
	}

	public function test_invalid_block_markup_carries_reasons(): void {
		$failure = new InvalidBlockMarkup( array( 'block 0: unregistered type core/does-not-exist' ) );

		self::assertSame( 'wpcb_invalid_blocks', $failure->error_code() );
		self::assertSame( array( 'block 0: unregistered type core/does-not-exist' ), $failure->reasons() );
	}

	public function test_seo_unsupported_carries_fields(): void {
		$failure = new SeoFieldUnsupported( array( 'twitter_card', 'schema_type' ) );

		self::assertSame( 'wpcb_seo_field_unsupported', $failure->error_code() );
		self::assertSame( array( 'twitter_card', 'schema_type' ), $failure->fields() );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter MutationFailuresTest`
Expected: FAIL — classes not found.

- [ ] **Step 3: Write minimal implementations**

`src/Application/Mutation/MutationConflict.php`:

```php
<?php
/**
 * Stale-write conflict failure.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Application\Mutation;

use RuntimeException;

/**
 * Thrown when the supplied version token no longer matches the object.
 */
final class MutationConflict extends RuntimeException {

	/**
	 * Returns the stable adapter error code.
	 *
	 * @return string
	 */
	public function error_code(): string {
		return 'wpcb_conflict';
	}
}
```

`src/Application/Mutation/InvalidBlockMarkup.php`:

```php
<?php
/**
 * Invalid block markup failure.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Application\Mutation;

use RuntimeException;

/**
 * Thrown when submitted block markup fails validation.
 */
final class InvalidBlockMarkup extends RuntimeException {

	/**
	 * Bounded human-readable reasons (no raw markup).
	 *
	 * @var list<string>
	 */
	private array $reasons;

	/**
	 * Creates the failure.
	 *
	 * @param list<string> $reasons Bounded reasons.
	 */
	public function __construct( array $reasons ) {
		$this->reasons = $reasons;
		parent::__construct( 'Submitted block markup is invalid.' );
	}

	/**
	 * Returns the stable adapter error code.
	 *
	 * @return string
	 */
	public function error_code(): string {
		return 'wpcb_invalid_blocks';
	}

	/**
	 * Returns bounded reasons.
	 *
	 * @return list<string>
	 */
	public function reasons(): array {
		return $this->reasons;
	}
}
```

`src/Application/Mutation/SeoFieldUnsupported.php`:

```php
<?php
/**
 * Unsupported SEO field failure.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Application\Mutation;

use RuntimeException;

/**
 * Thrown when an SEO write requests a field outside the writable allowlist.
 */
final class SeoFieldUnsupported extends RuntimeException {

	/**
	 * Offending field names.
	 *
	 * @var list<string>
	 */
	private array $fields;

	/**
	 * Creates the failure.
	 *
	 * @param list<string> $fields Offending field names.
	 */
	public function __construct( array $fields ) {
		$this->fields = $fields;
		parent::__construct( 'One or more requested SEO fields are not writable.' );
	}

	/**
	 * Returns the stable adapter error code.
	 *
	 * @return string
	 */
	public function error_code(): string {
		return 'wpcb_seo_field_unsupported';
	}

	/**
	 * Returns offending field names.
	 *
	 * @return list<string>
	 */
	public function fields(): array {
		return $this->fields;
	}
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter MutationFailuresTest`
Expected: PASS.

- [ ] **Step 5: Lint, analyse, commit**

```bash
vendor/bin/phpcs src/Application/Mutation tests/Unit/Application/Mutation
vendor/bin/phpstan analyse --memory-limit=512M --no-progress
# When authorized:
git add src/Application/Mutation tests/Unit/Application/Mutation
git commit -m "feat(mutation): add typed mutation failures"
```

---

### Task 3: Mutation port interfaces (Application)

Interfaces the use cases depend on; Infrastructure implements them in Plans 2–4.
Interfaces have no unit-test cycle of their own — the deliverable check is that
PHPStan resolves them. Define them now so wiring and fakes have stable types.

**Files:**
- Create: `src/Application/Mutation/ContentMutationRepository.php`
- Create: `src/Application/Mutation/BlockMarkupValidator.php`
- Create: `src/Application/Mutation/SeoWriter.php`
- Create: `src/Application/Mutation/AuditLog.php`
- Create: `src/Application/Mutation/AuditEvent.php` (immutable DTO carried into `AuditLog`)

**Interfaces:**
- Consumes: `VersionToken` (Task 1).
- Produces (used by Tasks 4–5 and Plans 2–4):
  - `AuditEvent` — `final readonly` DTO: `int $user_id`, `string $ability`, `?int $object_id`, `?string $object_type`, `list<string> $changed_fields`, `?string $expected_version`, `?string $resulting_version`, `string $outcome`, `?string $error_code`.
  - `interface AuditLog { public function record( AuditEvent $event ): void; }`
  - `interface BlockMarkupValidator { /** @return list<string> reasons; empty = valid */ public function validate( string $markup ): array; }`
  - `interface ContentMutationRepository` — signatures defined in Plan 2 header; declared minimally here as an empty marker is NOT allowed, so define the two methods now:
    - `public function create_draft( \IsuDev\WPContentBridge\Domain\Mutation\DraftInput $input ): int` (returns new post ID) — **note:** `DraftInput` is created in Plan 2; to avoid a forward type dependency, this interface is created in Plan 2, not here. See Step 1 note.
  - `interface SeoWriter` — defined in Plan 3 (depends on `SeoUpdate`). Not created here.

- [ ] **Step 1: Create the context-free ports only**

> Note: `ContentMutationRepository` and `SeoWriter` depend on DTOs introduced in
> Plans 2 and 3, so they are created there to keep types resolvable. This task
> creates only the ports with no forward dependency: `AuditEvent`, `AuditLog`,
> `BlockMarkupValidator`.

`src/Application/Mutation/AuditEvent.php`:

```php
<?php
/**
 * Mutation audit event.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Application\Mutation;

/**
 * Immutable, pre-redacted audit event (field names only, never values).
 */
final readonly class AuditEvent {

	/**
	 * Creates an audit event.
	 *
	 * @param int          $user_id           Acting principal.
	 * @param string       $ability           Ability id.
	 * @param int|null     $object_id         Target post ID, if any.
	 * @param string|null  $object_type       Target post type, if any.
	 * @param list<string> $changed_fields    Changed field names only.
	 * @param string|null  $expected_version  Incoming version token string.
	 * @param string|null  $resulting_version Resulting version token string.
	 * @param string       $outcome           success|conflict|invalid|denied|failure.
	 * @param string|null  $error_code        Stable error code, if any.
	 */
	public function __construct(
		public int $user_id,
		public string $ability,
		public ?int $object_id,
		public ?string $object_type,
		public array $changed_fields,
		public ?string $expected_version,
		public ?string $resulting_version,
		public string $outcome,
		public ?string $error_code,
	) {
	}
}
```

`src/Application/Mutation/AuditLog.php`:

```php
<?php
/**
 * Audit log port.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Application\Mutation;

/**
 * Append-only mutation audit sink.
 */
interface AuditLog {

	/**
	 * Records one mutation attempt (success or failure).
	 *
	 * @param AuditEvent $event Pre-redacted event.
	 * @return void
	 */
	public function record( AuditEvent $event ): void;
}
```

`src/Application/Mutation/BlockMarkupValidator.php`:

```php
<?php
/**
 * Block markup validation port.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Application\Mutation;

/**
 * Validates raw Gutenberg block markup.
 */
interface BlockMarkupValidator {

	/**
	 * Validates block markup.
	 *
	 * @param string $markup Raw Gutenberg block markup (may be empty).
	 * @return list<string> Bounded failure reasons; empty means valid.
	 */
	public function validate( string $markup ): array;
}
```

- [ ] **Step 2: Verify the ports analyse cleanly**

Run: `vendor/bin/phpcs src/Application/Mutation && vendor/bin/phpstan analyse --memory-limit=512M --no-progress`
Expected: PHPCS clean; PHPStan 0 errors.

- [ ] **Step 3: Commit**

```bash
# When authorized:
git add src/Application/Mutation/AuditEvent.php src/Application/Mutation/AuditLog.php src/Application/Mutation/BlockMarkupValidator.php
git commit -m "feat(mutation): add audit and block-validator ports"
```

---

### Task 4: Installer — capabilities, feature flags, audit table

Extend the existing `Installer` (idempotent, versioned). Grant the three write
capabilities to `administrator`, register the two write feature-flag options
(default false), and create the capped audit table with `dbDelta`.

**Files:**
- Modify: `src/Infrastructure/WordPress/Installer.php`
- Create: `tests/Integration/writes-foundation-verification.php`

**Interfaces:**
- Consumes: nothing new.
- Produces (constants other code reads):
  - `Installer::WRITES_ENABLED_OPTION = 'wpcb_writes_enabled'`
  - `Installer::PUBLISH_ENABLED_OPTION = 'wpcb_publish_enabled'`
  - `Installer::audit_table_name(): string` (returns `{$wpdb->prefix}wpcb_audit`)

- [ ] **Step 1: Modify the Installer**

Replace the body of `src/Infrastructure/WordPress/Installer.php` with:

```php
<?php
/**
 * Plugin installation and schema upgrades.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Infrastructure\WordPress;

/**
 * Applies idempotent installation changes.
 */
final class Installer {

	private const SCHEMA_VERSION = 4;
	private const VERSION_OPTION = 'wpcb_schema_version';

	public const WRITES_ENABLED_OPTION  = 'wpcb_writes_enabled';
	public const PUBLISH_ENABLED_OPTION = 'wpcb_publish_enabled';

	private const AUDIT_TABLE = 'wpcb_audit';

	/**
	 * Runs on plugin activation.
	 *
	 * @return void
	 */
	public static function activate(): void {
		self::grant_administrator_capability();
		add_option( WordPressContentAccessSettingsRepository::OPTION_NAME, array(), '', false );
		add_option( self::WRITES_ENABLED_OPTION, false, '', false );
		add_option( self::PUBLISH_ENABLED_OPTION, false, '', false );
		self::create_audit_table();
		update_option( self::VERSION_OPTION, self::SCHEMA_VERSION, false );
	}

	/**
	 * Upgrades already-active development installations.
	 *
	 * @return void
	 */
	public static function maybe_upgrade(): void {
		$stored_version  = get_option( self::VERSION_OPTION, 0 );
		$current_version = is_int( $stored_version ) ? $stored_version : 0;

		if ( $current_version >= self::SCHEMA_VERSION ) {
			return;
		}

		self::activate();
	}

	/**
	 * Returns the fully-qualified audit table name.
	 *
	 * @return string
	 */
	public static function audit_table_name(): string {
		global $wpdb;

		return $wpdb->prefix . self::AUDIT_TABLE;
	}

	/**
	 * Grants management and write capabilities to administrators.
	 *
	 * @return void
	 */
	private static function grant_administrator_capability(): void {
		$administrator = get_role( 'administrator' );

		if ( null === $administrator ) {
			return;
		}

		foreach ( array(
			'wpcb_manage_settings',
			'wpcb_read_content',
			'wpcb_edit_content',
			'wpcb_manage_seo',
			'wpcb_publish_content',
		) as $capability ) {
			$administrator->add_cap( $capability );
		}
	}

	/**
	 * Creates the append-only mutation audit table.
	 *
	 * @return void
	 */
	private static function create_audit_table(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table           = self::audit_table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			created_gmt DATETIME NOT NULL,
			user_id BIGINT UNSIGNED NOT NULL,
			ability VARCHAR(191) NOT NULL,
			object_id BIGINT UNSIGNED NULL,
			object_type VARCHAR(64) NULL,
			changed_fields TEXT NOT NULL,
			expected_version VARCHAR(191) NULL,
			resulting_version VARCHAR(191) NULL,
			outcome VARCHAR(32) NOT NULL,
			error_code VARCHAR(64) NULL,
			PRIMARY KEY  (id),
			KEY created_gmt (created_gmt)
		) {$charset_collate};";

		dbDelta( $sql );
	}
}
```

- [ ] **Step 2: Write the integration verification script**

`tests/Integration/writes-foundation-verification.php`:

```php
<?php
/**
 * Runtime verification for the write-phase foundation.
 *
 * Run: wp eval 'require "<abs path>/tests/Integration/writes-foundation-verification.php";'
 *
 * @package IsuDev\WPContentBridge\Tests
 */

declare(strict_types=1);

use IsuDev\WPContentBridge\Infrastructure\WordPress\Installer;

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Must run inside WordPress via wp eval.\n" );
	exit( 1 );
}

Installer::activate();

$failures = array();

$administrator = get_role( 'administrator' );
foreach ( array( 'wpcb_edit_content', 'wpcb_manage_seo', 'wpcb_publish_content' ) as $capability ) {
	if ( null === $administrator || ! $administrator->has_cap( $capability ) ) {
		$failures[] = "administrator missing {$capability}";
	}
}

if ( false !== get_option( Installer::WRITES_ENABLED_OPTION ) ) {
	$failures[] = 'wpcb_writes_enabled is not false by default';
}
if ( false !== get_option( Installer::PUBLISH_ENABLED_OPTION ) ) {
	$failures[] = 'wpcb_publish_enabled is not false by default';
}

global $wpdb;
$table  = Installer::audit_table_name();
$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
if ( $table !== $exists ) {
	$failures[] = "audit table {$table} was not created";
}

if ( array() === $failures ) {
	echo "PASS: writes foundation (caps, flags default-off, audit table)\n";
	exit( 0 );
}

echo "FAIL:\n - " . implode( "\n - ", $failures ) . "\n";
exit( 1 );
```

- [ ] **Step 3: Run static checks**

Run: `vendor/bin/phpcs src/Infrastructure/WordPress/Installer.php tests/Integration/writes-foundation-verification.php && vendor/bin/phpstan analyse --memory-limit=512M --no-progress`
Expected: PHPCS clean; PHPStan 0 errors.

- [ ] **Step 4: Run the runtime verification**

Run:
```bash
cd "/Users/lukaszbiedron/Local Sites/kormas-isu/app/public"
wp eval 'require "/Users/lukaszbiedron/Other Projects/wp-content-bridge/tests/Integration/writes-foundation-verification.php";'
```
Expected: `PASS: writes foundation (caps, flags default-off, audit table)`

- [ ] **Step 5: Commit**

```bash
# When authorized:
git add src/Infrastructure/WordPress/Installer.php tests/Integration/writes-foundation-verification.php
git commit -m "feat(mutation): install write caps, feature flags, and audit table"
```

---

### Task 5: `WordPressAuditLog` (Infrastructure)

Implements `AuditLog` against the audit table: insert one redacted row, prune to
a bounded row count, and fire `do_action( 'wpcb_mutation', ... )`.

**Files:**
- Create: `src/Infrastructure/WordPress/WordPressAuditLog.php`
- Modify: `tests/Integration/writes-foundation-verification.php` (append a record+read-back check)

**Interfaces:**
- Consumes: `AuditLog`, `AuditEvent` (Task 3), `Installer::audit_table_name()` (Task 4).
- Produces: `WordPressAuditLog implements AuditLog` with `__construct( int $max_rows = 5000 )`.

- [ ] **Step 1: Write the implementation**

```php
<?php
/**
 * WordPress audit-log adapter.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Infrastructure\WordPress;

use IsuDev\WPContentBridge\Application\Mutation\AuditEvent;
use IsuDev\WPContentBridge\Application\Mutation\AuditLog;

/**
 * Append-only, capped audit sink backed by a custom table plus an action hook.
 */
final class WordPressAuditLog implements AuditLog {

	/**
	 * Creates the audit log.
	 *
	 * @param int $max_rows Maximum retained rows before pruning oldest.
	 */
	public function __construct(
		private int $max_rows = 5000,
	) {
	}

	/**
	 * Records one mutation attempt and prunes the table.
	 *
	 * @param AuditEvent $event Pre-redacted event.
	 * @return void
	 */
	public function record( AuditEvent $event ): void {
		global $wpdb;

		$table = Installer::audit_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- dedicated audit table, no core API.
		$wpdb->insert(
			$table,
			array(
				'created_gmt'       => gmdate( 'Y-m-d H:i:s' ),
				'user_id'           => $event->user_id,
				'ability'           => $event->ability,
				'object_id'         => $event->object_id,
				'object_type'       => $event->object_type,
				'changed_fields'    => (string) wp_json_encode( array_values( $event->changed_fields ) ),
				'expected_version'  => $event->expected_version,
				'resulting_version' => $event->resulting_version,
				'outcome'           => $event->outcome,
				'error_code'        => $event->error_code,
			),
			array( '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		$this->prune( $table );

		/**
		 * Fires after a mutation attempt is audited.
		 *
		 * @param AuditEvent $event Pre-redacted audit event.
		 */
		do_action( 'wpcb_mutation', $event );
	}

	/**
	 * Removes rows beyond the retention cap.
	 *
	 * @param string $table Audit table name.
	 * @return void
	 */
	private function prune( string $table ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- maintenance on a dedicated table.
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );

		if ( $total <= $this->max_rows ) {
			return;
		}

		$excess = $total - $this->max_rows;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal; LIMIT is a bound integer.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} ORDER BY id ASC LIMIT %d", $excess ) );
	}
}
```

- [ ] **Step 2: Append a record + read-back check to the verifier**

Insert this block into `tests/Integration/writes-foundation-verification.php`
immediately before the final `if ( array() === $failures )`:

```php
$audit_log = new \IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressAuditLog();
$audit_log->record(
	new \IsuDev\WPContentBridge\Application\Mutation\AuditEvent(
		1,
		'wp-content-bridge/create-draft',
		null,
		'post',
		array( 'title', 'content' ),
		null,
		'abcdef0123456789:2026-07-20 12:30:00',
		'success',
		null
	)
);

$row = $wpdb->get_row( "SELECT * FROM {$table} ORDER BY id DESC LIMIT 1", ARRAY_A );
if ( null === $row ) {
	$failures[] = 'audit row was not written';
} else {
	if ( 'wp-content-bridge/create-draft' !== $row['ability'] ) {
		$failures[] = 'audit ability not persisted';
	}
	if ( array( 'title', 'content' ) !== json_decode( (string) $row['changed_fields'], true ) ) {
		$failures[] = 'audit changed_fields not persisted as name list';
	}
	// Redaction guard: no content/secret columns exist at all.
	if ( array_key_exists( 'content', $row ) || array_key_exists( 'secret', $row ) ) {
		$failures[] = 'audit table exposes a content/secret column';
	}
}
```

- [ ] **Step 3: Run static checks**

Run: `vendor/bin/phpcs src/Infrastructure/WordPress/WordPressAuditLog.php && vendor/bin/phpstan analyse --memory-limit=512M --no-progress`
Expected: PHPCS clean (phpcs:ignore annotations justify the direct queries); PHPStan 0 errors.

- [ ] **Step 4: Run the runtime verification**

Run:
```bash
cd "/Users/lukaszbiedron/Local Sites/kormas-isu/app/public"
wp eval 'require "/Users/lukaszbiedron/Other Projects/wp-content-bridge/tests/Integration/writes-foundation-verification.php";'
```
Expected: `PASS: writes foundation (caps, flags default-off, audit table)`

- [ ] **Step 5: Commit**

```bash
# When authorized:
git add src/Infrastructure/WordPress/WordPressAuditLog.php tests/Integration/writes-foundation-verification.php
git commit -m "feat(mutation): add capped WordPress audit-log adapter"
```

---

## Self-Review

- **Spec coverage (foundation portion of the spec):** §2 flags → Task 4; §3
  capabilities → Task 4; §6.1 VersionToken → Task 1; §13 audit table + hook +
  redaction → Tasks 4–5; port contracts (§5) → Task 3. Block validator
  *implementation* (§8), the write use cases/abilities (§7), SEO writes (§9),
  idempotency (§10), publish (§11), `list-block-patterns` (§12), and the
  additive `version_token` on `get-content` (§6.1) are intentionally deferred to
  Plans 2–4 (see roadmap below).
- **Placeholders:** none — every code step contains full code; Task 3 explicitly
  scopes out the forward-dependent ports and says where they live.
- **Type consistency:** `VersionToken(content_hash, modified_gmt)` order is used
  consistently; `AuditEvent` field order matches its use in the Task 5 verifier;
  `Installer::audit_table_name()` used by both Task 4 and Task 5.

---

## Roadmap — remaining M5 plans (written just-in-time before each executes)

**Plan 2 — `create-draft` + `update-content`** (the core content write):
`DraftInput`, `ContentUpdate` DTOs; `ContentMutationRepository` port +
`WordPressContentMutationRepository` (`wp_insert_post`/`wp_update_post`,
revisions); `PhpBlockMarkupValidator` (§8, parse round-trip + registry check);
idempotency store (§10); add read-only `version_token` to `get-content` output
(§6.1); `CreateDraft`/`UpdateContent` use cases; `MutationAbilities` registered
only when `wpcb_writes_enabled`; input/output schemas; write authorization-matrix
integration verifier. Deliverable: draft create/edit working end-to-end.

**Plan 3 — `update-seo`**: `SeoUpdate` DTO; `SeoWriter` port +
`YoastFreeSeoWriter` (allowlist §9, Yoast documented write path, re-read after
write); `UpdateSeo` use case; ability + schema; SEO write/re-read integration
verifier. Deliverable: Yoast Free SEO writes.

**Plan 4 — `publish-content` + `list-block-patterns`**: `PublishContent` use
case (draft→publish only, approval-compatible contract §11, own
`wpcb_publish_enabled` flag + `wpcb_publish_content` cap);
`BlockPatternCatalog` port + `WordPressBlockPatternCatalog` +
`PatternAbilities` (read-only §12); Settings-page checkboxes for both flags;
final exit-gate integration matrix (§15) incl. publish-blocked-when-flag-off and
writes-invisible-when-master-flag-off. Deliverable: gated publish + pattern
discovery; milestone exit gate met.

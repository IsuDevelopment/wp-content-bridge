<?php
/**
 * Validated site-local publish_at instant.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Domain\Status;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * A `publish_at` value already proven to exist, to be strictly in the
 * future, and to be resolvable in the site's timezone. Pure logic with no
 * WordPress dependency of its own: callers supply the site timezone and the
 * current instant, and
 * {@see \IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressSiteClock}
 * is the only place either actually comes from `wp_timezone()` or real time.
 * That separation is what makes the DST gap/fold and fixed-offset cases
 * (ADR 0024 task 5) exercisable from a plain unit test with an arbitrary
 * `DateTimeZone`, without loading WordPress.
 *
 * ADR 0024 requires `publish_at` to be rejected outright rather than
 * downgraded, because `wp_update_post()` silently turns a bad scheduled date
 * into an immediate publish (measured on WordPress 7.0.2/7.0.3) — the worst
 * possible outcome for a caller that asked to schedule. Validating here,
 * before any write is attempted, is what keeps that measured trap from ever
 * reaching storage.
 *
 * Never uses `strtotime()`: it accepts and silently misinterprets ambiguous
 * and malformed local strings in ways that are not guaranteed deterministic.
 * `DateTimeImmutable::createFromFormat()` against an explicit `DateTimeZone`
 * is deterministic and testable with any zone, including a fixed UTC offset
 * (the reference site's own configuration — `timezone_string` empty,
 * `gmt_offset` `0` — which has no DST at all and is therefore the default
 * case here, not the exotic one) and a named zone that observes it.
 */
final readonly class PublishAt {

	private const WIRE_FORMAT = 'Y-m-d\TH:i:s';

	/**
	 * MySQL date-time format shared by `post_date` and `post_date_gmt`.
	 *
	 * @var string
	 */
	public const MYSQL_FORMAT = 'Y-m-d H:i:s';

	/**
	 * Creates an already-validated publish_at.
	 *
	 * @param DateTimeImmutable $utc   The resolved instant, in UTC.
	 * @param DateTimeImmutable $local The same instant, in the site timezone.
	 */
	public function __construct(
		public DateTimeImmutable $utc,
		public DateTimeImmutable $local,
	) {
	}

	/**
	 * Parses and validates a wire-format local date-time string.
	 *
	 * Rejects three things deliberately, rather than letting PHP's own
	 * silent normalization decide: a malformed or out-of-range string (PHP
	 * rolls `2026-02-30` over to `2026-03-02` rather than failing), a local
	 * time that does not exist because it falls in the DST spring-forward
	 * gap (measured: PHP shifts it forward by the gap's length instead of
	 * failing), and any instant that is not strictly after `$now_utc`. All
	 * three are caught by the same check: reformatting the parsed value and
	 * comparing it back to the raw input. A rolled-over or gap-shifted value
	 * always reformats to something different from what was typed; a
	 * genuinely valid local time always round-trips exactly.
	 *
	 * A local time inside the autumn DST fold (one that exists *twice*) is
	 * deliberately accepted rather than rejected: the wall-clock string
	 * alone cannot say which of the two instants was intended, and
	 * `DateTimeImmutable` resolves it consistently to one specific instant
	 * (measured on `Europe/Warsaw`: the later, post-transition/standard-time
	 * occurrence) rather than varying between calls. That resolution is
	 * accepted as-is; disambiguating it further would need information —
	 * such as an explicit UTC offset — that the wire format deliberately
	 * omits, because `publish_at` is defined to be site-local.
	 *
	 * @param string            $raw           Wire-format local date-time, e.g. `2026-09-01T09:00:00`.
	 * @param DateTimeZone      $site_timezone Site timezone, from `wp_timezone()`.
	 * @param DateTimeImmutable $now_utc       Current instant, in UTC.
	 * @return self
	 * @throws InvalidArgumentException When malformed, nonexistent, or not strictly in the future.
	 */
	public static function from_local_string( string $raw, DateTimeZone $site_timezone, DateTimeImmutable $now_utc ): self {
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/', $raw ) ) {
			throw new InvalidArgumentException( 'publish_at must be a local date-time in the form YYYY-MM-DDTHH:MM:SS, without a UTC offset.' );
		}

		$local = DateTimeImmutable::createFromFormat( self::WIRE_FORMAT, $raw, $site_timezone );

		if ( false === $local || $local->format( self::WIRE_FORMAT ) !== $raw ) {
			throw new InvalidArgumentException( 'publish_at is not a valid, existing local date-time (it may fall within a daylight-saving transition that skips it).' );
		}

		$utc = $local->setTimezone( new DateTimeZone( 'UTC' ) );

		if ( $utc <= $now_utc ) {
			throw new InvalidArgumentException( 'publish_at must be strictly in the future.' );
		}

		return new self( $utc, $local );
	}

	/**
	 * MySQL-format UTC date-time, for `post_date_gmt`.
	 *
	 * @return string
	 */
	public function utc_mysql(): string {
		return $this->utc->format( self::MYSQL_FORMAT );
	}

	/**
	 * MySQL-format site-local date-time, for `post_date`.
	 *
	 * @return string
	 */
	public function local_mysql(): string {
		return $this->local->format( self::MYSQL_FORMAT );
	}

	/**
	 * Wire representation returned to the caller in both forms, per ADR 0024.
	 *
	 * @return array{local: string, utc: string}
	 */
	public function to_array(): array {
		return array(
			'local' => $this->local->format( 'Y-m-d\TH:i:sP' ),
			'utc'   => $this->utc->format( 'Y-m-d\TH:i:s\Z' ),
		);
	}
}

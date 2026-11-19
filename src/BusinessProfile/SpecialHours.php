<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\People\ListingsManagement\BusinessProfile;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * A location's `special_hours` rows as Business Profile's `specialHours` periods.
 *
 * The rows are expected already merged with the system-wide closures the
 * location honors (`SystemLocationClosures::effectiveSpecialHours()` on the
 * site), so this reads rows only (UAMS-Web/aether-listings-management#66):
 *
 * - A `holiday` row's rules each name a `date` and an optional `through`; a
 *   `temporary_change` row's weekly rules apply on their days from `valid_from`
 *   to `valid_through`, or with no end when that is blank.
 * - Business Profile takes one date per period, so every rule is expanded day
 *   by day, and only from today (America/Chicago) through the 89 days after it.
 *   An earlier date is never written, and a later one is written by a later run.
 * - On one date a holiday rule beats a temporary change, and a later rule beats
 *   an earlier one. A date no rule names has no period, so its regular hours stand.
 * - Status and spans read as for regular hours ({@see RegularHours::spans()}),
 *   and telemedicine rules are skipped. A closed date is `closed: true`. A
 *   period must be under 24 hours, so open all day is 00:00 to 23:59, a span
 *   past midnight closes the next day no later than 11:59, and a span that
 *   closes the minute it opens is dropped.
 */
final class SpecialHours
{
    /** The time zone "today" is read in. */
    public const string TIMEZONE = 'America/Chicago';

    /** How many days, today included, are written. */
    public const int HORIZON_DAYS = 90;

    /** The latest a period may close on the day after it opens: 11:59. */
    private const int LAST_NEXT_DAY_CLOSE = 719;

    /** Minutes in a day: a closing time of 24:00. */
    private const int DAY_END = 1440;

    /**
     * Today's date in the time zone the hours are kept in, as `YYYY-MM-DD`.
     */
    public static function today(): string
    {
        return new DateTimeImmutable('now', new DateTimeZone(self::TIMEZONE))->format('Y-m-d');
    }

    /**
     * The `specialHours` field for a set of rows: every period from today to the horizon, which may be none.
     *
     * @param  mixed  $rows  The `special_hours` replicator value, closures merged in.
     * @param  string  $today  Today, `YYYY-MM-DD`.
     * @return array{specialHourPeriods: list<array<string, mixed>>}
     */
    public static function fromRows(mixed $rows, string $today): array
    {
        $last = new DateTimeImmutable($today)->modify('+'.(self::HORIZON_DAYS - 1).' days')->format('Y-m-d');
        $rows = RegularHours::enabled($rows);

        // Date => the spans it is open, or none when closed; temporary changes first, so a holiday overrides them
        $dates = [];

        foreach ($rows as $row) {
            if (trim(Values::str($row['type'] ?? null)) === 'temporary_change') {
                self::applyChange($dates, $row, $today, $last);
            }
        }

        foreach ($rows as $row) {
            if (trim(Values::str($row['type'] ?? null)) === 'holiday') {
                self::applyHoliday($dates, $row, $today, $last);
            }
        }

        ksort($dates);
        $periods = [];

        foreach ($dates as $date => $spans) {
            if ($spans === []) {
                $periods[] = ['startDate' => self::dateObject($date), 'closed' => true];

                continue;
            }

            foreach ($spans as [$open, $close]) {
                $period = self::period((string) $date, $open, $close);

                if ($period !== null) {
                    $periods[] = $period;
                }
            }
        }

        return ['specialHourPeriods' => $periods];
    }

    /**
     * A date field's value as `YYYY-MM-DD`, or null when it is not a date.
     *
     * @param  mixed  $value  `YYYY-MM-DD`, optionally followed by a time, or a date object.
     */
    public static function date(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if (preg_match('/^(\d{4})-(\d\d)-(\d\d)(?:$|[ T])/', trim(Values::str($value)), $matches) !== 1) {
            return null;
        }

        return checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1]) ? $matches[1].'-'.$matches[2].'-'.$matches[3] : null;
    }

    /**
     * Set each date a holiday's in-person rules name.
     *
     * @param  array<string, list<array{int, int}>>  $dates  Date => spans, updated.
     * @param  array<array-key, mixed>  $row  A `holiday` row.
     * @param  string  $today  The first date written.
     * @param  string  $last  The last date written.
     */
    private static function applyHoliday(array &$dates, array $row, string $today, string $last): void
    {
        foreach (RegularHours::enabled($row['rules'] ?? null) as $rule) {
            $from = self::date($rule['date'] ?? null);
            $spans = RegularHours::spans($rule);

            if ($from === null || $spans === null || ! RegularHours::inPerson($rule)) {
                continue;
            }

            foreach (self::each($from, self::date($rule['through'] ?? null) ?? $from, $today, $last) as $date) {
                $dates[$date] = $spans;
            }
        }
    }

    /**
     * Set each date of a temporary change that one of its in-person weekly rules names by its weekday.
     *
     * @param  array<string, list<array{int, int}>>  $dates  Date => spans, updated.
     * @param  array<array-key, mixed>  $row  A `temporary_change` row.
     * @param  string  $today  The first date written.
     * @param  string  $last  The last date written, and the end of a change with none.
     */
    private static function applyChange(array &$dates, array $row, string $today, string $last): void
    {
        $from = self::date($row['valid_from'] ?? null);

        if ($from === null) {
            return;
        }

        $range = self::each($from, self::date($row['valid_through'] ?? null) ?? $last, $today, $last);

        foreach (RegularHours::enabled($row['rules'] ?? null) as $rule) {
            $spans = RegularHours::spans($rule);

            if ($spans === null || ! RegularHours::inPerson($rule)) {
                continue;
            }

            $days = RegularHours::days($rule['days'] ?? null);

            foreach ($range as $date) {
                if (in_array(strtolower(new DateTimeImmutable($date)->format('l')), $days, true)) {
                    $dates[$date] = $spans;
                }
            }
        }
    }

    /**
     * The dates from one date through another, kept to the written window.
     *
     * @param  string  $from  The first date.
     * @param  string  $through  The last date.
     * @param  string  $today  The first date written.
     * @param  string  $last  The last date written.
     * @return list<string>
     */
    private static function each(string $from, string $through, string $today, string $last): array
    {
        $through = min($through, $last);
        $dates = [];

        for ($date = new DateTimeImmutable(max($from, $today)); $date->format('Y-m-d') <= $through; $date = $date->modify('+1 day')) {
            $dates[] = $date->format('Y-m-d');
        }

        return $dates;
    }

    /**
     * One open period on a date, or null when Business Profile could not take it.
     *
     * @param  string  $date  The date it opens, `YYYY-MM-DD`.
     * @param  int  $open  Opening, in minutes from midnight.
     * @param  int  $close  Closing, in minutes from midnight, up to 1440.
     * @return ?array<string, mixed>
     */
    private static function period(string $date, int $open, int $close): ?array
    {
        if ($open === $close) {
            return null;
        }

        // Open all day is one minute short of 24 hours
        if ($open === 0 && $close === self::DAY_END) {
            $close = self::DAY_END - 1;
        }

        $endDate = $date;

        if ($close < $open) {
            $endDate = new DateTimeImmutable($date)->modify('+1 day')->format('Y-m-d');
            $close = min($close, self::LAST_NEXT_DAY_CLOSE);
        }

        return [
            'startDate' => self::dateObject($date),
            'openTime' => ['hours' => intdiv($open, 60), 'minutes' => $open % 60],
            'endDate' => self::dateObject($endDate),
            'closeTime' => ['hours' => intdiv($close, 60), 'minutes' => $close % 60],
        ];
    }

    /**
     * A `YYYY-MM-DD` date as a Business Profile date.
     *
     * @param  string  $date  The date.
     * @return array{year: int, month: int, day: int}
     */
    private static function dateObject(string $date): array
    {
        [$year, $month, $day] = array_map(intval(...), explode('-', $date));

        return ['year' => $year, 'month' => $month, 'day' => $day];
    }
}

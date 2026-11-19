<?php

declare(strict_types=1);

use UAMSWeb\Aether\People\ListingsManagement\BusinessProfile\SpecialHours;
use UAMSWeb\Aether\People\ListingsManagement\BusinessProfile\Values;

/**
 * A location's `special_hours` rows as Business Profile special-hour periods,
 * from a fixed today of Friday 2026-11-20, whose 90-day window ends 2027-02-17.
 *
 * @command  vendor/bin/pest tests/Unit/BusinessProfile/SpecialHoursTest.php
 */
const SPECIAL_TODAY = '2026-11-20';

/**
 * A holiday row, shaped as the replicator stores it.
 *
 * @param  list<array<string, mixed>>  $rules
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function holidayRow(array $rules, array $extra = []): array
{
    return array_replace(['type' => 'holiday', 'title' => 'Holiday', 'rules' => $rules], $extra);
}

/**
 * One dated holiday rule.
 *
 * @param  list<array{0: string, 1: string}>  $spans
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function holidayRule(string $date, ?string $through = null, string $status = 'closed', array $spans = [], array $extra = []): array
{
    return array_replace([
        'type' => 'rule',
        'date' => $date,
        'through' => $through,
        'service_mode' => 'in_person',
        'status' => $status,
        'time_span' => array_map(static fn (array $span): array => ['type' => 'new_set', 'open' => $span[0], 'close' => $span[1]], $spans),
    ], $extra);
}

/**
 * A temporary change row with weekly rules.
 *
 * @param  list<array<string, mixed>>  $rules
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function changeRow(?string $from, ?string $through, array $rules, array $extra = []): array
{
    return array_replace(['type' => 'temporary_change', 'title' => 'Change', 'valid_from' => $from, 'valid_through' => $through, 'rules' => $rules], $extra);
}

/**
 * One weekly rule in a temporary change.
 *
 * @param  list<string>  $days
 * @param  list<array{0: string, 1: string}>  $spans
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function weeklyRule(array $days, string $status = 'open', array $spans = [['09:00', '12:00']], array $extra = []): array
{
    return array_replace([
        'type' => 'custom',
        'days' => $days,
        'service_mode' => 'in_person',
        'status' => $status,
        'time_span' => array_map(static fn (array $span): array => ['type' => 'new_set', 'open' => $span[0], 'close' => $span[1]], $spans),
    ], $extra);
}

/**
 * The periods for rows, from the fixed today.
 *
 * @return list<array<string, mixed>>
 */
function specialPeriods(mixed $rows): array
{
    return SpecialHours::fromRows($rows, SPECIAL_TODAY)['specialHourPeriods'];
}

/**
 * The start dates of the periods for rows, as `YYYY-MM-DD`.
 *
 * @return list<string>
 */
function specialDates(mixed $rows): array
{
    return array_map(static function (array $period): string {
        $date = Values::arr($period['startDate'] ?? null);

        return sprintf('%04d-%02d-%02d', (int) Values::str($date['year'] ?? null), (int) Values::str($date['month'] ?? null), (int) Values::str($date['day'] ?? null));
    }, specialPeriods($rows));
}

/**
 * One open period, for comparing.
 *
 * @param  array{int, int, int}  $start  Year, month and day it opens.
 * @param  array{int, int, int}  $end  Year, month and day it closes.
 * @return array<string, mixed>
 */
function openPeriod(array $start, int $openHours, int $openMinutes, array $end, int $closeHours, int $closeMinutes): array
{
    return [
        'startDate' => ['year' => $start[0], 'month' => $start[1], 'day' => $start[2]],
        'openTime' => ['hours' => $openHours, 'minutes' => $openMinutes],
        'endDate' => ['year' => $end[0], 'month' => $end[1], 'day' => $end[2]],
        'closeTime' => ['hours' => $closeHours, 'minutes' => $closeMinutes],
    ];
}

it('writes a closed holiday as a closed date', function (): void {
    expect(SpecialHours::fromRows([holidayRow([holidayRule('2026-11-26')])], SPECIAL_TODAY))
        ->toBe(['specialHourPeriods' => [['startDate' => ['year' => 2026, 'month' => 11, 'day' => 26], 'closed' => true]]]);
});

it('writes nothing for no rows', function (mixed $rows): void {
    expect(SpecialHours::fromRows($rows, SPECIAL_TODAY))->toBe(['specialHourPeriods' => []]);
})->with([
    'none' => [null],
    'empty' => [[]],
    'not a list' => ['closed'],
]);

it('writes a holiday range one date at a time, with each span of an open rule', function (): void {
    $rows = [holidayRow([holidayRule('2026-11-27', '2026-11-28', 'open', [['08:00', '11:30'], ['13:00', '16:00']])])];

    expect(specialPeriods($rows))->toBe([
        openPeriod([2026, 11, 27], 8, 0, [2026, 11, 27], 11, 30),
        openPeriod([2026, 11, 27], 13, 0, [2026, 11, 27], 16, 0),
        openPeriod([2026, 11, 28], 8, 0, [2026, 11, 28], 11, 30),
        openPeriod([2026, 11, 28], 13, 0, [2026, 11, 28], 16, 0),
    ]);
});

it('writes no date before today, and today itself', function (): void {
    $rows = [holidayRow([holidayRule('2026-11-19'), holidayRule('2026-11-18', '2026-11-21')])];

    expect(specialDates($rows))->toBe(['2026-11-20', '2026-11-21']);
});

it('writes no date past the last day of the 90-day window', function (): void {
    $rows = [holidayRow([holidayRule('2027-02-17'), holidayRule('2027-02-18'), holidayRule('2027-02-16', '2027-03-31')])];

    expect(specialDates($rows))->toBe(['2027-02-16', '2027-02-17']);
});

it('writes a temporary change on the weekdays its rules name, from its first date through its last', function (): void {
    // Monday 2026-11-23 through Sunday 2026-11-29, open weekday mornings
    $rows = [changeRow('2026-11-23', '2026-11-29', [weeklyRule(['monday', 'tuesday', 'wednesday', 'thursday', 'friday'])])];

    expect(specialDates($rows))->toBe(['2026-11-23', '2026-11-24', '2026-11-25', '2026-11-26', '2026-11-27'])
        ->and(specialPeriods($rows)[0])->toBe(openPeriod([2026, 11, 23], 9, 0, [2026, 11, 23], 12, 0));
});

it('expands an ongoing temporary change through the last day of the window, and starts a past one today', function (): void {
    // Started a month ago, no end: every Wednesday from today on
    $dates = specialDates([changeRow('2026-10-01', null, [weeklyRule(['wednesday'], 'closed', [])])]);

    expect($dates)->toHaveCount(13)
        ->and($dates[0])->toBe('2026-11-25')
        ->and($dates[12])->toBe('2027-02-17')
        ->and(specialDates([changeRow('2026-10-01', '', [weeklyRule(['friday'], 'closed', [])])])[0])->toBe('2026-11-20');
});

it('writes nothing for a temporary change that has ended, or that has no first date', function (): void {
    expect(specialPeriods([
        changeRow('2026-10-01', '2026-11-19', [weeklyRule(['friday'])]),
        changeRow(null, '2026-12-31', [weeklyRule(['friday'])]),
        changeRow('not a date', null, [weeklyRule(['friday'])]),
    ]))->toBe([]);
});

it('lets a holiday beat a temporary change on the same date, whichever comes first', function (): void {
    $rows = [
        holidayRow([holidayRule('2026-11-26')]),
        changeRow('2026-11-23', '2026-11-27', [weeklyRule(['thursday', 'friday'])]),
    ];

    expect(specialPeriods($rows))->toBe([
        ['startDate' => ['year' => 2026, 'month' => 11, 'day' => 26], 'closed' => true],
        openPeriod([2026, 11, 27], 9, 0, [2026, 11, 27], 12, 0),
    ]);
});

it('lets a later rule beat an earlier one on the same date', function (): void {
    $holidays = [holidayRow([holidayRule('2026-11-26', '2026-11-27')]), holidayRow([holidayRule('2026-11-27', status: 'open', spans: [['10:00', '14:00']])])];
    $changes = [changeRow('2026-11-23', '2026-11-23', [weeklyRule(['monday']), weeklyRule(['monday'], spans: [['07:00', '08:00']])])];

    expect(specialPeriods($holidays))->toBe([
        ['startDate' => ['year' => 2026, 'month' => 11, 'day' => 26], 'closed' => true],
        openPeriod([2026, 11, 27], 10, 0, [2026, 11, 27], 14, 0),
    ])
        ->and(specialPeriods($changes))->toBe([openPeriod([2026, 11, 23], 7, 0, [2026, 11, 23], 8, 0)]);
});

it('lets an open rule with no usable span leave its dates as they were', function (): void {
    $rows = [holidayRow([holidayRule('2026-11-26'), holidayRule('2026-11-26', status: 'open', spans: [['', '']])])];

    expect(specialPeriods($rows))->toBe([['startDate' => ['year' => 2026, 'month' => 11, 'day' => 26], 'closed' => true]]);
});

it('skips telemedicine rules, rows and rules turned off, rules with no date, and rows of an unknown type', function (): void {
    $rows = [
        holidayRow([holidayRule('2026-11-26', extra: ['service_mode' => ' telemedicine '])]),
        holidayRow([holidayRule('2026-11-27')], ['enabled' => false]),
        holidayRow([holidayRule('2026-11-28', extra: ['enabled' => false]), holidayRule(''), holidayRule('2026-02-30')]),
        changeRow('2026-11-23', '2026-11-29', [weeklyRule(['monday'], extra: ['service_mode' => 'telemedicine']), weeklyRule(['tuesday'], extra: ['enabled' => false])]),
        changeRow('2026-11-23', '2026-11-29', [weeklyRule(['wednesday'])], ['enabled' => false]),
        holidayRow([holidayRule('2026-11-29')], ['type' => 'other']),
        holidayRow([holidayRule('2026-12-01')], ['type' => ' holiday ']),
        changeRow('2026-12-02', '2026-12-02', [weeklyRule(['wednesday'])], ['type' => ' temporary_change ']),
    ];

    expect(specialDates($rows))->toBe(['2026-12-01', '2026-12-02']);
});

it('writes open all day as 00:00 to 23:59, from the status or from a span of 00:00 to 00:00', function (): void {
    expect(specialPeriods([holidayRow([holidayRule('2026-11-26', status: 'open_24h'), holidayRule('2026-11-27', status: 'open', spans: [['00:00', '00:00']])])]))
        ->toBe([
            openPeriod([2026, 11, 26], 0, 0, [2026, 11, 26], 23, 59),
            openPeriod([2026, 11, 27], 0, 0, [2026, 11, 27], 23, 59),
        ]);
});

it('closes a span that ends at 00:00 at 24:00 on its own date', function (): void {
    expect(specialPeriods([holidayRow([holidayRule('2026-11-26', status: 'open', spans: [['18:00', '00:00']])])]))
        ->toBe([openPeriod([2026, 11, 26], 18, 0, [2026, 11, 26], 24, 0)]);
});

it('closes a span past midnight on the next date, no later than 11:59, across a year end', function (): void {
    expect(specialPeriods([holidayRow([
        holidayRule('2026-11-26', status: 'open', spans: [['22:00', '06:30']]),
        holidayRule('2026-12-31', status: 'open', spans: [['20:00', '13:00']]),
    ])]))->toBe([
        openPeriod([2026, 11, 26], 22, 0, [2026, 11, 27], 6, 30),
        openPeriod([2026, 12, 31], 20, 0, [2027, 1, 1], 11, 59),
    ]);
});

it('drops a span that closes the minute it opens, keeping the others on its date', function (): void {
    expect(specialPeriods([holidayRow([holidayRule('2026-11-26', status: 'open', spans: [['08:00', '08:00'], ['09:00', '10:00']])])]))
        ->toBe([openPeriod([2026, 11, 26], 9, 0, [2026, 11, 26], 10, 0)]);
});

it('reads a date as text with or without a time, or as a date object', function (mixed $value, ?string $expected): void {
    expect(SpecialHours::date($value))->toBe($expected);
})->with([
    'date' => ['2026-11-26', '2026-11-26'],
    'with a time' => ['2026-11-26 00:00', '2026-11-26'],
    'ISO with a time' => ['2026-11-26T08:00:00Z', '2026-11-26'],
    'surrounding space' => [' 2026-11-26 ', '2026-11-26'],
    'object' => [new DateTimeImmutable('2026-11-26 15:00'), '2026-11-26'],
    'no such day' => ['2026-02-30', null],
    'no such month' => ['2026-13-01', null],
    'trailing text' => ['2026-11-26x', null],
    'leading text' => ['x2026-11-26', null],
    'short year' => ['26-11-26', null],
    'not a date' => ['tomorrow', null],
    'not text' => [['2026-11-26'], null],
]);

it('reads today in the time zone the hours are kept in', function (): void {
    expect(SpecialHours::today())->toBe(new DateTimeImmutable('now', new DateTimeZone('America/Chicago'))->format('Y-m-d'))
        ->and(SpecialHours::TIMEZONE)->toBe('America/Chicago')
        ->and(SpecialHours::HORIZON_DAYS)->toBe(90);
});

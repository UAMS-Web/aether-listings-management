<?php

declare(strict_types=1);

use UAMSWeb\Aether\People\ListingsManagement\BusinessProfile\RegularHours;

/**
 * A location's `regular_hours` rules as Business Profile periods.
 *
 * @command  vendor/bin/pest tests/Unit/BusinessProfile/RegularHoursTest.php
 */

/**
 * One hours rule, shaped as the replicator stores it.
 *
 * @param  list<mixed>  $days
 * @param  list<array{0: string, 1: string}>  $spans  Open and close times.
 * @param  array<string, mixed>  $extra  More keys for the rule.
 * @return array<string, mixed>
 */
function hoursRule(array $days, string $status = 'open', array $spans = [['08:00', '17:00']], array $extra = []): array
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
 * One period, for comparing against the mapper's output.
 *
 * @return array{openDay: string, openTime: array{hours: int, minutes: int}, closeDay: string, closeTime: array{hours: int, minutes: int}}
 */
function hoursPeriod(string $openDay, int $openHours, int $openMinutes, string $closeDay, int $closeHours, int $closeMinutes): array
{
    return [
        'openDay' => $openDay,
        'openTime' => ['hours' => $openHours, 'minutes' => $openMinutes],
        'closeDay' => $closeDay,
        'closeTime' => ['hours' => $closeHours, 'minutes' => $closeMinutes],
    ];
}

it('writes a weekday rule as one period per day, in week order', function (): void {
    $hours = RegularHours::fromRules([hoursRule(['friday', 'monday', 'tuesday', 'wednesday', 'thursday'], spans: [['08:00', '16:30']])]);

    expect($hours)->toBe(['periods' => [
        hoursPeriod('MONDAY', 8, 0, 'MONDAY', 16, 30),
        hoursPeriod('TUESDAY', 8, 0, 'TUESDAY', 16, 30),
        hoursPeriod('WEDNESDAY', 8, 0, 'WEDNESDAY', 16, 30),
        hoursPeriod('THURSDAY', 8, 0, 'THURSDAY', 16, 30),
        hoursPeriod('FRIDAY', 8, 0, 'FRIDAY', 16, 30),
    ]]);
});

it('writes every span of a day, such as a midday closure', function (): void {
    $hours = RegularHours::fromRules([hoursRule(['saturday'], spans: [['08:00', '11:30'], ['12:30', '16:45']])]);

    expect($hours)->toBe(['periods' => [
        hoursPeriod('SATURDAY', 8, 0, 'SATURDAY', 11, 30),
        hoursPeriod('SATURDAY', 12, 30, 'SATURDAY', 16, 45),
    ]]);
});

it('lets a later rule replace an earlier one for the days it names', function (): void {
    // Daily hours, then shorter Friday hours
    $hours = RegularHours::fromRules([
        hoursRule(['monday', 'friday']),
        hoursRule(['friday'], spans: [['09:00', '12:00']]),
    ]);

    expect($hours)->toBe(['periods' => [
        hoursPeriod('MONDAY', 8, 0, 'MONDAY', 17, 0),
        hoursPeriod('FRIDAY', 9, 0, 'FRIDAY', 12, 0),
    ]]);
});

it('leaves closed days out of the periods, even when an earlier rule opened them', function (): void {
    $hours = RegularHours::fromRules([
        hoursRule(['monday', 'tuesday', 'sunday']),
        hoursRule(['tuesday', 'sunday'], ' closed ', []),
    ]);

    expect($hours)->toBe(['periods' => [hoursPeriod('MONDAY', 8, 0, 'MONDAY', 17, 0)]]);
});

it('writes no hours when every day is closed, or there are no rules', function (mixed $rules): void {
    expect(RegularHours::fromRules($rules))->toBeNull();
})->with([
    'all closed' => [[hoursRule(['monday', 'sunday'], 'closed', [])]],
    'empty list' => [[]],
    'no value' => [null],
    'not a list' => ['monday'],
]);

it('writes an open-24-hours day as 00:00 to 24:00', function (): void {
    expect(RegularHours::fromRules([hoursRule(['wednesday'], ' open_24h ', [])]))
        ->toBe(['periods' => [hoursPeriod('WEDNESDAY', 0, 0, 'WEDNESDAY', 24, 0)]]);
});

it('reads any other status, or none, as open during the spans', function (): void {
    $rule = hoursRule(['monday']);
    unset($rule['status']);

    expect(RegularHours::fromRules([$rule]))->toBe(['periods' => [hoursPeriod('MONDAY', 8, 0, 'MONDAY', 17, 0)]])
        ->and(RegularHours::fromRules([hoursRule(['monday'], 'unknown')]))->toBe(['periods' => [hoursPeriod('MONDAY', 8, 0, 'MONDAY', 17, 0)]]);
});

it('skips telemedicine rules and reads a rule with no service mode as in person', function (): void {
    $premises = hoursRule(['monday']);
    unset($premises['service_mode']);

    // The telemedicine rule comes first, so skipping it must not end the reading
    $hours = RegularHours::fromRules([
        hoursRule(['monday', 'tuesday'], spans: [['06:00', '22:00']], extra: ['service_mode' => ' telemedicine ']),
        $premises,
    ]);

    expect($hours)->toBe(['periods' => [hoursPeriod('MONDAY', 8, 0, 'MONDAY', 17, 0)]]);
});

it('closes a span that ends at 00:00 at the end of its own day', function (): void {
    expect(RegularHours::fromRules([hoursRule(['monday'], spans: [['18:00', '00:00']])]))
        ->toBe(['periods' => [hoursPeriod('MONDAY', 18, 0, 'MONDAY', 24, 0)]]);
});

it('reads a span from 00:00 to 00:00 as the whole day, and one closing when it opens as closing the next day', function (): void {
    expect(RegularHours::fromRules([hoursRule(['saturday'], spans: [['00:00', '00:00']])]))
        ->toBe(['periods' => [hoursPeriod('SATURDAY', 0, 0, 'SATURDAY', 24, 0)]])
        ->and(RegularHours::fromRules([hoursRule(['monday'], spans: [['08:00', '08:00']])]))
        ->toBe(['periods' => [hoursPeriod('MONDAY', 8, 0, 'TUESDAY', 8, 0)]]);
});

it('closes a span that ends before it opens on the next day, Sunday into Monday', function (): void {
    expect(RegularHours::fromRules([hoursRule(['tuesday', 'sunday'], spans: [['22:00', '06:15']])]))
        ->toBe(['periods' => [
            hoursPeriod('TUESDAY', 22, 0, 'WEDNESDAY', 6, 15),
            hoursPeriod('SUNDAY', 22, 0, 'MONDAY', 6, 15),
        ]]);
});

it('drops a span without two usable times, and keeps the good ones', function (string $open, string $close): void {
    expect(RegularHours::fromRules([hoursRule(['monday'], spans: [[$open, $close], ['13:00', '17:00']])]))
        ->toBe(['periods' => [hoursPeriod('MONDAY', 13, 0, 'MONDAY', 17, 0)]]);
})->with([
    'no open' => ['', '12:00'],
    'no close' => ['08:00', ''],
    'hour 24' => ['24:00', '12:00'],
    'minute 60' => ['08:60', '12:00'],
    'not a time' => ['8am', '12:00'],
    'trailing text' => ['08:00x', '12:00'],
    'leading text' => ['x08:00', '12:00'],
]);

it('lets an open rule with no usable span leave its days as they were', function (): void {
    $hours = RegularHours::fromRules([
        hoursRule(['monday']),
        hoursRule(['monday'], spans: [['', '']]),
        hoursRule(['monday'], spans: []),
    ]);

    expect($hours)->toBe(['periods' => [hoursPeriod('MONDAY', 8, 0, 'MONDAY', 17, 0)]]);
});

it('skips a rule or a span an editor turned off', function (): void {
    $rule = hoursRule(['monday'], spans: [], extra: ['time_span' => [
        ['type' => 'new_set', 'open' => '08:00', 'close' => '12:00', 'enabled' => false],
        ['type' => 'new_set', 'open' => '13:00', 'close' => '17:00', 'enabled' => true],
    ]]);

    $hours = RegularHours::fromRules([
        $rule,
        hoursRule(['monday'], 'closed', [], ['enabled' => false]),
    ]);

    expect($hours)->toBe(['periods' => [hoursPeriod('MONDAY', 13, 0, 'MONDAY', 17, 0)]]);
});

it('reads days as keys or as options, and ignores what is not a day', function (): void {
    $hours = RegularHours::fromRules([hoursRule([' monday ', ['key' => 'tuesday', 'value' => 'Tuesday'], ['value' => 'wednesday'], 'Thursday', 'someday', 5, null])]);

    expect(array_column($hours['periods'] ?? [], 'openDay'))->toBe(['MONDAY', 'TUESDAY', 'WEDNESDAY']);
});

it('reads a time with one hour digit, seconds or surrounding space', function (): void {
    expect(RegularHours::fromRules([hoursRule(['monday'], spans: [['8:05', ' 23:59:30 ']])]))
        ->toBe(['periods' => [hoursPeriod('MONDAY', 8, 5, 'MONDAY', 23, 59)]]);
});

it('reads a time of day as minutes from midnight', function (mixed $value, ?int $expected): void {
    expect(RegularHours::minutes($value))->toBe($expected);
})->with([
    'midnight' => ['00:00', 0],
    'last minute' => ['23:59', 1439],
    'hour 24' => ['24:00', null],
    'minute 60' => ['12:60', null],
    'three hour digits' => ['123:00', null],
    'one minute digit' => ['12:5', null],
    'bad seconds' => ['12:00:5', null],
    'not text' => [['12:00'], null],
]);

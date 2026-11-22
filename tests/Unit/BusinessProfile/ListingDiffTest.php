<?php

declare(strict_types=1);

use UAMSWeb\Aether\People\ListingsManagement\BusinessProfile\ListingDiff;

/**
 * Which fields and attributes differ from the live listing, and the mask that names them.
 *
 * @command  vendor/bin/pest tests/Unit/BusinessProfile/ListingDiffTest.php
 */

/**
 * Fields stating every owned field.
 *
 * @return array<string, mixed>
 */
function diffFields(): array
{
    return [
        'title' => 'Clinic',
        'storefrontAddress' => ['regionCode' => 'US', 'languageCode' => 'en', 'postalCode' => '72205', 'administrativeArea' => 'AR', 'locality' => 'Little Rock', 'addressLines' => ['4301 W Markham St', 'Suite 100']],
        'latlng' => ['latitude' => 34.7500012, 'longitude' => -92.3200049],
        'phoneNumbers' => ['primaryPhone' => '+15016868000'],
        'websiteUri' => 'https://example.test/clinic/',
        'categories' => ['primaryCategory' => ['name' => 'categories/gcid:a'], 'additionalCategories' => [['name' => 'categories/gcid:b'], ['name' => 'categories/gcid:c']]],
        'profile' => ['description' => 'About the clinic.'],
        'regularHours' => ['periods' => [
            ['openDay' => 'MONDAY', 'openTime' => ['hours' => 8, 'minutes' => 30], 'closeDay' => 'MONDAY', 'closeTime' => ['hours' => 17, 'minutes' => 0]],
            ['openDay' => 'FRIDAY', 'openTime' => ['hours' => 0, 'minutes' => 0], 'closeDay' => 'FRIDAY', 'closeTime' => ['hours' => 24, 'minutes' => 0]],
            ['openDay' => 'SUNDAY', 'openTime' => ['hours' => 20, 'minutes' => 0], 'closeDay' => 'SUNDAY', 'closeTime' => ['hours' => 24, 'minutes' => 0]],
        ]],
    ];
}

it('finds no change across formatting differences', function (): void {
    // The same values as Google formats them
    $live = [
        'title' => ' Clinic ',
        'storefrontAddress' => ['regionCode' => 'US', 'postalCode' => '72205 ', 'administrativeArea' => 'AR', 'locality' => 'Little Rock', 'addressLines' => [' 4301 W Markham St', 'Suite 100 ']],
        'latlng' => ['latitude' => 34.750004, 'longitude' => -92.320001],
        'phoneNumbers' => ['primaryPhone' => '(501) 686-8000'],
        'websiteUri' => 'https://example.test/clinic/',
        'categories' => ['primaryCategory' => ['name' => 'categories/gcid:a', 'displayName' => 'A'], 'additionalCategories' => [['name' => 'categories/gcid:c'], ['name' => 'categories/gcid:b']]],
        'profile' => ['description' => 'About the clinic. '],
        // Google leaves out zero parts, sends midnight as {}, and may close at 00:00 the next day; the order is its own
        'regularHours' => ['periods' => [
            ['openDay' => 'SUNDAY', 'openTime' => ['hours' => 20], 'closeDay' => 'MONDAY', 'closeTime' => []],
            ['openDay' => 'FRIDAY', 'openTime' => [], 'closeDay' => 'FRIDAY', 'closeTime' => ['hours' => 24]],
            ['openDay' => 'MONDAY', 'openTime' => ['hours' => 8, 'minutes' => 30], 'closeDay' => 'MONDAY', 'closeTime' => ['hours' => 17]],
        ]],
    ];

    expect(ListingDiff::changedFields(diffFields(), $live))->toBe([]);
});

it('reports each differing field on its own, with its desired value', function (string $field, mixed $live): void {
    // A listing matching in everything but one field
    $listing = array_replace(diffFields(), [$field => $live]);

    expect(ListingDiff::changedFields(diffFields(), $listing))->toBe([$field => diffFields()[$field]]);
})->with([
    'title' => ['title', 'Old Clinic'],
    'address line' => ['storefrontAddress', ['postalCode' => '72205', 'administrativeArea' => 'AR', 'locality' => 'Little Rock', 'addressLines' => ['4301 W Markham St']]],
    'postal code' => ['storefrontAddress', ['postalCode' => '72204', 'administrativeArea' => 'AR', 'locality' => 'Little Rock', 'addressLines' => ['4301 W Markham St', 'Suite 100']]],
    'city' => ['storefrontAddress', ['postalCode' => '72205', 'administrativeArea' => 'AR', 'locality' => 'Conway', 'addressLines' => ['4301 W Markham St', 'Suite 100']]],
    'state' => ['storefrontAddress', ['postalCode' => '72205', 'administrativeArea' => 'TX', 'locality' => 'Little Rock', 'addressLines' => ['4301 W Markham St', 'Suite 100']]],
    'latitude' => ['latlng', ['latitude' => 34.76, 'longitude' => -92.3200049]],
    'longitude' => ['latlng', ['latitude' => 34.7500012, 'longitude' => -92.33]],
    'phone' => ['phoneNumbers', ['primaryPhone' => '(501) 686-8001']],
    'website' => ['websiteUri', 'https://example.test/old/'],
    'primary category' => ['categories', ['primaryCategory' => ['name' => 'categories/gcid:z'], 'additionalCategories' => [['name' => 'categories/gcid:b'], ['name' => 'categories/gcid:c']]]],
    'additional category' => ['categories', ['primaryCategory' => ['name' => 'categories/gcid:a'], 'additionalCategories' => [['name' => 'categories/gcid:b']]]],
    'description' => ['profile', ['description' => 'Old text.']],
    'opening minute' => ['regularHours', ['periods' => [
        ['openDay' => 'MONDAY', 'openTime' => ['hours' => 8, 'minutes' => 0], 'closeDay' => 'MONDAY', 'closeTime' => ['hours' => 17]],
        ['openDay' => 'FRIDAY', 'openTime' => [], 'closeDay' => 'FRIDAY', 'closeTime' => ['hours' => 24]],
        ['openDay' => 'SUNDAY', 'openTime' => ['hours' => 20], 'closeDay' => 'SUNDAY', 'closeTime' => ['hours' => 24]],
    ]]],
    'closing hour' => ['regularHours', ['periods' => [
        ['openDay' => 'MONDAY', 'openTime' => ['hours' => 8, 'minutes' => 30], 'closeDay' => 'MONDAY', 'closeTime' => ['hours' => 18]],
        ['openDay' => 'FRIDAY', 'openTime' => [], 'closeDay' => 'FRIDAY', 'closeTime' => ['hours' => 24]],
        ['openDay' => 'SUNDAY', 'openTime' => ['hours' => 20], 'closeDay' => 'SUNDAY', 'closeTime' => ['hours' => 24]],
    ]]],
    'a day more' => ['regularHours', ['periods' => [
        ['openDay' => 'MONDAY', 'openTime' => ['hours' => 8, 'minutes' => 30], 'closeDay' => 'MONDAY', 'closeTime' => ['hours' => 17]],
        ['openDay' => 'TUESDAY', 'openTime' => ['hours' => 8, 'minutes' => 30], 'closeDay' => 'TUESDAY', 'closeTime' => ['hours' => 17]],
        ['openDay' => 'FRIDAY', 'openTime' => [], 'closeDay' => 'FRIDAY', 'closeTime' => ['hours' => 24]],
        ['openDay' => 'SUNDAY', 'openTime' => ['hours' => 20], 'closeDay' => 'SUNDAY', 'closeTime' => ['hours' => 24]],
    ]]],
    'another day' => ['regularHours', ['periods' => [
        ['openDay' => 'TUESDAY', 'openTime' => ['hours' => 8, 'minutes' => 30], 'closeDay' => 'TUESDAY', 'closeTime' => ['hours' => 17]],
        ['openDay' => 'FRIDAY', 'openTime' => [], 'closeDay' => 'FRIDAY', 'closeTime' => ['hours' => 24]],
        ['openDay' => 'SUNDAY', 'openTime' => ['hours' => 20], 'closeDay' => 'SUNDAY', 'closeTime' => ['hours' => 24]],
    ]]],
    'midnight that is not the next day' => ['regularHours', ['periods' => [
        ['openDay' => 'MONDAY', 'openTime' => ['hours' => 8, 'minutes' => 30], 'closeDay' => 'MONDAY', 'closeTime' => ['hours' => 17]],
        ['openDay' => 'FRIDAY', 'openTime' => [], 'closeDay' => 'FRIDAY', 'closeTime' => ['hours' => 24]],
        ['openDay' => 'SUNDAY', 'openTime' => ['hours' => 20], 'closeDay' => 'TUESDAY', 'closeTime' => []],
    ]]],
    'next day but not midnight' => ['regularHours', ['periods' => [
        ['openDay' => 'MONDAY', 'openTime' => ['hours' => 8, 'minutes' => 30], 'closeDay' => 'MONDAY', 'closeTime' => ['hours' => 17]],
        ['openDay' => 'FRIDAY', 'openTime' => [], 'closeDay' => 'FRIDAY', 'closeTime' => ['hours' => 24]],
        ['openDay' => 'SUNDAY', 'openTime' => ['hours' => 20], 'closeDay' => 'MONDAY', 'closeTime' => ['minutes' => 1]],
    ]]],
    'no hours' => ['regularHours', ['periods' => []]],
]);

it('counts a field missing from the live listing as a change', function (): void {
    $live = diffFields();
    unset($live['websiteUri']);

    expect(ListingDiff::changedFields(diffFields(), $live))->toBe(['websiteUri' => 'https://example.test/clinic/']);
});

it('never touches a field the content does not state, or one the sync does not own', function (): void {
    // The content states only a title, and labels the sync does not own; the listing has more, including regular hours
    $changed = ListingDiff::changedFields(['title' => 'Clinic', 'labels' => ['x']], ['title' => 'Old', 'websiteUri' => 'https://example.test/', 'regularHours' => ['periods' => [['openDay' => 'MONDAY']]], 'labels' => ['y']]);

    expect($changed)->toBe(['title' => 'Clinic'])
        ->and(ListingDiff::updateMask(['labels' => [], 'profile' => [], 'title' => 'x']))->toBe('title,profile');
});

it('names the changed fields in the owned order in the mask', function (): void {
    expect(ListingDiff::updateMask(['regularHours' => [], 'profile' => [], 'categories' => [], 'title' => 'x', 'latlng' => []]))->toBe('title,latlng,categories,profile,regularHours')
        ->and(ListingDiff::updateMask([]))->toBe('');
});

it('reduces a phone number to its digits without the United States country code', function (string $phone, string $expected): void {
    expect(ListingDiff::normalizePhone($phone))->toBe($expected);
})->with([
    'E.164' => ['+15016868000', '5016868000'],
    'national' => ['(501) 686-8000', '5016868000'],
    'with a leading 1' => ['1-501-686-8000', '5016868000'],
    'ten digits starting with 1 stay' => ['1501686800', '1501686800'],
    'eleven digits not starting with 1 stay' => ['25016868000', '25016868000'],
    'empty' => ['', ''],
]);

it('reports the attributes that are missing or differ, and not the ones that match', function (): void {
    $changed = ListingDiff::changedAttributes(
        ['has_restroom' => true, 'requires_appointments' => false, 'has_video_visits' => true],
        ['has_restroom' => true, 'requires_appointments' => true],
    );

    expect($changed)->toBe(['requires_appointments' => false, 'has_video_visits' => true])
        ->and(ListingDiff::changedAttributes(['has_restroom' => false], ['has_restroom' => false]))->toBe([]);
});

it('compares later fields when the content does not state an earlier one', function (): void {
    $desired = diffFields();
    unset($desired['title']);

    expect(ListingDiff::changedFields($desired, array_replace(diffFields(), ['title' => 'Other', 'profile' => ['description' => 'Old.']])))->toBe(['profile' => ['description' => 'About the clinic.']]);
});

it('counts a phone the listing lacks as a change even when the content gives an empty one', function (): void {
    expect(ListingDiff::changedFields(['phoneNumbers' => ['primaryPhone' => '']], []))->toBe(['phoneNumbers' => ['primaryPhone' => '']]);
});

it('compares coordinates to five places, and city and state without surrounding space', function (): void {
    $live = array_replace(diffFields(), ['storefrontAddress' => ['postalCode' => '72205', 'administrativeArea' => ' AR ', 'locality' => ' Little Rock ', 'addressLines' => ['4301 W Markham St', 'Suite 100']]]);

    expect(ListingDiff::changedFields(diffFields(), $live))->toBe([])
        ->and(array_keys(ListingDiff::changedFields(['latlng' => ['latitude' => 34.75001, 'longitude' => -92.32]], ['latlng' => ['latitude' => 34.75002, 'longitude' => -92.32]])))->toBe(['latlng'])
        ->and(array_keys(ListingDiff::changedFields(['latlng' => ['latitude' => 34.75, 'longitude' => -92.32001]], ['latlng' => ['latitude' => 34.75, 'longitude' => -92.32002]])))->toBe(['latlng']);
});

it('compares additional categories by name alone, and counts a different name as a change', function (): void {
    $desired = ['categories' => ['primaryCategory' => ['name' => 'categories/gcid:a'], 'additionalCategories' => [['name' => 'categories/gcid:b'], ['name' => 'categories/gcid:c']]]];
    $sameWithLabels = ['categories' => ['primaryCategory' => ['name' => 'categories/gcid:a'], 'additionalCategories' => [['name' => 'categories/gcid:b', 'displayName' => 'B'], ['name' => 'categories/gcid:c', 'displayName' => 'C']]]];
    $otherName = ['categories' => ['primaryCategory' => ['name' => 'categories/gcid:a'], 'additionalCategories' => [['name' => 'categories/gcid:b'], ['name' => 'categories/gcid:x']]]];

    expect(ListingDiff::changedFields($desired, $sameWithLabels))->toBe([])
        ->and(array_keys(ListingDiff::changedFields($desired, $otherName)))->toBe(['categories']);
});

it('counts a period whose opening day alone, or closing day alone, differs as a change', function (string $openDay, string $closeDay): void {
    // An overnight span, Monday 22:00 to Tuesday 06:00
    $desired = ['regularHours' => ['periods' => [['openDay' => 'MONDAY', 'openTime' => ['hours' => 22], 'closeDay' => 'TUESDAY', 'closeTime' => ['hours' => 6]]]]];
    $live = ['regularHours' => ['periods' => [['openDay' => $openDay, 'openTime' => ['hours' => 22], 'closeDay' => $closeDay, 'closeTime' => ['hours' => 6]]]]];

    expect(ListingDiff::changedFields($desired, $live))->toBe($desired)
        ->and(ListingDiff::changedFields($desired, $desired))->toBe([]);
})->with([
    'opening day' => ['SUNDAY', 'TUESDAY'],
    'closing day' => ['MONDAY', 'WEDNESDAY'],
]);

/**
 * A special hours field from its periods.
 *
 * @param  list<array<string, mixed>>  $periods
 * @return array<string, mixed>
 */
function specialField(array $periods): array
{
    return ['specialHours' => ['specialHourPeriods' => $periods]];
}

it('finds no special hours change across Google formatting, order and past dates', function (): void {
    $desired = specialField([
        ['startDate' => ['year' => 2026, 'month' => 11, 'day' => 26], 'closed' => true],
        ['startDate' => ['year' => 2026, 'month' => 11, 'day' => 27], 'openTime' => ['hours' => 0, 'minutes' => 0], 'endDate' => ['year' => 2026, 'month' => 11, 'day' => 27], 'closeTime' => ['hours' => 12, 'minutes' => 30]],
    ]);
    // Google's order, no end date, midnight as {}, closed said false, times on a closed date, and a date before today
    $live = specialField([
        ['startDate' => ['year' => 2026, 'month' => 11, 'day' => 27], 'closed' => false, 'openTime' => [], 'closeTime' => ['hours' => 12, 'minutes' => 30]],
        ['startDate' => ['year' => 2026, 'month' => 11, 'day' => 26], 'closed' => true, 'openTime' => ['hours' => 9]],
        ['startDate' => ['year' => 2026, 'month' => 11, 'day' => 19], 'closed' => true],
    ]);

    expect(ListingDiff::changedFields($desired, $live, '2026-11-20'))->toBe([])
        ->and(ListingDiff::changedFields($desired, $live))->toBe($desired);
});

it('counts a special hours difference as a change', function (array $live): void {
    $desired = specialField([['startDate' => ['year' => 2026, 'month' => 11, 'day' => 27], 'openTime' => ['hours' => 22, 'minutes' => 0], 'endDate' => ['year' => 2026, 'month' => 11, 'day' => 28], 'closeTime' => ['hours' => 6, 'minutes' => 30]]]);

    expect(ListingDiff::changedFields($desired, $live, '2026-11-20'))->toBe($desired);
})->with([
    'none on the listing' => [[]],
    'another date' => [specialField([['startDate' => ['year' => 2026, 'month' => 11, 'day' => 26], 'openTime' => ['hours' => 22], 'endDate' => ['year' => 2026, 'month' => 11, 'day' => 27], 'closeTime' => ['hours' => 6, 'minutes' => 30]]])],
    'another month' => [specialField([['startDate' => ['year' => 2026, 'month' => 12, 'day' => 27], 'openTime' => ['hours' => 22], 'endDate' => ['year' => 2026, 'month' => 11, 'day' => 28], 'closeTime' => ['hours' => 6, 'minutes' => 30]]])],
    'another year' => [specialField([['startDate' => ['year' => 2027, 'month' => 11, 'day' => 27], 'openTime' => ['hours' => 22], 'endDate' => ['year' => 2026, 'month' => 11, 'day' => 28], 'closeTime' => ['hours' => 6, 'minutes' => 30]]])],
    'closed' => [specialField([['startDate' => ['year' => 2026, 'month' => 11, 'day' => 27], 'closed' => true]])],
    'opening' => [specialField([['startDate' => ['year' => 2026, 'month' => 11, 'day' => 27], 'openTime' => ['hours' => 21], 'endDate' => ['year' => 2026, 'month' => 11, 'day' => 28], 'closeTime' => ['hours' => 6, 'minutes' => 30]]])],
    'end date' => [specialField([['startDate' => ['year' => 2026, 'month' => 11, 'day' => 27], 'openTime' => ['hours' => 22], 'closeTime' => ['hours' => 6, 'minutes' => 30]]])],
    'closing' => [specialField([['startDate' => ['year' => 2026, 'month' => 11, 'day' => 27], 'openTime' => ['hours' => 22], 'endDate' => ['year' => 2026, 'month' => 11, 'day' => 28], 'closeTime' => ['hours' => 6]]])],
    'a date more' => [specialField([
        ['startDate' => ['year' => 2026, 'month' => 11, 'day' => 27], 'openTime' => ['hours' => 22], 'endDate' => ['year' => 2026, 'month' => 11, 'day' => 28], 'closeTime' => ['hours' => 6, 'minutes' => 30]],
        ['startDate' => ['year' => 2026, 'month' => 11, 'day' => 20], 'closed' => true],
    ])],
]);

it('reads an empty special hours set as clearing the listing future periods, and as matching none or only past ones', function (): void {
    $none = specialField([]);

    expect(ListingDiff::changedFields($none, [], '2026-11-20'))->toBe([])
        ->and(ListingDiff::changedFields($none, ['specialHours' => []], '2026-11-20'))->toBe([])
        ->and(ListingDiff::changedFields($none, specialField([['startDate' => ['year' => 2026, 'month' => 11, 'day' => 19], 'closed' => true]]), '2026-11-20'))->toBe([])
        ->and(ListingDiff::changedFields($none, specialField([['startDate' => ['year' => 2026, 'month' => 11, 'day' => 20], 'closed' => true]]), '2026-11-20'))->toBe($none)
        ->and(ListingDiff::updateMask(['specialHours' => [], 'regularHours' => []]))->toBe('regularHours,specialHours');
});

it('reads a special period closing at 00:00 the next day as closing at 24:00 on its own', function (): void {
    $desired = specialField([['startDate' => ['year' => 2026, 'month' => 12, 'day' => 31], 'openTime' => ['hours' => 18, 'minutes' => 0], 'endDate' => ['year' => 2026, 'month' => 12, 'day' => 31], 'closeTime' => ['hours' => 24, 'minutes' => 0]]]);
    $nextDay = specialField([['startDate' => ['year' => 2026, 'month' => 12, 'day' => 31], 'openTime' => ['hours' => 18], 'endDate' => ['year' => 2027, 'month' => 1, 'day' => 1], 'closeTime' => []]]);
    $twoDays = specialField([['startDate' => ['year' => 2026, 'month' => 12, 'day' => 31], 'openTime' => ['hours' => 18], 'endDate' => ['year' => 2027, 'month' => 1, 'day' => 2], 'closeTime' => []]]);
    $notMidnight = specialField([['startDate' => ['year' => 2026, 'month' => 12, 'day' => 31], 'openTime' => ['hours' => 18], 'endDate' => ['year' => 2027, 'month' => 1, 'day' => 1], 'closeTime' => ['minutes' => 1]]]);

    expect(ListingDiff::changedFields($desired, $nextDay, '2026-11-20'))->toBe([])
        ->and(ListingDiff::changedFields($desired, $twoDays, '2026-11-20'))->toBe($desired)
        ->and(ListingDiff::changedFields($desired, $notMidnight, '2026-11-20'))->toBe($desired);
});

it('names the dates from today on whose listing special hours the content would replace', function (): void {
    $desired = specialField([['startDate' => ['year' => 2026, 'month' => 11, 'day' => 26], 'closed' => true]]);
    $live = specialField([
        ['startDate' => ['year' => 2026, 'month' => 11, 'day' => 25], 'openTime' => ['hours' => 9], 'closeTime' => ['hours' => 10]],
        ['startDate' => ['year' => 2026, 'month' => 11, 'day' => 27], 'openTime' => ['hours' => 9], 'closeTime' => ['hours' => 10]],
        ['startDate' => ['year' => 2026, 'month' => 11, 'day' => 27], 'openTime' => ['hours' => 11], 'closeTime' => ['hours' => 12]],
        ['startDate' => ['year' => 2026, 'month' => 11, 'day' => 26], 'closed' => true],
        ['startDate' => ['year' => 2026, 'month' => 11, 'day' => 25], 'closed' => true],
        ['startDate' => ['year' => 2026, 'month' => 11, 'day' => 19], 'closed' => true],
    ]);

    expect(ListingDiff::replacedSpecialDates($desired, $live, '2026-11-20'))->toBe(['2026-11-25', '2026-11-27'])
        ->and(ListingDiff::replacedSpecialDates([], $live, '2026-11-20'))->toBe([])
        ->and(ListingDiff::replacedSpecialDates($desired, [], '2026-11-20'))->toBe([]);
});

<?php

declare(strict_types=1);

use UAMSWeb\Aether\People\ListingsManagement\BusinessProfile\EntryData;
use UAMSWeb\Aether\People\ListingsManagement\BusinessProfile\FieldMapper;
use UAMSWeb\Aether\People\ListingsManagement\BusinessProfile\Silos;
use UAMSWeb\Aether\People\ListingsManagement\BusinessProfile\StoreCodeComparison;

/**
 * The store codes a fixed content snapshot generates, against the codes on the
 * listings it should match, with each difference and its cause.
 *
 * The snapshot is synthetic (see its `_comment`): the site has no location
 * entries yet, and reading the live listings needs the Google credentials.
 *
 * @command  vendor/bin/pest tests/Unit/BusinessProfile/StoreCodeComparisonTest.php
 */
it('generates the store codes the snapshot listings carry, and lists each difference with its cause', function (): void {
    // Read the snapshot
    /** @var array{locations: list<array{slug: string, title: string, parent?: string}>, people: list<array{slug: string, locations: list<string>}>, existing: list<string>, differences: list<array{store_code: string, side: string, cause: string}>} $snapshot */
    $snapshot = json_decode((string) file_get_contents(__DIR__.'/../../Fixtures/store-code-snapshot.json'), true, flags: JSON_THROW_ON_ERROR);

    // Build the locations, parents first, then attach each child's parent
    $bySlug = [];
    foreach ($snapshot['locations'] as $location) {
        $bySlug[$location['slug']] = new EntryData($location['slug'], $location['title']);
    }

    foreach ($snapshot['locations'] as $location) {
        if (isset($location['parent'])) {
            $bySlug[$location['slug']] = new EntryData($location['slug'], $location['title'], parent: $bySlug[$location['parent']]);
        }
    }

    // Generate every store code the content asks for
    $mapper = new FieldMapper([]);
    $silos = new Silos([], [], false);
    $generated = array_map(fn (EntryData $location): string => $mapper->locationItem($location, $silos)->storeCode, array_values($bySlug));

    foreach ($snapshot['people'] as $person) {
        $locations = array_map(fn (string $slug): EntryData => $bySlug[$slug], $person['locations']);

        foreach ($mapper->providerItems(new EntryData($person['slug'], $person['slug']), $locations, $silos) as $item) {
            $generated[] = $item->storeCode;
        }
    }

    // Expect exactly the listed differences, so every other code matches
    expect(StoreCodeComparison::compare($generated, $snapshot['existing']))->toBe($snapshot['differences'])
        ->and(count($generated))->toBe(8);
});

it('names a difference only in letter case', function (): void {
    expect(StoreCodeComparison::compare(['jane-doe_west-clinic'], ['Jane-Doe_West-Clinic']))->toBe([
        ['store_code' => 'jane-doe_west-clinic', 'side' => 'generated', 'cause' => 'differs only in letter case from Jane-Doe_West-Clinic'],
        ['store_code' => 'Jane-Doe_West-Clinic', 'side' => 'existing', 'cause' => 'differs only in letter case from jane-doe_west-clinic'],
    ]);
});

it('lists nothing when the two sides match, repeats included', function (): void {
    expect(StoreCodeComparison::compare(['a', 'b_c', 'a'], ['b_c', 'a']))->toBe([]);
});

it('does not call a code with another number of slugs a shortened one', function (): void {
    expect(StoreCodeComparison::compare(['main'], ['main_cardiology']))->toBe([
        ['store_code' => 'main', 'side' => 'generated', 'cause' => 'no listing carries it, so the sync would create one'],
        ['store_code' => 'main_cardiology', 'side' => 'existing', 'cause' => 'no content generates it, so the sync never updates that listing'],
    ]);
});

it('does not call a code with an empty slug a shortened one', function (): void {
    expect(StoreCodeComparison::compare(['_cardiology'], ['main_cardiology'])[0]['cause'])->toBe('no listing carries it, so the sync would create one');
});

it('lists each difference once, in order, whatever the input order and repeats', function (): void {
    expect(StoreCodeComparison::compare(['c', 'a', 'c', 'b'], ['b', 'z', 'y', 'z']))->toBe([
        ['store_code' => 'a', 'side' => 'generated', 'cause' => 'no listing carries it, so the sync would create one'],
        ['store_code' => 'c', 'side' => 'generated', 'cause' => 'no listing carries it, so the sync would create one'],
        ['store_code' => 'y', 'side' => 'existing', 'cause' => 'no content generates it, so the sync never updates that listing'],
        ['store_code' => 'z', 'side' => 'existing', 'cause' => 'no content generates it, so the sync never updates that listing'],
    ]);
});

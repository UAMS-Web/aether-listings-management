<?php

declare(strict_types=1);

use UAMSWeb\Aether\People\ListingsManagement\BusinessProfile\EntryData;
use UAMSWeb\Aether\People\ListingsManagement\BusinessProfile\FieldMapper;
use UAMSWeb\Aether\People\ListingsManagement\BusinessProfile\Kind;
use UAMSWeb\Aether\People\ListingsManagement\BusinessProfile\Silos;
use UAMSWeb\Aether\People\ListingsManagement\BusinessProfile\SyncItem;
use UAMSWeb\Aether\People\ListingsManagement\BusinessProfile\Values;

/**
 * The field mapping from `people` and `clinical_locations` entries to listings,
 * through the handles the shipped configuration names, and the store codes.
 *
 * @command  vendor/bin/pest tests/Unit/BusinessProfile/FieldMapperTest.php
 */

/**
 * The shipped mapping, with overrides.
 *
 * @param  array<string, mixed>  $location  Location handle overrides.
 * @param  array<string, mixed>  $provider  Provider handle overrides.
 */
function mapper(array $location = [], array $provider = [], ?string $today = null): FieldMapper
{
    /** @var array{mapping: array{location: array<string, mixed>, provider: array<string, mixed>}} $config */
    $config = require __DIR__.'/../../../config/aether-listings-management.php';
    $mapping = $config['mapping'];
    $mapping['location'] = array_replace($mapping['location'], $location);
    $mapping['provider'] = array_replace($mapping['provider'], $provider);

    return new FieldMapper($mapping, $today);
}

function mapperSilos(): Silos
{
    return Silos::fromConfig([
        'main' => ['brand' => null, 'accounts' => ['provider' => 'accounts/mp', 'location' => 'accounts/ml']],
        'partner' => ['brand' => 'partner-health', 'accounts' => ['provider' => 'accounts/pp', 'location' => 'accounts/pl']],
    ], [], true);
}

/**
 * A location entry with a full set of values.
 *
 * @param  array<array-key, mixed>  $values  Value overrides.
 */
function locationEntry(string $slug = 'west-clinic', string $title = 'West Clinic in Little Rock', array $values = [], ?EntryData $parent = null): EntryData
{
    return new EntryData($slug, $title, 'https://health.example.test/locations/'.$slug, array_replace([
        'resolved_street_address' => '4301 W Markham St',
        'resolved_facility_name' => 'Outpatient Center',
        'resolved_facility_level' => 'Floor 2',
        'resolved_suite' => 'Suite 200',
        'resolved_city' => 'Little Rock',
        'resolved_state' => 'AR',
        'resolved_postal_code' => '72205',
        'resolved_map' => ['lat' => 34.75, 'lng' => '-92.32', 'zoom' => 16],
        'phone' => '+15016868000',
        'google_business_profile_categories' => [['gcid' => 'medical_clinic', 'title' => 'Medical clinic'], ['gcid' => 'family_practice_physician', 'title' => 'Family practice physician']],
        'short_desc' => '<p>A clinic &amp; more.</p>',
        'about' => 'Longer about text.',
        'google_business_profile_prefix_query' => true,
    ], $values), $parent);
}

/**
 * A person entry.
 *
 * @param  array<array-key, mixed>  $values  Value overrides.
 */
function personEntry(array $values = []): EntryData
{
    return new EntryData('jane-doe', 'Jane Doe', 'https://health.example.test/people/jane-doe', array_replace([
        'full_name_with_credentials' => 'Jane Q. Doe, MD',
        'display_name_short_with_prefix' => 'Dr. Jane Doe',
        'clinical_title' => 'Internist',
        'google_business_profile_clinical_category' => [['gcid' => 'internist', 'title' => 'Internist']],
        'bio_clinical_short' => '',
        'bio_clinical' => '',
    ], $values));
}

it('makes a location store code its slug, or its parent slug and its own', function (): void {
    $parent = locationEntry('main-campus', 'Main Campus');

    expect(FieldMapper::locationStoreCode(locationEntry()))->toBe('west-clinic')
        ->and(FieldMapper::locationStoreCode(locationEntry('cardiology', 'Cardiology', parent: $parent)))->toBe('main-campus_cardiology');
});

it('makes a provider store code the person slug and the location own slug, even for a child location', function (): void {
    $child = locationEntry('cardiology', 'Cardiology', parent: locationEntry('main-campus', 'Main Campus'));

    expect(FieldMapper::providerStoreCode(personEntry(), locationEntry()))->toBe('jane-doe_west-clinic')
        ->and(FieldMapper::providerStoreCode(personEntry(), $child))->toBe('jane-doe_cardiology');
});

it('maps a location to every field the sync owns', function (): void {
    $item = mapper()->locationItem(locationEntry(), mapperSilos());

    expect($item->storeCode)->toBe('west-clinic')
        ->and($item->kind)->toBe(Kind::Location)
        ->and($item->silo)->toBe('main')
        ->and($item->excluded)->toBeFalse()
        ->and($item->unresolvedCategories)->toBe([])
        ->and($item->fields)->toBe([
            'title' => 'UAMS Health - West Clinic in Little Rock',
            'storefrontAddress' => [
                'regionCode' => 'US',
                'languageCode' => 'en',
                'postalCode' => '72205',
                'administrativeArea' => 'AR',
                'locality' => 'Little Rock',
                'addressLines' => ['4301 W Markham St', 'Outpatient Center', 'Floor 2', 'Suite 200'],
            ],
            'latlng' => ['latitude' => 34.75, 'longitude' => -92.32],
            'phoneNumbers' => ['primaryPhone' => '+15016868000'],
            'profile' => ['description' => 'A clinic & more.'],
            'categories' => [
                'primaryCategory' => ['name' => 'categories/gcid:medical_clinic'],
                'additionalCategories' => [['name' => 'categories/gcid:family_practice_physician']],
            ],
            'websiteUri' => 'https://health.example.test/locations/west-clinic/?utm_source=google&utm_medium=gmb&utm_campaign=clinical&utm_term=location&utm_content=profile&utm_specs=west-clinic',
        ]);
});

it('leaves out a blank title, address line, phone, website and coordinates', function (): void {
    $location = new EntryData('bare', '', '', [
        'resolved_street_address' => '1 Main St',
        'resolved_facility_name' => '',
        'resolved_city' => 'Conway',
        'resolved_state' => 'AR',
        'resolved_postal_code' => '72032',
        'resolved_map' => ['lat' => '', 'lng' => -92.4],
        'phone' => '  ',
    ]);
    $fields = mapper()->locationItem($location, mapperSilos())->fields;

    expect(array_keys($fields))->toBe(['storefrontAddress', 'categories'])
        ->and($fields['storefrontAddress'])->toMatchArray(['addressLines' => ['1 Main St']]);
});

it('sends the address only with a line, a city, a state and a postal code', function (string $missing): void {
    $fields = mapper()->locationItem(locationEntry(values: [$missing => '']), mapperSilos())->fields;

    expect($fields)->not->toHaveKey('storefrontAddress');
})->with(['resolved_city', 'resolved_state', 'resolved_postal_code']);

it('sends no address without any line', function (): void {
    $fields = mapper()->locationItem(locationEntry(values: ['resolved_street_address' => '', 'resolved_facility_name' => '', 'resolved_facility_level' => '', 'resolved_suite' => '']), mapperSilos())->fields;

    expect($fields)->not->toHaveKey('storefrontAddress');
});

it('reads a child location title prefix, description and attributes from its parent, and its phone from itself', function (): void {
    $parent = locationEntry('main-campus', 'Main Campus', ['google_business_profile_prefix_query' => false, 'short_desc' => '', 'about' => 'The main campus.', 'phone' => '+15015550000', 'google_business_profile_has_restroom' => 'true']);
    $child = locationEntry('cardiology', 'Cardiology', ['google_business_profile_prefix_query' => true, 'short_desc' => 'Child text.', 'phone' => '+15015551111', 'google_business_profile_has_restroom' => 'false'], $parent);
    $item = mapper()->locationItem($child, mapperSilos());

    expect($item->storeCode)->toBe('main-campus_cardiology')
        ->and($item->fields['title'])->toBe('Cardiology')
        ->and($item->fields['profile'])->toBe(['description' => 'The main campus.'])
        ->and($item->fields['phoneNumbers'])->toBe(['primaryPhone' => '+15015551111'])
        ->and($item->attributes)->toBe(['has_restroom' => true]);
});

it('prefers the short description and falls back to the about text', function (): void {
    expect(mapper()->locationItem(locationEntry(values: ['short_desc' => '']), mapperSilos())->fields['profile'])->toBe(['description' => 'Longer about text.'])
        ->and(mapper()->locationItem(locationEntry(values: ['short_desc' => '', 'about' => '']), mapperSilos())->fields)->not->toHaveKey('profile');
});

it('cleans a description of markup and line breaks and cuts it to 747 characters with an ellipsis', function (): void {
    $long = str_repeat('word ', 200);
    $cut = FieldMapper::cleanDescription("<p>First line\nsecond\r\nline</p>".$long);

    expect(FieldMapper::cleanDescription("<p>First line\nsecond</p>"))->toBe('First line second')
        ->and(mb_strlen($cut))->toBe(747)
        ->and($cut)->toEndWith('...')
        ->and(FieldMapper::cleanDescription(str_repeat('a', 747)))->toBe(str_repeat('a', 747));
});

it('sends the first category as primary and up to nine others, without repeats', function (): void {
    $terms = array_map(fn (int $n): array => ['gcid' => 'c'.$n, 'title' => 'C'.$n], range(1, 12));
    $terms[] = ['gcid' => 'c1', 'title' => 'C1 again'];
    $categories = mapper()->locationItem(locationEntry(values: ['google_business_profile_categories' => array_merge([$terms[0]], $terms)]), mapperSilos())->fields['categories'];

    expect($categories)->toBe([
        'primaryCategory' => ['name' => 'categories/gcid:c1'],
        'additionalCategories' => array_map(fn (int $n): array => ['name' => 'categories/gcid:c'.$n], range(2, 10)),
    ]);
});

it('reports a category with no Google ID, and sends no categories when that is the primary one', function (): void {
    $withBadAdditional = mapper()->locationItem(locationEntry(values: ['google_business_profile_categories' => [['gcid' => 'medical_clinic'], ['title' => 'Made Up']]]), mapperSilos());
    $withBadPrimary = mapper()->locationItem(locationEntry(values: ['google_business_profile_categories' => [['title' => 'Made Up'], ['gcid' => 'medical_clinic']]]), mapperSilos());

    expect($withBadAdditional->fields['categories'])->toBe(['primaryCategory' => ['name' => 'categories/gcid:medical_clinic']])
        ->and($withBadAdditional->unresolvedCategories)->toBe(['Made Up'])
        ->and($withBadPrimary->fields)->not->toHaveKey('categories')
        ->and($withBadPrimary->unresolvedCategories)->toBe(['Made Up']);
});

it('sends the default category when none is chosen, and accepts bare IDs', function (): void {
    expect(mapper()->locationItem(locationEntry(values: ['google_business_profile_categories' => []]), mapperSilos())->fields['categories'])->toBe(['primaryCategory' => ['name' => 'categories/gcid:medical_clinic']])
        ->and(mapper()->locationItem(locationEntry(values: ['google_business_profile_categories' => 'gcid:pharmacy']), mapperSilos())->fields['categories'])->toBe(['primaryCategory' => ['name' => 'categories/gcid:pharmacy']])
        ->and(FieldMapper::categoryName('categories/gcid:x'))->toBe('categories/gcid:x')
        ->and(FieldMapper::categoryName(' doctor '))->toBe('categories/gcid:doctor');
});

it('sends no category when none is chosen and no default is configured', function (): void {
    $mapper = new FieldMapper(['location' => ['categories' => 'cats']]);

    expect($mapper->locationItem(locationEntry(), mapperSilos())->fields)->not->toHaveKey('categories');
});

it('reads attributes from the radios and the toggles, and leaves out every question without an answer', function (): void {
    $item = mapper()->locationItem(locationEntry(values: [
        'google_business_profile_has_wheelchair_accessible_entrance' => 'true',
        'google_business_profile_has_wheelchair_accessible_restroom' => 'notapplicable',
        'google_business_profile_has_restroom' => false,
        'google_business_profile_requires_appointments' => 'false',
        'telemed_query' => true,
    ]), mapperSilos());
    $noVideo = mapper()->locationItem(locationEntry(values: ['telemed_query' => false]), mapperSilos());

    expect($item->attributes)->toBe(['has_wheelchair_accessible_entrance' => true, 'has_restroom' => false, 'requires_appointments' => false, 'has_video_visits' => true])
        ->and($noVideo->attributes)->toBe([]);
});

it('excludes a location that excludes itself or whose parent does', function (bool $own, bool $parent, bool $expected): void {
    $parentEntry = locationEntry('main-campus', 'Main Campus', ['google_business_profile_exclude_query' => $parent]);
    $child = locationEntry('cardiology', 'Cardiology', ['google_business_profile_exclude_query' => $own], $parentEntry);

    expect(mapper()->locationItem($child, mapperSilos())->excluded)->toBe($expected)
        ->and(mapper()->locationItem(locationEntry(values: ['google_business_profile_exclude_query' => $own]), mapperSilos())->excluded)->toBe($own);
})->with([
    'neither' => [false, false, false],
    'its own' => [true, false, true],
    'its parent' => [false, true, true],
]);

it('resolves the silo from the location own brand', function (array $values, ?string $expected): void {
    expect(mapper()->silo(locationEntry(values: $values), mapperSilos()))->toBe($expected);
})->with([
    'the brand toggle off' => [['brand_organization_query' => false, 'brand_organization' => 'partner-health'], 'main'],
    'a partner brand' => [['brand_organization_query' => true, 'brand_organization' => 'partner-health'], 'partner'],
    'a partner brand as a list' => [['brand_organization_query' => true, 'brand_organization' => ['partner-health']], 'partner'],
    'a partner brand as an entry' => [['brand_organization_query' => true, 'brand_organization' => ['slug' => 'partner-health', 'title' => 'Partner']], 'partner'],
    'a partner brand in a list of entries' => [['brand_organization_query' => true, 'brand_organization' => [['slug' => 'partner-health']]], 'partner'],
    'a brand with no silo' => [['brand_organization_query' => true, 'brand_organization' => 'someone-else'], null],
    'the toggle on and no brand' => [['brand_organization_query' => true, 'brand_organization' => ''], null],
]);

it('ignores a parent location brand', function (): void {
    $parent = locationEntry('main-campus', 'Main Campus', ['brand_organization_query' => true, 'brand_organization' => 'partner-health']);

    expect(mapper()->locationItem(locationEntry('cardiology', 'Cardiology', parent: $parent), mapperSilos())->silo)->toBe('main');
});

it('removes the place a location is in, at or on from its name for an address line', function (string $name, string $expected): void {
    expect(FieldMapper::nameWithoutPlace($name))->toBe($expected);
})->with([
    'in' => ['West Clinic in Little Rock', 'West Clinic'],
    'at the' => ['Cardiology at the Main Campus', 'Cardiology'],
    'on' => ['Clinic On Markham', 'Clinic'],
    'first joiner wins' => ['Clinic at Main in Town', 'Clinic'],
    'no joiner' => ['Family Medical Center', 'Family Medical Center'],
    'a joiner inside a word' => ['Clinicat Main', 'Clinicat Main'],
    'nothing after the joiner' => ['Clinic in', 'Clinic in'],
    'nothing before the joiner' => ['in Little Rock', 'in Little Rock'],
    'trimmed' => ['  Clinic  ', 'Clinic'],
]);

it('titles a provider listing with the location, or without it when the location has no title', function (): void {
    expect(FieldMapper::providerTitle(' Jane Doe, MD ', ' West Clinic '))->toBe('UAMS Health - Jane Doe, MD - West Clinic')
        ->and(FieldMapper::providerTitle('Jane Doe, MD', ''))->toBe('UAMS Health - Jane Doe, MD');
});

it('maps a provider to one listing per location, with the location place and the provider own text', function (): void {
    $parent = locationEntry('main-campus', 'Main Campus at Markham');
    $child = locationEntry('cardiology', 'Cardiology in the Heart Center', ['phone' => '+15015551111'], $parent);
    $items = mapper()->providerItems(personEntry(['bio_clinical_short' => 'Treats adults.']), [locationEntry(), $child], mapperSilos());

    expect(array_map(fn (SyncItem $item): string => $item->storeCode, $items))->toBe(['jane-doe_west-clinic', 'jane-doe_cardiology'])
        ->and($items[0]->kind)->toBe(Kind::Provider)
        ->and($items[0]->attributes)->toBe([])
        ->and($items[0]->fields)->toBe([
            'title' => 'UAMS Health - Jane Q. Doe, MD - West Clinic in Little Rock',
            'storefrontAddress' => [
                'regionCode' => 'US',
                'languageCode' => 'en',
                'postalCode' => '72205',
                'administrativeArea' => 'AR',
                'locality' => 'Little Rock',
                'addressLines' => ['4301 W Markham St', 'Outpatient Center', 'West Clinic'],
            ],
            'latlng' => ['latitude' => 34.75, 'longitude' => -92.32],
            'phoneNumbers' => ['primaryPhone' => '+15016868000'],
            'profile' => ['description' => 'Treats adults.'],
            'categories' => ['primaryCategory' => ['name' => 'categories/gcid:internist']],
            'websiteUri' => 'https://health.example.test/people/jane-doe/?utm_source=google&utm_medium=gmb&utm_campaign=clinical&utm_term=provider&utm_content=profile&utm_specs=jane-doe_west-clinic',
        ])
        ->and($items[1]->fields['storefrontAddress'])->toMatchArray(['addressLines' => ['4301 W Markham St', 'Outpatient Center', 'Main Campus', 'Cardiology']])
        ->and($items[1]->fields['phoneNumbers'])->toBe(['primaryPhone' => '+15015551111']);
});

it('gives a provider no listings without a location', function (): void {
    expect(mapper()->providerItems(personEntry(), [], mapperSilos()))->toBe([]);
});

it('falls back to the longer bio, then to a sentence naming the provider and the location', function (array $values, string $expected): void {
    expect(mapper()->providerItems(personEntry($values), [locationEntry()], mapperSilos())[0]->fields['profile'])->toBe(['description' => $expected]);
})->with([
    'the longer bio' => [['bio_clinical' => 'Long bio.'], 'Long bio.'],
    'a title starting with a consonant' => [['clinical_title' => 'Cardiologist'], 'Dr. Jane Doe is a cardiologist at West Clinic in Little Rock employed by UAMS Health.'],
    'a title starting with a vowel' => [['clinical_title' => 'Oncologist'], 'Dr. Jane Doe is an oncologist at West Clinic in Little Rock employed by UAMS Health.'],
    'no title' => [['clinical_title' => ''], 'Dr. Jane Doe is a health care provider at West Clinic in Little Rock employed by UAMS Health.'],
    'no short name' => [['display_name_short_with_prefix' => '', 'clinical_title' => ''], 'Jane Q. Doe, MD is a health care provider at West Clinic in Little Rock employed by UAMS Health.'],
]);

it('leaves the location out of the fallback sentence when it has no title', function (): void {
    $items = mapper()->providerItems(personEntry(['clinical_title' => '']), [locationEntry(title: '')], mapperSilos());

    expect($items[0]->fields['profile'])->toBe(['description' => 'Dr. Jane Doe is a health care provider employed by UAMS Health.'])
        ->and($items[0]->fields['title'])->toBe('UAMS Health - Jane Q. Doe, MD');
});

it('sends no provider title without a name', function (): void {
    expect(mapper()->providerItems(personEntry(['full_name_with_credentials' => '']), [locationEntry()], mapperSilos())[0]->fields)->not->toHaveKey('title');
});

it('excludes a provider who excludes themself, or at a location that excludes its providers when that field is configured', function (): void {
    $excludingLocation = locationEntry('excluding', 'Excluding', ['exclude_providers_flag' => true]);
    $plain = mapper()->providerItems(personEntry(), [locationEntry(), $excludingLocation], mapperSilos());
    $configured = mapper(location: ['exclude_providers' => 'exclude_providers_flag'])->providerItems(personEntry(), [locationEntry(), $excludingLocation], mapperSilos());
    $self = mapper()->providerItems(personEntry(['google_business_profile_clinical_exclude_query' => true]), [locationEntry()], mapperSilos());

    expect(array_map(fn (SyncItem $item): bool => $item->excluded, $plain))->toBe([false, false])
        ->and(array_map(fn (SyncItem $item): bool => $item->excluded, $configured))->toBe([false, true])
        ->and($self[0]->excluded)->toBeTrue();
});

it('excludes a provider at a location whose providers toggle is on, through the shipped mapping', function (): void {
    // The same provider at a location with the toggle on, off, and never saved
    $on = locationEntry('on-clinic', 'On Clinic', ['google_business_profile_exclude_providers_query' => true]);
    $off = locationEntry('off-clinic', 'Off Clinic', ['google_business_profile_exclude_providers_query' => false]);
    $items = mapper()->providerItems(personEntry(), [$on, $off, locationEntry()], mapperSilos());

    expect(array_map(fn (SyncItem $item): string => $item->storeCode, $items))->toBe(['jane-doe_on-clinic', 'jane-doe_off-clinic', 'jane-doe_west-clinic'])
        ->and(array_map(fn (SyncItem $item): bool => $item->excluded, $items))->toBe([true, false, false]);
});

it('excludes no location itself for its providers toggle', function (): void {
    $location = locationEntry(values: ['google_business_profile_exclude_providers_query' => true]);

    expect(mapper()->locationItem($location, mapperSilos())->excluded)->toBeFalse();
});

it('does not exclude a provider at a location that excludes only itself', function (): void {
    $items = mapper()->providerItems(personEntry(), [locationEntry(values: ['google_business_profile_exclude_query' => true])], mapperSilos());

    expect($items[0]->excluded)->toBeFalse();
});

it('puts a provider listing in the silo of the location it is for', function (): void {
    $partnerLocation = locationEntry('partner-clinic', 'Partner Clinic', ['brand_organization_query' => true, 'brand_organization' => 'partner-health']);
    $items = mapper()->providerItems(personEntry(), [locationEntry(), $partnerLocation], mapperSilos());

    expect(array_map(fn (SyncItem $item): ?string => $item->silo, $items))->toBe(['main', 'partner']);
});

it('sends the provider default category when the provider chooses none', function (): void {
    $items = mapper()->providerItems(personEntry(['google_business_profile_clinical_category' => null]), [locationEntry()], mapperSilos());

    expect($items[0]->fields['categories'])->toBe(['primaryCategory' => ['name' => 'categories/gcid:doctor']]);
});

it('sends the bare URL when no tracking is configured', function (): void {
    $mapper = new FieldMapper(['location' => []]);

    expect($mapper->locationItem(locationEntry(), mapperSilos())->fields['websiteUri'])->toBe('https://health.example.test/locations/west-clinic/');
});

it('reads entry values as text, toggles and the entry to inherit from', function (): void {
    $parent = new EntryData('p', 'P');
    $entry = new EntryData('c', 'C', '', ['a' => ' x &amp; y ', 'n' => 5, 'on' => 'true', 'one' => 1, 'off' => 'no', 'list' => ['x']], $parent);

    expect($entry->text('a'))->toBe('x & y')
        ->and($entry->text('n'))->toBe('5')
        ->and($entry->text('list'))->toBe('')
        ->and($entry->text(null))->toBe('')
        ->and($entry->get(''))->toBeNull()
        ->and($entry->isOn('on'))->toBeTrue()
        ->and($entry->isOn('one'))->toBeTrue()
        ->and($entry->isOn('off'))->toBeFalse()
        ->and($entry->isOn('missing'))->toBeFalse()
        ->and($entry->parentOrSelf())->toBe($parent)
        ->and($parent->parentOrSelf())->toBe($parent);
});

it('trims a location title, keeps the address lines in order past a blank one, and reads coordinates given as text', function (): void {
    $fields = mapper()->locationItem(locationEntry(title: '  West Clinic  ', values: ['resolved_facility_name' => '', 'resolved_map' => ['lat' => '34.75', 'lng' => -92.32]]), mapperSilos())->fields;

    expect($fields['title'])->toBe('UAMS Health - West Clinic')
        ->and($fields['storefrontAddress'])->toMatchArray(['addressLines' => ['4301 W Markham St', 'Floor 2', 'Suite 200']])
        ->and($fields['latlng'])->toBe(['latitude' => 34.75, 'longitude' => -92.32]);
});

it('adds the tracking query after a single slash, and sends no website without a URL', function (): void {
    $withSlash = new EntryData('west-clinic', 'West Clinic', 'https://health.example.test/locations/west-clinic/');

    expect(mapper()->locationItem($withSlash, mapperSilos())->fields['websiteUri'])->toStartWith('https://health.example.test/locations/west-clinic/?utm_source=google&')
        ->and(mapper()->locationItem(new EntryData('west-clinic', 'West Clinic'), mapperSilos())->fields)->not->toHaveKey('websiteUri');
});

it('sends the default category for an empty value and ignores a blank bare ID', function (): void {
    $empty = mapper()->locationItem(locationEntry(values: ['google_business_profile_categories' => '']), mapperSilos());
    $withBlank = mapper()->locationItem(locationEntry(values: ['google_business_profile_categories' => ['medical_clinic', '']]), mapperSilos());

    expect($empty->fields['categories'])->toBe(['primaryCategory' => ['name' => 'categories/gcid:medical_clinic']])
        ->and($withBlank->fields['categories'])->toBe(['primaryCategory' => ['name' => 'categories/gcid:medical_clinic']])
        ->and($withBlank->unresolvedCategories)->toBe([]);
});

it('reports a category without an ID by its title, else its slug, and lists the reports in order', function (): void {
    $item = mapper()->locationItem(locationEntry(values: ['google_business_profile_categories' => [['gcid' => 'medical_clinic'], ['foo' => 'nameless'], ['slug' => 'made-up'], ['title' => 'Made Up Too', 'slug' => 'ignored']]]), mapperSilos());

    expect($item->unresolvedCategories)->toBe(['made-up', 'Made Up Too']);
});

it('sends additional categories once each, in order', function (): void {
    $terms = [['gcid' => 'a'], ['gcid' => 'b'], ['gcid' => 'a'], ['gcid' => 'c'], ['gcid' => 'b'], ['gcid' => 'd']];

    expect(mapper()->locationItem(locationEntry(values: ['google_business_profile_categories' => $terms]), mapperSilos())->fields['categories'])
        ->toBe(['primaryCategory' => ['name' => 'categories/gcid:a'], 'additionalCategories' => [['name' => 'categories/gcid:b'], ['name' => 'categories/gcid:c'], ['name' => 'categories/gcid:d']]]);
});

it('reads a description from a single configured handle', function (): void {
    expect(mapper(location: ['description' => 'about'])->locationItem(locationEntry(), mapperSilos())->fields['profile'])->toBe(['description' => 'Longer about text.']);
});

it('replaces carriage returns, counts no leading space toward the width, and decodes quotes', function (): void {
    expect(FieldMapper::cleanDescription("one\rtwo"))->toBe('one two')
        ->and(FieldMapper::cleanDescription(' '.str_repeat('a', 747)))->toBe(str_repeat('a', 747))
        ->and(FieldMapper::cleanDescription('O&apos;Neil &quot;Clinic&quot; &#32;'))->toBe('O\'Neil "Clinic"');
});

it('trims the location in the fallback description', function (): void {
    $items = mapper()->providerItems(personEntry(['clinical_title' => '']), [locationEntry(title: ' West Clinic ')], mapperSilos());

    expect($items[0]->fields['profile'])->toBe(['description' => 'Dr. Jane Doe is a health care provider at West Clinic employed by UAMS Health.']);
});

it('answers an attribute only from an exact true or false, and drops one configured with no name', function (): void {
    $item = mapper(location: [
        'attributes' => ['a1' => 'one', 'a2' => 'two', 'a3' => 'three', 'a4' => '', 'a5' => 'five'],
        'attributes_when_on' => ['t1' => ''],
    ])->locationItem(locationEntry(values: ['a1' => 'TRUE', 'a2' => ' false', 'a3' => 'yes', 'a4' => 'true', 'a5' => 'true', 't1' => true]), mapperSilos());

    expect($item->attributes)->toBe(['five' => true]);
});

it('trims a brand given as text', function (): void {
    expect(mapper()->silo(locationEntry(values: ['brand_organization_query' => true, 'brand_organization' => ' partner-health ']), mapperSilos()))->toBe('partner');
});

it('reads a toggle as on only for true, 1, the text 1 or the text true', function (mixed $value, bool $on): void {
    expect(new EntryData('s', 't', '', ['flag' => $value])->isOn('flag'))->toBe($on);
})->with([
    'true' => [true, true],
    '1' => [1, true],
    "'1'" => ['1', true],
    "'true'" => ['true', true],
    'false' => [false, false],
    "'yes'" => ['yes', false],
    '0' => [0, false],
    'null' => [null, false],
]);

it('reads nothing for a handle that is not a string, and decodes quotes in text', function (): void {
    $entry = new EntryData('s', 't', '', ['' => 'blank key', 'name' => 'O&apos;Neil']);

    expect($entry->get(['name']))->toBeNull()
        ->and($entry->get(5))->toBeNull()
        ->and($entry->text('name'))->toBe("O'Neil")
        ->and($entry->url)->toBe('');
});

it('keeps only the arrays in a list of values, as a list', function (): void {
    expect(Values::arrays([1, ['a'], 'x', ['b']]))->toBe([['a'], ['b']])
        ->and(Values::arrays('not a list'))->toBe([]);
});

it('reads a rich-text description as the text of its nodes', function (): void {
    $bard = [
        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Dr. Doe treats knees.'], ['type' => 'text', 'text' => 'And hips.']]],
        ['type' => 'paragraph'],
        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Since 2001.']]],
    ];
    $items = mapper()->providerItems(personEntry(['bio_clinical' => $bard]), [locationEntry()], mapperSilos());
    $location = mapper()->locationItem(locationEntry(values: ['short_desc' => '', 'about' => $bard]), mapperSilos());

    expect($items[0]->fields['profile'])->toBe(['description' => 'Dr. Doe treats knees. And hips. Since 2001.'])
        ->and($location->fields['profile'])->toBe(['description' => 'Dr. Doe treats knees. And hips. Since 2001.'])
        ->and(FieldMapper::plainText(['type' => 'text', 'text' => 'one']))->toBe('one')
        ->and(FieldMapper::plainText(['type' => 'doc', 'content' => [['type' => 'text', 'text' => 'a'], ['type' => 'hardBreak'], ['type' => 'text', 'text' => 'b']]]))->toBe('a b')
        ->and(FieldMapper::plainText(['type' => 'text', 'text' => 5]))->toBe('')
        ->and(FieldMapper::plainText(7))->toBe('7');
});

it('decodes entities in every title it sends or writes into an address line', function (): void {
    $parent = locationEntry('main', 'Heart &amp; Vascular at Main');
    $child = locationEntry('cardio', 'Cardio &amp; Rehab', parent: $parent);
    $location = mapper()->locationItem(locationEntry(title: 'Orthopaedics &amp; Sports Medicine'), mapperSilos());
    $quoted = mapper()->locationItem(locationEntry(title: 'O&apos;Neil Clinic'), mapperSilos());
    $provider = mapper()->providerItems(personEntry(['clinical_title' => '', 'bio_clinical' => '']), [$child], mapperSilos())[0];

    expect($location->fields['title'])->toBe('UAMS Health - Orthopaedics & Sports Medicine')
        ->and($quoted->fields['title'])->toBe("UAMS Health - O'Neil Clinic")
        ->and($provider->fields['title'])->toBe('UAMS Health - Jane Q. Doe, MD - Cardio & Rehab')
        ->and($provider->fields['storefrontAddress'])->toMatchArray(['addressLines' => ['4301 W Markham St', 'Outpatient Center', 'Heart & Vascular', 'Cardio & Rehab']])
        ->and($provider->fields['profile'])->toBe(['description' => 'Dr. Jane Doe is a health care provider at Cardio & Rehab employed by UAMS Health.']);
});

/**
 * A weekday rule from 8:00 to 17:00 and a closed Saturday, as the replicator stores them.
 *
 * @return list<array<string, mixed>>
 */
function weekdayHours(): array
{
    return [
        ['type' => 'weekday', 'days' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'], 'service_mode' => 'in_person', 'status' => 'open', 'time_span' => [['type' => 'new_set', 'open' => '08:00', 'close' => '17:00']]],
        ['type' => 'custom', 'days' => ['saturday'], 'service_mode' => 'in_person', 'status' => 'closed'],
    ];
}

it('writes a location regular hours, and gives a provider there the same hours', function (): void {
    $location = locationEntry(values: ['regular_hours' => weekdayHours(), 'hours_variable_query' => false]);
    $item = mapper()->locationItem($location, mapperSilos());
    $provider = mapper()->providerItems(personEntry(), [$location], mapperSilos())[0];

    $periods = Values::arrays(Values::arr($item->fields['regularHours'] ?? null)['periods'] ?? null);
    expect(array_column($periods, 'openDay'))->toBe(['MONDAY', 'TUESDAY', 'WEDNESDAY', 'THURSDAY', 'FRIDAY'])
        ->and($periods[0])->toBe(['openDay' => 'MONDAY', 'openTime' => ['hours' => 8, 'minutes' => 0], 'closeDay' => 'MONDAY', 'closeTime' => ['hours' => 17, 'minutes' => 0]])
        ->and($provider->fields['regularHours'])->toBe($item->fields['regularHours']);
});

it('writes no hours for a location with none, or marked as having variable hours, and leaves its other fields as they were', function (array $values): void {
    $plain = mapper()->locationItem(locationEntry(), mapperSilos());
    $item = mapper()->locationItem(locationEntry(values: $values), mapperSilos());
    $provider = mapper()->providerItems(personEntry(), [locationEntry(values: $values)], mapperSilos())[0];

    expect($item->fields)->toBe($plain->fields)
        ->and($item->fields)->not->toHaveKey('regularHours')
        ->and($provider->fields)->not->toHaveKey('regularHours');
})->with([
    'no rules' => [['regular_hours' => []]],
    'only closed days' => [['regular_hours' => [weekdayHours()[1]]]],
    'variable, with rules left over' => [['regular_hours' => weekdayHours(), 'hours_variable_query' => true]],
]);

it('reads a child location hours from the child, never its parent', function (): void {
    $parent = locationEntry('main-campus', 'Main Campus', ['regular_hours' => weekdayHours()]);
    $child = locationEntry('cardiology', 'Cardiology', [], $parent);

    expect(mapper()->locationItem($child, mapperSilos())->fields)->not->toHaveKey('regularHours')
        ->and(mapper()->providerItems(personEntry(), [$child], mapperSilos())[0]->fields)->not->toHaveKey('regularHours');
});

it('reads hours through the handles the configuration names', function (): void {
    $location = locationEntry(values: ['hours' => weekdayHours(), 'varies' => true, 'regular_hours' => [], 'hours_variable_query' => false]);

    expect(mapper(['regular_hours' => 'hours', 'hours_variable' => 'other'])->locationItem($location, mapperSilos())->fields)->toHaveKey('regularHours')
        ->and(mapper(['regular_hours' => 'hours', 'hours_variable' => 'varies'])->locationItem($location, mapperSilos())->fields)->not->toHaveKey('regularHours');
});

/**
 * A closed Thanksgiving, as the host passes it with the closures merged in.
 *
 * @return list<array<string, mixed>>
 */
function thanksgiving(): array
{
    return [['type' => 'holiday', 'title' => 'Thanksgiving', 'rules' => [['type' => 'rule', 'date' => '2026-11-26', 'service_mode' => 'in_person', 'status' => 'closed']]]];
}

it('writes a location special hours beside its regular hours, and gives a provider there the same', function (): void {
    $location = locationEntry(values: ['regular_hours' => weekdayHours(), 'special_hours' => thanksgiving()]);
    $item = mapper(today: '2026-11-20')->locationItem($location, mapperSilos());
    $provider = mapper(today: '2026-11-20')->providerItems(personEntry(), [$location], mapperSilos())[0];

    expect($item->fields['specialHours'] ?? null)->toBe(['specialHourPeriods' => [['startDate' => ['year' => 2026, 'month' => 11, 'day' => 26], 'closed' => true]]])
        ->and($provider->fields['specialHours'] ?? null)->toBe($item->fields['specialHours'] ?? null)
        ->and(mapper(today: '2026-11-27')->locationItem($location, mapperSilos())->fields['specialHours'] ?? null)->toBe(['specialHourPeriods' => []]);
});

it('states an empty special hours set for a location with regular hours and none special, so a deleted closure is cleared', function (): void {
    $item = mapper()->locationItem(locationEntry(values: ['regular_hours' => weekdayHours()]), mapperSilos());

    expect($item->fields['specialHours'] ?? null)->toBe(['specialHourPeriods' => []]);
});

it('writes no special hours for a location that writes no regular hours', function (array $values): void {
    $item = mapper(today: '2026-11-20')->locationItem(locationEntry(values: $values + ['special_hours' => thanksgiving()]), mapperSilos());

    expect($item->fields)->not->toHaveKey('specialHours')
        ->and($item->fields)->not->toHaveKey('regularHours');
})->with([
    'no regular hours' => [[]],
    'variable hours' => [['regular_hours' => weekdayHours(), 'hours_variable_query' => true]],
]);

it('reads special hours through the handle the configuration names', function (): void {
    $location = locationEntry(values: ['regular_hours' => weekdayHours(), 'closures' => thanksgiving()]);

    expect(Values::arr(mapper(['special_hours' => 'closures'], today: '2026-11-20')->locationItem($location, mapperSilos())->fields['specialHours'] ?? null)['specialHourPeriods'] ?? null)->toHaveCount(1);
});

<?php

declare(strict_types=1);

use UAMSWeb\Aether\People\ListingsManagement\BusinessProfile\Action;
use UAMSWeb\Aether\People\ListingsManagement\BusinessProfile\Client;
use UAMSWeb\Aether\People\ListingsManagement\BusinessProfile\CreateQuota;
use UAMSWeb\Aether\People\ListingsManagement\BusinessProfile\Credentials;
use UAMSWeb\Aether\People\ListingsManagement\BusinessProfile\HttpResponse;
use UAMSWeb\Aether\People\ListingsManagement\BusinessProfile\InMemoryCreateQuotaStore;
use UAMSWeb\Aether\People\ListingsManagement\BusinessProfile\Kind;
use UAMSWeb\Aether\People\ListingsManagement\BusinessProfile\ListingIndex;
use UAMSWeb\Aether\People\ListingsManagement\BusinessProfile\Mode;
use UAMSWeb\Aether\People\ListingsManagement\BusinessProfile\Outcome;
use UAMSWeb\Aether\People\ListingsManagement\BusinessProfile\Photo;
use UAMSWeb\Aether\People\ListingsManagement\BusinessProfile\Silos;
use UAMSWeb\Aether\People\ListingsManagement\BusinessProfile\Sync;
use UAMSWeb\Aether\People\ListingsManagement\BusinessProfile\SyncItem;
use UAMSWeb\Aether\People\ListingsManagement\Tests\Support\FakeTransport;

/**
 * The update, create, no-op, review and skip decisions, live and as a dry run.
 * Account groups are made up; every request goes to a fake transport.
 *
 * @command  vendor/bin/pest tests/Unit/BusinessProfile/SyncTest.php
 */

/** The silos the tests run against: a default silo and a partner brand's. */
const SYNC_TEST_SILOS = [
    'main' => ['brand' => null, 'accounts' => ['provider' => 'accounts/mp', 'location' => 'accounts/ml']],
    'partner' => ['brand' => 'partner', 'accounts' => ['provider' => 'accounts/pp', 'location' => 'accounts/pl']],
];

/**
 * The fields a complete location item carries.
 *
 * @return array<string, mixed>
 */
function syncFields(string $title = 'Clinic'): array
{
    return [
        'title' => $title,
        'storefrontAddress' => ['regionCode' => 'US', 'languageCode' => 'en', 'postalCode' => '72205', 'administrativeArea' => 'AR', 'locality' => 'Little Rock', 'addressLines' => ['4301 W Markham St']],
        'phoneNumbers' => ['primaryPhone' => '+15016868000'],
        'categories' => ['primaryCategory' => ['name' => 'categories/gcid:medical_clinic']],
    ];
}

/**
 * A location item.
 *
 * @param  array<string, mixed>  $fields
 * @param  array<string, bool>  $attributes
 */
function syncItem(string $code = 'clinic', ?string $silo = 'main', array $fields = [], array $attributes = [], Kind $kind = Kind::Location, bool $excluded = false): SyncItem
{
    return new SyncItem($code, $kind, $silo, $excluded, $fields === [] ? syncFields() : $fields, $attributes, []);
}

/**
 * Run the sync over items and return the outcomes, the transport and the log.
 *
 * @param  list<SyncItem>  $items
 * @param  list<array{account: string, listing: array<array-key, mixed>}>  $listings
 * @param  array<array-key, mixed>  $modes
 * @return array{outcomes: list<Outcome>, transport: FakeTransport, log: list<Outcome>}
 */
function runSync(array $items, array $listings = [], array $modes = ['main' => 'live', 'partner' => 'live'], bool $production = true, ?Closure $router = null, ?string $today = '2026-11-20', ?CreateQuota $quota = null): array
{
    $transport = new FakeTransport($router);
    $log = [];
    $sync = new Sync(
        new Client(new Credentials('id', 'secret', 'refresh'), $transport),
        Silos::fromConfig(SYNC_TEST_SILOS, $modes, $production),
        new ListingIndex($listings),
        function (Outcome $outcome) use (&$log): void {
            $log[] = $outcome;
        },
        $today,
        $quota,
    );

    return ['outcomes' => $sync->run($items), 'transport' => $transport, 'log' => $log];
}

/**
 * A listing in an account group, matching syncFields() unless fields are given.
 *
 * @param  array<string, mixed>  $fields
 * @return array{account: string, listing: array<array-key, mixed>}
 */
function syncListing(string $account = 'accounts/ml', string $code = 'clinic', string $name = 'locations/1', array $fields = []): array
{
    return ['account' => $account, 'listing' => ['name' => $name, 'storeCode' => $code] + ($fields === [] ? array_replace(syncFields(), ['phoneNumbers' => ['primaryPhone' => '(501) 686-8000']]) : $fields)];
}

it('updates only the changed fields of the matched listing, with a mask naming them', function (): void {
    // A listing whose title is out of date
    $run = runSync([syncItem(fields: syncFields('New Clinic'))], [syncListing()]);
    $outcome = $run['outcomes'][0];

    // Expect one patch of the title alone
    $writes = $run['transport']->writes();
    expect($outcome->action)->toBe(Action::Update)
        ->and($outcome->reason)->toBe('Updated')
        ->and($outcome->fields)->toBe(['title'])
        ->and($outcome->listingName)->toBe('locations/1')
        ->and($outcome->written)->toBeTrue()
        ->and($outcome->isSettled())->toBeTrue()
        ->and($writes)->toHaveCount(1)
        ->and($writes[0]['method'])->toBe('PATCH')
        ->and($writes[0]['url'])->toBe('https://mybusinessbusinessinformation.googleapis.com/v1/locations/1?updateMask=title')
        ->and(json_decode((string) $writes[0]['body'], true))->toBe(['title' => 'New Clinic']);
});

it('is a no-op, with no write, when the listing already matches', function (): void {
    // A listing that differs only in phone formatting
    $run = runSync([syncItem()], [syncListing()]);
    $outcome = $run['outcomes'][0];

    // Expect a settled no-op and nothing sent
    expect($outcome->action)->toBe(Action::Noop)
        ->and($outcome->reason)->toBe('Listing already matches')
        ->and($outcome->listingName)->toBe('locations/1')
        ->and($outcome->isSettled())->toBeTrue()
        ->and($run['transport']->apiRequests())->toBe([]);
});

it('creates in the silo group for the kind when no listing has the store code', function (Kind $kind, string $silo, string $account): void {
    // No listing anywhere carries the code
    $run = runSync([syncItem(silo: $silo, kind: $kind)], [syncListing(code: 'something-else')], router: fn (string $method, string $url): ?HttpResponse => $method === 'POST' && str_contains($url, '/locations') ? FakeTransport::json(['name' => 'locations/99']) : null);
    $outcome = $run['outcomes'][0];

    // Expect one create in the right group, named on the outcome
    $writes = $run['transport']->writes();
    expect($outcome->action)->toBe(Action::Create)
        ->and($outcome->reason)->toBe('Created')
        ->and($outcome->listingName)->toBe('locations/99')
        ->and($outcome->written)->toBeTrue()
        ->and($writes)->toHaveCount(1)
        ->and($writes[0]['url'])->toStartWith('https://mybusinessbusinessinformation.googleapis.com/v1/'.$account.'/locations?requestId=')
        ->and(json_decode((string) $writes[0]['body'], true))->toMatchArray(['storeCode' => 'clinic', 'title' => 'Clinic']);
})->with([
    'a location in the default silo' => [Kind::Location, 'main', 'accounts/ml'],
    'a provider in the default silo' => [Kind::Provider, 'main', 'accounts/mp'],
    'a location in a partner silo' => [Kind::Location, 'partner', 'accounts/pl'],
]);

it('creates a store code once when it appears twice in one run', function (): void {
    // The same code twice
    $run = runSync([syncItem(), syncItem()], router: fn (string $method, string $url): ?HttpResponse => $method === 'POST' && str_contains($url, '/locations') ? FakeTransport::json(['name' => 'locations/5']) : null);

    // Expect a create, then a no-op against the listing just made
    expect(array_map(fn (Outcome $outcome): Action => $outcome->action, $run['outcomes']))->toBe([Action::Create, Action::Noop])
        ->and($run['transport']->writes())->toHaveCount(1);
});

it('skips a create that lacks a title, an address or a category, naming what is missing', function (string $missing): void {
    // A new code whose fields lack one required field
    $fields = syncFields();
    unset($fields[$missing]);
    $run = runSync([syncItem(fields: $fields)]);

    // Expect a skip naming the field, with nothing sent
    expect($run['outcomes'][0]->action)->toBe(Action::Skip)
        ->and($run['outcomes'][0]->reason)->toBe('Cannot create, missing '.$missing)
        ->and($run['transport']->apiRequests())->toBe([]);
})->with(['title', 'storefrontAddress', 'categories']);

it('does not need a phone, a website or a description to create', function (): void {
    // A new code with only the required fields
    $fields = syncFields();
    unset($fields['phoneNumbers']);
    $run = runSync([syncItem(fields: $fields)]);

    expect($run['outcomes'][0]->action)->toBe(Action::Create);
});

it('sends a store code found on two listings to review and writes neither', function (): void {
    // Two listings carry the code
    $run = runSync([syncItem(fields: syncFields('New'))], [syncListing(name: 'locations/1'), syncListing(name: 'locations/2')]);

    // Expect a review and nothing sent
    expect($run['outcomes'][0]->action)->toBe(Action::Review)
        ->and($run['outcomes'][0]->reason)->toBe('Store code is on 2 listings')
        ->and($run['outcomes'][0]->isSettled())->toBeFalse()
        ->and($run['transport']->apiRequests())->toBe([]);
});

it('sends a listing outside the silo group for the kind to review and never writes or recreates it', function (string $account, string $reason): void {
    // The only listing with the code sits in another group
    $run = runSync([syncItem(fields: syncFields('New'))], [syncListing(account: $account)]);

    // Expect a review naming where it is, and nothing sent
    expect($run['outcomes'][0]->action)->toBe(Action::Review)
        ->and($run['outcomes'][0]->reason)->toBe($reason)
        ->and($run['transport']->apiRequests())->toBe([]);
})->with([
    "this silo's provider group" => ['accounts/mp', "Listing is in this silo's other group (accounts/mp)"],
    "another silo's group" => ['accounts/pl', "Listing is in another silo's group (partner)"],
    'a group no silo owns' => ['accounts/parked', 'Listing is parked in accounts/parked'],
]);

it('skips an excluded item without looking it up, even when its listing exists and differs', function (): void {
    $run = runSync([syncItem(fields: syncFields('New'), excluded: true)], [syncListing()]);

    expect($run['outcomes'][0]->action)->toBe(Action::Skip)
        ->and($run['outcomes'][0]->reason)->toBe('Excluded from Google Business Profile by the content')
        ->and($run['outcomes'][0]->isSettled())->toBeTrue()
        ->and($run['transport']->apiRequests())->toBe([])
        ->and($run['log'])->toHaveCount(1);
});

it('skips an item whose brand has no silo', function (?string $silo): void {
    $run = runSync([syncItem(silo: $silo)]);

    expect($run['outcomes'][0]->action)->toBe(Action::Skip)
        ->and($run['outcomes'][0]->reason)->toBe("No silo for this location's brand")
        ->and($run['transport']->apiRequests())->toBe([])
        ->and($run['log'])->toHaveCount(1);
})->with(['no silo' => [null], 'an unknown silo' => ['unknown']]);

it('skips an off silo without a call and keeps it out of the log', function (array $modes): void {
    $run = runSync([syncItem()], modes: $modes);

    expect($run['outcomes'][0]->action)->toBe(Action::Skip)
        ->and($run['outcomes'][0]->mode)->toBe(Mode::Off)
        ->and($run['outcomes'][0]->reason)->toBe('Silo main is off')
        ->and($run['transport']->apiRequests())->toBe([])
        ->and($run['log'])->toBe([]);
})->with([
    'set off' => [['main' => 'off']],
    'with no mode' => [[]],
    'with an unknown mode' => [['main' => 'on']],
]);

it('skips a silo with no account group for the kind', function (): void {
    // A silo with a location group and no provider group
    $transport = new FakeTransport;
    $sync = new Sync(new Client(new Credentials('id', 'secret', 'refresh'), $transport), new Silos(['main' => ['brand' => null, 'accounts' => ['provider' => '', 'location' => 'accounts/ml']]], ['main' => 'live'], true), new ListingIndex);
    $outcome = $sync->run([syncItem(kind: Kind::Provider)])[0];

    expect($outcome->action)->toBe(Action::Skip)
        ->and($outcome->reason)->toBe('Silo main has no provider account group')
        ->and($transport->apiRequests())->toBe([]);
});

it('reports a dry-run update and create, and writes nothing', function (array $modes, bool $production): void {
    // A stale listing and a new code
    $run = runSync([syncItem(fields: syncFields('New')), syncItem(code: 'new-clinic')], [syncListing()], $modes, $production);
    [$update, $create] = $run['outcomes'];

    // Expect both decided, neither sent nor settled
    expect($update->action)->toBe(Action::Update)
        ->and($update->mode)->toBe(Mode::DryRun)
        ->and($update->reason)->toBe('Dry run, no write made')
        ->and($update->fields)->toBe(['title'])
        ->and($update->written)->toBeFalse()
        ->and($update->isSettled())->toBeFalse()
        ->and($create->action)->toBe(Action::Create)
        ->and($create->reason)->toBe('Dry run, no listing created')
        ->and($create->fields)->toBe(['title', 'storefrontAddress', 'phoneNumbers', 'categories'])
        ->and($create->isSettled())->toBeFalse()
        ->and($run['transport']->writes())->toBe([])
        ->and($run['log'])->toHaveCount(2);
})->with([
    'a dry-run silo on production' => [['main' => 'dry-run'], true],
    'a live silo off production' => [['main' => 'live'], false],
]);

it('writes only the attributes that differ, reading them only for an item that states some', function (): void {
    // A listing whose fields match and whose restroom attribute differs
    $router = fn (string $method, string $url): ?HttpResponse => $method === 'GET' && str_ends_with($url, '/attributes')
        ? FakeTransport::json(['attributes' => [['name' => 'attributes/has_restroom', 'valueType' => 'BOOL', 'values' => [false]], ['name' => 'attributes/requires_appointments', 'valueType' => 'BOOL', 'values' => [true]]]])
        : null;
    $run = runSync([syncItem(attributes: ['has_restroom' => true, 'requires_appointments' => true]), syncItem(code: 'other', kind: Kind::Provider)], [syncListing(), syncListing(account: 'accounts/mp', code: 'other', name: 'locations/2')], router: $router);
    [$location, $provider] = $run['outcomes'];

    // Expect one attribute write, and no attribute read for the item that states none
    $writes = $run['transport']->writes();
    expect($location->action)->toBe(Action::Update)
        ->and($location->fields)->toBe([])
        ->and($location->attributes)->toBe(['has_restroom'])
        ->and($writes)->toHaveCount(1)
        ->and($writes[0]['url'])->toEndWith('/locations/1/attributes?attributeMask='.rawurlencode('attributes/has_restroom'))
        ->and($provider->action)->toBe(Action::Noop)
        ->and(array_filter($run['transport']->apiRequests(), fn (array $request): bool => str_contains($request['url'], 'locations/2')))->toBe([]);
});

it('writes fields and attributes together when both differ', function (): void {
    $run = runSync([syncItem(fields: syncFields('New'), attributes: ['has_restroom' => true])], [syncListing()]);

    expect($run['outcomes'][0]->fields)->toBe(['title'])
        ->and($run['outcomes'][0]->attributes)->toBe(['has_restroom'])
        ->and(array_map(fn (array $request): string => $request['method'], $run['transport']->writes()))->toBe(['PATCH', 'PATCH']);
});

it('sets a new location listing attributes after creating it', function (): void {
    $run = runSync([syncItem(attributes: ['has_restroom' => true])], router: fn (string $method, string $url): ?HttpResponse => $method === 'POST' && str_contains($url, '/locations') ? FakeTransport::json(['name' => 'locations/7']) : null);

    $writes = $run['transport']->writes();
    expect($run['outcomes'][0]->attributes)->toBe(['has_restroom'])
        ->and($writes)->toHaveCount(2)
        ->and($writes[1]['url'])->toContain('/locations/7/attributes?attributeMask=');
});

it('reports a failed call as an error, retryable only for a rate limit, and carries on', function (int $status, bool $retryable): void {
    // The first item's patch fails; the second matches
    $router = fn (string $method, string $url): ?HttpResponse => $method === 'PATCH' ? FakeTransport::json(['error' => ['message' => 'Refused']], $status) : null;
    $run = runSync([syncItem(fields: syncFields('New')), syncItem(code: 'second')], [syncListing(), syncListing(code: 'second', name: 'locations/2')], router: $router);
    [$failed, $next] = $run['outcomes'];

    expect($failed->action)->toBe(Action::Error)
        ->and($failed->reason)->toBe('Google API PATCH failed ('.$status.'): Refused')
        ->and($failed->retryable)->toBe($retryable)
        ->and($failed->written)->toBeFalse()
        ->and($failed->isSettled())->toBeFalse()
        ->and($next->action)->toBe(Action::Noop);
})->with([
    'a rate limit' => [429, true],
    'a bad request' => [400, false],
]);

it('reports a failed create, and a failed attribute read, as errors', function (): void {
    // A create refused, then an attribute read refused
    $router = fn (string $method, string $url): ?HttpResponse => (($method === 'POST' && str_contains($url, '/locations')) || ($method === 'GET' && str_ends_with($url, '/attributes'))) ? FakeTransport::json(['error' => ['message' => 'No']], 500) : null;
    $run = runSync([syncItem(), syncItem(code: 'listed', attributes: ['has_restroom' => true])], [syncListing(code: 'listed')], router: $router);
    [$create, $update] = $run['outcomes'];

    expect($create->action)->toBe(Action::Error)
        ->and($create->reason)->toBe('Google API POST failed (500): No')
        ->and($create->written)->toBeFalse()
        ->and($update->action)->toBe(Action::Error)
        ->and($update->listingName)->toBe('locations/1')
        ->and($update->written)->toBeFalse()
        ->and($run['transport']->writes())->toHaveCount(1);
});

it('names a created listing even when setting its attributes fails', function (): void {
    $router = fn (string $method, string $url): ?HttpResponse => match (true) {
        $method === 'POST' && str_contains($url, '/locations') => FakeTransport::json(['name' => 'locations/8']),
        $method === 'PATCH' => FakeTransport::json(['error' => ['message' => 'Bad attribute']], 400),
        default => null,
    };
    $outcome = runSync([syncItem(attributes: ['has_restroom' => true])], router: $router)['outcomes'][0];

    expect($outcome->action)->toBe(Action::Error)
        ->and($outcome->listingName)->toBe('locations/8')
        ->and($outcome->written)->toBeTrue();
});

it('never sends a delete, whatever it decides', function (): void {
    // Every decision in one run
    $run = runSync(
        [syncItem(fields: syncFields('New')), syncItem(code: 'same'), syncItem(code: 'new'), syncItem(code: 'twice'), syncItem(excluded: true)],
        [syncListing(), syncListing(code: 'same', name: 'locations/2'), syncListing(code: 'twice', name: 'locations/3'), syncListing(code: 'twice', name: 'locations/4')],
    );

    expect(array_map(fn (Outcome $outcome): string => $outcome->action->value, $run['outcomes']))->toBe(['update', 'noop', 'create', 'review', 'skip'])
        ->and(array_filter($run['transport']->requests, fn (array $request): bool => $request['method'] === 'DELETE'))->toBe([]);
});

it('writes an outcome as plain data for the log', function (): void {
    $outcome = runSync([new SyncItem('clinic', Kind::Location, 'main', false, syncFields('New'), [], ['Made Up Clinic'])], [syncListing()], ['main' => 'dry-run'])['outcomes'][0];

    expect($outcome->toArray())->toBe([
        'store_code' => 'clinic',
        'kind' => 'location',
        'silo' => 'main',
        'mode' => 'dry-run',
        'action' => 'update',
        'reason' => 'Dry run, no write made',
        'fields' => ['title'],
        'attributes' => [],
        'unresolved_categories' => ['Made Up Clinic'],
        'listing_name' => 'locations/1',
        'retryable' => false,
        'photos' => [],
        'photos_sent' => [],
        'resume_at' => null,
    ]);
});

it('runs without a logger', function (): void {
    $sync = new Sync(new Client(new Credentials('id', 'secret', 'refresh'), new FakeTransport), new Silos(SYNC_TEST_SILOS, ['main' => 'dry-run'], true), new ListingIndex);

    expect($sync->run([syncItem()])[0]->action)->toBe(Action::Create);
});

it('finds a listing created earlier in the run by its name', function (): void {
    $run = runSync([syncItem(), syncItem()], router: fn (string $method, string $url): ?HttpResponse => $method === 'POST' && str_contains($url, '/locations') ? FakeTransport::json(['name' => 'locations/5']) : null);

    expect($run['outcomes'][1]->listingName)->toBe('locations/5');
});

it('names no listing on a failed create', function (): void {
    $run = runSync([syncItem()], router: fn (string $method, string $url): ?HttpResponse => $method === 'POST' && str_contains($url, '/locations') ? FakeTransport::json(['error' => ['message' => 'No']], 400) : null);

    expect($run['outcomes'][0]->listingName)->toBe('');
});

it('sets no attributes when Google does not name the new listing', function (): void {
    $run = runSync([syncItem(attributes: ['has_restroom' => true])]);

    expect($run['outcomes'][0]->reason)->toBe('Created')
        ->and($run['transport']->writes())->toHaveCount(1);
});

it('starts an outcome with no listing name, and an item as not excluded', function (): void {
    $item = new SyncItem('code', Kind::Location, 'main');

    expect((new Outcome($item, Mode::Off, Action::Skip, 'reason'))->listingName)->toBe('')
        ->and($item->excluded)->toBeFalse();
});

it('reads every listing of the given account groups into the index', function (): void {
    $transport = new FakeTransport(fn (string $method, string $url): ?HttpResponse => match (true) {
        str_contains($url, 'accounts/a/locations') => FakeTransport::json(['locations' => [['name' => 'locations/1', 'storeCode' => 'one'], ['name' => 'locations/2']]]),
        str_contains($url, 'accounts/b/locations') => FakeTransport::json(['locations' => [['name' => 'locations/3', 'storeCode' => 'one']]]),
        default => null,
    });
    $index = ListingIndex::fromClient(new Client(new Credentials('id', 'secret', 'refresh'), $transport), ['accounts/a', 'accounts/b']);

    expect($index->find('one'))->toBe([
        ['account' => 'accounts/a', 'listing' => ['name' => 'locations/1', 'storeCode' => 'one']],
        ['account' => 'accounts/b', 'listing' => ['name' => 'locations/3', 'storeCode' => 'one']],
    ])
        ->and($index->find(''))->toBe([]);
});

it('sends a second item for a store code Google created without a name to review, and calls nothing', function (): void {
    $run = runSync([syncItem(), syncItem(fields: syncFields('New'))]);
    [$create, $second] = $run['outcomes'];

    expect($create->action)->toBe(Action::Create)
        ->and($second->action)->toBe(Action::Review)
        ->and($second->reason)->toBe('Store code is on a listing Google created without a name')
        ->and($run['transport']->apiRequests())->toHaveCount(1);
});

it('logs an excluded item even when its silo is off', function (): void {
    $run = runSync([syncItem(excluded: true)], modes: ['main' => 'off']);

    expect($run['outcomes'][0]->reason)->toBe('Excluded from Google Business Profile by the content')
        ->and($run['log'])->toHaveCount(1);
});

/**
 * A regular hours field: Monday from the given opening hour to 17:00.
 *
 * @return array{periods: list<array<string, mixed>>}
 */
function syncHours(int $opens = 8): array
{
    return ['periods' => [['openDay' => 'MONDAY', 'openTime' => ['hours' => $opens, 'minutes' => 0], 'closeDay' => 'MONDAY', 'closeTime' => ['hours' => 17, 'minutes' => 0]]]];
}

it('reports each listing whose live hours differ from its entry in a dry run, and writes nothing', function (): void {
    // Two listings: one with other hours, one with the same hours in Google's form
    $listings = [
        syncListing(fields: syncFields() + ['regularHours' => syncHours(9)]),
        syncListing(code: 'other', name: 'locations/2', fields: syncFields() + ['regularHours' => ['periods' => [['openDay' => 'MONDAY', 'openTime' => ['hours' => 8], 'closeDay' => 'MONDAY', 'closeTime' => ['hours' => 17]]]]]),
    ];
    $run = runSync([syncItem(fields: syncFields() + ['regularHours' => syncHours()]), syncItem(code: 'other', fields: syncFields() + ['regularHours' => syncHours()])], $listings, ['main' => 'dry-run']);
    [$differs, $matches] = $run['outcomes'];

    expect($differs->action)->toBe(Action::Update)
        ->and($differs->reason)->toBe('Dry run, no write made')
        ->and($differs->fields)->toBe(['regularHours'])
        ->and($differs->toArray()['fields'])->toBe(['regularHours'])
        ->and($matches->action)->toBe(Action::Noop)
        ->and($run['transport']->writes())->toBe([]);
});

it('writes the hours of a live listing with a mask naming them', function (): void {
    $run = runSync([syncItem(fields: syncFields() + ['regularHours' => syncHours()])], [syncListing(fields: syncFields() + ['regularHours' => syncHours(9)])]);
    $writes = $run['transport']->writes();

    expect($run['outcomes'][0]->fields)->toBe(['regularHours'])
        ->and($writes)->toHaveCount(1)
        ->and($writes[0]['url'])->toBe('https://mybusinessbusinessinformation.googleapis.com/v1/locations/1?updateMask=regularHours')
        ->and(json_decode((string) $writes[0]['body'], true))->toBe(['regularHours' => syncHours()]);
});

it('leaves the hours already on a listing when the entry states none', function (): void {
    // The entry states no hours (none set, or variable); the listing has hours someone keeps by hand
    $kept = syncFields() + ['regularHours' => syncHours(7)];
    $unchanged = runSync([syncItem()], [syncListing(fields: $kept)]);
    $retitled = runSync([syncItem(fields: syncFields('New Clinic'))], [syncListing(fields: $kept)]);
    $writes = $retitled['transport']->writes();

    // Expect no write at all, and a title change that neither names nor sends the hours
    expect($unchanged['outcomes'][0]->action)->toBe(Action::Noop)
        ->and($unchanged['transport']->writes())->toBe([])
        ->and($writes)->toHaveCount(1)
        ->and($writes[0]['url'])->toBe('https://mybusinessbusinessinformation.googleapis.com/v1/locations/1?updateMask=title')
        ->and(json_decode((string) $writes[0]['body'], true))->toBe(['title' => 'New Clinic']);
});

it('creates a listing with its hours', function (): void {
    $run = runSync([syncItem(fields: syncFields() + ['regularHours' => syncHours()])]);
    $writes = $run['transport']->writes();

    expect($run['outcomes'][0]->action)->toBe(Action::Create)
        ->and(json_decode((string) $writes[0]['body'], true))->toMatchArray(['regularHours' => syncHours()]);
});

/**
 * A special hours field: the given dates closed.
 *
 * @return array{specialHourPeriods: list<array<string, mixed>>}
 */
function syncClosures(int ...$days): array
{
    return ['specialHourPeriods' => array_values(array_map(static fn (int $day): array => ['startDate' => ['year' => 2026, 'month' => 11, 'day' => $day], 'closed' => true], $days))];
}

it('reports each listing whose live special hours differ in a dry run, including hand-kept ones it would replace, and writes nothing', function (): void {
    $fields = syncFields() + ['regularHours' => syncHours(), 'specialHours' => syncClosures(26)];
    $listings = [
        // Someone keeps a closure by hand that the entry does not have
        syncListing(fields: syncFields() + ['regularHours' => syncHours(), 'specialHours' => syncClosures(26, 27)]),
        // The same closure, and one already past
        syncListing(code: 'other', name: 'locations/2', fields: syncFields() + ['regularHours' => syncHours(), 'specialHours' => syncClosures(19, 26)]),
    ];
    $run = runSync([syncItem(fields: $fields), syncItem(code: 'other', fields: $fields)], $listings, ['main' => 'dry-run']);
    [$differs, $matches] = $run['outcomes'];

    expect($differs->action)->toBe(Action::Update)
        ->and($differs->reason)->toBe("Dry run, no write made; replaces the listing's special hours on 2026-11-27")
        ->and($differs->fields)->toBe(['specialHours'])
        ->and($matches->action)->toBe(Action::Noop)
        ->and($run['transport']->writes())->toBe([]);
});

it('clears a deleted closure from a live listing with a mask naming special hours', function (): void {
    $run = runSync([syncItem(fields: syncFields() + ['regularHours' => syncHours(), 'specialHours' => syncClosures()])], [syncListing(fields: syncFields() + ['regularHours' => syncHours(), 'specialHours' => syncClosures(26)])]);
    $writes = $run['transport']->writes();

    expect($writes)->toHaveCount(1)
        ->and($run['outcomes'][0]->reason)->toBe("Updated; replaces the listing's special hours on 2026-11-26")
        ->and($writes[0]['url'])->toBe('https://mybusinessbusinessinformation.googleapis.com/v1/locations/1?updateMask=specialHours')
        ->and($writes[0]['body'])->toBe('{"specialHours":{"specialHourPeriods":[]}}');
});

it('compares special hours against the real today when none is given', function (): void {
    // A closure dated before every real today: past, so it matches an empty set
    $fields = syncFields() + ['regularHours' => syncHours(), 'specialHours' => syncClosures()];
    $listing = syncListing(fields: syncFields() + ['regularHours' => syncHours(), 'specialHours' => ['specialHourPeriods' => [['startDate' => ['year' => 2020, 'month' => 1, 'day' => 1], 'closed' => true]]]]);
    $future = syncListing(fields: syncFields() + ['regularHours' => syncHours(), 'specialHours' => ['specialHourPeriods' => [['startDate' => ['year' => 2999, 'month' => 1, 'day' => 1], 'closed' => true]]]]);

    expect(runSync([syncItem(fields: $fields)], [$listing], today: null)['outcomes'][0]->action)->toBe(Action::Noop)
        ->and(runSync([syncItem(fields: $fields)], [$future], today: null)['outcomes'][0]->action)->toBe(Action::Update);
});

it('compares special hours from the given today, so a date that is past on the real calendar still counts', function (): void {
    // The 20th is today for the given date and past for every real date after it
    $fields = syncFields() + ['regularHours' => syncHours(), 'specialHours' => syncClosures(26)];
    $listing = syncListing(fields: syncFields() + ['regularHours' => syncHours(), 'specialHours' => syncClosures(20, 26)]);

    $outcome = runSync([syncItem(fields: $fields)], [$listing], ['main' => 'dry-run'], today: '2026-11-20')['outcomes'][0];

    expect($outcome->action)->toBe(Action::Update)
        ->and($outcome->reason)->toBe("Dry run, no write made; replaces the listing's special hours on 2026-11-20");
});

it('names no replaced dates when only the regular hours change, or when special hours only gain a date', function (): void {
    $fields = syncFields() + ['regularHours' => syncHours(), 'specialHours' => syncClosures(26, 27)];
    $regular = runSync([syncItem(fields: $fields)], [syncListing(fields: syncFields() + ['regularHours' => syncHours(9), 'specialHours' => syncClosures(26, 27)])]);
    $gained = runSync([syncItem(fields: $fields)], [syncListing(fields: syncFields() + ['regularHours' => syncHours(), 'specialHours' => syncClosures(26)])]);

    expect($regular['outcomes'][0]->reason)->toBe('Updated')
        ->and($gained['outcomes'][0]->reason)->toBe('Updated');
});

it('creates a listing with its special hours beside its regular hours', function (): void {
    $run = runSync([syncItem(fields: syncFields() + ['regularHours' => syncHours(), 'specialHours' => syncClosures(26)])]);

    expect(json_decode((string) $run['transport']->writes()[0]['body'], true))->toMatchArray(['regularHours' => syncHours(), 'specialHours' => syncClosures(26)]);
});

/**
 * A location item asking for photos, matching syncListing() otherwise.
 *
 * @param  list<Photo>  $photos
 * @param  array<string, list<string>>  $sent
 */
function photoItem(array $photos, array $sent = []): SyncItem
{
    return new SyncItem('clinic', Kind::Location, 'main', false, syncFields(), [], [], $photos, $sent);
}

/**
 * The logo, the cover and two other photos.
 *
 * @return list<Photo>
 */
function syncPhotos(): array
{
    return [
        new Photo(Photo::LOGO, 'https://x.test/logo.png'),
        new Photo(Photo::COVER, 'https://x.test/cover.jpg'),
        new Photo(Photo::ADDITIONAL, 'https://x.test/one.jpg'),
        new Photo(Photo::ADDITIONAL, 'https://x.test/two.jpg'),
    ];
}

/**
 * The photos a transport was sent, as category => address pairs, in order.
 *
 * @return list<array{0: mixed, 1: mixed}>
 */
function photoUploads(FakeTransport $transport): array
{
    $uploads = [];

    foreach ($transport->writes() as $request) {
        if (str_ends_with($request['url'], '/media')) {
            $body = (array) json_decode((string) $request['body'], true);
            $uploads[] = [((array) ($body['locationAssociation'] ?? []))['category'] ?? null, $body['sourceUrl'] ?? null];
        }
    }

    return $uploads;
}

it('sends a matching listing only the photos whose addresses it has not been sent, and reports them', function (): void {
    $run = runSync([photoItem(syncPhotos(), ['locations/1' => ['https://x.test/logo.png', 'https://x.test/one.jpg']])], [syncListing()]);
    $outcome = $run['outcomes'][0];

    expect(photoUploads($run['transport']))->toBe([['COVER', 'https://x.test/cover.jpg'], ['ADDITIONAL', 'https://x.test/two.jpg']])
        ->and($run['transport']->writes()[0]['url'])->toBe('https://mybusiness.googleapis.com/v4/accounts/ml/locations/1/media')
        ->and($outcome->action)->toBe(Action::Update)
        ->and($outcome->reason)->toBe('Photos sent')
        ->and($outcome->fields)->toBe([])
        ->and($outcome->written)->toBeTrue()
        ->and($outcome->photos)->toBe(['https://x.test/cover.jpg', 'https://x.test/two.jpg'])
        ->and($outcome->photosSent)->toBe(['https://x.test/cover.jpg', 'https://x.test/two.jpg']);
});

it('is a no-op, with no call, when every photo was already sent to the listing', function (): void {
    $run = runSync([photoItem(syncPhotos(), ['locations/1' => Photo::urls(syncPhotos())])], [syncListing()]);

    expect($run['outcomes'][0]->action)->toBe(Action::Noop)
        ->and($run['transport']->writes())->toBe([]);
});

it('sends every photo to a listing other than the one the record was made for', function (): void {
    $run = runSync([photoItem(syncPhotos(), ['locations/9' => Photo::urls(syncPhotos())])], [syncListing()]);

    expect(photoUploads($run['transport']))->toHaveCount(4);
});

it('sends an address that appears under two categories once, under the first', function (): void {
    $photos = [new Photo(Photo::COVER, 'https://x.test/same.jpg'), new Photo(Photo::ADDITIONAL, 'https://x.test/same.jpg')];

    expect(photoUploads(runSync([photoItem($photos)], [syncListing()])['transport']))->toBe([['COVER', 'https://x.test/same.jpg']]);
});

it('writes changed fields and then the new photos, reporting the update', function (): void {
    $item = new SyncItem('clinic', Kind::Location, 'main', false, syncFields('New'), [], [], [new Photo(Photo::LOGO, 'https://x.test/logo.png')]);
    $run = runSync([$item], [syncListing()]);

    expect(array_column($run['transport']->writes(), 'method'))->toBe(['PATCH', 'POST'])
        ->and($run['outcomes'][0]->reason)->toBe('Updated')
        ->and($run['outcomes'][0]->photosSent)->toBe(['https://x.test/logo.png']);
});

it('sends a new listing every photo, whatever was recorded, after creating it', function (): void {
    $router = fn (string $method, string $url): ?HttpResponse => $method === 'POST' && str_contains($url, '/locations?') ? FakeTransport::json(['name' => 'locations/8']) : null;
    $run = runSync([photoItem(syncPhotos(), ['locations/8' => ['https://x.test/logo.png']])], router: $router);
    $outcome = $run['outcomes'][0];

    expect(photoUploads($run['transport']))->toHaveCount(4)
        ->and($run['transport']->writes()[1]['url'])->toBe('https://mybusiness.googleapis.com/v4/accounts/ml/locations/8/media')
        ->and($outcome->action)->toBe(Action::Create)
        ->and($outcome->photosSent)->toBe(Photo::urls(syncPhotos()));
});

it('sends no photo to a new listing Google did not name', function (): void {
    $run = runSync([photoItem(syncPhotos())]);

    expect(photoUploads($run['transport']))->toBe([])
        ->and($run['outcomes'][0]->action)->toBe(Action::Create)
        ->and($run['outcomes'][0]->photosSent)->toBe([]);
});

it('lists the photos a dry run would send, and sends none', function (bool $listed): void {
    $run = runSync([photoItem(syncPhotos(), ['locations/1' => ['https://x.test/logo.png']])], $listed ? [syncListing()] : [], ['main' => 'dry-run']);
    $outcome = $run['outcomes'][0];

    expect($run['transport']->writes())->toBe([])
        ->and($outcome->photos)->toBe($listed === false ? Photo::urls(syncPhotos()) : ['https://x.test/cover.jpg', 'https://x.test/one.jpg', 'https://x.test/two.jpg'])
        ->and($outcome->photosSent)->toBe([])
        ->and($outcome->toArray()['photos'])->toBe($outcome->photos);
})->with([
    'an update' => [true],
    'a create' => [false],
]);

it('reports a refused photo as an error naming its address, and still sends the ones after it', function (): void {
    $router = fn (string $method, string $url, ?string $body): ?HttpResponse => str_contains((string) $body, 'one.jpg') ? FakeTransport::json(['error' => ['message' => 'Bad image']], 400) : null;
    $run = runSync([photoItem(syncPhotos())], [syncListing()], router: $router);
    $outcome = $run['outcomes'][0];

    expect(photoUploads($run['transport']))->toHaveCount(4)
        ->and($outcome->action)->toBe(Action::Error)
        ->and($outcome->reason)->toBe('https://x.test/one.jpg: Google API POST failed (400): Bad image')
        ->and($outcome->retryable)->toBeFalse()
        ->and($outcome->written)->toBeTrue()
        ->and($outcome->listingName)->toBe('locations/1')
        ->and($outcome->photosSent)->toBe(['https://x.test/logo.png', 'https://x.test/cover.jpg', 'https://x.test/two.jpg']);
});

it('reports only the first of several refused photos', function (): void {
    $router = fn (string $method, string $url): ?HttpResponse => str_ends_with($url, '/media') ? FakeTransport::json(['error' => ['message' => 'No']], 400) : null;
    $outcome = runSync([photoItem(syncPhotos())], [syncListing()], router: $router)['outcomes'][0];

    expect($outcome->reason)->toBe('https://x.test/logo.png: Google API POST failed (400): No')
        ->and($outcome->written)->toBeFalse()
        ->and($outcome->photosSent)->toBe([]);
});

it('ends the sending at a rate limit, retryable, keeping the photos that went', function (bool $daily): void {
    $message = $daily ? 'Quota exceeded for quota metric requests per day' : 'Slow down';
    $router = fn (string $method, string $url, ?string $body): ?HttpResponse => str_contains((string) $body, 'cover.jpg') ? FakeTransport::json(['error' => ['message' => $message]], 429) : null;
    $run = runSync([photoItem(syncPhotos())], [syncListing()], router: $router);
    $outcome = $run['outcomes'][0];

    expect(photoUploads($run['transport']))->toHaveCount(2)
        ->and($outcome->action)->toBe(Action::Error)
        ->and($outcome->reason)->toBe('https://x.test/cover.jpg: Google API POST failed (429): '.$message)
        ->and($outcome->retryable)->toBeTrue()
        ->and($outcome->photosSent)->toBe(['https://x.test/logo.png']);
})->with([
    'a per-minute limit' => [false],
    'a per-day quota' => [true],
]);

it('keeps a created listing named when a photo is refused', function (): void {
    $router = fn (string $method, string $url): ?HttpResponse => match (true) {
        str_ends_with($url, '/media') => FakeTransport::json(['error' => ['message' => 'No']], 400),
        $method === 'POST' && str_contains($url, '/locations?') => FakeTransport::json(['name' => 'locations/8']),
        default => null,
    };
    $outcome = runSync([photoItem(syncPhotos())], router: $router)['outcomes'][0];

    expect($outcome->action)->toBe(Action::Error)
        ->and($outcome->listingName)->toBe('locations/8')
        ->and($outcome->written)->toBeTrue()
        ->and($outcome->photos)->toBe(Photo::urls(syncPhotos()));
});

it('attaches the sent record to an item without changing anything else', function (): void {
    $item = new SyncItem('clinic', Kind::Provider, 'main', true, syncFields(), ['has_restroom' => true], ['Made Up'], syncPhotos(), ['locations/9' => ['https://x.test/old.jpg']]);
    $with = $item->withPhotosSent(['locations/1' => ['https://x.test/logo.png']]);

    expect($with->photosSentTo('locations/1'))->toBe(['https://x.test/logo.png'])
        ->and($with->photosSentTo('locations/9'))->toBe([])
        ->and([$with->storeCode, $with->kind, $with->silo, $with->excluded, $with->fields, $with->attributes, $with->unresolvedCategories, $with->photos])
        ->toBe([$item->storeCode, $item->kind, $item->silo, $item->excluded, $item->fields, $item->attributes, $item->unresolvedCategories, $item->photos])
        ->and($item->photosSentTo('locations/9'))->toBe(['https://x.test/old.jpg']);
});

it('reports a write when the fields went through and the attributes failed', function (): void {
    $router = fn (string $method, string $url): ?HttpResponse => $method === 'PATCH' && str_ends_with(explode('?', $url)[0], '/attributes') ? FakeTransport::json(['error' => ['message' => 'Bad attribute']], 400) : null;
    $item = new SyncItem('clinic', Kind::Location, 'main', false, syncFields('New'), ['has_restroom' => true], [], syncPhotos());
    $outcome = runSync([$item], [syncListing()], router: $router)['outcomes'][0];

    expect($outcome->action)->toBe(Action::Error)
        ->and($outcome->written)->toBeTrue()
        ->and($outcome->photos)->toBe(Photo::urls(syncPhotos()))
        ->and($outcome->photosSent)->toBe([]);
});

it('reports no write when the fields themselves were refused, listing the photos it did not send', function (): void {
    $router = fn (string $method): ?HttpResponse => $method === 'PATCH' ? FakeTransport::json(['error' => ['message' => 'No']], 400) : null;
    $item = new SyncItem('clinic', Kind::Location, 'main', false, syncFields('New'), [], [], syncPhotos());
    $run = runSync([$item], [syncListing()], router: $router);

    expect($run['outcomes'][0]->written)->toBeFalse()
        ->and($run['outcomes'][0]->photos)->toBe(Photo::urls(syncPhotos()))
        ->and(photoUploads($run['transport']))->toBe([]);
});

it('reports a write when the fields went through and every photo was refused', function (): void {
    $router = fn (string $method, string $url): ?HttpResponse => str_ends_with($url, '/media') ? FakeTransport::json(['error' => ['message' => 'No']], 400) : null;
    $item = new SyncItem('clinic', Kind::Location, 'main', false, syncFields('New'), [], [], syncPhotos());

    expect(runSync([$item], [syncListing()], router: $router)['outcomes'][0]->written)->toBeTrue();
});

it('lists the photos on a failed create and on a failed attribute write after one', function (bool $createFails): void {
    $router = fn (string $method, string $url): ?HttpResponse => match (true) {
        $method === 'POST' && str_contains($url, '/locations?') => $createFails ? FakeTransport::json(['error' => ['message' => 'No']], 500) : FakeTransport::json(['name' => 'locations/8']),
        $method === 'PATCH' => FakeTransport::json(['error' => ['message' => 'No']], 400),
        default => null,
    };
    $item = new SyncItem('clinic', Kind::Location, 'main', false, syncFields(), ['has_restroom' => true], [], syncPhotos());
    $run = runSync([$item], router: $router);

    expect($run['outcomes'][0]->action)->toBe(Action::Error)
        ->and($run['outcomes'][0]->written)->toBe(! $createFails)
        ->and($run['outcomes'][0]->photos)->toBe(Photo::urls(syncPhotos()))
        ->and(photoUploads($run['transport']))->toBe([]);
})->with([
    'the create' => [true],
    'the attributes after it' => [false],
]);

it('reports a rate limit over an earlier refusal, and sends nothing after it', function (): void {
    $router = fn (string $method, string $url, ?string $body): ?HttpResponse => match (true) {
        str_contains((string) $body, 'logo.png') => FakeTransport::json(['error' => ['message' => 'Bad image']], 400),
        str_contains((string) $body, 'cover.jpg') => FakeTransport::json(['error' => ['message' => 'Slow down']], 429),
        default => null,
    };
    $run = runSync([photoItem(syncPhotos())], [syncListing()], router: $router);
    $outcome = $run['outcomes'][0];

    expect(photoUploads($run['transport']))->toHaveCount(2)
        ->and($outcome->reason)->toBe('https://x.test/cover.jpg: Google API POST failed (429): Slow down')
        ->and($outcome->retryable)->toBeTrue()
        ->and($outcome->photosSent)->toBe([]);
});

/** 12:00 PST on 2026-11-22, when the sync tests run their create quota. */
const SYNC_TEST_NOW = 1795377600;

/** The next Pacific midnight after SYNC_TEST_NOW. */
const SYNC_TEST_RESET = 1795420800;

/**
 * A create quota on a fixed clock.
 */
function syncQuota(int $budget = 90, ?InMemoryCreateQuotaStore $store = null, int $now = SYNC_TEST_NOW): CreateQuota
{
    return new CreateQuota($store ?? new InMemoryCreateQuotaStore, $budget, fn (): int => $now);
}

/**
 * The create requests a run sent.
 *
 * @return list<array{method: string, url: string, headers: array<string, string>, body: ?string}>
 */
function createRequests(FakeTransport $transport): array
{
    return array_values(array_filter($transport->apiRequests(), static fn (array $request): bool => $request['method'] === 'POST' && str_contains($request['url'], '/locations?requestId=')));
}

it('names the test clock at noon Pacific and its reset at the next Pacific midnight', function (): void {
    expect(CreateQuota::format(SYNC_TEST_NOW))->toBe('2026-11-22T12:00:00-08:00')
        ->and(CreateQuota::nextReset(SYNC_TEST_NOW))->toBe(SYNC_TEST_RESET);
});

it('defers a create Google refuses for the day, holds every create after it, and still updates', function (): void {
    $message = "Quota exceeded for quota metric 'Create requests' and limit 'Create requests per day'";
    $router = fn (string $method, string $url): ?HttpResponse => $method === 'POST' && str_contains($url, '/locations?') ? FakeTransport::json(['error' => ['message' => $message]], 429) : null;
    $store = new InMemoryCreateQuotaStore;
    $run = runSync([syncItem(code: 'first'), syncItem(code: 'second'), syncItem(fields: syncFields('New'))], [syncListing()], router: $router, quota: syncQuota(store: $store));
    [$first, $second, $update] = $run['outcomes'];

    expect($first->action)->toBe(Action::Deferred)
        ->and($first->resumeAt)->toBe(SYNC_TEST_RESET)
        ->and($first->reason)->toBe('Google API POST failed (429): '.$message.'; creates resume at 2026-11-23T00:00:00-08:00')
        ->and($first->isSettled())->toBeFalse()
        ->and($first->retryable)->toBeFalse()
        ->and($first->written)->toBeFalse()
        ->and($first->fields)->toBe(array_keys(syncFields()))
        ->and($first->toArray()['resume_at'])->toBe('2026-11-23T00:00:00-08:00')
        ->and($second->action)->toBe(Action::Deferred)
        ->and($second->resumeAt)->toBe(SYNC_TEST_RESET)
        ->and($second->reason)->toBe("Creates paused by Google's daily create limit; creates resume at 2026-11-23T00:00:00-08:00")
        ->and($update->action)->toBe(Action::Update)
        ->and($update->written)->toBeTrue()
        ->and(createRequests($run['transport']))->toHaveCount(1)
        ->and($store->pausedUntil())->toBe(SYNC_TEST_RESET);
});

it('keeps a pause from an earlier run, and creates again once it ends', function (): void {
    $store = new InMemoryCreateQuotaStore;
    $store->pauseUntil(SYNC_TEST_RESET);

    $paused = runSync([syncItem()], quota: syncQuota(store: $store));
    $after = runSync([syncItem()], quota: syncQuota(store: $store, now: SYNC_TEST_RESET));

    expect($paused['outcomes'][0]->action)->toBe(Action::Deferred)
        ->and(createRequests($paused['transport']))->toBe([])
        ->and($after['outcomes'][0]->action)->toBe(Action::Create)
        ->and(createRequests($after['transport']))->toHaveCount(1);
});

it('reports a per-minute rate limit on a create as a retryable error, and sends the next create', function (): void {
    $calls = 0;
    $router = function (string $method, string $url) use (&$calls): ?HttpResponse {
        if ($method !== 'POST' || ! str_contains($url, '/locations?')) {
            return null;
        }

        return ++$calls === 1 ? FakeTransport::json(['error' => ['message' => 'Quota exceeded for quota metric requests per minute']], 429) : null;
    };
    $store = new InMemoryCreateQuotaStore;
    $run = runSync([syncItem(code: 'first'), syncItem(code: 'second')], router: $router, quota: syncQuota(store: $store));
    [$first, $second] = $run['outcomes'];

    expect($first->action)->toBe(Action::Error)
        ->and($first->retryable)->toBeTrue()
        ->and($first->resumeAt)->toBeNull()
        ->and($first->listingName)->toBe('')
        ->and($second->action)->toBe(Action::Create)
        ->and(createRequests($run['transport']))->toHaveCount(2)
        ->and($store->pausedUntil())->toBe(0)
        ->and($store->created('2026-11-22'))->toBe(2);
});

it('stops creating at the daily budget, deferring the rest to Pacific midnight, and goes on the next day', function (): void {
    $store = new InMemoryCreateQuotaStore;
    $run = runSync([syncItem(code: 'first'), syncItem(code: 'second'), syncItem(code: 'third')], quota: syncQuota(2, $store));
    $next = runSync([syncItem(code: 'third')], quota: syncQuota(2, $store, SYNC_TEST_RESET));

    expect(array_map(fn (Outcome $outcome): string => $outcome->action->value, $run['outcomes']))->toBe(['create', 'create', 'deferred'])
        ->and($run['outcomes'][2]->reason)->toBe('Daily create budget of 2 spent; creates resume at 2026-11-23T00:00:00-08:00')
        ->and($run['outcomes'][2]->resumeAt)->toBe(SYNC_TEST_RESET)
        ->and(createRequests($run['transport']))->toHaveCount(2)
        ->and($next['outcomes'][0]->action)->toBe(Action::Create)
        ->and($store->created('2026-11-23'))->toBe(1);
});

it('counts a refused create against the budget, since Google counts the request', function (): void {
    $store = new InMemoryCreateQuotaStore;
    runSync([syncItem()], router: fn (string $method, string $url): ?HttpResponse => $method === 'POST' && str_contains($url, '/locations?') ? FakeTransport::json(['error' => ['message' => 'No']], 400) : null, quota: syncQuota(store: $store));

    expect($store->created('2026-11-22'))->toBe(1);
});

it('neither counts nor holds a dry-run create, or an update', function (): void {
    $store = new InMemoryCreateQuotaStore;
    $dry = runSync([syncItem(code: 'first'), syncItem(code: 'second')], modes: ['main' => 'dry-run'], quota: syncQuota(0, $store));
    $update = runSync([syncItem(fields: syncFields('New'))], [syncListing()], quota: syncQuota(0, $store));

    expect(array_map(fn (Outcome $outcome): string => $outcome->action->value, $dry['outcomes']))->toBe(['create', 'create'])
        ->and($update['outcomes'][0]->action)->toBe(Action::Update)
        ->and($store->created('2026-11-22'))->toBe(0);
});

it('sends a store code over 64 characters to review, naming it and its length, with no call', function (): void {
    $code = str_repeat('a', 65);
    $store = new InMemoryCreateQuotaStore;
    $run = runSync([syncItem(code: $code), syncItem(code: $code, fields: syncFields('New'))], [syncListing(code: $code)], quota: syncQuota(store: $store));

    expect($run['outcomes'][0]->action)->toBe(Action::Review)
        ->and($run['outcomes'][0]->reason)->toBe('Store code '.$code.' is 65 characters, over the 64 Google allows')
        ->and($run['outcomes'][1]->action)->toBe(Action::Review)
        ->and($run['transport']->requests)->toBe([])
        ->and($store->created('2026-11-22'))->toBe(0);
});

it('sends a store code of exactly 64 characters, counted in characters rather than bytes', function (string $code): void {
    $run = runSync([syncItem(code: $code)]);

    expect($run['outcomes'][0]->action)->toBe(Action::Create)
        ->and(createRequests($run['transport']))->toHaveCount(1);
})->with([
    'ASCII' => [str_repeat('a', 64)],
    'two-byte letters' => [str_repeat('é', 64)],
]);

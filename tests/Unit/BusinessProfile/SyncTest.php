<?php

declare(strict_types=1);

use UAMSWeb\Aether\People\ListingsManagement\BusinessProfile\Action;
use UAMSWeb\Aether\People\ListingsManagement\BusinessProfile\Client;
use UAMSWeb\Aether\People\ListingsManagement\BusinessProfile\Credentials;
use UAMSWeb\Aether\People\ListingsManagement\BusinessProfile\HttpResponse;
use UAMSWeb\Aether\People\ListingsManagement\BusinessProfile\Kind;
use UAMSWeb\Aether\People\ListingsManagement\BusinessProfile\ListingIndex;
use UAMSWeb\Aether\People\ListingsManagement\BusinessProfile\Mode;
use UAMSWeb\Aether\People\ListingsManagement\BusinessProfile\Outcome;
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
function runSync(array $items, array $listings = [], array $modes = ['main' => 'live', 'partner' => 'live'], bool $production = true, ?Closure $router = null): array
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
        ->and($update->action)->toBe(Action::Error)
        ->and($update->listingName)->toBe('locations/1')
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

Written against v0.4.1.

By the end of this page you will have one silo set to `dry-run` and a run that reports what it would write to Google, with nothing written.

It assumes the add-on is installed (see [Installation (README)](https://github.com/UAMS-Web/aether-listings-management#installation)) in a Statamic site that has provider and location entries, and that you have a Google OAuth client and a refresh token granted for the Business Profile scope.

The add-on has no command of its own, so the last steps are code in the site that calls it. Where the site already has that code, read steps 4 and 5 as what it does.

## 1. Publish the configuration

```bash
php artisan vendor:publish --tag=aether-listings-management-config
```

This copies `config/aether-listings-management.php` into the site. See [Configuration](Configuration.md) for every key.

## 2. Set the credentials

Add the three values to the site's environment:

```text
GOOGLE_BUSINESS_PROFILE_CLIENT_ID=...
GOOGLE_BUSINESS_PROFILE_CLIENT_SECRET=...
GOOGLE_BUSINESS_PROFILE_REFRESH_TOKEN=...
```

With any one missing, nothing is synced.

## 3. Define a silo in dry-run mode

In the published file, set one silo with its two account groups and run it as a dry run:

```php
'silos' => [
    'uams' => [
        'brand' => null,
        'accounts' => [
            'provider' => 'accounts/111',
            'location' => 'accounts/222',
        ],
    ],
],
'modes' => [
    'uams' => 'dry-run',
],
```

`Client::accountGroups()` lists the location-group accounts the credentials manage, as account name and display name, if you need the `accounts/...` values. Leave `production_host` empty: a dry run never writes, and an empty production host keeps every silo from writing.

## 4. Run the sync

```php
use UAMSWeb\Aether\People\ListingsManagement\BusinessProfile\{Client, Credentials, FieldMapper, ListingIndex, Mode, Silos, Sync};

$config = config('aether-listings-management');

$client = new Client(
    new Credentials(
        $config['credentials']['client_id'],
        $config['credentials']['client_secret'],
        $config['credentials']['refresh_token'],
    ),
    $transport,
);

$silos = Silos::fromConfig(
    $config['silos'],
    $config['modes'],
    Mode::isProductionHost(request()->getHost(), $config['production_host']),
);

// Every account group of every silo, so a store code anywhere is found
$accounts = [];
foreach ($silos->silos as $silo) {
    array_push($accounts, ...array_values($silo['accounts']));
}
$index = ListingIndex::fromClient($client, array_values(array_unique(array_filter($accounts))));

$mapper = new FieldMapper($config['mapping']);
$sync = new Sync($client, $silos, $index, fn ($outcome) => logger()->info('listing', $outcome->toArray()));

$outcomes = $sync->run([$mapper->locationItem($locationData, $silos)]);
```

`$transport` is the site's implementation of `Transport`, and `$locationData` is an `EntryData` read from the entry. See [Classes](Classes.md).

## 5. Read the outcomes

Each outcome says what a live run would do. In a dry run you see:

- `update` with the reason `Dry run, no write made`, and the `fields` it would write.
- `create` with the reason `Dry run, no listing created`.
- `noop` with `Listing already matches`.
- `review` where a person has to look.

Nothing was sent to Google. See [Outcomes and actions](Outcomes-and-actions.md) for the full list.

**Note:** a dry run still reads from Google: it signs in and reads every listing in the silo's account groups, so the credentials and the account groups must be right.

## Go further

- [Going live for a brand](Going-live-for-a-brand.md): what to check before the silo writes.
- [Troubleshooting](Troubleshooting.md): what each outcome reason means.

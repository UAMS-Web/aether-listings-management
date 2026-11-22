<?php

declare(strict_types=1);

use UAMSWeb\Aether\People\ListingsManagement\BusinessProfile\ApiException;
use UAMSWeb\Aether\People\ListingsManagement\BusinessProfile\Client;
use UAMSWeb\Aether\People\ListingsManagement\BusinessProfile\Credentials;
use UAMSWeb\Aether\People\ListingsManagement\BusinessProfile\HttpResponse;
use UAMSWeb\Aether\People\ListingsManagement\BusinessProfile\Photo;
use UAMSWeb\Aether\People\ListingsManagement\BusinessProfile\RateLimitedException;
use UAMSWeb\Aether\People\ListingsManagement\Tests\Support\FakeTransport;

/**
 * The Business Profile client: the refresh-token sign-in, the reads and writes,
 * and how a failure is named. Every request goes to a fake transport.
 *
 * @command  vendor/bin/pest tests/Unit/BusinessProfile/ClientTest.php
 */
function clientWith(FakeTransport $transport, ?Closure $clock = null): Client
{
    return new Client(new Credentials('client-id', 'client-secret-value', 'refresh-token-value'), $transport, $clock);
}

it('exchanges the refresh token once and reuses the access token until a minute before it expires', function (): void {
    // Set a clock the test moves
    $now = 1_000_000;
    $transport = new FakeTransport;
    $client = clientWith($transport, function () use (&$now): int {
        return $now;
    });

    // Two calls inside the token's life, then one in its last minute
    $client->categories();
    $client->categories();

    $tokensBefore = count($transport->requests) - count($transport->apiRequests());
    $now += 3541;
    $client->categories();

    // Expect one exchange for the first two calls and a second once the token is near expiry
    expect($tokensBefore)->toBe(1)
        ->and(count($transport->requests) - count($transport->apiRequests()))->toBe(2);
});

it('sends the refresh grant and signs each call with the bearer token', function (): void {
    // Make one call
    $transport = new FakeTransport;
    clientWith($transport)->categories();

    // Expect the grant in the exchange and the token on the call
    parse_str((string) $transport->requests[0]['body'], $grant);
    expect($grant)->toBe(['client_id' => 'client-id', 'client_secret' => 'client-secret-value', 'refresh_token' => 'refresh-token-value', 'grant_type' => 'refresh_token'])
        ->and($transport->apiRequests()[0]['headers']['Authorization'])->toBe('Bearer test-token');
});

it('names a failed token exchange by status and Google error code, and leaks no credential', function (): void {
    // Refuse the exchange
    $transport = new FakeTransport(fn (string $method, string $url): ?HttpResponse => str_contains($url, 'oauth2') ? FakeTransport::json(['error' => 'invalid_grant', 'error_description' => 'refresh-token-value is bad'], 400) : null);

    // Expect the status and code, and neither the secret nor the token
    expect(fn (): array => clientWith($transport)->categories())->toThrow(function (ApiException $apiException): void {
        expect($apiException->getMessage())->toBe('Google token exchange failed (400): invalid_grant')
            ->not->toContain('client-secret-value')
            ->not->toContain('refresh-token-value');
    });
});

it('names the transport reason when a request could not be made at all', function (): void {
    // Fail the exchange, then a call, before either is sent
    $unsentToken = new FakeTransport(fn (): HttpResponse => new HttpResponse(0, 'cURL error 6: Could not resolve host'));
    $unsentCall = new FakeTransport(fn (string $method, string $url): ?HttpResponse => str_contains($url, 'oauth2') ? null : new HttpResponse(0, 'cURL error 28: timed out'));

    // Expect each failure to carry the transport's reason
    expect(fn (): array => clientWith($unsentToken)->categories())->toThrow(ApiException::class, 'Google token exchange failed (0): cURL error 6: Could not resolve host')
        ->and(fn (): array => clientWith($unsentCall)->categories())->toThrow(ApiException::class, 'Google API GET failed (0): cURL error 28: timed out');
});

it('keeps generic wording when nothing explains a failure', function (): void {
    // Answer the exchange with nothing usable, then a call with an empty error
    $noToken = new FakeTransport(fn (): HttpResponse => new HttpResponse(0, ''));
    $noMessage = new FakeTransport(fn (string $method, string $url): ?HttpResponse => str_contains($url, 'oauth2') ? null : new HttpResponse(500, ''));

    // Expect the fallback wording
    expect(fn (): array => clientWith($noToken)->categories())->toThrow(ApiException::class, 'Google token exchange failed (0): no token returned')
        ->and(fn (): array => clientWith($noMessage)->categories())->toThrow(ApiException::class, 'Google API GET failed (500): no message');
});

it('rejects a token response that is a success with no token in it', function (): void {
    // Answer the exchange with 200 and no token
    $transport = new FakeTransport(fn (string $method, string $url): ?HttpResponse => str_contains($url, 'oauth2') ? FakeTransport::json(['expires_in' => 3600]) : null);

    // Expect a failure rather than an empty bearer token
    expect(fn (): array => clientWith($transport)->categories())->toThrow(ApiException::class, 'Google token exchange failed (200): no token returned');
});

it('carries the status and Google message on an API failure', function (): void {
    // Refuse a call
    $transport = new FakeTransport(fn (string $method, string $url): ?HttpResponse => str_contains($url, 'oauth2') ? null : FakeTransport::json(['error' => ['message' => 'Request contains an invalid argument.']], 400));

    // Expect a plain failure, not a rate limit
    expect(fn (): array => clientWith($transport)->patch('locations/1', ['title' => 'x'], 'title'))->toThrow(function (ApiException $apiException): void {
        expect($apiException)->not->toBeInstanceOf(RateLimitedException::class)
            ->and($apiException->getMessage())->toBe('Google API PATCH failed (400): Request contains an invalid argument.');
    });
});

it('treats only 429 and 503 as rate limits, and only a 429 naming a per-day quota as daily', function (int $status, string $message, bool $limited, bool $daily): void {
    // Refuse a call with the status
    $transport = new FakeTransport(fn (string $method, string $url): ?HttpResponse => str_contains($url, 'oauth2') ? null : FakeTransport::json(['error' => ['message' => $message]], $status));

    try {
        clientWith($transport)->categories();
        $caught = null;
    } catch (ApiException $apiException) {
        $caught = $apiException;
    }

    // Expect the class and the daily flag the status calls for
    expect($caught)->toBeInstanceOf(ApiException::class)
        ->and($caught instanceof RateLimitedException)->toBe($limited)
        ->and($caught instanceof RateLimitedException && $caught->daily)->toBe($daily);
})->with([
    'per-minute 429' => [429, 'Quota exceeded for quota metric requests per minute', true, false],
    'per-day 429' => [429, 'Quota exceeded for quota metric creates per day', true, true],
    'daily 429' => [429, 'Daily limit reached', true, true],
    '503' => [503, 'The service is unavailable, try again daily', true, false],
    '500' => [500, 'Internal error', false, false],
    '403' => [403, 'per day', false, false],
]);

it('lists only location groups and follows every page', function (): void {
    // Serve two pages of accounts
    $transport = new FakeTransport(function (string $method, string $url): ?HttpResponse {
        if (! str_contains($url, 'accountmanagement')) {
            return null;
        }

        return str_contains($url, 'pageToken=p2')
            ? FakeTransport::json(['accounts' => [['name' => 'accounts/3', 'type' => 'LOCATION_GROUP', 'accountName' => 'Locations']]])
            : FakeTransport::json(['accounts' => [['name' => 'accounts/1', 'type' => 'LOCATION_GROUP', 'accountName' => 'Providers'], ['name' => 'accounts/2', 'type' => 'PERSONAL', 'accountName' => 'Someone']], 'nextPageToken' => 'p2']);
    });

    // Expect the two groups and the second page asked for by token
    expect(clientWith($transport)->accountGroups())->toBe(['accounts/1' => 'Providers', 'accounts/3' => 'Locations'])
        ->and($transport->apiRequests()[0]['url'])->toBe('https://mybusinessaccountmanagement.googleapis.com/v1/accounts?pageSize=20')
        ->and($transport->apiRequests()[1]['url'])->toBe('https://mybusinessaccountmanagement.googleapis.com/v1/accounts?pageSize=20&pageToken=p2');
});

it('reads the compared fields of every listing in a group across pages', function (): void {
    // Serve two pages of listings
    $transport = new FakeTransport(function (string $method, string $url): ?HttpResponse {
        if (! str_contains($url, '/locations')) {
            return null;
        }

        return str_contains($url, 'pageToken=next')
            ? FakeTransport::json(['locations' => [['name' => 'locations/2']]])
            : FakeTransport::json(['locations' => [['name' => 'locations/1']], 'nextPageToken' => 'next']);
    });

    // Expect both listings and the read mask
    expect(clientWith($transport)->listings('accounts/9'))->toBe([['name' => 'locations/1'], ['name' => 'locations/2']])
        ->and($transport->apiRequests()[0]['url'])->toBe('https://mybusinessbusinessinformation.googleapis.com/v1/accounts/9/locations?readMask='.rawurlencode(Client::READ_MASK).'&pageSize=100');
});

it('maps category display names to resource names', function (): void {
    // Serve categories, one without a name
    $transport = new FakeTransport(fn (string $method, string $url): ?HttpResponse => str_contains($url, '/categories') ? FakeTransport::json(['categories' => [['name' => 'categories/gcid:doctor', 'displayName' => 'Doctor'], ['displayName' => 'Nameless']]]) : null);

    // Expect only the complete one, asked for in United States English
    expect(clientWith($transport)->categories())->toBe(['Doctor' => 'categories/gcid:doctor'])
        ->and($transport->apiRequests()[0]['url'])->toContain('regionCode=US&languageCode=en&view=BASIC&pageSize=100');
});

it('patches only the given fields with their mask, and validates only when asked', function (): void {
    // Patch twice, once as a validation
    $transport = new FakeTransport;
    $client = clientWith($transport);
    $client->patch('locations/7', ['title' => 'New'], 'title');
    $client->patch('locations/7', ['title' => 'New'], 'title', validateOnly: true);

    // Expect the body, the mask and the validation flag on the second only
    [$first, $second] = $transport->apiRequests();
    expect($first['method'])->toBe('PATCH')
        ->and($first['url'])->toBe('https://mybusinessbusinessinformation.googleapis.com/v1/locations/7?updateMask=title')
        ->and($first['body'])->toBe('{"title":"New"}')
        ->and($first['headers']['Content-Type'])->toBe('application/json')
        ->and($second['url'])->toBe('https://mybusinessbusinessinformation.googleapis.com/v1/locations/7?updateMask=title&validateOnly=true');
});

it('creates in the account group with the store code and a request ID stable per store code, and never as a validation', function (): void {
    // Create twice with one code and once with another
    $transport = new FakeTransport;
    $client = clientWith($transport);
    $client->create('accounts/5', ['title' => 'Clinic'], 'clinic');
    $client->create('accounts/5', ['title' => 'Clinic'], 'clinic');
    $client->create('accounts/5', ['title' => 'Clinic'], 'other');

    // Expect the body to carry the code, and the ID to follow the code alone
    [$first, $second, $third] = $transport->apiRequests();
    expect($first['url'])->toBe('https://mybusinessbusinessinformation.googleapis.com/v1/accounts/5/locations?requestId='.Client::requestId('clinic'))
        ->and(Client::requestId('clinic'))->toBe('uamswp-'.substr(sha1('clinic'), 0, 32))
        ->and(json_decode((string) $first['body'], true))->toBe(['title' => 'Clinic', 'storeCode' => 'clinic', 'languageCode' => 'en'])
        ->and($second['url'])->toBe($first['url'])
        ->and($third['url'])->not->toContain(Client::requestId('clinic'));
});

it('reads attributes as booleans and ignores any other value type', function (): void {
    // Serve a boolean, a boolean-looking enum and a malformed boolean
    $transport = new FakeTransport(fn (string $method, string $url): ?HttpResponse => str_ends_with($url, '/attributes') ? FakeTransport::json(['attributes' => [
        ['name' => 'attributes/has_restroom', 'valueType' => 'BOOL', 'values' => [true]],
        ['name' => 'attributes/requires_appointments', 'valueType' => 'BOOL', 'values' => [false]],
        ['name' => 'attributes/wi_fi', 'valueType' => 'ENUM', 'values' => [true]],
        ['name' => 'attributes/has_video_visits', 'valueType' => 'BOOL', 'values' => ['true']],
    ]]) : null);

    // Expect the two booleans, and an empty map for a listing with none
    expect(clientWith($transport)->attributes('locations/3'))->toBe(['has_restroom' => true, 'requires_appointments' => false])
        ->and(clientWith(new FakeTransport)->attributes('locations/3'))->toBe([]);
});

it('writes attributes with a mask naming exactly the attributes sent', function (): void {
    // Write two attributes
    $transport = new FakeTransport;
    clientWith($transport)->updateAttributes('locations/3', ['has_restroom' => true, 'requires_appointments' => false]);

    // Expect the mask and the body
    $request = $transport->apiRequests()[0];
    expect($request['url'])->toBe('https://mybusinessbusinessinformation.googleapis.com/v1/locations/3/attributes?attributeMask='.rawurlencode('attributes/has_restroom,attributes/requires_appointments'))
        ->and(json_decode((string) $request['body'], true))->toBe(['name' => 'locations/3/attributes', 'attributes' => [
            ['name' => 'attributes/has_restroom', 'values' => [true]],
            ['name' => 'attributes/requires_appointments', 'values' => [false]],
        ]]);
});

it('reports whether every credential is present', function (): void {
    expect(new Credentials('a', 'b', 'c')->isComplete())->toBeTrue()
        ->and(new Credentials('', 'b', 'c')->isComplete())->toBeFalse()
        ->and(new Credentials('a', '', 'c')->isComplete())->toBeFalse()
        ->and(new Credentials('a', 'b', '')->isComplete())->toBeFalse();
});

it('renews the token at exactly a minute before it expires, using the expiry Google gives or an hour', function (array $token, int $life): void {
    // A token with the given life, and a clock the test moves
    $now = 5_000;
    $transport = new FakeTransport(fn (string $method, string $url): ?HttpResponse => str_contains($url, 'oauth2') ? FakeTransport::json(['access_token' => 'tok'] + $token) : null);
    $client = clientWith($transport, function () use (&$now): int {
        return $now;
    });
    $exchanges = fn (): int => count($transport->requests) - count($transport->apiRequests());

    // One second before the renewal point, then at it
    $client->categories();
    $now += $life - 61;
    $client->categories();
    $before = $exchanges();
    $now += 1;
    $client->categories();

    expect($before)->toBe(1)
        ->and($exchanges())->toBe(2);
})->with([
    'the expiry Google gives' => [['expires_in' => 600], 600],
    'no expiry given' => [[], 3600],
    'an expiry that is not a number' => [['expires_in' => '600'], 3600],
]);

it('sends the token exchange as a form', function (): void {
    $transport = new FakeTransport;
    clientWith($transport)->categories();

    expect($transport->requests[0]['method'])->toBe('POST')
        ->and($transport->requests[0]['url'])->toBe('https://oauth2.googleapis.com/token')
        ->and($transport->requests[0]['headers'])->toBe(['Content-Type' => 'application/x-www-form-urlencoded']);
});

it('treats every 2xx status as success and anything either side of it as a failure', function (int $status, bool $succeeds): void {
    $transport = new FakeTransport(fn (string $method, string $url): ?HttpResponse => str_contains($url, 'oauth2') ? null : new HttpResponse($status, ''));
    $call = fn (): array => clientWith($transport)->patch('locations/1', ['title' => 'x'], 'title');

    if ($succeeds) {
        expect($call())->toBe([]);
    } else {
        expect($call)->toThrow(ApiException::class, 'Google API PATCH failed ('.$status.'): no message');
    }
})->with([
    '199' => [199, false],
    '200' => [200, true],
    '204' => [204, true],
    '299' => [299, true],
    '300' => [300, false],
]);

it('does not take a body that is not JSON for the failure message', function (): void {
    $transport = new FakeTransport(fn (string $method, string $url): ?HttpResponse => str_contains($url, 'oauth2') ? null : new HttpResponse(502, '<html>Bad gateway</html>'));
    $unsentEmpty = new FakeTransport(fn (string $method, string $url): ?HttpResponse => str_contains($url, 'oauth2') ? null : new HttpResponse(0, ''));

    expect(fn (): array => clientWith($transport)->categories())->toThrow(ApiException::class, 'Google API GET failed (502): no message')
        ->and(fn (): array => clientWith($unsentEmpty)->categories())->toThrow(ApiException::class, 'Google API GET failed (0): no message');
});

it('asks the categories and attributes endpoints by their full address', function (): void {
    $transport = new FakeTransport;
    $client = clientWith($transport);
    $client->categories();
    $client->attributes('locations/3');

    expect($transport->apiRequests()[0]['url'])->toBe('https://mybusinessbusinessinformation.googleapis.com/v1/categories?regionCode=US&languageCode=en&view=BASIC&pageSize=100')
        ->and($transport->apiRequests()[1]['url'])->toBe('https://mybusinessbusinessinformation.googleapis.com/v1/locations/3/attributes')
        ->and($transport->apiRequests()[1]['method'])->toBe('GET');
});

it('leaves out a category with no display name', function (): void {
    $transport = new FakeTransport(fn (string $method, string $url): ?HttpResponse => str_contains($url, '/categories') ? FakeTransport::json(['categories' => [['name' => 'categories/gcid:x', 'displayName' => ''], ['name' => 'categories/gcid:y', 'displayName' => 'Y']]]) : null);

    expect(clientWith($transport)->categories())->toBe(['Y' => 'categories/gcid:y']);
});

it('keeps an attribute name without the prefix as it is', function (): void {
    $transport = new FakeTransport(fn (string $method, string $url): ?HttpResponse => str_ends_with($url, '/attributes') ? FakeTransport::json(['attributes' => [['name' => 'has_restroom', 'valueType' => 'BOOL', 'values' => [true]]]]) : null);

    expect(clientWith($transport)->attributes('locations/3'))->toBe(['has_restroom' => true]);
});

it('sends an empty attribute list when asked to write no attributes', function (): void {
    $transport = new FakeTransport;
    clientWith($transport)->updateAttributes('locations/3', []);

    expect(json_decode((string) $transport->apiRequests()[0]['body'], true))->toBe(['name' => 'locations/3/attributes', 'attributes' => []]);
});

it('marks a rate limit as not daily unless told', function (): void {
    expect((new RateLimitedException('slow down'))->daily)->toBeFalse()
        ->and((new RateLimitedException('slow down', true))->daily)->toBeTrue();
});

it('fails a success reply that is not JSON rather than reading it as an empty page', function (): void {
    $transport = new FakeTransport(fn (string $method, string $url): ?HttpResponse => str_contains($url, '/locations') ? new HttpResponse(200, '<html>proxy</html>') : null);

    expect(fn (): array => clientWith($transport)->listings('accounts/1'))->toThrow(ApiException::class, 'Google API GET failed (200): the reply is not JSON');
});

it('sends a value with bytes that are not UTF-8 with a replacement character instead of failing', function (): void {
    $transport = new FakeTransport;
    clientWith($transport)->patch('locations/1', ['title' => "Caf\xE9"], 'title');

    expect(json_decode((string) $transport->apiRequests()[0]['body'], true))->toBe(['title' => "Caf\u{FFFD}"]);
});

it('fails when Google hands back a page token a second time', function (): void {
    // Every page points at the same next page; past a few pages the fake stops, so a broken guard ends the test instead of hanging it
    $served = 0;
    $transport = new FakeTransport(function (string $method, string $url) use (&$served): ?HttpResponse {
        if (! str_contains($url, '/categories')) {
            return null;
        }

        return FakeTransport::json(['categories' => [], 'nextPageToken' => ++$served < 5 ? 'again' : '']);
    });

    expect(fn (): array => clientWith($transport)->categories())->toThrow(ApiException::class, 'the page token for https://mybusinessbusinessinformation.googleapis.com/v1/categories came back a second time')
        ->and($transport->apiRequests())->toHaveCount(2);
});

it('reads at most the page limit, and fails past it', function (): void {
    // Every page points at a new next page; past the limit the fake stops, so a broken limit ends the test instead of hanging it
    $served = 0;
    $transport = new FakeTransport(function (string $method, string $url) use (&$served): ?HttpResponse {
        if (! str_contains($url, '/categories')) {
            return null;
        }

        return FakeTransport::json(['categories' => [], 'nextPageToken' => ++$served < Client::MAX_PAGES + 10 ? 'p'.$served : '']);
    });

    expect(fn (): array => clientWith($transport)->categories())->toThrow(ApiException::class, 'Google API GET failed: https://mybusinessbusinessinformation.googleapis.com/v1/categories ran past 1000 pages')
        ->and($transport->apiRequests())->toHaveCount(Client::MAX_PAGES);
});

it('adds a photo to a listing through the v4 media endpoint, by its address and category', function (): void {
    $transport = new FakeTransport;

    clientWith($transport)->uploadPhoto('accounts/9', 'locations/3', new Photo(Photo::COVER, 'https://health.example.test/cover.jpg'));

    $request = $transport->apiRequests()[0];
    expect($request['method'])->toBe('POST')
        ->and($request['url'])->toBe('https://mybusiness.googleapis.com/v4/accounts/9/locations/3/media')
        ->and(json_decode((string) $request['body'], true))->toBe([
            'mediaFormat' => 'PHOTO',
            'locationAssociation' => ['category' => 'COVER'],
            'sourceUrl' => 'https://health.example.test/cover.jpg',
        ]);
});

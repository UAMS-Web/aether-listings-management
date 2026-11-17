<?php

declare(strict_types=1);

use UAMSWeb\Aether\People\ListingsManagement\BusinessProfile\Client;
use UAMSWeb\Aether\People\ListingsManagement\ServiceProvider;

/**
 * The sync's classes stay off the framework and the network, and no credential
 * or account group identifier is committed.
 *
 * @command  vendor/bin/pest tests/Unit/BusinessProfile/BoundaryTest.php
 */
arch('the Business Profile classes use no framework class and no network function outside the injected transport')
    ->expect('UAMSWeb\Aether\People\ListingsManagement\BusinessProfile')
    ->not->toUse([
        'Illuminate',
        'Statamic',
        'GuzzleHttp',
        'Symfony\Component\HttpClient',
        'curl_init',
        'curl_exec',
        'file_get_contents',
        'fopen',
        'fsockopen',
        'stream_socket_client',
        'stream_context_create',
        'socket_create',
        'get_headers',
        'env',
        'config',
        'app',
    ]);

// Controls for the expectation above: it sees a framework class and a plain function call when one is used
arch('the boundary check sees a framework class where one is used')
    ->expect(ServiceProvider::class)
    ->toUse('Illuminate');

arch('the boundary check sees a function call where one is made')
    ->expect(Client::class)
    ->toUse('http_build_query');

it('commits no account group identifier or credential value in the source or the configuration', function (): void {
    // Read every committed PHP file the add-on ships
    $files = array_merge(glob(__DIR__.'/../../../src/BusinessProfile/*.php') ?: [], [__DIR__.'/../../../config/aether-listings-management.php']);
    expect(count($files))->toBeGreaterThan(10);

    $found = [];
    foreach ($files as $file) {
        $contents = (string) file_get_contents($file);

        // An account group ID, an OAuth client ID, a client secret or a refresh token
        if (preg_match('#accounts/\d{6,}|\d{6,}-[a-z0-9]+\.apps\.googleusercontent\.com|GOCSPX-|1//0[0-9A-Za-z_-]{20,}#', $contents) === 1) {
            $found[] = basename($file);
        }
    }

    expect($found)->toBe([]);
});

it('detects an account group identifier, a client ID, a secret and a refresh token when one is present', function (string $value): void {
    // The same pattern as above, against a value of each kind
    expect(preg_match('#accounts/\d{6,}|\d{6,}-[a-z0-9]+\.apps\.googleusercontent\.com|GOCSPX-|1//0[0-9A-Za-z_-]{20,}#', $value))->toBe(1);
})->with([
    'account group' => ["'provider' => 'accounts/112233445566'"],
    'client ID' => ['123456789012-abcdefg.apps.googleusercontent.com'],
    'client secret' => ['GOCSPX-abc'],
    'refresh token' => ['1//0abcdefghijklmnopqrstuvwxyz'],
]);

it('ships no silo, no mode and credentials read only from the environment', function (): void {
    /** @var array{credentials: array<string, string>, silos: array<string, mixed>, modes: array<string, string>, production_host: string} $config */
    $config = require __DIR__.'/../../../config/aether-listings-management.php';
    $source = (string) file_get_contents(__DIR__.'/../../../config/aether-listings-management.php');

    expect($config['silos'])->toBe([])
        ->and($config['modes'])->toBe([])
        ->and($source)->toContain("env('GOOGLE_BUSINESS_PROFILE_CLIENT_ID', '')")
        ->and($source)->toContain("env('GOOGLE_BUSINESS_PROFILE_CLIENT_SECRET', '')")
        ->and($source)->toContain("env('GOOGLE_BUSINESS_PROFILE_REFRESH_TOKEN', '')")
        ->and($source)->toContain("env('BUSINESS_PROFILE_PRODUCTION_HOST', '')");
});

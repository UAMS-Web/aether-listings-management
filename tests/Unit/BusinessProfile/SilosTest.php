<?php

declare(strict_types=1);

use UAMSWeb\Aether\People\ListingsManagement\BusinessProfile\Kind;
use UAMSWeb\Aether\People\ListingsManagement\BusinessProfile\Mode;
use UAMSWeb\Aether\People\ListingsManagement\BusinessProfile\Silos;

/**
 * Silos and modes as configuration: which silo serves a brand, the account
 * group for each kind, and the mode a silo actually runs in.
 *
 * @command  vendor/bin/pest tests/Unit/BusinessProfile/SilosTest.php
 */
/**
 * @param  array<array-key, mixed>  $modes
 */
function testSilos(array $modes = [], bool $production = true): Silos
{
    return Silos::fromConfig([
        'main' => ['brand' => null, 'accounts' => ['provider' => 'accounts/mp', 'location' => 'accounts/ml']],
        'partner' => ['brand' => 'partner', 'accounts' => ['provider' => 'accounts/pp', 'location' => 'accounts/pl']],
        'broken' => 'not an array',
        7 => ['brand' => '', 'accounts' => ['location' => 'accounts/7l']],
    ], $modes, $production);
}

it('caps a live mode at dry run off production and treats anything unknown as off', function (mixed $configured, bool $production, Mode $expected): void {
    expect(Mode::effective($configured, $production))->toBe($expected);
})->with([
    'live on production' => ['live', true, Mode::Live],
    'live elsewhere' => ['live', false, Mode::DryRun],
    'dry run on production' => ['dry-run', true, Mode::DryRun],
    'dry run elsewhere' => ['dry-run', false, Mode::DryRun],
    'off' => ['off', true, Mode::Off],
    'unknown' => ['Live', true, Mode::Off],
    'none' => [null, true, Mode::Off],
    'not a string' => [true, true, Mode::Off],
]);

it('finds the silo for a brand, and the default silo for no brand', function (): void {
    $silos = testSilos();

    expect($silos->forBrand('partner'))->toBe('partner')
        ->and($silos->forBrand(null))->toBe('main')
        ->and($silos->forBrand(''))->toBe('main')
        ->and($silos->forBrand('unknown'))->toBeNull();
});

it('has no silo for no brand when no silo serves the default brand', function (): void {
    $silos = Silos::fromConfig(['partner' => ['brand' => 'partner', 'accounts' => ['provider' => 'a', 'location' => 'b']]], [], true);

    expect($silos->forBrand(null))->toBeNull();
});

it('reads each silo account groups and keeps a silo with a group missing', function (): void {
    $silos = testSilos();

    expect($silos->account('main', Kind::Provider))->toBe('accounts/mp')
        ->and($silos->account('partner', Kind::Location))->toBe('accounts/pl')
        ->and($silos->account('7', Kind::Provider))->toBe('')
        ->and($silos->account('7', Kind::Location))->toBe('accounts/7l')
        ->and($silos->account('unknown', Kind::Location))->toBe('')
        ->and($silos->has('broken'))->toBeTrue()
        ->and($silos->account('broken', Kind::Location))->toBe('')
        ->and($silos->has('unknown'))->toBeFalse();
});

it('names the silo that owns an account group', function (): void {
    $silos = testSilos();

    expect($silos->owner('accounts/ml'))->toBe('main')
        ->and($silos->owner('accounts/pp'))->toBe('partner')
        ->and($silos->owner('accounts/parked'))->toBeNull();
});

it('reads each silo mode and caps it by environment', function (): void {
    $modes = ['main' => 'live', 'partner' => 'dry-run', 'broken' => ['live']];

    expect(testSilos($modes)->mode('main'))->toBe(Mode::Live)
        ->and(testSilos($modes, production: false)->mode('main'))->toBe(Mode::DryRun)
        ->and(testSilos($modes)->mode('partner'))->toBe(Mode::DryRun)
        ->and(testSilos($modes)->mode('broken'))->toBe(Mode::Off)
        ->and(testSilos($modes)->mode('7'))->toBe(Mode::Off);
});

it('returns a numeric silo name as a string', function (): void {
    $silos = Silos::fromConfig([7 => ['brand' => 'seven', 'accounts' => ['provider' => 'accounts/7p', 'location' => 'accounts/7l']]], [7 => 'live'], true);

    expect($silos->forBrand('seven'))->toBe('7')
        ->and($silos->owner('accounts/7p'))->toBe('7')
        ->and($silos->mode('7'))->toBe(Mode::Live)
        ->and($silos->account('7', Kind::Location))->toBe('accounts/7l');
});

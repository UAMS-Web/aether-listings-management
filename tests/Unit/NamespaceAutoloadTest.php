<?php

declare(strict_types=1);

use UAMSWeb\Aether\People\ListingsManagement\ServiceProvider;

/**
 * The add-on's namespace nests inside `aether-people`'s prefix, so its own
 * `psr-4` key maps to `src/` and the classes load from there.
 *
 * @command  vendor/bin/pest tests/Unit/NamespaceAutoloadTest.php
 */
it('maps its namespace to src/ in composer.json', function (): void {
    // Read and decode the manifest
    $contents = file_get_contents(__DIR__.'/../../composer.json');
    expect($contents)->toBeString();

    /** @var array{autoload: array{'psr-4': array<string, string>}} $manifest */
    $manifest = json_decode((string) $contents, true, flags: JSON_THROW_ON_ERROR);

    // Expect exactly the nested prefix, mapped to src/
    expect($manifest['autoload']['psr-4'])->toBe(['UAMSWeb\\Aether\\People\\ListingsManagement\\' => 'src/']);
});

it('autoloads its service provider from src/ServiceProvider.php', function (): void {
    // Expect the class to load, and to come from the file the mapping implies
    expect(class_exists(ServiceProvider::class))->toBeTrue()
        ->and(new ReflectionClass(ServiceProvider::class)->getFileName())->toBe(realpath(__DIR__.'/../../src/ServiceProvider.php'));
});

it('keeps no ListingsManagement directory under src/', function (): void {
    // The prefix is already the add-on's own, so a nested directory would repeat it
    expect(__DIR__.'/../../src/ListingsManagement')->not->toBeDirectory();
});

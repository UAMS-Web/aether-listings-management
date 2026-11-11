<?php

declare(strict_types=1);

use Statamic\Statamic;
use UAMSWeb\Aether\People\ListingsManagement\ServiceProvider;

/**
 * What `statamic/cms` derives from the provider's `$vite` property: the
 * `Statamic::vite()` registration and the publish mapping of the build (#28).
 * `ViteBuildTest` pins the property itself; these tests pin the result of
 * `AddonServiceProvider::registerVite()`, so a change in `statamic/cms` that
 * alters either one fails here.
 *
 * Statamic names the registration and the public directory after the package
 * name without its vendor (`aether-listings-management`), and tags the publish
 * mapping with the add-on's slug, which is the same string.
 *
 * @command  vendor/bin/pest tests/Integration/ViteRegistrationTest.php
 */

/**
 * The repository root.
 *
 * @return string Absolute path of the repository root.
 */
function viteRegistrationRoot(): string
{
    return dirname(__DIR__, 2);
}

it('registers the Vite build under the add-on package name', function (): void {
    // Read the Vite registrations Statamic holds
    $registrations = Statamic::availableVites(request());

    // Expect the entry files and the public build directory
    expect($registrations)->toHaveKey('aether-listings-management.input', ['resources/js/cp.js', 'resources/css/cp.css'])
        ->and($registrations)->toHaveKey('aether-listings-management.buildDirectory', 'vendor/aether-listings-management/build');

    // Expect the hot file inside the add-on's dist directory, whichever slash the platform writes
    $separator = '[\\\\/]';
    $root = str_replace('/', $separator, preg_quote(str_replace('\\', '/', viteRegistrationRoot()), '#'));
    expect(data_get($registrations, 'aether-listings-management.hotFile'))
        ->toMatch('#^'.$root.$separator.'resources'.$separator.'dist'.$separator.'hot$#');
});

it('publishes the committed build to the public vendor directory', function (): void {
    // Read the paths published under the add-on's slug
    $paths = ServiceProvider::pathsToPublish(null, 'aether-listings-management');

    // Expect the build directory to publish to the add-on's public vendor directory
    expect(array_map(realpath(...), array_keys($paths)))->toBe([realpath(viteRegistrationRoot().'/resources/dist/build')])
        ->and(array_values($paths))->toBe([public_path('vendor/aether-listings-management/build/')]);

    // Expect the source to hold the committed manifest
    expect(viteRegistrationRoot().'/resources/dist/build/manifest.json')->toBeFile();
});

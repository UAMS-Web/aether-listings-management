<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\People\ListingsManagement;

use Illuminate\Contracts\Foundation\Application;
use Statamic\Providers\AddonServiceProvider;

/**
 * Service provider for the add-on.
 *
 * The host application discovers it through `extra.laravel.providers` in
 * `composer.json`. Register the add-on's tags, fieldtypes, listeners, routes and
 * the like here, using the properties `AddonServiceProvider` documents, or in
 * `bootAddon()` for anything that needs Statamic booted first.
 */
class ServiceProvider extends AddonServiceProvider
{
    /**
     * The Vite build of the Control Panel assets, set in the constructor. `input`
     * and `publicDirectory` must match `vite.config.js`; the built files are
     * committed under `resources/dist`.
     *
     * @var array{input: list<string>, publicDirectory: string}
     */
    protected $vite;

    /**
     * Create the provider and set its Vite build.
     *
     * The build is assigned here and not as the property's default because a
     * coverage driver reports no executable line for a property default, so a
     * mutation run counts every mutant in it uncovered and never tests it.
     *
     * @param  Application  $app  The application the provider belongs to.
     */
    public function __construct($app)
    {
        parent::__construct($app);

        // Point Statamic at the Control Panel entry files and their public directory
        $this->vite = [
            'input' => [
                'resources/js/cp.js',
                'resources/css/cp.css',
            ],
            'publicDirectory' => 'resources/dist',
        ];
    }

    /**
     * Boot the add-on once Statamic has booted.
     */
    public function bootAddon(): void
    {
        // Register the behavior of the add-on here
    }
}

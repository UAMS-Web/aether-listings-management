# ListingsManagement

A Statamic add-on that syncs Google Business Profile listings for UAMS Statamic sites. Its package is `uams-web/aether-listings-management`, and its namespace, `UAMSWeb\Aether\People\ListingsManagement`, nests inside the prefix owned by [`aether-people`](https://github.com/UAMS-Web/aether-people).

This is a skeleton: it holds the service provider, the tooling and the tests, and no listing code yet. The work moves out of the host site under [`UAMS-Web/uams-statamic#419`](https://github.com/UAMS-Web/uams-statamic/issues/419).

## Requirements

- PHP 8.4 or later
- Statamic 6.35 or later

`tests/Unit/ReadmeTest.php` fails when these drift from `composer.json`.

## Installation

```bash
composer require uams-web/aether-listings-management
```

The host application discovers the service provider through `extra.laravel.providers`. The add-on's settings, once it has any, publish with:

```bash
php artisan vendor:publish --tag=aether-listings-management-config
```

## Development

[The development guide](docs/DEVELOPMENT.md) covers running the add-on against a host application, and [the submission checklist](docs/SUBMISSION.md) covers Packagist and the Marketplace. `composer ci` runs the gate, and `AGENTS.md` lists what each script does.

## Namespace

`aether-people` owns `UAMSWeb\Aether\People\`, and this add-on's `UAMSWeb\Aether\People\ListingsManagement\` nests inside it. Each add-on maps its own prefix to its own `src/`, so this package's `src/` holds no code that belongs to `aether-people`, and `aether-people` keeps no `src/ListingsManagement` directory.

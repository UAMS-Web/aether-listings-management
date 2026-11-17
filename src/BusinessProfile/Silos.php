<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\People\ListingsManagement\BusinessProfile;

/**
 * The silos and their modes, as configuration.
 *
 * A silo is one brand's pair of Google account groups, one for provider listings
 * and one for location listings, and it is the only writer to those two groups.
 * Each silo runs off, as a dry run or live, and a live silo runs as a dry run
 * anywhere but the production host. A brand with no silo is never synced.
 */
final readonly class Silos
{
    /**
     * @param  array<array-key, array{brand: ?string, accounts: array{provider: string, location: string}}>  $silos  Silo name (an integer key when the name is numeric) => the brand it serves (null for the default brand) and its two account groups.
     * @param  array<array-key, string>  $modes  Silo name => configured mode; a silo with none is off.
     * @param  bool  $isProduction  Whether this is the production host.
     */
    public function __construct(
        public array $silos,
        public array $modes,
        public bool $isProduction,
    ) {}

    /**
     * Read the silos from the add-on's configuration, dropping anything malformed.
     *
     * A silo without both account groups is kept with an empty group, so the sync
     * reports the missing group instead of acting as if the silo did not exist.
     *
     * @param  mixed  $silos  The `silos` setting.
     * @param  mixed  $modes  The `modes` setting.
     * @param  bool  $isProduction  Whether this is the production host.
     */
    public static function fromConfig(mixed $silos, mixed $modes, bool $isProduction): self
    {
        $parsed = [];

        foreach (Values::arr($silos) as $name => $silo) {
            $silo = Values::arr($silo);
            $accounts = Values::arr($silo['accounts'] ?? null);
            $brand = Values::str($silo['brand'] ?? null);

            $parsed[$name] = [
                'brand' => $brand === '' ? null : $brand,
                'accounts' => [
                    'provider' => Values::str($accounts['provider'] ?? null),
                    'location' => Values::str($accounts['location'] ?? null),
                ],
            ];
        }

        $parsedModes = [];
        foreach (Values::arr($modes) as $name => $mode) {
            $parsedModes[$name] = Values::str($mode);
        }

        return new self($parsed, $parsedModes, $isProduction);
    }

    /**
     * The silo that serves a brand: the silo for that brand, or for no brand the default silo.
     *
     * @param  ?string  $brand  The brand a location carries, or null (or empty) for the default brand.
     * @return ?string The silo name, or null when the brand has no silo.
     */
    public function forBrand(?string $brand): ?string
    {
        $brand = $brand === '' ? null : $brand;

        foreach ($this->silos as $name => $silo) {
            if ($silo['brand'] === $brand) {
                // A numeric silo name is an integer key; the caller is promised a string
                return (string) $name;
            }
        }

        return null;
    }

    /**
     * Whether a silo is configured.
     *
     * @param  string  $silo  A silo name.
     */
    public function has(string $silo): bool
    {
        return isset($this->silos[$silo]);
    }

    /**
     * The mode a silo actually runs in.
     *
     * @param  string  $silo  A silo name.
     */
    public function mode(string $silo): Mode
    {
        return Mode::effective($this->modes[$silo] ?? null, $this->isProduction);
    }

    /**
     * The account group a silo keeps one kind of listing in, or empty when it has none.
     *
     * @param  string  $silo  A silo name.
     * @param  Kind  $kind  The kind of listing.
     */
    public function account(string $silo, Kind $kind): string
    {
        return $this->silos[$silo]['accounts'][$kind->value] ?? '';
    }

    /**
     * The silo that owns an account group, or null when no silo does.
     *
     * @param  string  $account  An account group, `accounts/123`.
     */
    public function owner(string $account): ?string
    {
        foreach ($this->silos as $name => $silo) {
            if (in_array($account, $silo['accounts'], true)) {
                // A numeric silo name is an integer key; the caller is promised a string
                return (string) $name;
            }
        }

        return null;
    }
}

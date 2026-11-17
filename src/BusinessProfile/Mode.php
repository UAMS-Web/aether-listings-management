<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\People\ListingsManagement\BusinessProfile;

/**
 * How a silo runs: not at all, deciding and logging without writing, or writing.
 */
enum Mode: string
{
    case Off = 'off';
    case DryRun = 'dry-run';
    case Live = 'live';

    /**
     * The mode a silo actually runs in: its configured mode, capped at dry run off production.
     *
     * Anything that is not a mode, including a silo with no configured mode, is off.
     *
     * @param  mixed  $configured  The configured mode.
     * @param  bool  $isProduction  Whether this is the production host.
     */
    public static function effective(mixed $configured, bool $isProduction): self
    {
        $mode = is_string($configured) ? self::tryFrom($configured) ?? self::Off : self::Off;

        return $mode === self::Live && ! $isProduction ? self::DryRun : $mode;
    }

    /**
     * Whether the host a run is on is the production host.
     *
     * An empty production host means none is configured, so no host is
     * production and no silo writes. Host names compare without letter case.
     *
     * @param  string  $host  The host the run is on, as the application's URL names it.
     * @param  string  $productionHost  The configured production host.
     */
    public static function isProductionHost(string $host, string $productionHost): bool
    {
        $productionHost = trim($productionHost);

        return $productionHost !== '' && strcasecmp(trim($host), $productionHost) === 0;
    }
}

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
}

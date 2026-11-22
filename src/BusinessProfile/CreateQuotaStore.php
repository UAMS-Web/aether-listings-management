<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\People\ListingsManagement\BusinessProfile;

/**
 * Where the create quota keeps its state between runs: the pause, and the creates sent each day.
 *
 * The host supplies one that outlives a request, such as its cache. Runs can
 * overlap, so recordCreate() should add one atomically.
 */
interface CreateQuotaStore
{
    /**
     * The Unix time creates are paused until, or 0 when they never were.
     */
    public function pausedUntil(): int;

    /**
     * Pause creates until a Unix time.
     *
     * @param  int  $time  When creates may go again.
     */
    public function pauseUntil(int $time): void;

    /**
     * How many creates were sent on one day.
     *
     * @param  string  $day  The Pacific day, `YYYY-MM-DD`.
     */
    public function created(string $day): int;

    /**
     * Count one create sent on a day.
     *
     * @param  string  $day  The Pacific day, `YYYY-MM-DD`.
     */
    public function recordCreate(string $day): void;
}

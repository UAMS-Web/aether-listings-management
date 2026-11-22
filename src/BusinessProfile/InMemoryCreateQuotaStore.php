<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\People\ListingsManagement\BusinessProfile;

/**
 * A create quota store that lasts as long as the object does.
 *
 * It holds the quota for one run, which is what the sync uses when the host
 * supplies no store; a pause or a count is forgotten when the run ends.
 */
final class InMemoryCreateQuotaStore implements CreateQuotaStore
{
    /** The Unix time creates are paused until, or 0. */
    private int $pausedUntil = 0;

    /** @var array<string, int> Creates sent, keyed by Pacific day. */
    private array $created = [];

    /**
     * The Unix time creates are paused until, or 0 when they never were.
     */
    public function pausedUntil(): int
    {
        return $this->pausedUntil;
    }

    /**
     * Pause creates until a Unix time.
     *
     * @param  int  $time  When creates may go again.
     */
    public function pauseUntil(int $time): void
    {
        $this->pausedUntil = $time;
    }

    /**
     * How many creates were sent on one day.
     *
     * @param  string  $day  The Pacific day, `YYYY-MM-DD`.
     */
    public function created(string $day): int
    {
        return $this->created[$day] ?? 0;
    }

    /**
     * Count one create sent on a day.
     *
     * @param  string  $day  The Pacific day, `YYYY-MM-DD`.
     */
    public function recordCreate(string $day): void
    {
        $this->created[$day] = $this->created($day) + 1;
    }
}

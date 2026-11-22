<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\People\ListingsManagement\BusinessProfile;

use Closure;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Google's daily limit on creating listings, and the budget kept below it.
 *
 * Google counts creates per day in Pacific time and refuses the rest of the day
 * with a 429 once the limit is used; on 2026-10-30 it accepted 102 of 117 and
 * refused the others. So the sync stops at a budget set below that, counted per
 * Pacific day, and a per-day 429 pauses creates until the next Pacific midnight.
 * The day is read in `America/Los_Angeles`, never at a fixed offset, so the reset
 * stays at midnight across the daylight saving change. Updates are not counted
 * or paused.
 */
final class CreateQuota
{
    /** The time zone Google's daily quota resets in. */
    public const string TIMEZONE = 'America/Los_Angeles';

    /** The creates sent in one Pacific day by default, below the 102 Google accepted before refusing. */
    public const int DEFAULT_BUDGET = 90;

    /** The most creates sent in one Pacific day; never below zero. */
    private readonly int $budget;

    /** @var Closure(): int The current Unix time. */
    private readonly Closure $clock;

    /**
     * @param  CreateQuotaStore  $store  Where the pause and the counts are kept.
     * @param  int  $budget  The most creates sent in one Pacific day; zero sends none.
     * @param  ?Closure(): int  $clock  The current Unix time; the system clock when null.
     */
    public function __construct(private readonly CreateQuotaStore $store, int $budget = self::DEFAULT_BUDGET, ?Closure $clock = null)
    {
        $this->budget = max(0, $budget);
        $this->clock = $clock ?? time(...);
    }

    /**
     * The Pacific day a Unix time falls on.
     *
     * @param  int  $time  A Unix time.
     * @return string `YYYY-MM-DD`.
     */
    public static function day(int $time): string
    {
        return self::pacific($time)->format('Y-m-d');
    }

    /**
     * The next Pacific midnight after a Unix time, when the daily quota resets.
     *
     * @param  int  $time  A Unix time.
     */
    public static function nextReset(int $time): int
    {
        return self::pacific($time)->modify('tomorrow')->getTimestamp();
    }

    /**
     * A Unix time as an ISO 8601 date and time in Pacific time, for a log entry.
     *
     * @param  int  $time  A Unix time.
     */
    public static function format(int $time): string
    {
        return self::pacific($time)->format(DATE_ATOM);
    }

    /**
     * When creates may go again, or null when one may go now.
     *
     * Creates wait while a per-day 429 pause lasts, and once today's budget is spent, until the next Pacific midnight.
     */
    public function resumeAt(): ?int
    {
        $now = ($this->clock)();
        $paused = $this->store->pausedUntil();

        if ($paused > $now) {
            return $paused;
        }

        return $this->store->created(self::day($now)) >= $this->budget ? self::nextReset($now) : null;
    }

    /**
     * Whether creates wait because Google refused one for the day, rather than because the budget is spent.
     */
    public function isPaused(): bool
    {
        return $this->store->pausedUntil() > ($this->clock)();
    }

    /**
     * The most creates sent in one Pacific day.
     */
    public function budget(): int
    {
        return $this->budget;
    }

    /**
     * Count one create about to be sent against today's budget.
     *
     * Every create sent counts, whatever Google answers, since Google counts the request.
     */
    public function recordCreate(): void
    {
        $this->store->recordCreate(self::day(($this->clock)()));
    }

    /**
     * Pause creates until the next Pacific midnight, after a per-day 429.
     *
     * @return int When creates may go again.
     */
    public function pause(): int
    {
        $until = self::nextReset(($this->clock)());
        $this->store->pauseUntil($until);

        return $until;
    }

    /**
     * A Unix time in Pacific time.
     *
     * @param  int  $time  A Unix time.
     */
    private static function pacific(int $time): DateTimeImmutable
    {
        return new DateTimeImmutable('@'.$time)->setTimezone(new DateTimeZone(self::TIMEZONE));
    }
}

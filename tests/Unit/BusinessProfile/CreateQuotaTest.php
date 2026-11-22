<?php

declare(strict_types=1);

use UAMSWeb\Aether\People\ListingsManagement\BusinessProfile\CreateQuota;
use UAMSWeb\Aether\People\ListingsManagement\BusinessProfile\InMemoryCreateQuotaStore;

/**
 * The daily create quota: the Pacific day, the reset at Pacific midnight across
 * the daylight saving change, the budget, and the pause after a per-day 429.
 *
 * @command  vendor/bin/pest tests/Unit/BusinessProfile/CreateQuotaTest.php
 */

/** A Unix time from an ISO 8601 string. */
function quotaTime(string $iso): int
{
    return new DateTimeImmutable($iso)->getTimestamp();
}

it('reads the day and the next reset in Pacific time, not UTC', function (string $now, string $day, string $reset): void {
    expect(CreateQuota::day(quotaTime($now)))->toBe($day)
        ->and(CreateQuota::nextReset(quotaTime($now)))->toBe(quotaTime($reset));
})->with([
    'a UTC morning is the Pacific night before' => ['2026-11-22T06:00:00Z', '2026-11-21', '2026-11-22T08:00:00Z'],
    'a summer day resets at 07:00 UTC' => ['2026-07-04T20:00:00Z', '2026-07-04', '2026-07-05T07:00:00Z'],
    'the night before the fall change still resets at PDT midnight' => ['2026-11-01T06:30:00Z', '2026-10-31', '2026-11-01T07:00:00Z'],
    'the 25-hour fall day resets at PST midnight' => ['2026-11-01T12:00:00Z', '2026-11-01', '2026-11-02T08:00:00Z'],
    'the 23-hour spring day resets at PDT midnight' => ['2027-03-14T12:00:00Z', '2027-03-14', '2027-03-15T07:00:00Z'],
    'midnight itself belongs to the new day' => ['2026-11-23T08:00:00Z', '2026-11-23', '2026-11-24T08:00:00Z'],
]);

it('formats a time as ISO 8601 in Pacific time', function (): void {
    expect(CreateQuota::format(quotaTime('2026-11-23T08:00:00Z')))->toBe('2026-11-23T00:00:00-08:00')
        ->and(CreateQuota::format(quotaTime('2026-07-05T07:00:00Z')))->toBe('2026-07-05T00:00:00-07:00');
});

it('lets creates go until the budget is spent, then holds them until Pacific midnight', function (): void {
    $now = quotaTime('2026-11-22T20:00:00Z');
    $quota = new CreateQuota(new InMemoryCreateQuotaStore, 2, function () use (&$now): int {
        return $now;
    });

    // Two creates spend the budget
    $before = $quota->resumeAt();
    $quota->recordCreate();
    $between = $quota->resumeAt();
    $quota->recordCreate();

    expect($before)->toBeNull()
        ->and($between)->toBeNull()
        ->and($quota->resumeAt())->toBe(quotaTime('2026-11-23T08:00:00Z'))
        ->and($quota->isPaused())->toBeFalse();

    // A second before midnight it still holds; at midnight it resets
    $now = quotaTime('2026-11-23T07:59:59Z');
    $held = $quota->resumeAt();
    $now = quotaTime('2026-11-23T08:00:00Z');

    expect($held)->toBe(quotaTime('2026-11-23T08:00:00Z'))
        ->and($quota->resumeAt())->toBeNull();
});

it('resets the budget at Pacific midnight on the night the clocks fall back', function (): void {
    // 23:30 PDT on the last day of daylight saving time
    $now = quotaTime('2026-11-01T06:30:00Z');
    $quota = new CreateQuota(new InMemoryCreateQuotaStore, 1, function () use (&$now): int {
        return $now;
    });
    $quota->recordCreate();

    $spent = $quota->resumeAt();

    // Midnight PDT is 07:00 UTC; a fixed -08:00 offset would wait until 08:00
    $now = quotaTime('2026-11-01T07:00:00Z');

    expect($spent)->toBe(quotaTime('2026-11-01T07:00:00Z'))
        ->and($quota->resumeAt())->toBeNull();
});

it('pauses creates until the next Pacific midnight, whatever the budget', function (): void {
    $now = quotaTime('2026-11-22T20:00:00Z');
    $store = new InMemoryCreateQuotaStore;
    $quota = new CreateQuota($store, 90, function () use (&$now): int {
        return $now;
    });

    $until = $quota->pause();

    expect($until)->toBe(quotaTime('2026-11-23T08:00:00Z'))
        ->and($store->pausedUntil())->toBe($until)
        ->and($quota->resumeAt())->toBe($until)
        ->and($quota->isPaused())->toBeTrue();

    // The pause ends at the reset
    $now = $until;

    expect($quota->resumeAt())->toBeNull()
        ->and($quota->isPaused())->toBeFalse();
});

it('keeps the pause and the count in the store, so a second quota over it sees them', function (): void {
    $store = new InMemoryCreateQuotaStore;
    $clock = fn (): int => quotaTime('2026-11-22T20:00:00Z');
    $first = new CreateQuota($store, 1, $clock);
    $first->recordCreate();

    expect(new CreateQuota($store, 1, $clock)->resumeAt())->toBe(quotaTime('2026-11-23T08:00:00Z'))
        ->and(new CreateQuota($store, 2, $clock)->resumeAt())->toBeNull()
        ->and($store->created('2026-11-22'))->toBe(1)
        ->and($store->created('2026-11-23'))->toBe(0);
});

it('takes a budget of zero, or below, as no creates at all', function (int $budget): void {
    $quota = new CreateQuota(new InMemoryCreateQuotaStore, $budget, fn (): int => quotaTime('2026-11-22T20:00:00Z'));

    expect($quota->budget())->toBe(0)
        ->and($quota->resumeAt())->toBe(quotaTime('2026-11-23T08:00:00Z'));
})->with([0, -5]);

it('defaults to a budget below the 102 creates Google accepted, and to the system clock', function (): void {
    $quota = new CreateQuota(new InMemoryCreateQuotaStore);
    $quota->recordCreate();

    expect($quota->budget())->toBe(90)
        ->and(CreateQuota::DEFAULT_BUDGET)->toBeLessThan(102)
        ->and($quota->resumeAt())->toBeNull();
});

Written against v0.4.1.

Google counts listing creates per day in Pacific time and refuses the rest of the day with an HTTP 429 once its limit is used. On 2026-10-30 it accepted 102 creates of 117 and refused the others. The add-on stops short of that limit and defers the creates it holds back, from v0.4.0 on. Updates are never counted or paused.

## How it works

- **A budget.** At most `create_budget` creates are sent in one Pacific day (default 90). The day is read in `America/Los_Angeles`, never at a fixed offset, so the reset stays at midnight across the daylight saving change. A budget of zero sends no creates.
- **A pause.** A per-day refusal from Google pauses every create until the next Pacific midnight. A per-minute refusal does not pause: it is an `error` outcome marked `retryable`.
- **Every create sent counts**, whatever Google answers, since Google counts the request.
- **A deferred create** has the action `deferred` and a reason that names why and when: `Creates paused by Google's daily create limit; creates resume at <time>` or `Daily create budget of <n> spent; creates resume at <time>`. The outcome's `resume_at` carries the same time as a Unix time, and `toArray()` gives it in Pacific time. A deferred create is not settled, so the next save of the entry goes again.
- **A dry run** never reaches the quota. It reports `create` as it would, whatever the budget.

## Keep the count between runs

By default the quota is kept in memory for the one run, so a pause or a count is forgotten when the run ends. A site that runs the sync more than once a day supplies its own `CreateQuotaStore`, such as one backed by its cache, and passes a `CreateQuota` to `Sync`:

```php
use UAMSWeb\Aether\People\ListingsManagement\BusinessProfile\CreateQuota;

$quota = new CreateQuota($store, $config['create_budget']);
$sync = new Sync($client, $silos, $index, $logger, quota: $quota);
```

`$store` implements `pausedUntil()`, `pauseUntil()`, `created()` and `recordCreate()`. Runs can overlap, so `recordCreate()` should add one atomically.

## Draining a backlog

A silo with more new listings than the budget creates `create_budget` of them on the first day and defers the rest. Each following day continues, so a backlog of 400 at the default takes five days. Nothing needs to be done by hand: the deferred creates are retried whenever their entries are synced again.

## Reference

- [Configuration](Configuration.md): `create_budget`.
- [Outcomes and actions](Outcomes-and-actions.md): the `deferred` action.

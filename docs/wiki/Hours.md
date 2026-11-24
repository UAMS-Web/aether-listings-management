Written against v0.4.1.

A location listing, and each provider listing at that location, carries the location's regular hours and special hours. Both are written from the entry's `regular_hours` and `special_hours` fields, which the add-on reads the way the site reads them for a visitor, so a listing and the page cannot disagree about one stored value.

Special hours are written from v0.3.0 on.

## When no hours are written

A location states no hours of either kind, and the sync leaves whatever hours its listings already have, when:

- the variable-hours toggle (`hours_variable`) is on, or
- no day is open in its regular hours.

Business Profile refuses special hours on a listing without regular hours, so special hours are stated only beside regular hours. A location that states regular hours always states its special hours, none included, so a closure deleted from the entry is cleared from the listing.

## Regular hours

Each rule of `regular_hours` names days and a status.

- Only in-person rules count. A rule whose service mode is `telemedicine` is skipped, because a listing's hours describe the premises. An absent or unknown service mode is in person.
- Where two rules name the same day, the later rule wins, so an editor can add a Daily rule and then a Custom rule for the day that differs.
- `closed` makes its days closed. A closed day has no period, which is how Business Profile says a day is closed.
- `open_24h` is one period from 00:00 to 24:00.
- Any other status, or none, is open during the rule's time spans. A span needs an opening and a closing time as `HH:MM` (seconds are ignored). A span without both is dropped, and an open rule left with no span changes no day.
- A span that closes at 00:00 closes at the end of its day (24:00), so 00:00 to 00:00 is the whole day. A span that closes earlier than it opens, or at the minute it opens, closes the next day.
- A rule or span marked as not enabled is skipped.

## Special hours

Rows of `special_hours` are expected already merged with the system-wide closures the location honors, so the add-on reads rows only.

- A `holiday` row's rules each name a `date` and an optional `through`.
- A `temporary_change` row's weekly rules apply on their days from `valid_from` to `valid_through`, or with no end when that is blank.
- Business Profile takes one date per period, so every rule is expanded day by day.
- Only dates from today (in `America/Chicago`) through the 89 days after it are written, a window of 90 days. An earlier date is never written, and a later date is written by a later run.
- On one date, a holiday rule beats a temporary change, and a later rule beats an earlier one. A date no rule names has no period, so its regular hours stand.
- Status and spans are read as for regular hours, and telemedicine rules are skipped.
- A closed date is written as `closed: true`.
- A period must be under 24 hours. Open all day is written as 00:00 to 23:59. A span past midnight closes the next day no later than 11:59. A span that closes the minute it opens is read as running past midnight, as regular hours read it, and closes a minute earlier the next day, by 11:59.

## Hand-kept hours

When a write replaces special hours already on the listing for dates the content does not state, the outcome's reason ends with `; replaces the listing's special hours on <dates>`. Read it in a dry run before a silo goes live. See [Going live for a brand](Going-live-for-a-brand.md).

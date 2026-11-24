Written against v0.4.1.

A run decides one action for each item it is given, in the order it is given, and returns one outcome per item. The site records each outcome in its activity log. This page lists the actions, how a decision is made, and what an outcome carries.

## How an item is decided

The sync looks at an item in this order and stops at the first that applies:

1. The content excludes it: `skip`, reason `Excluded from Google Business Profile by the content`. An excluded item is never looked up, so an existing listing is left alone.
2. The item's brand has no silo: `skip`, reason `No silo for this location's brand`.
3. The silo is off: `skip`, reason `Silo <name> is off`.
4. The silo has no account group for the item's kind: `skip`, reason `Silo <name> has no <kind> account group`.
5. The store code is longer than 64 characters (counted as UTF-8 characters): `review`. Nothing is sent. Since v0.4.0.
6. The store code is looked up among every listing the credentials can see:
   - No listing has it: the item is a create (see below).
   - It is on two or more listings: `review`, reason `Store code is on <n> listings`.
   - It is on one listing in another account group than the silo keeps that kind in: `review`. The reason says whether the group is the silo's other group, another silo's group, or a group no silo owns (`parked`).
   - It is on one listing in the right group: the item is an update.

The sync writes only into the account group its silo keeps that kind in. It never deletes anything, never creates a listing whose store code exists anywhere, and never replaces or removes a photo.

## Actions

| Action | Meaning | Settled |
| --- | --- | --- |
| `skip` | Nothing is done: the item is excluded, has no silo, its silo is off or lacks the group, or a create lacks a required field. | Yes |
| `noop` | The listing already matches the content. | Yes |
| `update` | The listing's fields, attributes or photos differ and are written (or in a dry run would be). | Once written |
| `create` | No listing has the store code, so one is created (or in a dry run would be). | Once written |
| `review` | A person has to look: the store code is too long, is on two listings, or is on a listing in a group this item may not write. | No |
| `error` | A call to Google failed. | No |
| `deferred` | A create waits because Google refused one for the day or the daily budget is spent. Since v0.4.0. | No |

An outcome is settled when it is final for the content it was decided from. A review depends on Google's state, an error may clear, a dry run wrote nothing, and a deferred create goes again once its resume time comes, so the next save of the entry runs it again.

## Updates

An update compares only the fields the sync owns: `title`, `storefrontAddress`, `latlng`, `phoneNumbers`, `websiteUri`, `categories`, `profile`, `regularHours` and `specialHours`. A field the content does not state is never compared, so the sync cannot clear it. Photos, posts and questions belong to people and are never touched, except that missing photos are added.

Values are compared after normalization, so a formatting difference is not a change:

- Phone numbers compare by digits, without the United States country code (`+15016868149` and `(501) 686-8149` are the same).
- Coordinates compare to five places.
- Categories compare by resource name, the additional ones in any order.
- Hours compare as periods in any order, and special hours only from today on.

Business Profile attributes are read from Google only when the content states some, so a provider item costs no extra call. Only true or false attributes are compared. An attribute the content gives no answer for is left out, so an attribute is never turned off for want of an answer.

When a write replaces special hours already on the listing for dates the content does not state, the reason ends with `; replaces the listing's special hours on <dates>`, so hours kept by hand are seen before they go.

A photo goes only when its address has not been sent to that listing before. An unchanged image makes no call, and a changed image, being a new address, is sent. See [Photos](Photos.md).

## Creates

A create needs `title`, `storefrontAddress` and `categories`. An item missing any of them is skipped with the reason `Cannot create, missing <fields>`. A new listing gets every photo, and its attributes are set right after it exists. If setting the attributes fails, the failure is reported with the listing already created, and the next run sets them as an update.

Creates stop at the daily budget and pause after a per-day refusal from Google. See [Living with the daily create limit](Living-with-the-daily-create-limit.md).

## Failures

A failed call is an `error` outcome whose reason is the HTTP status and Google's own message. No credential or token appears in it. Only a rate limit (HTTP 429, or 503) is marked `retryable`. When a photo is refused, the other photos still go and the first refusal is reported with the photo's address; a rate limit ends the photo sending at once. A refused photo is not recorded as sent, so the next run sends it again.

## What an outcome carries

`Outcome::toArray()` returns plain data for a log entry:

| Key | Content |
| --- | --- |
| `store_code`, `kind`, `silo` | The item. `kind` is `provider` or `location`. |
| `mode` | The mode the silo ran in, `off` when the item never reached a silo. |
| `action`, `reason` | What was decided, and why in words for a person. |
| `fields`, `attributes` | The fields and attributes written, or that a dry run would write. |
| `unresolved_categories` | Category names Google has no category for. They are dropped from the listing. |
| `listing_name` | The matched or created listing's resource name, when there is one. |
| `retryable` | Whether a failure was a rate limit that a later attempt can clear. |
| `photos` | The addresses of the photos the run set out to send, or that a dry run would send. |
| `photos_sent` | The addresses Google accepted in this run. The site records them against the listing and passes them back on the next run with `SyncItem::withPhotosSent()`. |
| `resume_at` | For a deferred create, when it may go again, as an ISO 8601 time in Pacific time. |

The run's logger receives every outcome except the skip of an item whose silo is off, since that says nothing the settings do not.

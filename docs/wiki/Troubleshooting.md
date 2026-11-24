Written against v0.4.1.

One entry per outcome reason, as it appears in the activity log: the message, its cause, and the fix. Each reason is the text of an outcome's `reason`.

- [A live silo only reports a dry run](#a-live-silo-only-reports-a-dry-run)
- [Silo is off](#silo-is-off)
- [No silo for this location's brand](#no-silo-for-this-locations-brand)
- [Silo has no account group](#silo-has-no-account-group)
- [Excluded from Google Business Profile by the content](#excluded-from-google-business-profile-by-the-content)
- [Cannot create, missing fields](#cannot-create-missing-fields)
- [Store code is over the 64 characters Google allows](#store-code-is-over-the-64-characters-google-allows)
- [Store code is on two or more listings](#store-code-is-on-two-or-more-listings)
- [Listing is in another account group](#listing-is-in-another-account-group)
- [Store code is on a listing Google created without a name](#store-code-is-on-a-listing-google-created-without-a-name)
- [Creates are deferred](#creates-are-deferred)
- [A listing's hours are never written](#a-listings-hours-are-never-written)
- [A category is missing from a listing](#a-category-is-missing-from-a-listing)
- [A photo is refused](#a-photo-is-refused)
- [A call to Google failed](#a-call-to-google-failed)

## A live silo only reports a dry run

The silo's mode is `live` and the log holds `Dry run, no write made` or `Dry run, no listing created`, with `mode` set to `dry-run`.

**Cause:** the run is not on the production host. A live silo runs as a dry run anywhere but the host in `production_host`, and an empty `production_host` means no host is production.

**Fix:** set `BUSINESS_PROFILE_PRODUCTION_HOST` on the production site to its host name. The comparison ignores letter case but not the host's spelling. A staging site is meant to stay a dry run.

## Silo is off

`Silo <name> is off`

**Cause:** the silo's mode is `off`, is missing from `modes`, or is a value other than `off`, `dry-run` and `live`. The sync does not log this skip for a silo that is off by configuration.

**Fix:** set the silo's mode in `modes`. See [Configuration](Configuration.md#modes).

## No silo for this location's brand

`No silo for this location's brand`

**Cause:** no silo in `silos` has the location's brand. A location whose brand toggle is on with no brand chosen has no brand, and is never synced.

**Fix:** add a silo whose `brand` is the brand's slug, or `null` for locations whose brand toggle is off, or choose a brand on the location.

## Silo has no account group

`Silo <name> has no provider account group` or `Silo <name> has no location account group`

**Cause:** the silo's `accounts` lacks that kind's group.

**Fix:** add `provider` or `location` under the silo's `accounts`. `Client::accountGroups()` lists the groups the credentials manage.

## Excluded from Google Business Profile by the content

`Excluded from Google Business Profile by the content`

**Cause:** the entry's exclude toggle is on, a child location's parent has it on, or a provider's location has the exclude-providers toggle on. An excluded item is never looked up, so its existing listing is left alone and not removed.

**Fix:** turn the toggle off on the entry to sync it. To remove a listing, remove it in Google.

## Cannot create, missing fields

`Cannot create, missing <fields>`

**Cause:** no listing has the store code, and the content lacks a field a new listing cannot be created without: `title`, `storefrontAddress` or `categories`. The address is sent only with a street line, city, state and postal code.

**Fix:** fill the missing fields on the entry. See [Listing fields](Listing-fields.md).

## Store code is over the 64 characters Google allows

`Store code <code> is <n> characters, over the 64 Google allows`

**Cause:** Google refuses a store code longer than 64 characters. The sync sends nothing for it and the action is `review`. Since v0.4.0.

**Fix:** shorten the slug of the entry (or its parent). A store code is the slug, a parent and child slug joined by `_`, or a person and location slug joined by `_`. See [Store codes](Store-codes.md).

## Store code is on two or more listings

`Store code is on <n> listings`

**Cause:** the same store code is carried by more than one listing across the account groups the credentials can see, so the sync cannot tell which is meant.

**Fix:** remove or recode the duplicates in Google. The next run then finds one.

## Listing is in another account group

`Listing is in this silo's other group (accounts/...)`, `Listing is in another silo's group (<silo>)` or `Listing is parked in accounts/...`

**Cause:** the listing with the store code is in a group this item may not write: the same silo's group for the other kind, another silo's group, or a group no silo owns. The sync never writes outside the group its silo keeps that kind in.

**Fix:** move the listing to the right group in Google, or correct the silo's `accounts`.

## Store code is on a listing Google created without a name

`Store code is on a listing Google created without a name`

**Cause:** a listing created earlier in the run was returned without a name, so it cannot be addressed, and the sync will not create it again.

**Fix:** run again once Google returns the listing with its name.

## Creates are deferred

`Creates paused by Google's daily create limit; creates resume at <time>` or `Daily create budget of <n> spent; creates resume at <time>`

**Cause:** Google refused a create for the day, or the day's budget is spent. The action is `deferred`. Since v0.4.0.

**Fix:** none needed. The create goes again after the time named, in Pacific time. To change the daily number, set `create_budget`. See [Living with the daily create limit](Living-with-the-daily-create-limit.md).

## A listing's hours are never written

**Cause:** the location's variable-hours toggle is on, or no day is open in its regular hours. The sync then states no hours of either kind and leaves the listing's hours as they are.

**Fix:** turn the variable-hours toggle off and give at least one open day. See [Hours](Hours.md#when-no-hours-are-written).

## A category is missing from a listing

**Cause:** a category on the entry has no Google ID. Its name is in the outcome's `unresolved_categories`, and it is not sent. When it is the primary category, no categories are sent.

**Fix:** give the category term a Google ID.

## A photo is refused

The outcome is an `error` whose reason starts with the photo's address, followed by Google's message.

**Cause:** Google could not fetch or accept the image at that address.

**Fix:** make the address public and a supported image. The refused photo is not recorded as sent, so the next run sends it again.

## A call to Google failed

An `error` outcome whose reason is the HTTP status and Google's message.

**Cause:** the credentials, an account group or the request is wrong, or Google is unavailable. A rate limit (HTTP 429 or 503) has `retryable` true.

**Fix:** for a retryable error, run again. Otherwise read Google's message in the reason. No credential or token appears in it.

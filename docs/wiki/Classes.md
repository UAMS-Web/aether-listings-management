Written against v0.4.1.

Everything the add-on ships is in the `UAMSWeb\Aether\People\ListingsManagement` namespace. The service provider registers the configuration file (merged under `aether-listings-management` and published with the tag `aether-listings-management-config`) and the Control Panel Vite build, whose script is empty. It registers no command, route, tag, permission, listener, Control Panel screen or fieldtype. The classes below, all in `BusinessProfile\`, are called by the site.

Nothing in them reads Laravel's configuration or touches the network by itself: the site passes in the settings, and a `Transport` it binds sends each request.

| Class | What it is for |
| --- | --- |
| `Credentials` | The OAuth client ID, client secret and refresh token. The values are marked sensitive, so a stack trace does not print them. `isComplete()` says whether all three are present. |
| `Transport` | The one way the client reaches the network: `send(method, url, headers, body)` returns an `HttpResponse`. A request that could not be made is status 0 with the reason as the body. The site binds one backed by its HTTP client. |
| `HttpResponse` | A status and a raw body. |
| `Client` | The Google Business Profile client. Signs in with the refresh token, reads account groups, listings, categories and attributes, and writes a listing's fields, a new listing, a listing's attributes and a photo. It never deletes, and never logs or returns a credential. |
| `ApiException` | A failed Google call. The message carries the HTTP status and Google's message only. |
| `RateLimitedException` | A subclass of `ApiException` for HTTP 429 or 503. Its `daily` property is true when Google refused creates for the rest of the day. |
| `Silos` | The silos and their modes, read from configuration with `Silos::fromConfig()`. Answers which silo serves a brand, which account group holds a kind, which silo owns a group, and the mode a silo actually runs in. |
| `Mode` | `off`, `dry-run` or `live`. `Mode::effective()` caps a live silo at a dry run off production, and `Mode::isProductionHost()` compares the current host with `production_host`. |
| `Kind` | `provider` or `location`. |
| `EntryData` | One entry's values as plain data for the mapper: slug, title, absolute URL, field values and a child location's parent. |
| `FieldMapper` | Turns entries into `SyncItem`s. See [Listing fields](Listing-fields.md). |
| `SyncItem` | One listing the content asks for: store code, kind, silo, whether it is excluded, the fields and attributes it states, its photos and the photo addresses already sent. |
| `Photo` | One photo: where it goes (`LOGO`, `COVER` or `ADDITIONAL`) and its public address. |
| `ListingIndex` | Every listing the credentials can see, by store code, with the group each was found in. `ListingIndex::fromClient()` reads them through the client. |
| `ListingDiff` | Which fields and attributes differ from the content, after normalization. |
| `RegularHours`, `SpecialHours` | Turn the hours rules into Business Profile periods. See [Hours](Hours.md). |
| `Sync` | Decides and, where the silo is live, carries out one action per item. See [Outcomes and actions](Outcomes-and-actions.md). |
| `Action`, `Outcome` | What was decided, and the record of it for the activity log. |
| `CreateQuota` | Google's daily create limit and the budget kept below it. |
| `CreateQuotaStore`, `InMemoryCreateQuotaStore` | Where the quota keeps its pause and counts. The in-memory store lasts one run; a site supplies one that outlives a request. |
| `StoreCodeComparison` | Lists the store codes the content and the existing listings do not share. See [Store codes](Store-codes.md). |
| `Values` | Narrowing for values read from decoded JSON and entry data. Internal. |

## What the site supplies

- The entries, read into `EntryData`, and the decision of when a run happens (on a save, or by a queue or command of the site's).
- A `Transport`.
- A `CreateQuotaStore` that outlives a request, such as the cache.
- The record of photo addresses already sent, kept from each outcome's `photos_sent`.
- The activity log, fed by the logger closure passed to `Sync`.

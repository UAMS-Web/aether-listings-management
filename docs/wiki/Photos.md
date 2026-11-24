Written against v0.4.1.

A listing is sent photos by address: Google fetches each one from its public address. Photos are sent from v0.3.0 on.

## What each listing gets

Photos go in this order.

| Listing | Photos |
| --- | --- |
| Location | The logo, the cover (the first address of the cover image field, `featured_image` by default), then the other photos (every address of `gallery_image`, in order). |
| Provider | The logo, the wide portrait (`portrait_wide`, first address) as the cover, and the location's wayfinding photo. The wayfinding photo is the first other photo when the provider has a wide portrait, and the cover when not. A location with no wayfinding photo leaves the provider's photos as they are. |

The logo is the address in `mapping.logo`. Empty sends no logo.

An image field is read as its public address: a string, a list of strings, or an array, or a list of arrays, carrying the address under `url`. Blank addresses are left out.

## Child locations

A child location uses its parent's image of each kind unless it overrides that kind. A child overrides a kind only when both the general toggle (`photos_override`) and that kind's toggle are on. An override with no image removes the parent's.

## Only added, never replaced

- A photo is identified by its address. The sync records an address once it is sent, so an unchanged image is never sent again, and a changed image, being a new address, is.
- A photo is only ever added. Nothing Google already holds is replaced or deleted.
- The same address under two categories is sent once, under the first.
- A new listing gets every photo. An existing listing gets the photos whose address was not sent to that listing before.
- The record belongs to the listing it was made for, so a different listing for the same content starts with none and is sent every photo.

## The record the site keeps

The mapper reads content only. The site attaches the record it keeps from earlier outcomes' `photos_sent` to each item with `SyncItem::withPhotosSent()`, a map of listing resource name to the addresses already sent, before the item is synced. Without it every run sends every photo again.

## Failures

One refused photo does not stop the ones after it. The first refusal is reported, named by its address, as an `error` outcome. A rate limit ends the sending at once, since every later photo would be refused for the same reason, and is the failure reported so the run is retried. A refused photo is not recorded as sent, so the next run sends it again.

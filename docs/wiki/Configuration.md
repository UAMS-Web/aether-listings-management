Written against v0.4.1.

The add-on's settings live in one file, `config/aether-listings-management.php`. Statamic merges it into the application's configuration under the handle `aether-listings-management`, so `config('aether-listings-management.silos')` reads a key. To change a setting, publish the file to the site:

```bash
php artisan vendor:publish --tag=aether-listings-management-config
```

No credential or account group identifier belongs in the file shipped with the add-on: credentials come from the environment, and the account groups are set in the site's own copy. Every default leaves the sync doing nothing.

## Keys

| Key | Values | Default | Effect |
| --- | --- | --- | --- |
| `credentials.client_id` | text | `GOOGLE_BUSINESS_PROFILE_CLIENT_ID`, else empty | The OAuth client the sync signs in with. |
| `credentials.client_secret` | text | `GOOGLE_BUSINESS_PROFILE_CLIENT_SECRET`, else empty | The OAuth client secret. |
| `credentials.refresh_token` | text | `GOOGLE_BUSINESS_PROFILE_REFRESH_TOKEN`, else empty | A refresh token granted for the Business Profile scope. With any of the three missing, nothing is synced. |
| `production_host` | host name | `BUSINESS_PROFILE_PRODUCTION_HOST`, else empty | The host a live silo writes from. Anywhere else a live silo runs as a dry run. Empty means no host is production, so nothing is ever written. Compared without letter case. |
| `silos` | map of silo name to silo | empty | One brand's pair of Google account groups each. See [Silos](#silos). A location whose brand has no silo is never synced. |
| `modes` | map of silo name to `off`, `dry-run` or `live` | empty | Each silo's mode. A silo with no mode, or a value that is not one of the three, is off. |
| `create_budget` | whole number | `90` | The most listings created in one Pacific day. Updates do not count. See [Living with the daily create limit](Living-with-the-daily-create-limit.md). |
| `mapping` | map | see [Mapping](#mapping) | Which entry field each part of a listing is read from. |

## Silos

A silo is one brand's pair of Google account groups: one for provider listings and one for location listings. A silo is the only writer to those two groups.

```php
'silos' => [
    'uams' => [
        'brand' => null,
        'accounts' => [
            'provider' => 'accounts/111',
            'location' => 'accounts/222',
        ],
    ],
],
'modes' => [
    'uams' => 'dry-run',
],
```

- `brand` is the slug of the brand organization a location chooses, or `null` for the default brand, which is what a location whose brand toggle is off carries.
- A silo missing one of its account groups is kept, and the run reports the missing group (`Silo uams has no provider account group`) instead of acting as if the silo did not exist.
- The first silo whose `brand` matches serves that brand.

## Modes

| Mode | What a run does |
| --- | --- |
| `off` | Nothing. The silo's skips are not logged. |
| `dry-run` | Decides and logs every action, and writes nothing. |
| `live` | Writes, on the production host only. Anywhere else the silo runs as `dry-run`. |

## Mapping

`mapping` names the entry field each value is read from. Set a handle to `null` to leave that part out. Where a key lists several handles, they are read in order and the first with text is used.

### `mapping.location`

| Key | Default handle | Used for |
| --- | --- | --- |
| `street`, `facility`, `facility_level`, `suite` | `resolved_street_address`, `resolved_facility_name`, `resolved_facility_level`, `resolved_suite` | The address lines, in that order. |
| `city`, `state`, `postal_code` | `resolved_city`, `resolved_state`, `resolved_postal_code` | The rest of the address. |
| `map` | `resolved_map` | The coordinates, a value with `lat` and `lng`. |
| `phone` | `phone` | The primary phone number. |
| `categories` | `google_business_profile_categories` | The categories. |
| `description` | `short_desc`, then `about` | The description. |
| `prefix` | `google_business_profile_prefix_query` | The toggle that prefixes the title with `UAMS Health - `. |
| `exclude` | `google_business_profile_exclude_query` | The toggle that excludes the location. |
| `exclude_providers` | `google_business_profile_exclude_providers_query` | The toggle that excludes the location's providers. |
| `brand_query`, `brand` | `brand_organization_query`, `brand_organization` | The brand toggle and the brand chosen. |
| `regular_hours`, `hours_variable` | `regular_hours`, `hours_variable_query` | See [Hours](Hours.md). |
| `special_hours` | `special_hours` | See [Hours](Hours.md). |
| `cover_photo`, `other_photos`, `wayfinding_photo` | `featured_image`, `gallery_image`, `wayfinding_image` | See [Photos](Photos.md). |
| `photos_override`, `cover_photo_override`, `other_photos_override`, `wayfinding_photo_override` | `image_parent_override_query`, `featured_image_parent_override_query`, `gallery_image_parent_override_query`, `wayfinding_image_parent_override_query` | The toggles that let a child location use its own image instead of its parent's. |
| `attributes` | four radio fields: `google_business_profile_has_wheelchair_accessible_entrance`, `..._has_wheelchair_accessible_restroom`, `..._has_restroom` and `..._requires_appointments` | Business Profile attributes `has_wheelchair_accessible_entrance`, `has_wheelchair_accessible_restroom`, `has_restroom` and `requires_appointments`, each answered true, false or not applicable. |
| `attributes_when_on` | `telemed_query` mapped to `has_video_visits` | A toggle that sets an attribute true when on and gives no answer when off. |

### `mapping.provider`

| Key | Default handle | Used for |
| --- | --- | --- |
| `name` | `full_name_with_credentials` | The name in the listing's title. |
| `short_name` | `display_name_short_with_prefix` | The name in the fallback description. |
| `clinical_title` | `clinical_title` | The title in the fallback description. |
| `categories` | `google_business_profile_clinical_category` | The categories. |
| `description` | `bio_clinical_short`, then `bio_clinical` | The description. |
| `exclude` | `google_business_profile_clinical_exclude_query` | The toggle that excludes the provider. |
| `cover_photo` | `portrait_wide` | The cover photo. |

### Other mapping keys

| Key | Default | Effect |
| --- | --- | --- |
| `logo` | empty | The public address of the logo every listing gets. Empty sends no logo. |
| `default_categories` | `provider` is `doctor`, `location` is `medical_clinic` | The Google category ID a listing gets when its entry chooses none. |
| `tracking` | `utm_source` is `google`, `utm_medium` is `gmb`, `utm_campaign` is `clinical`, `utm_term` is `{kind}`, `utm_content` is `profile`, `utm_specs` is `{store_code}` | The query added to each listing's website. `{kind}` becomes `provider` or `location`, and `{store_code}` the listing's store code. |

See [Listing fields](Listing-fields.md) for what each part does to a listing.

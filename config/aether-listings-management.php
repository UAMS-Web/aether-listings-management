<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Add-on configuration
|--------------------------------------------------------------------------
|
| Statamic merges this file into the application's config under the add-on's
| handle, so `config('aether-listings-management.silos')` reads it. The host
| publishes it to its own `config/` directory with:
|
|     php artisan vendor:publish --tag=aether-listings-management-config
|
| No credential or account group identifier belongs in this file: each comes
| from the host's environment or its published copy, and every default here
| leaves the sync doing nothing.
|
*/

return [

    /*
    | The OAuth client and refresh token the sync signs in to Google with. All
    | three come from the environment; with any one missing, nothing is synced.
    */
    'credentials' => [
        'client_id' => env('GOOGLE_BUSINESS_PROFILE_CLIENT_ID', ''),
        'client_secret' => env('GOOGLE_BUSINESS_PROFILE_CLIENT_SECRET', ''),
        'refresh_token' => env('GOOGLE_BUSINESS_PROFILE_REFRESH_TOKEN', ''),
    ],

    /*
    | The host a live silo writes from. Anywhere else, a live silo runs as a dry
    | run. Empty means no host is production, so nothing is ever written.
    */
    'production_host' => env('BUSINESS_PROFILE_PRODUCTION_HOST', ''),

    /*
    | The silos: one brand's pair of Google account groups each, one for
    | provider listings and one for location listings. `brand` is the slug of
    | the brand organization a location chooses, or null for the default brand
    | (a location whose brand toggle is off). A location whose brand has no silo
    | is never synced. The account groups (`accounts/123`) are set in the host.
    |
    |     'uams' => ['brand' => null, 'accounts' => ['provider' => 'accounts/...', 'location' => 'accounts/...']],
    */
    'silos' => [],

    /*
    | Each silo's mode: `off`, `dry-run` (decide and log, write nothing) or
    | `live`. A silo with no mode here is off.
    */
    'modes' => [],

    /*
    | How entries are read. Each value is a field handle on the entry; set one to
    | null to leave that field out. A location's address fields are the resolved
    | ones, so a child location inherits its parent's address unless it
    | overrides it.
    */
    'mapping' => [

        'location' => [
            'street' => 'resolved_street_address',
            'facility' => 'resolved_facility_name',
            'facility_level' => 'resolved_facility_level',
            'suite' => 'resolved_suite',
            'city' => 'resolved_city',
            'state' => 'resolved_state',
            'postal_code' => 'resolved_postal_code',
            'map' => 'resolved_map',
            'phone' => 'phone',
            'categories' => 'google_business_profile_categories',
            'description' => ['short_desc', 'about'],
            'prefix' => 'google_business_profile_prefix_query',
            'exclude' => 'google_business_profile_exclude_query',
            'exclude_providers' => 'google_business_profile_exclude_providers_query',
            'brand_query' => 'brand_organization_query',
            'brand' => 'brand_organization',
            // The weekly hours rules, and the toggle marking hours that vary (which writes none)
            'regular_hours' => 'regular_hours',
            'hours_variable' => 'hours_variable_query',
            // Holiday hours and temporary changes, which the host passes already merged with the system-wide closures the location honors
            'special_hours' => 'special_hours',
            // Images, each read as its public address: the cover, the other photos, and the
            // wayfinding photo a provider's listing at the location gets
            'cover_photo' => 'featured_image',
            'other_photos' => 'gallery_image',
            'wayfinding_photo' => 'wayfinding_image',
            // A child location uses its parent's image of a kind unless both of these are on
            'photos_override' => 'image_parent_override_query',
            'cover_photo_override' => 'featured_image_parent_override_query',
            'other_photos_override' => 'gallery_image_parent_override_query',
            'wayfinding_photo_override' => 'wayfinding_image_parent_override_query',
            // Radio fields answering true, false or not applicable => Business Profile attribute
            'attributes' => [
                'google_business_profile_has_wheelchair_accessible_entrance' => 'has_wheelchair_accessible_entrance',
                'google_business_profile_has_wheelchair_accessible_restroom' => 'has_wheelchair_accessible_restroom',
                'google_business_profile_has_restroom' => 'has_restroom',
                'google_business_profile_requires_appointments' => 'requires_appointments',
            ],
            // Toggles that set an attribute true when on, and give no answer when off
            'attributes_when_on' => [
                'telemed_query' => 'has_video_visits',
            ],
        ],

        'provider' => [
            'name' => 'full_name_with_credentials',
            'short_name' => 'display_name_short_with_prefix',
            'clinical_title' => 'clinical_title',
            'categories' => 'google_business_profile_clinical_category',
            'description' => ['bio_clinical_short', 'bio_clinical'],
            'exclude' => 'google_business_profile_clinical_exclude_query',
            // The wide portrait, read as its public address, sent as the listing's cover
            'cover_photo' => 'portrait_wide',
        ],
        // The public address of the logo every listing gets; empty sends no logo
        'logo' => '',

        // The category a listing gets when its entry chooses none, by Google category ID
        'default_categories' => [
            'provider' => 'doctor',
            'location' => 'medical_clinic',
        ],

        // The query added to each listing's website; {kind} and {store_code} are filled in
        'tracking' => [
            'utm_source' => 'google',
            'utm_medium' => 'gmb',
            'utm_campaign' => 'clinical',
            'utm_term' => '{kind}',
            'utm_content' => 'profile',
            'utm_specs' => '{store_code}',
        ],

    ],

];

<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\People\ListingsManagement\BusinessProfile;

/**
 * Turns `people` and `clinical_locations` entries into the listings they ask for.
 *
 * Every value is read through a configured handle, so the mapper depends on no
 * class of the site's and the handles can follow the content model. The rules
 * are the WordPress sync's (UAMS-Web/UAMSWP-Find-a-Doc#915, #916), so the
 * listings it already keeps up to date get the same store codes and fields:
 *
 * - A location's store code is its slug, or `{parent_slug}_{child_slug}` for a
 *   child. A provider's is `{person_slug}_{location_slug}`, with the location's
 *   own slug even when it is a child.
 * - A provider gets one listing per location it practices at, titled
 *   `UAMS Health - <name> - <location>`.
 * - A location's title prefix, description and attributes are read from its
 *   parent when it has one, as the WordPress sync read them.
 * - A location's regular and special hours are its own, and a provider's
 *   listing carries the hours of the location it is at. A location marked as
 *   having variable hours, or with no open day, states no hours of either
 *   kind, so the sync leaves whatever hours its listings already have. A
 *   location that states regular hours always states its special hours, none
 *   included, so a closure deleted from the entry is cleared from the listing.
 */
final readonly class FieldMapper
{
    /** The longest description sent, in characters, the ellipsis included. */
    public const int DESCRIPTION_WIDTH = 747;

    /** The most additional categories a listing is given, after its primary category. */
    public const int MAX_ADDITIONAL_CATEGORIES = 9;

    /**
     * @param  array<array-key, mixed>  $config  The add-on's `mapping` setting: `location` and `provider` handles, `default_categories` and `tracking`.
     * @param  ?string  $today  Today, `YYYY-MM-DD`, the first date special hours are written for; by default today in SpecialHours::TIMEZONE.
     */
    public function __construct(private array $config, private ?string $today = null) {}

    /**
     * A location's store code: its slug, or its parent's slug and its own joined by `_`.
     *
     * @param  EntryData  $location  The location.
     */
    public static function locationStoreCode(EntryData $location): string
    {
        return ($location->parent instanceof EntryData ? $location->parent->slug.'_' : '').$location->slug;
    }

    /**
     * A provider's store code at one location: the person's slug and the location's own slug joined by `_`.
     *
     * @param  EntryData  $person  The provider.
     * @param  EntryData  $location  One of the provider's locations.
     */
    public static function providerStoreCode(EntryData $person, EntryData $location): string
    {
        return $person->slug.'_'.$location->slug;
    }

    /**
     * The title of a provider's listing at one location.
     *
     * A provider at several locations has one listing per location, so the title
     * names the location; otherwise Google could not tell the listings apart.
     *
     * @param  string  $name  The provider's name.
     * @param  string  $location  The location's title, or empty.
     */
    public static function providerTitle(string $name, string $location): string
    {
        $title = 'UAMS Health - '.trim($name);
        $location = trim($location);

        return $location === '' ? $title : $title.' - '.$location;
    }

    /**
     * A location name without the place it is "in", "at" or "on", for an address line.
     *
     * Everything from the first ` in`, ` at` or ` on` (each with or without
     * ` the`, in any letter case) on goes. A name with no joiner, or nothing
     * before or after one, is left as it is.
     *
     * @param  string  $name  A location's name.
     */
    public static function nameWithoutPlace(string $name): string
    {
        $name = trim($name);

        return preg_match('/^(.*?\S)\s+(?:in|at|on)(?:\s+the)?\s+\S/iu', $name, $matches) === 1 ? $matches[1] : $name;
    }

    /**
     * The listing a location asks for.
     *
     * @param  EntryData  $location  The location, with its parent attached when it is a child.
     * @param  Silos  $silos  The silos, to resolve the location's brand.
     */
    public function locationItem(EntryData $location, Silos $silos): SyncItem
    {
        $handles = $this->handles('location');
        $source = $location->parentOrSelf();

        // Title, with the prefix the parent (or the location itself) asks for
        $fields = [];
        $title = self::decode($location->title);
        if ($title !== '') {
            $fields['title'] = ($source->isOn($handles['prefix'] ?? null) ? 'UAMS Health - ' : '').$title;
        }

        // Street, then building, floor and suite
        $lines = [$location->text($handles['street'] ?? null), $location->text($handles['facility'] ?? null), $location->text($handles['facility_level'] ?? null), $location->text($handles['suite'] ?? null)];
        $fields += $this->placeFields($location, $lines, $handles);

        $description = $this->description($source, $handles['description'] ?? null);
        if ($description !== '') {
            $fields['profile'] = ['description' => $description];
        }

        [$categories, $unresolved] = $this->categories($location->get($handles['categories'] ?? null), Kind::Location);

        return new SyncItem(
            self::locationStoreCode($location),
            Kind::Location,
            $this->silo($location, $silos),
            $location->isOn($handles['exclude'] ?? null) || ($location->parent instanceof EntryData && $location->parent->isOn($handles['exclude'] ?? null)),
            $fields + $categories + $this->website($location, self::locationStoreCode($location), Kind::Location) + $this->hours($location),
            $this->attributes($source),
            $unresolved,
        );
    }

    /**
     * The listings a provider asks for: one per location it practices at.
     *
     * @param  EntryData  $person  The provider.
     * @param  list<EntryData>  $locations  The provider's published locations, each with its parent attached when it is a child.
     * @param  Silos  $silos  The silos, to resolve each location's brand.
     * @return list<SyncItem>
     */
    public function providerItems(EntryData $person, array $locations, Silos $silos): array
    {
        $handles = $this->handles('provider');
        $locationHandles = $this->handles('location');
        $name = $person->text($handles['name'] ?? null);
        [$categories, $unresolved] = $this->categories($person->get($handles['categories'] ?? null), Kind::Provider);
        $items = [];

        foreach ($locations as $location) {
            $storeCode = self::providerStoreCode($person, $location);
            $fields = [];

            if ($name !== '') {
                $fields['title'] = self::providerTitle($name, self::decode($location->title));
            }

            // Street, building, then the location's name (a child's parent first) without its place
            $lines = [$location->text($locationHandles['street'] ?? null), $location->text($locationHandles['facility'] ?? null)];
            if ($location->parent instanceof EntryData) {
                $lines[] = self::nameWithoutPlace(self::decode($location->parent->title));
            }

            $lines[] = self::nameWithoutPlace(self::decode($location->title));
            $fields += $this->placeFields($location, $lines, $locationHandles);

            $description = $this->description($person, $handles['description'] ?? null);
            $fields['profile'] = ['description' => $description !== '' ? $description : $this->providerFallbackDescription($person, self::decode($location->title))];

            $items[] = new SyncItem(
                $storeCode,
                Kind::Provider,
                $this->silo($location, $silos),
                $person->isOn($handles['exclude'] ?? null) || $location->isOn($locationHandles['exclude_providers'] ?? null),
                $fields + $categories + $this->website($person, $storeCode, Kind::Provider) + $this->hours($location),
                [],
                $unresolved,
            );
        }

        return $items;
    }

    /**
     * The silo a location's brand belongs to.
     *
     * With the brand toggle off the location carries the default brand; with it
     * on and no brand chosen, it has none and is never synced.
     *
     * @param  EntryData  $location  The location, read for its own brand and never its parent's.
     * @param  Silos  $silos  The silos.
     */
    public function silo(EntryData $location, Silos $silos): ?string
    {
        $handles = $this->handles('location');

        if (! $location->isOn($handles['brand_query'] ?? null)) {
            return $silos->forBrand(null);
        }

        $brand = self::slugOf($location->get($handles['brand'] ?? null));

        return $brand === '' ? null : $silos->forBrand($brand);
    }

    /**
     * The address, coordinates and phone of a location.
     *
     * The address is sent only when it has a line, a city, a state and a postal code.
     *
     * @param  EntryData  $location  The location.
     * @param  list<string>  $lines  The address lines, blanks included; blanks are dropped.
     * @param  array<array-key, mixed>  $handles  The location handles.
     * @return array<string, mixed>
     */
    private function placeFields(EntryData $location, array $lines, array $handles): array
    {
        $fields = [];
        $lines = array_values(array_filter($lines, static fn (string $line): bool => $line !== ''));
        $city = $location->text($handles['city'] ?? null);
        $state = $location->text($handles['state'] ?? null);
        $postal = $location->text($handles['postal_code'] ?? null);

        if ($lines !== [] && $city !== '' && $state !== '' && $postal !== '') {
            $fields['storefrontAddress'] = [
                'regionCode' => 'US',
                'languageCode' => 'en',
                'postalCode' => $postal,
                'administrativeArea' => $state,
                'locality' => $city,
                'addressLines' => $lines,
            ];
        }

        // Both coordinates or neither
        $map = Values::arr($location->get($handles['map'] ?? null));
        $lat = $map['lat'] ?? null;
        $lng = $map['lng'] ?? null;
        if (is_numeric($lat) && is_numeric($lng)) {
            $fields['latlng'] = ['latitude' => (float) $lat, 'longitude' => (float) $lng];
        }

        // The location's own phone, never its parent's
        $phone = $location->text($handles['phone'] ?? null);
        if ($phone !== '') {
            $fields['phoneNumbers'] = ['primaryPhone' => $phone];
        }

        return $fields;
    }

    /**
     * A location's regular and special hours fields, or neither when it is marked as having variable hours or no day is open.
     *
     * Business Profile refuses special hours on a listing without regular hours,
     * so special hours are stated only beside them, and then always.
     *
     * @param  EntryData  $location  The location, read for its own hours and never its parent's.
     * @return array<string, mixed>
     */
    private function hours(EntryData $location): array
    {
        $handles = $this->handles('location');
        $regular = $location->isOn($handles['hours_variable'] ?? null) ? null : RegularHours::fromRules($location->get($handles['regular_hours'] ?? null));

        if ($regular === null) {
            return [];
        }

        return [
            'regularHours' => $regular,
            'specialHours' => SpecialHours::fromRows($location->get($handles['special_hours'] ?? null), $this->today ?? SpecialHours::today()),
        ];
    }

    /**
     * The website: the entry's URL with the tracking parameters, naming the listing's kind and store code.
     *
     * @param  EntryData  $entry  The provider or location the website is.
     * @param  string  $storeCode  The listing's store code.
     * @param  Kind  $kind  The listing's kind.
     * @return array<string, string>
     */
    private function website(EntryData $entry, string $storeCode, Kind $kind): array
    {
        if ($entry->url === '') {
            return [];
        }

        $query = [];
        foreach (Values::arr($this->config['tracking'] ?? null) as $key => $value) {
            $query[$key] = strtr(Values::str($value), ['{kind}' => $kind->value, '{store_code}' => $storeCode]);
        }

        $url = rtrim($entry->url, '/').'/';

        return ['websiteUri' => $query === [] ? $url : $url.'?'.http_build_query($query, arg_separator: '&', encoding_type: PHP_QUERY_RFC3986)];
    }

    /**
     * The categories field and the category names Google cannot be given.
     *
     * The first category is the primary one, and the next nine are additional.
     * A category is sent by its Google ID; one without an ID is reported, and when
     * that is the primary one no categories are sent at all, as the WordPress sync
     * did. With no categories chosen, the kind's default category is sent.
     *
     * @param  mixed  $value  The categories field: terms with a `gcid` and a `title`, or bare IDs.
     * @param  Kind  $kind  The listing's kind, for its default category.
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    private function categories(mixed $value, Kind $kind): array
    {
        $terms = is_array($value) ? $value : ($value === null || $value === '' ? [] : [$value]);

        if ($terms === []) {
            $default = Values::str(Values::arr($this->config['default_categories'] ?? null)[$kind->value] ?? null);

            return [$default === '' ? [] : ['categories' => ['primaryCategory' => ['name' => self::categoryName($default)]]], []];
        }

        $names = [];
        $unresolved = [];

        foreach ($terms as $term) {
            $gcid = is_array($term) ? Values::str($term['gcid'] ?? null) : Values::str($term);
            $names[] = $gcid === '' ? '' : self::categoryName($gcid);

            if ($gcid === '') {
                $unresolved[] = is_array($term) ? Values::str($term['title'] ?? $term['slug'] ?? null) : '';
            }
        }

        $unresolved = array_values(array_filter($unresolved, static fn (string $name): bool => $name !== ''));
        $primary = array_shift($names);

        if ($primary === '') {
            return [[], $unresolved];
        }

        $categories = ['primaryCategory' => ['name' => $primary]];
        $additional = array_slice(array_unique(array_filter($names, static fn (string $name): bool => $name !== '' && $name !== $primary)), 0, self::MAX_ADDITIONAL_CATEGORIES);

        if ($additional !== []) {
            $categories['additionalCategories'] = array_map(static fn (string $name): array => ['name' => $name], $additional);
        }

        return [['categories' => $categories], $unresolved];
    }

    /**
     * A category's resource name from its Google ID, with or without the `gcid:` prefix.
     *
     * @param  string  $gcid  The Google category ID, `doctor` or `gcid:doctor`.
     */
    public static function categoryName(string $gcid): string
    {
        $gcid = trim($gcid);

        if (str_starts_with($gcid, 'categories/')) {
            return $gcid;
        }

        return 'categories/'.(str_starts_with($gcid, 'gcid:') ? $gcid : 'gcid:'.$gcid);
    }

    /**
     * The first description field with text, cleaned for Google and cut to its width.
     *
     * @param  EntryData  $entry  The entry to read.
     * @param  mixed  $handles  A handle, or a list of handles in order of preference.
     */
    private function description(EntryData $entry, mixed $handles): string
    {
        foreach (is_array($handles) ? $handles : [$handles] as $handle) {
            $text = self::cleanDescription(self::plainText($entry->get($handle)));

            if ($text !== '') {
                return $text;
            }
        }

        return '';
    }

    /**
     * A title with its HTML entities decoded and its ends trimmed, so `&amp;` is sent as `&`.
     *
     * @param  string  $title  An entry title.
     */
    private static function decode(string $title): string
    {
        return trim(html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /**
     * A text field's value as text: a string as it is, and a rich-text (Bard)
     * value as the text of its nodes, one space between nodes.
     *
     * A rich-text field holds a list of ProseMirror nodes rather than a string, so
     * reading it as a string would find it empty and send the fallback instead.
     *
     * @param  mixed  $value  A text or rich-text field's value.
     */
    public static function plainText(mixed $value): string
    {
        if (! is_array($value)) {
            return Values::str($value);
        }

        // A text node carries its words; any other node, or a list of nodes, carries them in its content
        if (isset($value['text']) && is_string($value['text'])) {
            return $value['text'];
        }

        $parts = array_map(self::plainText(...), array_is_list($value) ? $value : Values::arr($value['content'] ?? null));

        return implode(' ', array_filter($parts, static fn (string $part): bool => $part !== ''));
    }

    /**
     * Text without markup or line breaks, decoded, and cut to the description width with an ellipsis.
     *
     * @param  string  $text  Text that may hold HTML.
     */
    public static function cleanDescription(string $text): string
    {
        $text = trim(str_replace(["\r", "\n"], ' ', strip_tags($text)));

        return trim(html_entity_decode(mb_strimwidth($text, 0, self::DESCRIPTION_WIDTH, '...'), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /**
     * A provider's description when the content gives none.
     *
     * `<name> is a <clinical title> at <location> employed by UAMS Health.`, with
     * `a health care provider` when there is no clinical title.
     *
     * @param  EntryData  $person  The provider.
     * @param  string  $location  The location's title.
     */
    private function providerFallbackDescription(EntryData $person, string $location): string
    {
        $handles = $this->handles('provider');
        $name = $person->text($handles['short_name'] ?? null);
        $name = $name !== '' ? $name : $person->text($handles['name'] ?? null);

        $title = strtolower($person->text($handles['clinical_title'] ?? null));
        $role = $title === '' ? 'a health care provider' : (preg_match('/^[aeiou]/i', $title) === 1 ? 'an ' : 'a ').$title;

        return $name.' is '.$role.($location === '' ? '' : ' at '.$location).' employed by UAMS Health.';
    }

    /**
     * A location's true or false attributes: from its radio fields (true, false, or no answer) and its toggles (on is true, off is no answer).
     *
     * An answer the content does not give is left out, so the sync never turns an attribute off for want of one.
     *
     * @param  EntryData  $location  The location (its parent, for a child).
     * @return array<string, bool>
     */
    private function attributes(EntryData $location): array
    {
        $handles = $this->handles('location');
        $attributes = [];

        foreach (Values::arr($handles['attributes'] ?? null) as $handle => $name) {
            $value = $location->get($handle);
            $answer = is_bool($value) ? ($value ? 'true' : 'false') : Values::str($value);

            if ($answer === 'true') {
                $attributes[Values::str($name)] = true;
            } elseif ($answer === 'false') {
                $attributes[Values::str($name)] = false;
            }
        }

        foreach (Values::arr($handles['attributes_when_on'] ?? null) as $handle => $name) {
            if ($location->isOn($handle)) {
                $attributes[Values::str($name)] = true;
            }
        }

        unset($attributes['']);

        return $attributes;
    }

    /**
     * The configured handles for one kind of entry.
     *
     * @param  string  $kind  `location` or `provider`.
     * @return array<array-key, mixed>
     */
    private function handles(string $kind): array
    {
        return Values::arr($this->config[$kind] ?? null);
    }

    /**
     * A relationship value as a slug: a string as it is, the first of a list, or an array's `slug`.
     *
     * @param  mixed  $value  A relationship value.
     */
    private static function slugOf(mixed $value): string
    {
        if (is_array($value)) {
            $value = array_is_list($value) ? ($value[0] ?? null) : ($value['slug'] ?? null);
        }

        return is_array($value) ? Values::str($value['slug'] ?? null) : trim(Values::str($value));
    }
}

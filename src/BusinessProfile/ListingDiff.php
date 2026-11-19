<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\People\ListingsManagement\BusinessProfile;

/**
 * Which of a listing's fields and attributes differ from what the content says.
 *
 * Values are compared after normalization (phone digits only, coordinates to
 * five places, categories by resource name in any order, hours as periods in
 * any order), so a formatting difference is not a change. Only the fields the
 * sync owns are ever compared or written; photos, posts, special hours and
 * questions belong to people.
 */
final class ListingDiff
{
    /** The Business Profile fields the sync owns, in the order an update mask names them. */
    public const array OWNED_FIELDS = ['title', 'storefrontAddress', 'latlng', 'phoneNumbers', 'websiteUri', 'categories', 'profile', 'regularHours'];

    /**
     * The desired fields that differ from the live listing.
     *
     * A field the content does not state is never compared, so the sync cannot clear it.
     *
     * @param  array<string, mixed>  $desired  Fields from the field mapper.
     * @param  array<array-key, mixed>  $live  The live listing as the API returns it.
     * @return array<string, mixed> The subset of $desired that differs; empty when nothing needs writing.
     */
    public static function changedFields(array $desired, array $live): array
    {
        $changed = [];

        foreach (self::OWNED_FIELDS as $field) {
            // Skip a field the content does not state
            if (! array_key_exists($field, $desired)) {
                continue;
            }

            if (self::normalize($field, $desired[$field]) !== self::normalize($field, $live[$field] ?? null)) {
                $changed[$field] = $desired[$field];
            }
        }

        return $changed;
    }

    /**
     * The `updateMask` for a set of changed fields, naming only fields the sync owns.
     *
     * @param  array<string, mixed>  $changed  Fields from self::changedFields().
     * @return string Comma-separated field names.
     */
    public static function updateMask(array $changed): string
    {
        return implode(',', array_intersect(self::OWNED_FIELDS, array_keys($changed)));
    }

    /**
     * The desired attributes that are missing from the listing or hold another value.
     *
     * @param  array<string, bool>  $desired  Attribute name => value, from the field mapper.
     * @param  array<string, bool>  $live  From Client::attributes().
     * @return array<string, bool>
     */
    public static function changedAttributes(array $desired, array $live): array
    {
        return array_filter(
            $desired,
            static fn (bool $value, string $name): bool => ! array_key_exists($name, $live) || $live[$name] !== $value,
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /**
     * A field value in a form that compares equal across harmless formatting differences.
     *
     * @param  string  $field  A field name from self::OWNED_FIELDS.
     * @param  mixed  $value  The value, or null when the listing has none.
     * @return mixed The comparable form, or null when there is no value.
     */
    private static function normalize(string $field, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        $parts = Values::arr($value);

        return match ($field) {
            'phoneNumbers' => ['primaryPhone' => self::normalizePhone(Values::str($parts['primaryPhone'] ?? null))],
            // Five decimal places is about a meter
            'latlng' => [
                round((float) Values::str($parts['latitude'] ?? null), 5),
                round((float) Values::str($parts['longitude'] ?? null), 5),
            ],
            // The parts the content states, trimmed; the region and language codes are Google's defaults
            'storefrontAddress' => [
                'postalCode' => trim(Values::str($parts['postalCode'] ?? null)),
                'administrativeArea' => trim(Values::str($parts['administrativeArea'] ?? null)),
                'locality' => trim(Values::str($parts['locality'] ?? null)),
                'addressLines' => array_map(static fn (mixed $line): string => trim(Values::str($line)), Values::arr($parts['addressLines'] ?? null)),
            ],
            'categories' => self::normalizeCategories($parts),
            'profile' => ['description' => trim(Values::str($parts['description'] ?? null))],
            'regularHours' => self::normalizeHours($parts),
            default => is_string($value) ? trim($value) : $value,
        };
    }

    /**
     * A phone number as its digits, without the United States country code.
     *
     * The content stores a number as `+15016868149` and Google returns it as
     * `(501) 686-8149`; both are `5016868149` here, so neither counts as a change.
     *
     * @param  string  $phone  A phone number in any format.
     */
    public static function normalizePhone(string $phone): string
    {
        $digits = implode('', array_filter(str_split($phone), ctype_digit(...)));

        return strlen($digits) === 11 && $digits[0] === '1' ? substr($digits, 1) : $digits;
    }

    /**
     * Regular hours as the open day, open time, close day and close time of each period, sorted.
     *
     * Google may give midnight as `{}` and leave out a zero part, and a period
     * that closes at midnight may close at 24:00 or at 00:00 the next day; each
     * pair reads the same here.
     *
     * @param  array<array-key, mixed>  $hours  A `regularHours` field.
     * @return list<array{string, array{int, int}, string, array{int, int}}>
     */
    private static function normalizeHours(array $hours): array
    {
        $days = array_values(RegularHours::DAYS);
        $next = array_combine($days, [...array_slice($days, 1), $days[0]]);
        $periods = [];

        foreach (Values::arrays($hours['periods'] ?? null) as $period) {
            $openDay = Values::str($period['openDay'] ?? null);
            $closeDay = Values::str($period['closeDay'] ?? null);
            $close = self::clock($period['closeTime'] ?? null);

            // Midnight starting the next day is 24:00 ending this one
            if ($close === [0, 0] && $closeDay === ($next[$openDay] ?? null)) {
                $closeDay = $openDay;
                $close = [24, 0];
            }

            $periods[] = [$openDay, self::clock($period['openTime'] ?? null), $closeDay, $close];
        }

        sort($periods);

        return $periods;
    }

    /**
     * A Business Profile time of day as its hours and minutes; a missing part is zero.
     *
     * @param  mixed  $time  A time of day: `hours` and `minutes`.
     * @return array{int, int}
     */
    private static function clock(mixed $time): array
    {
        $time = Values::arr($time);

        return [(int) Values::str($time['hours'] ?? null), (int) Values::str($time['minutes'] ?? null)];
    }

    /**
     * Categories by resource name, the additional ones in a fixed order.
     *
     * @param  array<array-key, mixed>  $categories  A `categories` field.
     * @return array{primary: string, additional: list<string>}
     */
    private static function normalizeCategories(array $categories): array
    {
        $additional = array_map(
            static fn (array $category): string => Values::str($category['name'] ?? null),
            Values::arrays($categories['additionalCategories'] ?? null),
        );
        sort($additional);

        return [
            'primary' => Values::str(Values::arr($categories['primaryCategory'] ?? null)['name'] ?? null),
            'additional' => $additional,
        ];
    }
}

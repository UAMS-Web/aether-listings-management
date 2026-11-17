<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\People\ListingsManagement\BusinessProfile;

/**
 * Narrowing for values read out of decoded JSON and entry data, where every
 * value is `mixed` until it is checked.
 */
final class Values
{
    /**
     * A value as a string: a string as it is, an integer or float in its usual form, anything else empty.
     *
     * @param  mixed  $value  Any value.
     */
    public static function str(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        return is_int($value) || is_float($value) ? (string) $value : '';
    }

    /**
     * A value as an array keyed by string, or an empty array when it is not one.
     *
     * Integer keys are kept as they are; the type says only that the caller reads it by key.
     *
     * @param  mixed  $value  Any value.
     * @return array<array-key, mixed>
     */
    public static function arr(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /**
     * A value as a list of arrays, dropping anything in it that is not an array.
     *
     * @param  mixed  $value  Any value.
     * @return list<array<array-key, mixed>>
     */
    public static function arrays(mixed $value): array
    {
        return array_values(array_filter(self::arr($value), is_array(...)));
    }
}

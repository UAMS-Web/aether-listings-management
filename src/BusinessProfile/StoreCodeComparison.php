<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\People\ListingsManagement\BusinessProfile;

/**
 * Compares the store codes the content generates with the ones existing listings carry.
 *
 * The add-on has to produce the codes the listings already carry, or the sync
 * creates a second listing beside each one it fails to recognize. This lists
 * every code on one side only, with the likeliest cause, for a person to read
 * before a silo goes live.
 */
final class StoreCodeComparison
{
    /**
     * Every difference between the generated and the existing store codes.
     *
     * A code on both sides is a match and is not listed. A code on one side only
     * is listed with a cause: a near miss on the other side (the same code in
     * another letter case, or a slug the other side carries in full where this one
     * is cut short), else a code with no counterpart at all.
     *
     * @param  list<string>  $generated  The store codes the content generates.
     * @param  list<string>  $existing  The store codes on the existing listings.
     * @return list<array{store_code: string, side: string, cause: string}> Each difference, generated codes first, both sorted.
     */
    public static function compare(array $generated, array $existing): array
    {
        $onlyGenerated = array_unique(array_diff($generated, $existing));
        $onlyExisting = array_unique(array_diff($existing, $generated));
        sort($onlyGenerated);
        sort($onlyExisting);

        $differences = [];

        foreach ($onlyGenerated as $code) {
            $differences[] = ['store_code' => $code, 'side' => 'generated', 'cause' => self::cause($code, $onlyExisting, 'no listing carries it, so the sync would create one')];
        }

        foreach ($onlyExisting as $code) {
            $differences[] = ['store_code' => $code, 'side' => 'existing', 'cause' => self::cause($code, $onlyGenerated, 'no content generates it, so the sync never updates that listing')];
        }

        return $differences;
    }

    /**
     * Why a code has no exact counterpart.
     *
     * @param  string  $code  A code on one side only.
     * @param  list<string>  $others  The codes on the other side only.
     * @param  string  $otherwise  The cause when nothing on the other side is close.
     */
    private static function cause(string $code, array $others, string $otherwise): string
    {
        foreach ($others as $other) {
            if (strcasecmp($code, $other) === 0) {
                return 'differs only in letter case from '.$other;
            }
        }

        foreach ($others as $other) {
            if (self::isShortenedOf($code, $other) || self::isShortenedOf($other, $code)) {
                return 'a slug is cut short against '.$other;
            }
        }

        return $otherwise;
    }

    /**
     * Whether one code is the other with one or both of its slugs cut short.
     *
     * @param  string  $short  The possibly shortened code.
     * @param  string  $long  The possibly full code.
     */
    private static function isShortenedOf(string $short, string $long): bool
    {
        $shortParts = explode('_', $short);
        $longParts = explode('_', $long);

        if ($short === $long || count($shortParts) !== count($longParts)) {
            return false;
        }

        return array_all($shortParts, fn ($part, $position): bool => $part !== '' && str_starts_with($longParts[$position], $part));
    }
}

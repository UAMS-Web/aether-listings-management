<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\People\ListingsManagement\BusinessProfile;

/**
 * One listing the content asks for: a provider at a location, or a location.
 */
final readonly class SyncItem
{
    /**
     * @param  string  $storeCode  The listing's store code, the key it is found by.
     * @param  Kind  $kind  Whether it is a provider's or a location's listing.
     * @param  ?string  $silo  The silo its location's brand belongs to, or null when the brand has none.
     * @param  bool  $excluded  Whether the content excludes it from Business Profile.
     * @param  array<string, mixed>  $fields  The Business Profile fields the content states.
     * @param  array<string, bool>  $attributes  The true or false attributes the content states.
     * @param  list<string>  $unresolvedCategories  Category names Google has no category for, dropped from $fields.
     */
    public function __construct(
        public string $storeCode,
        public Kind $kind,
        public ?string $silo,
        public bool $excluded = false,
        public array $fields = [],
        public array $attributes = [],
        public array $unresolvedCategories = [],
    ) {}
}

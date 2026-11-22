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
     * @param  list<Photo>  $photos  The photos the content asks for: the logo, the cover and the others, in order.
     * @param  array<string, list<string>>  $photosSent  Listing resource name => the photo addresses already sent to that listing.
     */
    public function __construct(
        public string $storeCode,
        public Kind $kind,
        public ?string $silo,
        public bool $excluded = false,
        public array $fields = [],
        public array $attributes = [],
        public array $unresolvedCategories = [],
        public array $photos = [],
        public array $photosSent = [],
    ) {}

    /**
     * The photo addresses already sent to one listing.
     *
     * The record belongs to the listing it was made for, so a different listing
     * for the same content starts with none and is sent every photo.
     *
     * @param  string  $listing  The listing's resource name, `locations/123`.
     * @return list<string>
     */
    public function photosSentTo(string $listing): array
    {
        return $this->photosSent[$listing] ?? [];
    }

    /**
     * This item with the photo addresses already sent to each listing.
     *
     * The field mapper reads content only, so the caller attaches the record it
     * keeps from earlier outcomes' `photosSent` before the item is synced.
     *
     * @param  array<string, list<string>>  $photosSent  Listing resource name => the photo addresses already sent to that listing.
     */
    public function withPhotosSent(array $photosSent): self
    {
        return new self($this->storeCode, $this->kind, $this->silo, $this->excluded, $this->fields, $this->attributes, $this->unresolvedCategories, $this->photos, $photosSent);
    }
}

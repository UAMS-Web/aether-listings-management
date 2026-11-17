<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\People\ListingsManagement\BusinessProfile;

/**
 * One entry's values, as plain data, for the field mapper to read.
 *
 * The caller (the queue, the command) reads a `people` or `clinical_locations`
 * entry and hands over its slug, title, absolute URL and field values, with a
 * child location's parent attached. The mapper reads only this, through the
 * configured handles, so it never imports a Statamic class or the site's own.
 * A relationship value is expected already resolved: a brand organization as
 * its slug, a category term as an array with its `gcid` and `title`. A
 * rich-text (Bard) value may stay as its list of nodes; descriptions read its text.
 */
final readonly class EntryData
{
    /**
     * @param  string  $slug  The entry's slug.
     * @param  string  $title  The entry's title.
     * @param  string  $url  The entry's absolute URL, or empty when it has none.
     * @param  array<array-key, mixed>  $values  Field handle => value.
     * @param  ?EntryData  $parent  A child location's parent location, or null.
     */
    public function __construct(
        public string $slug,
        public string $title,
        public string $url = '',
        public array $values = [],
        public ?EntryData $parent = null,
    ) {}

    /**
     * A field's value, or null when the entry has none.
     *
     * @param  mixed  $handle  The field handle; anything but a string reads nothing.
     */
    public function get(mixed $handle): mixed
    {
        return is_string($handle) ? $this->values[$handle] ?? null : null;
    }

    /**
     * A field's value as trimmed text, or empty.
     *
     * HTML entities are decoded, so `&amp;` in a title is sent as `&`.
     *
     * @param  mixed  $handle  The field handle; anything but a string reads nothing.
     */
    public function text(mixed $handle): string
    {
        return trim(html_entity_decode(Values::str($this->get($handle)), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /**
     * Whether a toggle is on: true, 1, '1' or 'true'.
     *
     * @param  mixed  $handle  The field handle; anything but a string reads nothing.
     */
    public function isOn(mixed $handle): bool
    {
        $value = $this->get($handle);

        return in_array($value, [true, 1, '1', 'true'], true);
    }

    /**
     * The entry this one inherits from: its parent for a child location, itself otherwise.
     */
    public function parentOrSelf(): self
    {
        return $this->parent ?? $this;
    }
}

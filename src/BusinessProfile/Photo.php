<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\People\ListingsManagement\BusinessProfile;

/**
 * One photo a listing asks for: where on the listing it goes, and its public address.
 *
 * A photo is identified by its address. Google fetches it from there, and the
 * sync records the address once it is sent, so an unchanged image is never sent
 * again and a changed one, being a new address, is (UAMS-Web/uams-statamic#709).
 */
final readonly class Photo
{
    /** The listing's logo. */
    public const string LOGO = 'LOGO';

    /** The listing's cover photo. */
    public const string COVER = 'COVER';

    /** One of the listing's other photos. */
    public const string ADDITIONAL = 'ADDITIONAL';

    /**
     * @param  string  $category  Where it goes: self::LOGO, self::COVER or self::ADDITIONAL.
     * @param  string  $url  Its public address, which Google fetches.
     */
    public function __construct(
        public string $category,
        public string $url,
    ) {}

    /**
     * The photos not yet sent: those whose address is not among the addresses already sent.
     *
     * The same address under two categories is sent once, under the first.
     *
     * @param  list<Photo>  $photos  The photos the content asks for, in order.
     * @param  list<string>  $sent  The addresses already sent to the listing.
     * @return list<Photo>
     */
    public static function toSend(array $photos, array $sent): array
    {
        $pending = [];
        $seen = array_flip($sent);

        foreach ($photos as $photo) {
            if (! isset($seen[$photo->url])) {
                $pending[] = $photo;
                $seen[$photo->url] = $photo;
            }
        }

        return $pending;
    }

    /**
     * The addresses of some photos, in order.
     *
     * @param  list<Photo>  $photos  The photos.
     * @return list<string>
     */
    public static function urls(array $photos): array
    {
        return array_map(static fn (Photo $photo): string => $photo->url, $photos);
    }
}

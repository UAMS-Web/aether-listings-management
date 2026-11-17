<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\People\ListingsManagement\BusinessProfile;

/**
 * Every listing the credentials can see, by store code, with the group each was found in.
 *
 * The sync decides from this index alone, so a store code anywhere in any group,
 * a silo's own or not, is found before anything is created for it.
 */
final class ListingIndex
{
    /** @var array<string, list<array{account: string, listing: array<array-key, mixed>}>> Store code => the listings that carry it. */
    private array $byStoreCode = [];

    /**
     * @param  list<array{account: string, listing: array<array-key, mixed>}>  $listings  Each listing with the account group it was found in.
     */
    public function __construct(array $listings = [])
    {
        foreach ($listings as $entry) {
            $this->add($entry['account'], $entry['listing']);
        }
    }

    /**
     * Read every listing in every account group through the client.
     *
     * @param  Client  $client  The Business Profile client.
     * @param  list<string>  $accounts  The account groups to read.
     */
    public static function fromClient(Client $client, array $accounts): self
    {
        $index = new self;

        foreach ($accounts as $account) {
            foreach ($client->listings($account) as $listing) {
                $index->add($account, $listing);
            }
        }

        return $index;
    }

    /**
     * Add one listing. A listing with no store code cannot be matched and is left out.
     *
     * @param  string  $account  The account group it is in.
     * @param  array<array-key, mixed>  $listing  The listing as the API returns it.
     */
    public function add(string $account, array $listing): void
    {
        $code = Values::str($listing['storeCode'] ?? null);

        if ($code !== '') {
            $this->byStoreCode[$code][] = ['account' => $account, 'listing' => $listing];
        }
    }

    /**
     * The listings that carry a store code.
     *
     * @param  string  $storeCode  A store code.
     * @return list<array{account: string, listing: array<array-key, mixed>}>
     */
    public function find(string $storeCode): array
    {
        return $this->byStoreCode[$storeCode] ?? [];
    }
}

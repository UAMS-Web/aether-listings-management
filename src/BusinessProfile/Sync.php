<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\People\ListingsManagement\BusinessProfile;

use Closure;

/**
 * The update, create, review and skip decisions, carried out where the silo is live.
 *
 * Each item's store code is looked up in an index of every listing the
 * credentials can see. The sync writes only to a listing in the account group
 * its silo keeps that kind in, never creates a listing whose store code exists
 * anywhere, sends a store code found on two listings or in another group to
 * review, and never deletes. A silo that is not live decides and reports the
 * same way and sends nothing. The client, the silos and the index are injected,
 * so none of it needs the framework or the network.
 */
final class Sync
{
    /** The fields a listing cannot be created without. */
    public const array REQUIRED_FOR_CREATE = ['title', 'storefrontAddress', 'categories'];

    /**
     * @param  Client  $client  The Business Profile client.
     * @param  Silos  $silos  The silos and their modes.
     * @param  ListingIndex  $index  Every listing the credentials can see.
     * @param  ?Closure(Outcome): void  $logger  Receives each outcome, except an off silo's skip, which says nothing the settings do not.
     */
    public function __construct(private readonly Client $client, private readonly Silos $silos, private readonly ListingIndex $index, private readonly ?Closure $logger = null) {}

    /**
     * Decide and, where the silo is live, carry out one action per item.
     *
     * @param  list<SyncItem>  $items  The listings the content asks for.
     * @return list<Outcome> One outcome per item, in item order.
     */
    public function run(array $items): array
    {
        $outcomes = [];

        foreach ($items as $item) {
            $outcome = $this->handle($item);
            $outcomes[] = $outcome;

            // An off silo's skip stays out of the log, so it cannot fill it or clear an entry needing attention
            $silent = ! $item->excluded && $item->silo !== null && $this->silos->has($item->silo) && $this->silos->mode($item->silo) === Mode::Off;
            if (! $silent && $this->logger instanceof Closure) {
                ($this->logger)($outcome);
            }
        }

        return $outcomes;
    }

    /**
     * Decide one item.
     *
     * @param  SyncItem  $item  The item.
     */
    private function handle(SyncItem $item): Outcome
    {
        // An excluded item is never looked up, so an existing listing is left alone too
        if ($item->excluded) {
            return new Outcome($item, Mode::Off, Action::Skip, 'Excluded from Google Business Profile by the content');
        }

        if ($item->silo === null || ! $this->silos->has($item->silo)) {
            return new Outcome($item, Mode::Off, Action::Skip, "No silo for this location's brand");
        }

        $mode = $this->silos->mode($item->silo);
        if ($mode === Mode::Off) {
            return new Outcome($item, $mode, Action::Skip, 'Silo '.$item->silo.' is off');
        }

        $account = $this->silos->account($item->silo, $item->kind);
        if ($account === '') {
            return new Outcome($item, $mode, Action::Skip, 'Silo '.$item->silo.' has no '.$item->kind->value.' account group');
        }

        // The store code is the key: none anywhere is a create, two is for a person to sort out
        $found = $this->index->find($item->storeCode);

        if ($found === []) {
            return $this->create($item, $mode, $account);
        }

        if (count($found) > 1) {
            return new Outcome($item, $mode, Action::Review, 'Store code is on '.count($found).' listings');
        }

        $match = $found[0];

        // A listing outside this silo's group for the kind is never written, whoever owns it
        if ($match['account'] !== $account) {
            $owner = $this->silos->owner($match['account']);
            $reason = match (true) {
                $owner === $item->silo => "Listing is in this silo's other group (".$match['account'].')',
                $owner !== null => "Listing is in another silo's group (".$owner.')',
                default => 'Listing is parked in '.$match['account'],
            };

            return new Outcome($item, $mode, Action::Review, $reason);
        }

        return $this->update($item, $mode, $match['listing']);
    }

    /**
     * Update a matched listing where it differs from the content.
     *
     * @param  SyncItem  $item  The item.
     * @param  Mode  $mode  The silo's mode, dry run or live.
     * @param  array<array-key, mixed>  $listing  The matched listing.
     */
    private function update(SyncItem $item, Mode $mode, array $listing): Outcome
    {
        $name = Values::str($listing['name'] ?? null);
        $changed = ListingDiff::changedFields($item->fields, $listing);

        // Read the live attributes only when the content states some, so a provider item costs no call
        try {
            $attributes = $item->attributes === [] ? [] : ListingDiff::changedAttributes($item->attributes, $this->client->attributes($name));
        } catch (ApiException $apiException) {
            return $this->failure($item, $mode, $apiException, $name);
        }

        if ($changed === [] && $attributes === []) {
            return new Outcome($item, $mode, Action::Noop, 'Listing already matches', listingName: $name);
        }

        $fields = array_keys($changed);
        $attributeNames = array_keys($attributes);

        if ($mode !== Mode::Live) {
            return new Outcome($item, $mode, Action::Update, 'Dry run, no write made', $fields, $attributeNames, $name);
        }

        try {
            if ($changed !== []) {
                $this->client->patch($name, $changed, ListingDiff::updateMask($changed));
            }

            if ($attributes !== []) {
                $this->client->updateAttributes($name, $attributes);
            }
        } catch (ApiException $apiException) {
            return $this->failure($item, $mode, $apiException, $name, $fields, $attributeNames);
        }

        return new Outcome($item, $mode, Action::Update, 'Updated', $fields, $attributeNames, $name, written: true);
    }

    /**
     * Create a listing for a store code no listing has.
     *
     * @param  SyncItem  $item  The item.
     * @param  Mode  $mode  The silo's mode, dry run or live.
     * @param  string  $account  The account group the silo keeps the item's kind in.
     */
    private function create(SyncItem $item, Mode $mode, string $account): Outcome
    {
        $missing = array_diff(self::REQUIRED_FOR_CREATE, array_keys($item->fields));

        if ($missing !== []) {
            return new Outcome($item, $mode, Action::Skip, 'Cannot create, missing '.implode(', ', $missing));
        }

        $fields = array_keys($item->fields);
        $attributeNames = array_keys($item->attributes);

        if ($mode !== Mode::Live) {
            return new Outcome($item, $mode, Action::Create, 'Dry run, no listing created', $fields, $attributeNames);
        }

        try {
            $created = $this->client->create($account, $item->fields, $item->storeCode);
        } catch (ApiException $apiException) {
            return $this->failure($item, $mode, $apiException, '', $fields, $attributeNames);
        }

        // Remember it, so the same store code later in the run is not created a second time
        $name = Values::str($created['name'] ?? null);
        $this->index->add($account, ['name' => $name, 'storeCode' => $item->storeCode] + $item->fields);

        // The listing exists now; a failure setting its attributes is reported, and the next run sets them as an update
        if ($item->attributes !== [] && $name !== '') {
            try {
                $this->client->updateAttributes($name, $item->attributes);
            } catch (ApiException $apiException) {
                return $this->failure($item, $mode, $apiException, $name, $fields, $attributeNames, written: true);
            }
        }

        return new Outcome($item, $mode, Action::Create, 'Created', $fields, $attributeNames, $name, written: true);
    }

    /**
     * The outcome of a failed call; only a rate limit is worth retrying.
     *
     * @param  SyncItem  $item  The item.
     * @param  Mode  $mode  The silo's mode.
     * @param  ApiException  $apiException  The failure.
     * @param  string  $name  The listing's resource name, when one is known.
     * @param  list<string>  $fields  The fields that were being written.
     * @param  list<string>  $attributes  The attributes that were being written.
     * @param  bool  $written  Whether a write had already gone through.
     */
    private function failure(SyncItem $item, Mode $mode, ApiException $apiException, string $name, array $fields = [], array $attributes = [], bool $written = false): Outcome
    {
        return new Outcome(
            $item,
            $mode,
            Action::Error,
            $apiException->getMessage(),
            $fields,
            $attributes,
            $name,
            $apiException instanceof RateLimitedException,
            $written,
        );
    }
}

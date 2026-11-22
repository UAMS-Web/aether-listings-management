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
 * review, and never deletes. A listing it creates or updates is also sent each
 * photo whose address has not been sent to that listing before; a photo is only
 * ever added, never replaced or removed. A silo that is not live decides and reports the
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

        return $this->update($item, $mode, $match['listing'], $account);
    }

    /**
     * Update a matched listing where it differs from the content.
     *
     * @param  SyncItem  $item  The item.
     * @param  Mode  $mode  The silo's mode, dry run or live.
     * @param  array<array-key, mixed>  $listing  The matched listing.
     * @param  string  $account  The account group the listing is in.
     */
    private function update(SyncItem $item, Mode $mode, array $listing, string $account): Outcome
    {
        $name = Values::str($listing['name'] ?? null);

        // A listing created earlier in this run without a name cannot be addressed, and must not be created again
        if ($name === '') {
            return new Outcome($item, $mode, Action::Review, 'Store code is on a listing Google created without a name');
        }

        $changed = ListingDiff::changedFields($item->fields, $listing);

        // Read the live attributes only when the content states some, so a provider item costs no call
        try {
            $attributes = $item->attributes === [] ? [] : ListingDiff::changedAttributes($item->attributes, $this->client->attributes($name));
        } catch (ApiException $apiException) {
            return $this->failure($item, $mode, $apiException, $name);
        }

        // A photo goes only when its address is new for this listing, so an unchanged image makes no call
        $photos = Photo::toSend($item->photos, $item->photosSentTo($name));

        if ($changed === [] && $attributes === [] && $photos === []) {
            return new Outcome($item, $mode, Action::Noop, 'Listing already matches', listingName: $name);
        }

        $fields = array_keys($changed);
        $attributeNames = array_keys($attributes);
        $urls = Photo::urls($photos);

        if ($mode !== Mode::Live) {
            return new Outcome($item, $mode, Action::Update, 'Dry run, no write made', $fields, $attributeNames, $name, photos: $urls);
        }

        try {
            if ($changed !== []) {
                $this->client->patch($name, $changed, ListingDiff::updateMask($changed));
            }

            if ($attributes !== []) {
                $this->client->updateAttributes($name, $attributes);
            }
        } catch (ApiException $apiException) {
            return $this->failure($item, $mode, $apiException, $name, $fields, $attributeNames, photos: $urls);
        }

        $wrote = $changed !== [] || $attributes !== [];
        [$sent, $refused] = $this->sendPhotos($account, $name, $photos);

        if ($refused instanceof ApiException) {
            return $this->failure($item, $mode, $refused, $name, $fields, $attributeNames, $wrote || $sent !== [], $urls, $sent);
        }

        return new Outcome($item, $mode, Action::Update, $wrote ? 'Updated' : 'Photos sent', $fields, $attributeNames, $name, written: true, photos: $urls, photosSent: $sent);
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

        // A new listing gets every photo
        $photos = Photo::toSend($item->photos, []);
        $urls = Photo::urls($photos);

        if ($mode !== Mode::Live) {
            return new Outcome($item, $mode, Action::Create, 'Dry run, no listing created', $fields, $attributeNames, photos: $urls);
        }

        try {
            $created = $this->client->create($account, $item->fields, $item->storeCode);
        } catch (ApiException $apiException) {
            return $this->failure($item, $mode, $apiException, '', $fields, $attributeNames, photos: $urls);
        }

        // Remember it, so the same store code later in the run is not created a second time
        $name = Values::str($created['name'] ?? null);
        $this->index->add($account, ['name' => $name, 'storeCode' => $item->storeCode] + $item->fields);

        // The listing exists now; a failure setting its attributes is reported, and the next run sets them as an update
        if ($item->attributes !== [] && $name !== '') {
            try {
                $this->client->updateAttributes($name, $item->attributes);
            } catch (ApiException $apiException) {
                return $this->failure($item, $mode, $apiException, $name, $fields, $attributeNames, written: true, photos: $urls);
            }
        }

        // A listing Google did not name cannot be addressed, so its photos wait for the next run
        [$sent, $refused] = $name === '' ? [[], null] : $this->sendPhotos($account, $name, $photos);

        if ($refused instanceof ApiException) {
            return $this->failure($item, $mode, $refused, $name, $fields, $attributeNames, true, $urls, $sent);
        }

        return new Outcome($item, $mode, Action::Create, 'Created', $fields, $attributeNames, $name, written: true, photos: $urls, photosSent: $sent);
    }

    /**
     * Send photos to a listing one at a time, noting each address Google accepts.
     *
     * One refused photo does not stop the ones after it; the first refusal is the
     * one returned, named by its address. A rate limit ends the sending at once,
     * since every later photo would be refused for the same reason.
     *
     * @param  string  $account  The account group the listing is in.
     * @param  string  $name  The listing's resource name.
     * @param  list<Photo>  $photos  The photos to send.
     * @return array{0: list<string>, 1: ?ApiException} The addresses Google accepted, and the failure to report, if any.
     */
    private function sendPhotos(string $account, string $name, array $photos): array
    {
        $sent = [];
        $refused = null;

        foreach ($photos as $photo) {
            try {
                $this->client->uploadPhoto($account, $name, $photo);
                $sent[] = $photo->url;
            } catch (RateLimitedException $rateLimitedException) {
                return [$sent, new RateLimitedException($photo->url.': '.$rateLimitedException->getMessage(), $rateLimitedException->daily)];
            } catch (ApiException $apiException) {
                $refused ??= new ApiException($photo->url.': '.$apiException->getMessage());
            }
        }

        return [$sent, $refused];
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
     * @param  list<string>  $photos  The addresses of the photos that were being sent.
     * @param  list<string>  $photosSent  The addresses Google accepted before the failure.
     */
    private function failure(SyncItem $item, Mode $mode, ApiException $apiException, string $name, array $fields = [], array $attributes = [], bool $written = false, array $photos = [], array $photosSent = []): Outcome
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
            $photos,
            $photosSent,
        );
    }
}

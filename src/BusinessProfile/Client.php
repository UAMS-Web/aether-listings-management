<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\People\ListingsManagement\BusinessProfile;

use Closure;

/**
 * The Google Business Profile API client.
 *
 * Signs in with an OAuth refresh token and reads account groups, listings,
 * categories and attributes, and writes a listing's fields (a patch with an
 * update mask), a new listing, a listing's attributes, and a photo added to a
 * listing. It never deletes.
 * Every HTTP call goes through the injected transport, so the client runs the
 * same under the host's HTTP client and under a test's fake. It never logs or
 * returns a credential, and a failure names only its status and Google's message.
 */
final class Client
{
    /** Where a refresh token is exchanged for an access token. */
    private const string TOKEN_URL = 'https://oauth2.googleapis.com/token';

    /** The Business Information API, which holds listings, categories and attributes. */
    private const string INFO_URL = 'https://mybusinessbusinessinformation.googleapis.com/v1';

    /** Where the account groups are listed. */
    private const string ACCOUNTS_URL = 'https://mybusinessaccountmanagement.googleapis.com/v1/accounts';

    /** The older Google My Business API, which alone takes a listing's photos. */
    private const string MEDIA_URL = 'https://mybusiness.googleapis.com/v4';

    /** The most pages one list is read to: 100,000 listings at 100 a page. */
    public const int MAX_PAGES = 1000;

    /** The fields read back from a listing for the comparison. */
    public const string READ_MASK = 'name,storeCode,title,storefrontAddress,latlng,phoneNumbers,websiteUri,categories,profile,regularHours,specialHours,metadata,openInfo';

    /** The access token from the last exchange, or null before the first. */
    private ?string $token = null;

    /** The Unix time the access token expires at, set with the token. */
    private int $tokenExpires;

    /** @var Closure(): int The clock that decides when the token is renewed. */
    private readonly Closure $clock;

    /**
     * @param  Credentials  $credentials  The OAuth client and refresh token.
     * @param  Transport  $transport  Sends each request.
     * @param  ?Closure(): int  $clock  The current Unix time; the system clock when null.
     */
    public function __construct(
        private readonly Credentials $credentials,
        private readonly Transport $transport,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? time(...);
    }

    /**
     * An access token, exchanged from the refresh token and reused until a minute before it expires.
     *
     * @throws ApiException When the exchange fails; the message names the status and Google's error code only.
     */
    public function accessToken(): string
    {
        // Reuse the token while it has more than a minute left
        $now = ($this->clock)();
        if ($this->token !== null && $now < $this->tokenExpires - 60) {
            return $this->token;
        }

        // Exchange the refresh token for a new access token
        $response = $this->transport->send('POST', self::TOKEN_URL, ['Content-Type' => 'application/x-www-form-urlencoded'], http_build_query([
            'client_id' => $this->credentials->clientId,
            'client_secret' => $this->credentials->clientSecret,
            'refresh_token' => $this->credentials->refreshToken,
            'grant_type' => 'refresh_token',
        ]));

        $data = Values::arr(json_decode($response->body, true));
        $token = Values::str($data['access_token'] ?? null);

        // Fail with Google's error code, or the transport's reason when nothing was sent
        if ($response->status !== 200 || $token === '') {
            $unsent = $response->status === 0 && $response->body !== '' ? $response->body : 'no token returned';
            $reason = Values::str($data['error'] ?? null);

            throw new ApiException('Google token exchange failed ('.$response->status.'): '.($reason !== '' ? $reason : $unsent));
        }

        // Keep the token until it expires
        $this->token = $token;
        $expiresIn = $data['expires_in'] ?? null;
        $this->tokenExpires = $now + (is_int($expiresIn) ? $expiresIn : 3600);

        return $this->token;
    }

    /**
     * One authenticated call. Returns the decoded body, or an empty array for an empty body.
     *
     * @param  string  $method  The HTTP method.
     * @param  string  $url  The URL without its query string.
     * @param  array<string, scalar>  $query  Query parameters; an empty value is still sent, so leave it out instead.
     * @param  ?array<array-key, mixed>  $body  The JSON body, or null for none.
     * @return array<array-key, mixed>
     *
     * @throws RateLimitedException On a 429 or a 503.
     * @throws ApiException On any other status outside 2xx, carrying the status and Google's message.
     */
    public function request(string $method, string $url, array $query = [], ?array $body = null): array
    {
        // Append the query string
        if ($query !== []) {
            $url .= (str_contains($url, '?') ? '&' : '?').http_build_query($query);
        }

        // Sign the request, and mark a JSON body
        $headers = ['Authorization' => 'Bearer '.$this->accessToken()];
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
        }

        $response = $this->transport->send($method, $url, $headers, $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE));
        $decoded = json_decode($response->body, true);
        $data = Values::arr($decoded);

        if ($response->status >= 200 && $response->status <= 299) {
            // A reply that is not JSON is a failure, never an empty page: an empty page of listings would lead to creates
            if ($response->body !== '' && ! is_array($decoded)) {
                throw new ApiException('Google API '.$method.' failed ('.$response->status.'): the reply is not JSON');
            }

            return $data;
        }

        // Name the failure by Google's message, or the transport's reason when nothing was sent
        $message = Values::str(Values::arr($data['error'] ?? null)['message'] ?? null);
        if ($message === '') {
            $message = $response->status === 0 && $response->body !== '' ? $response->body : 'no message';
        }

        $failure = 'Google API '.$method.' failed ('.$response->status.'): '.$message;

        // Only a 429 that names a per-day quota is a daily limit; a 503 never is
        if (in_array($response->status, [429, 503], true)) {
            throw new RateLimitedException($failure, $response->status === 429 && preg_match('/per[\s-]?day|daily/i', $message) === 1);
        }

        throw new ApiException($failure);
    }

    /**
     * The location-group accounts the credentials manage.
     *
     * @return array<string, string> Account name (`accounts/123`) => display name.
     */
    public function accountGroups(): array
    {
        $groups = [];

        foreach ($this->pages(self::ACCOUNTS_URL, ['pageSize' => 20], 'accounts') as $account) {
            // Keep the location groups and skip personal and organization accounts
            if (($account['type'] ?? null) === 'LOCATION_GROUP') {
                $groups[Values::str($account['name'] ?? null)] = Values::str($account['accountName'] ?? null);
            }
        }

        return $groups;
    }

    /**
     * Every listing in one account group, with the fields the sync compares.
     *
     * @param  string  $account  The account group, `accounts/123`.
     * @return list<array<array-key, mixed>>
     */
    public function listings(string $account): array
    {
        return $this->pages(self::INFO_URL.'/'.$account.'/locations', ['readMask' => self::READ_MASK, 'pageSize' => 100], 'locations');
    }

    /**
     * Google's categories for the United States, for turning a display name into a resource name.
     *
     * @return array<string, string> Display name => `categories/gcid:...`.
     */
    public function categories(): array
    {
        $ids = [];
        $query = ['regionCode' => 'US', 'languageCode' => 'en', 'view' => 'BASIC', 'pageSize' => 100];

        foreach ($this->pages(self::INFO_URL.'/categories', $query, 'categories') as $category) {
            $name = Values::str($category['name'] ?? null);
            $display = Values::str($category['displayName'] ?? null);

            if ($name !== '' && $display !== '') {
                $ids[$display] = $name;
            }
        }

        return $ids;
    }

    /**
     * Update the named fields of one listing.
     *
     * @param  string  $listing  The listing's resource name, `locations/123`.
     * @param  array<string, mixed>  $fields  Field values keyed by Business Profile field name.
     * @param  string  $mask  The `updateMask`, naming exactly the fields in $fields.
     * @param  bool  $validateOnly  Ask Google to check the request without applying it.
     * @return array<array-key, mixed> The listing as Google returns it.
     */
    public function patch(string $listing, array $fields, string $mask, bool $validateOnly = false): array
    {
        $query = ['updateMask' => $mask] + ($validateOnly ? ['validateOnly' => 'true'] : []);

        return $this->request('PATCH', self::INFO_URL.'/'.$listing, $query, $fields);
    }

    /**
     * Create a listing in an account group.
     *
     * The request ID is derived from the store code, so Google recognizes a retry
     * of the same creation as the same request rather than a second listing.
     * There is no validate-only create: Google counts a validation against the
     * daily create limit, so checking first would spend the quota twice.
     *
     * @param  string  $account  The account group, `accounts/123`.
     * @param  array<string, mixed>  $fields  Field values keyed by Business Profile field name.
     * @param  string  $storeCode  The listing's store code.
     * @return array<array-key, mixed> The new listing, with its resource name.
     */
    public function create(string $account, array $fields, string $storeCode): array
    {
        return $this->request('POST', self::INFO_URL.'/'.$account.'/locations', ['requestId' => self::requestId($storeCode)], $fields + ['storeCode' => $storeCode, 'languageCode' => 'en']);
    }

    /**
     * The request ID a create for a store code is sent with.
     *
     * @param  string  $storeCode  The listing's store code.
     */
    public static function requestId(string $storeCode): string
    {
        return 'uamswp-'.substr(sha1($storeCode), 0, 32);
    }

    /**
     * A listing's true or false attributes. Attributes of any other value type are left out.
     *
     * @param  string  $listing  The listing's resource name, `locations/123`.
     * @return array<string, bool> Attribute name without the `attributes/` prefix => value.
     */
    public function attributes(string $listing): array
    {
        $data = $this->request('GET', self::INFO_URL.'/'.$listing.'/attributes');
        $attributes = [];

        foreach (Values::arrays($data['attributes'] ?? null) as $attribute) {
            $value = Values::arr($attribute['values'] ?? null)[0] ?? null;

            // Keep a boolean attribute only when its value is a boolean
            if (($attribute['valueType'] ?? null) === 'BOOL' && is_bool($value)) {
                $name = Values::str($attribute['name'] ?? null);
                $attributes[str_starts_with($name, 'attributes/') ? substr($name, 11) : $name] = $value;
            }
        }

        return $attributes;
    }

    /**
     * Set true or false attributes on a listing. The mask names exactly the attributes sent.
     *
     * @param  string  $listing  The listing's resource name, `locations/123`.
     * @param  array<string, bool>  $values  Attribute name without `attributes/` => value.
     * @return array<array-key, mixed>
     */
    public function updateAttributes(string $listing, array $values): array
    {
        $names = [];
        $body = ['name' => $listing.'/attributes', 'attributes' => []];

        foreach ($values as $name => $value) {
            $names[] = 'attributes/'.$name;
            $body['attributes'][] = ['name' => 'attributes/'.$name, 'values' => [$value]];
        }

        return $this->request('PATCH', self::INFO_URL.'/'.$listing.'/attributes', ['attributeMask' => implode(',', $names)], $body);
    }

    /**
     * Add one photo to a listing, by its address, which Google fetches.
     *
     * The photo goes through the Google My Business v4 media endpoint, which
     * addresses a listing under its account group. Nothing Google already holds
     * is replaced or deleted: this only adds a photo.
     *
     * @param  string  $account  The account group the listing is in, `accounts/123`.
     * @param  string  $listing  The listing's resource name, `locations/123`.
     * @param  Photo  $photo  The photo: its category and address.
     * @return array<array-key, mixed> The media item as Google returns it.
     */
    public function uploadPhoto(string $account, string $listing, Photo $photo): array
    {
        return $this->request('POST', self::MEDIA_URL.'/'.$account.'/'.$listing.'/media', [], [
            'mediaFormat' => 'PHOTO',
            'locationAssociation' => ['category' => $photo->category],
            'sourceUrl' => $photo->url,
        ]);
    }

    /**
     * Every item of a paged list, following `nextPageToken` to the end.
     *
     * A token Google hands back a second time, or a list longer than
     * self::MAX_PAGES pages, is a failure rather than a loop that never ends.
     *
     * @param  string  $url  The list URL.
     * @param  array<string, scalar>  $query  The query for every page.
     * @param  string  $key  The key the items are under.
     * @return list<array<array-key, mixed>>
     *
     * @throws ApiException When a page token repeats or the pages run past the limit.
     */
    private function pages(string $url, array $query, string $key): array
    {
        $items = [];
        $page = '';
        $seen = [];

        do {
            // Ask for the next page, if there is a token for one
            $data = $this->request('GET', $url, $query + ($page !== '' ? ['pageToken' => $page] : []));
            array_push($items, ...Values::arrays($data[$key] ?? null));
            $page = Values::str($data['nextPageToken'] ?? null);

            if ($page === '') {
                return $items;
            }

            if (in_array($page, $seen, true)) {
                throw new ApiException('Google API GET failed: the page token for '.$url.' came back a second time');
            }

            $seen[] = $page;
        } while (count($seen) < self::MAX_PAGES);

        throw new ApiException('Google API GET failed: '.$url.' ran past '.self::MAX_PAGES.' pages');
    }
}

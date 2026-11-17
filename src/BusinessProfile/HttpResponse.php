<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\People\ListingsManagement\BusinessProfile;

/**
 * What a transport got back for one request.
 */
final readonly class HttpResponse
{
    /**
     * @param  int  $status  The HTTP status, or 0 when the request was never made.
     * @param  string  $body  The raw response body, or the reason when the status is 0.
     */
    public function __construct(
        public int $status,
        public string $body,
    ) {}
}

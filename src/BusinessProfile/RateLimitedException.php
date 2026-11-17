<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\People\ListingsManagement\BusinessProfile;

/**
 * Google asked the caller to slow down (HTTP 429) or is briefly unavailable (503).
 *
 * A subclass of the general failure, so a handler of that still catches it, while
 * the code that runs the sync can tell it from a failure retrying will not fix.
 */
final class RateLimitedException extends ApiException
{
    /**
     * @param  string  $message  The failure, with its status and Google's message.
     * @param  bool  $daily  Whether Google refused because a per-day quota is used up, which does not clear in minutes the way a per-minute limit does.
     */
    public function __construct(string $message, public readonly bool $daily = false)
    {
        parent::__construct($message);
    }
}

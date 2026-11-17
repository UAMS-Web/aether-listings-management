<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\People\ListingsManagement\BusinessProfile;

use SensitiveParameter;

/**
 * The OAuth client and refresh token the Business Profile client signs in with.
 *
 * The values come from the host's environment and are never committed. They are
 * marked sensitive, so a stack trace through the constructor does not print them.
 */
final readonly class Credentials
{
    /**
     * @param  string  $clientId  The OAuth client ID.
     * @param  string  $clientSecret  The OAuth client secret.
     * @param  string  $refreshToken  A refresh token granted for the Business Profile scope.
     */
    public function __construct(
        #[SensitiveParameter]
        public string $clientId,
        #[SensitiveParameter]
        public string $clientSecret,
        #[SensitiveParameter]
        public string $refreshToken,
    ) {}

    /**
     * Whether every value is present, so a sync can say it is not configured instead of failing a request.
     */
    public function isComplete(): bool
    {
        return $this->clientId !== '' && $this->clientSecret !== '' && $this->refreshToken !== '';
    }
}

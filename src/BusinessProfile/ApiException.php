<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\People\ListingsManagement\BusinessProfile;

use RuntimeException;

/**
 * A Google API call failed: the token exchange or a Business Profile request.
 *
 * The message carries the HTTP status and Google's own message only, never a
 * credential or a token.
 */
class ApiException extends RuntimeException {}

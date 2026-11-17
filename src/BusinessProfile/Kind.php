<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\People\ListingsManagement\BusinessProfile;

/**
 * Whose listing it is. Each silo keeps the two kinds in separate account groups.
 */
enum Kind: string
{
    case Provider = 'provider';
    case Location = 'location';
}

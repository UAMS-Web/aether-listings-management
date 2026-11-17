<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\People\ListingsManagement\BusinessProfile;

/**
 * What the sync decided for one store code.
 */
enum Action: string
{
    /** Nothing is done: the item is excluded, has no silo, its silo is off, or a create lacks a required field. */
    case Skip = 'skip';

    /** The listing already matches the content. */
    case Noop = 'noop';

    /** The listing's fields or attributes differ and are (or in a dry run, would be) written. */
    case Update = 'update';

    /** No listing has the store code anywhere, so one is (or in a dry run, would be) created. */
    case Create = 'create';

    /** A person has to look: the store code is on two listings, or its listing is in a group this item may not write. */
    case Review = 'review';

    /** A call to Google failed. */
    case Error = 'error';
}

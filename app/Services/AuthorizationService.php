<?php

namespace App\Services;

use DomainException;

/**
 * Segregation of duties (architecture §4.1): the maker of a transaction
 * may never be its checker. Used by journal posting in Step 3A and by
 * every money module from then on.
 */
class AuthorizationService
{
    public function assertDifferentMakerChecker(int $enteredBy, int $authorizedBy): void
    {
        if ($enteredBy === $authorizedBy) {
            throw new DomainException(__('entered_by_and_authorized_by_must_differ'));
        }
    }
}

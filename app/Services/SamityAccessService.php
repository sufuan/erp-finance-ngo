<?php

namespace App\Services;

use App\Models\User;

/**
 * Samity access checks for direct (single-samity) routes: query-level
 * scoping lives in SamityScope; this service guards endpoints that take
 * an explicit samity id (change forms, future collection routes).
 *
 * Restriction follows ASSIGNMENT only, never a role name (owner rule,
 * 2026-11-14): a user is samity-restricted exactly when an admin has
 * ticked at least one samity for them on the user form (`samity_user`).
 * Restricted users see exactly their ticked samities; everyone else —
 * whatever their role — sees all samities of their branch.
 */
class SamityAccessService
{
    /** Restricted ⇔ ≥1 assigned samity in `samity_user`. */
    public function isRestricted(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        return \Illuminate\Support\Facades\DB::table('samity_user')
            ->where('user_id', $user->id)
            ->exists();
    }

    /** @return int[] */
    public function assignedSamityIds(User $user): array
    {
        return \Illuminate\Support\Facades\DB::table('samity_user')
            ->where('user_id', $user->id)
            ->pluck('samity_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public function canAccess(?User $user, int $samityId): bool
    {
        if (! $this->isRestricted($user)) {
            return true;
        }

        return in_array($samityId, $this->assignedSamityIds($user), true);
    }

    public function assertAccess(?User $user, int $samityId): void
    {
        abort_unless($this->canAccess($user, $samityId), 403);
    }
}

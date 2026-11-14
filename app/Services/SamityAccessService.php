<?php

namespace App\Services;

use App\Models\User;

/**
 * Samity access checks for direct (single-samity) routes: query-level
 * scoping lives in SamityScope; this service guards endpoints that take
 * an explicit samity id (change forms, future collection routes).
 *
 * Visibility is ASSIGNMENT ONLY, never a role name (owner rule,
 * 2026-11-14, stated twice): a user sees exactly the samities ticked
 * for them on the user form (`samity_user`) — 1 tick → that samity,
 * all ticked → all, NO ticks → not one samity. This holds for every
 * role (custom roles, Field Officer, Branch Manager alike). The single
 * exemption is Super Admin (the IT-department account), which is
 * unrestricted so the system stays administrable.
 */
class SamityAccessService
{
    /** Restricted ⇔ signed-in user who is not Super Admin. */
    public function isRestricted(?User $user): bool
    {
        return $user !== null && ! $user->hasRole('super-admin');
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

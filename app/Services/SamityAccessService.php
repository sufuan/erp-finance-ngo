<?php

namespace App\Services;

use App\Models\User;

/**
 * Samity access checks for direct (single-samity) routes: query-level
 * scoping lives in SamityScope; this service guards endpoints that take
 * an explicit samity id (change forms, future collection routes).
 */
class SamityAccessService
{
    /** Only Field Officers are samity-restricted. */
    public function isRestricted(?User $user): bool
    {
        return $user !== null && $user->hasRole('field-officer');
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

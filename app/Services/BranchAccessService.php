<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Branch scoping rules (plan Step 2 §4.1):
 *  - Super Admin sees every active branch.
 *  - Everyone else sees only their branch_user assignments, expanded to
 *    descendants when the assignment's include_children flag is set.
 */
class BranchAccessService
{
    /** @return Collection<int, Branch> */
    public function visibleBranches(?User $user): Collection
    {
        if (! $user) {
            return collect();
        }
        if ($user->hasRole('super-admin')) {
            return Branch::where('status', 'Active')->orderBy('code')->get();
        }
        $ids = $this->accessibleIds($user);

        return Branch::whereIn('id', $ids)->where('status', 'Active')->orderBy('code')->get();
    }

    /** @return array<int, int> */
    public function accessibleIds(?User $user): array
    {
        if (! $user) {
            return [];
        }
        if ($user->hasRole('super-admin')) {
            return Branch::pluck('id')->all();
        }
        $ids = [];
        foreach ($user->branches as $branch) {
            $ids[] = $branch->id;
            if ($branch->pivot->include_children) {
                $ids = array_merge($ids, $branch->descendantIds());
            }
        }

        return array_values(array_unique($ids));
    }

    public function canAccess(?User $user, int $branchId): bool
    {
        return in_array($branchId, $this->accessibleIds($user), true);
    }
}

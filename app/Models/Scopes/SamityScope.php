<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Schema;

/**
 * Samity-level row scoping (plan Step 7 / A8): visibility is ASSIGNMENT
 * ONLY, never a role name (owner rule, 2026-11-14, stated twice) — a
 * user sees exactly the samities ticked for them on the user form
 * (`samity_user`); NO ticks means NOT ONE samity, for every role
 * (custom roles, Field Officer, Branch Manager alike). The single
 * exemption is Super Admin (the IT-department account), which is
 * unscoped so the system stays administrable. Applies to any model
 * whose table carries `samity_id` — and to `samities` itself (via
 * `id`). Every consumer (web, reports, the Step 42 API) inherits the
 * restriction at query level. No authenticated user (console, seeders,
 * jobs) → no scoping. Mirrors SamityAccessService::isRestricted() —
 * keep the two in step.
 */
class SamityScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $user = auth()->user();
        if (! $user || ! method_exists($user, 'hasRole')) {
            return;
        }
        if ($user->hasRole('super-admin')) {
            return;
        }
        if (! Schema::hasTable('samities')) {
            return;
        }

        $table = $model->getTable();
        $column = $table === 'samities' ? 'id' : 'samity_id';
        if (! Schema::hasColumn($table, $column)) {
            return;
        }

        // Read the pivot directly — going through the Samity model would
        // re-enter this very scope and recurse. An empty pivot yields an
        // empty whereIn, i.e. zero rows: unassigned users see nothing.
        $ids = \Illuminate\Support\Facades\DB::table('samity_user')
            ->where('user_id', $user->id)
            ->pluck('samity_id')
            ->all();
        $builder->whereIn($table.'.'.$column, $ids);
    }
}

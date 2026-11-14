<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Schema;

/**
 * Samity-level row scoping (plan Step 7 / A8): a user is samity-restricted
 * when they hold the Field Officer role OR when an admin has ticked at
 * least one samity for them on the user form (`samity_user`) — visibility
 * follows the assignment, so custom collector roles behave like Field
 * Officers once samities are ticked, and a manager with one tick becomes
 * restricted to it. Restricted users only ever see rows of their assigned
 * samities. Applies to any model whose table carries `samity_id` — and to
 * `samities` itself (via `id`). Every consumer (web, reports, the Step 42
 * API) inherits the restriction at query level. Mirrors
 * SamityAccessService::isRestricted() — keep the two in step.
 */
class SamityScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $user = auth()->user();
        if (! $user || ! method_exists($user, 'hasRole')) {
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
        // re-enter this very scope and recurse.
        $ids = \Illuminate\Support\Facades\DB::table('samity_user')
            ->where('user_id', $user->id)
            ->pluck('samity_id')
            ->all();
        if (! $user->hasRole('field-officer') && $ids === []) {
            return;
        }
        $builder->whereIn($table.'.'.$column, $ids);
    }
}

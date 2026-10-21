<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Schema;

/**
 * Samity-level row scoping (plan Step 7 / A8): users with the Field
 * Officer role only ever see rows of their assigned samities
 * (`samity_user`). Applies to any model whose table carries `samity_id`
 * — and to `samities` itself (via `id`). Every consumer (web, reports,
 * the Step 42 API) inherits the restriction at query level.
 */
class SamityScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $user = auth()->user();
        if (! $user || ! method_exists($user, 'hasRole') || ! $user->hasRole('field-officer')) {
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
        $builder->whereIn($table.'.'.$column, $ids);
    }
}

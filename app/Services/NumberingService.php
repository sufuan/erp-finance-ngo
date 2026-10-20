<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Sequence;
use Illuminate\Support\Facades\DB;

/**
 * Central numbering (plan §4.3). Every display ID in the system comes from
 * here — branch-prefixed, zero-padded: branch 101 + seq 162 = 101000162.
 */
class NumberingService
{
    /**
     * Atomically take the next number for (branch, entity).
     */
    public function next(int $branchId, string $entity, ?string $prefix = null): int
    {
        return DB::transaction(function () use ($branchId, $entity, $prefix) {
            $row = Sequence::where('branch_id', $branchId)
                ->where('entity', $entity)
                ->lockForUpdate()
                ->first();

            if (! $row) {
                $row = Sequence::create([
                    'branch_id' => $branchId,
                    'entity' => $entity,
                    'prefix' => $prefix,
                    'last_number' => 0,
                ]);
                // Re-fetch with a lock so concurrent creators serialize.
                $row = Sequence::where('id', $row->id)->lockForUpdate()->first();
            }

            $row->last_number++;
            $row->save();

            return (int) $row->last_number;
        });
    }

    /**
     * Branch-prefixed display ID: code + 6-digit zero-padded sequence.
     */
    public function displayId(Branch|int $branch, int $number): string
    {
        $code = $branch instanceof Branch ? $branch->code : (string) $branch;

        return $code.str_pad((string) $number, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Convenience: next number already formatted as a display ID.
     */
    public function nextDisplayId(int $branchId, string $entity): string
    {
        $branch = Branch::findOrFail($branchId);

        return $this->displayId($branch, $this->next($branchId, $entity));
    }
}

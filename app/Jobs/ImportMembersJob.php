<?php

namespace App\Jobs;

use App\Models\MemberImportBatch;
use App\Models\Samity;
use App\Services\MemberAdmissionService;
use App\Services\MemberImportRowValidator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Step 9A — queued, chunked member import (100 rows/chunk). Atomicity is
 * per-row: every row is re-validated and admitted inside its own
 * transaction (via MemberAdmissionService); a bad row is recorded in the
 * batch's failures and never blocks the rows behind it.
 */
class ImportMembersJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $batchId) {}

    public function handle(MemberImportRowValidator $validator, MemberAdmissionService $admissions): void
    {
        $batch = MemberImportBatch::find($this->batchId);
        if (! $batch || $batch->status === 'Completed') {
            return;
        }
        $batch->update(['status' => 'Processing']);
        $defaultSamity = $batch->samity;

        $failures = [];
        $imported = 0;
        $seenMobiles = [];
        $seenNids = [];

        foreach (array_chunk($batch->rows ?? [], 100) as $chunk) {
            foreach ($chunk as $row) {
                $raw = $row['raw'] ?? [];
                $result = $validator->validate($raw, $defaultSamity, $seenMobiles, $seenNids);
                if ($result['errors'] !== []) {
                    $failures[] = $this->failure($row, $raw, implode('; ', $result['errors']));
                    continue;
                }
                try {
                    $samity = Samity::withoutGlobalScopes()->findOrFail($result['data']['samity_id']);
                    $admissions->admit($result['data'], $samity, $batch->created_by);
                    $imported++;
                } catch (\Throwable $e) {
                    $failures[] = $this->failure($row, $raw, $e->getMessage());
                }
            }
            $batch->update([
                'imported_count' => $imported,
                'failed_count' => count($failures),
                'failures' => $failures,
            ]);
        }

        $batch->update([
            'status' => 'Completed',
            'imported_count' => $imported,
            'failed_count' => count($failures),
            'failures' => $failures,
        ]);
    }

    private function failure(array $row, array $raw, string $reason): array
    {
        return [
            'row' => $row['no'] ?? null,
            'member_id' => $raw['member_id'] ?? null,
            'name' => $raw['name'] ?? null,
            'mobile' => $raw['mobile'] ?? null,
            'reason' => $reason,
        ];
    }
}

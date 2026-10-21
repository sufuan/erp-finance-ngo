<?php

namespace App\Jobs;

use App\Models\BalanceImportBatch;
use App\Models\User;
use App\Services\BalanceImportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Step 20A — queued, chunked balance import (100 rows/chunk). Atomicity
 * is per-row: each row re-validates and posts inside its own transaction
 * (via BalanceImportService); a bad row lands in the batch failures and
 * never blocks the rows behind it.
 */
class ImportBalancesJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $batchId) {}

    public function handle(BalanceImportService $service): void
    {
        $batch = BalanceImportBatch::find($this->batchId);
        if (! $batch || $batch->status === 'Completed') {
            return;
        }
        $batch->update(['status' => 'Processing']);
        $actor = User::findOrFail($batch->created_by);
        $batch->load('samity');

        $failures = [];
        $imported = 0;
        foreach (array_chunk($batch->rows ?? [], 100) as $chunk) {
            foreach ($chunk as $row) {
                $raw = $row['raw'] ?? [];
                $result = $service->validateRow($raw, $batch->samity, (bool) $batch->add_members);
                if ($result['errors'] !== []) {
                    $failures[] = $this->failure($row, $raw, implode('; ', $result['errors']));
                    continue;
                }
                try {
                    $service->importRow($result['data'], $batch, $actor);
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
        $batch->update(['status' => 'Completed']);
    }

    private function failure(array $row, array $raw, string $reason): array
    {
        return [
            'row' => $row['no'] ?? null,
            'member_id' => $raw['member_id'] ?? '',
            'name' => $raw['member_name'] ?? '',
            'reason' => $reason,
        ];
    }
}

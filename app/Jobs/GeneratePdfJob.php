<?php

namespace App\Jobs;

use App\Services\PdfService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * Queued PDF generation (plan Step 0: heavy multi-page reports must not
 * render inside a web request — 5 managers printing at once must not OOM
 * a small VPS). A cache lock keeps at most 2 Chromium renders running at
 * any time; excess jobs wait their turn on the queue.
 */
class GeneratePdfJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public string $view,
        public array $data,
        public string $outputDiskPath,
    ) {}

    public function handle(PdfService $pdf): void
    {
        $lock = Cache::lock('pdf-render-slot-'.(crc32($this->outputDiskPath) % 2), 120);
        $lock->block(110);

        try {
            $bytes = $pdf->renderView($this->view, $this->data);
            Storage::put($this->outputDiskPath, $bytes);
        } finally {
            $lock->release();
        }
    }
}

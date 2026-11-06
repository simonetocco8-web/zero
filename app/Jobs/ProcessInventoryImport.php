<?php

namespace App\Jobs;

use App\Models\InventoryImport;
use App\Services\InventoryImport\ImportWorkflow;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ProcessInventoryImport implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 90;

    public int $backoff = 15;

    public function __construct(public int $importId) {}

    public function handle(ImportWorkflow $workflow): void
    {
        try {
            if ($workflow->process($this->importId)) {
                $workflow->enqueue($this->importId);
            }
        } catch (Throwable) {
            // Queue logs must not contain CSV cells or SQL bindings from the source file.
            throw new \RuntimeException('Elaborazione importazione '.$this->importId.' non riuscita. Riprovare dopo aver verificato piano, autorizzazioni e database.');
        }
    }

    public function failed(?Throwable $exception): void
    {
        InventoryImport::whereKey($this->importId)->whereIn('status', ['queued', 'processing'])->update(['status' => 'failed', 'failure' => 'Elaborazione interrotta. Le righe già registrate sono conservate. Verifica piano, autorizzazioni e coda, poi riprendi.']);
    }
}

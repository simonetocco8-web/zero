<?php

namespace App\Jobs;

use App\Models\InventoryImport;
use App\Services\InventoryImport\ImportWorkflow;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ValidateInventoryImport implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public int $backoff = 15;

    public function __construct(public int $importId) {}

    public function handle(ImportWorkflow $workflow): void
    {
        $import = InventoryImport::findOrFail($this->importId);
        if ($import->status !== 'validating') {
            return;
        }
        try {
            $workflow->preview($import->retailer->user, $import);
        } catch (Throwable) {
            throw new \RuntimeException('Validazione importazione '.$this->importId.' non riuscita. Nessuna giacenza è stata modificata.');
        }
    }

    public function failed(?Throwable $exception): void
    {
        InventoryImport::whereKey($this->importId)->where('status', 'validating')->update(['status' => 'failed', 'failure' => 'Validazione interrotta. Nessuna giacenza è stata modificata. Verifica piano e coda e riprendi.']);
    }
}

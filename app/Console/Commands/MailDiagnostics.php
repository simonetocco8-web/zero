<?php

namespace App\Console\Commands;

use App\Services\DeliveringMailerGuard;
use Illuminate\Console\Command;

class MailDiagnostics extends Command
{
    protected $signature = 'mail:diagnose';

    protected $description = 'Mostra soltanto mailer, transport, host, porta e mittente, senza credenziali';

    public function handle(DeliveringMailerGuard $guard): int
    {
        foreach ($guard->diagnostics() as $label => $value) {
            $this->line($label.': '.$value);
        }

        return self::SUCCESS;
    }
}

<?php

namespace Tests\Feature;

use Symfony\Component\Process\Process;
use Tests\TestCase;

class DeploymentReadinessTest extends TestCase
{
    public function test_example_environment_boots_durable_queue_mail_and_encrypted_sessions(): void
    {
        $script = tempnam(sys_get_temp_dir(), 'zero-deployment-env-');
        file_put_contents($script, <<<'CODE'
<?php
require $argv[1].'/vendor/autoload.php';
$set = function ($name, $value) {
    putenv($name.'='.$value);
    $_ENV[$name] = $_SERVER[$name] = $value;
};
foreach (Dotenv\Dotenv::createArrayBacked($argv[1], '.env.example')->load() as $name => $value) {
    $set($name, $value);
}
$set('APP_ENV', 'production');
$set('APP_DEBUG', 'false');
$set('APP_KEY', 'base64:'.base64_encode(random_bytes(32)));
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (config('app.env') !== 'production' || config('app.debug') || !config('session.encrypt')) {
    throw new RuntimeException('Invalid production/session configuration.');
}
if (config('queue.connections.database.connection') !== null || config('queue.connections.database.retry_after') <= config('integrations.lease_seconds')) {
    throw new RuntimeException('Invalid queue connection or retry timeout.');
}
if (!(app('queue')->connection('database') instanceof Illuminate\Queue\DatabaseQueue)) {
    throw new RuntimeException('Durable queue cannot be initialized.');
}
if (!(app('mail.manager')->mailer('log') instanceof Illuminate\Mail\Mailer)) {
    throw new RuntimeException('Development mailer cannot be initialized.');
}
if (config('filesystems.disks.local.root') !== storage_path('app/private')) {
    throw new RuntimeException('Inventory storage is not private.');
}
$encrypter = app('encrypter');
if ($encrypter->decryptString($encrypter->encryptString('readiness')) !== 'readiness') {
    throw new RuntimeException('Encryption cannot round trip.');
}
echo 'Deployment environment ready';
CODE);
        try {
            $process = new Process([PHP_BINARY, $script, base_path()], base_path(), ['APP_CONFIG_CACHE' => $script.'.cache']);
            $process->setTimeout(20);
            $process->mustRun();
            $this->assertSame('Deployment environment ready', $process->getOutput());
        } finally {
            @unlink($script);
            @unlink($script.'.cache');
        }
    }

    public function test_scheduler_registers_publication_and_integration_recovery(): void
    {
        $this->artisan('schedule:list')
            ->expectsOutputToContain('store:sync')
            ->expectsOutputToContain('integrations:retry')
            ->expectsOutputToContain('billing:sync')
            ->assertSuccessful();
    }
}

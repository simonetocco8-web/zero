<?php

use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\Console\Output\BufferedOutput;

// Installer temporaneo: eliminare questo file dopo la prima installazione.
// Non passa dal routing HTTP Laravel e non accetta comandi dalla richiesta.
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '0'); // Anche i log PHP potrebbero contenere parametri sensibili.
error_reporting(E_ALL);
header('Content-Type: text/plain; charset=UTF-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");

$respond = static function (int $status, string $message): never {
    http_response_code($status);
    echo $message."\n";
    exit;
};

$root = dirname(__DIR__);
$marker = $root.'/storage/app/installation-complete';
if (PHP_SAPI === 'cli' || file_exists($marker) || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    $respond(404, 'Not found');
}

$stage = 'autorizzazione';
$authorized = false;
$lock = null;
$bufferLevel = ob_get_level();
ob_start();

try {
    require $root.'/vendor/autoload.php';
    // Leggere il token anche dopo config:cache, senza dipendere dal config Laravel.
    $values = Dotenv\Dotenv::createArrayBacked($root)->safeLoad();
    $token = $_ENV['ZERO_INSTALL_TOKEN'] ?? $_SERVER['ZERO_INSTALL_TOKEN'] ?? getenv('ZERO_INSTALL_TOKEN');
    if ($token === false || $token === null) {
        $token = $values['ZERO_INSTALL_TOKEN'] ?? '';
    }
    $provided = $_SERVER['HTTP_X_ZERO_INSTALL_TOKEN'] ?? $_POST['token'] ?? '';
    if (! is_string($token) || $token === '' || ! is_string($provided) || ! hash_equals($token, $provided)) {
        while (ob_get_level() > $bufferLevel) {
            ob_end_clean();
        }
        $respond(404, 'Not found');
    }
    $authorized = true;

    if (($_SERVER['HTTPS'] ?? '') !== 'on' && ($_SERVER['SERVER_PORT'] ?? '') !== '443') {
        throw new RuntimeException('HTTPS required.');
    }
    // Non conservare il token nella request che il framework può acquisire.
    unset($_POST['token'], $_SERVER['HTTP_X_ZERO_INSTALL_TOKEN']);
    $stage = 'lock';
    $lock = fopen($root.'/storage/app/installation.lock', 'c');
    if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
        while (ob_get_level() > $bufferLevel) {
            ob_end_clean();
        }
        $respond(409, 'Installazione già in corso.');
    }
    clearstatcache(true, $marker);
    if (file_exists($marker)) {
        while (ob_get_level() > $bufferLevel) {
            ob_end_clean();
        }
        $respond(404, 'Not found');
    }

    $stage = 'bootstrap';
    $app = require $root.'/bootstrap/app.php';
    $kernel = $app->make(Kernel::class);
    $kernel->bootstrap();
    // La chiave deve essere stata generata offline, mai tramite questo script.
    if (! $app->environment('production') || $app['config']->get('app.debug') || $app['config']->get('app.key') === '') {
        throw new RuntimeException('Invalid production configuration.');
    }
    $app->make('encrypter'); // Verifica formato/lunghezza APP_KEY senza stamparla.

    $stage = 'database';
    $connection = $app->make('db')->connection();
    $connection->getPdo();
    if ($connection->getSchemaBuilder()->getTables() !== []) {
        // Impedisce di usare il primo installer per aggiornare un DB esistente.
        throw new RuntimeException('First installation requires an empty database.');
    }

    $commands = [
        ['migrate', ['--force' => true]],
        ['db:seed', ['--class' => 'Database\\Seeders\\PlanSeeder', '--force' => true]],
        // Eseguire e verificare separatamente i comandi di cache richiesti.
        ['optimize', ['--except' => 'config,routes,views']],
        ['config:cache', []],
        ['route:cache', []],
        ['view:cache', []],
    ];
    foreach ($commands as [$command, $arguments]) {
        $stage = $command;
        $output = new BufferedOutput;
        if ($kernel->call($command, $arguments + ['--no-interaction' => true], $output) !== 0) {
            throw new RuntimeException('Installation command failed.');
        }
        if ($command === 'optimize' && ! file_exists($app->getCachedEventsPath())) {
            throw new RuntimeException('Event cache not created.');
        }
    }

    $stage = 'marker';
    if (file_put_contents($marker, gmdate('c')."\n", LOCK_EX) === false) {
        throw new RuntimeException('Cannot write installation marker.');
    }
    chmod($marker, 0600);
    while (ob_get_level() > $bufferLevel) {
        ob_end_clean();
    }
    $respond(200, 'Installazione completata. Eliminare install-zero.php e ZERO_INSTALL_TOKEN dal .env.');
} catch (Throwable) {
    while (ob_get_level() > $bufferLevel) {
        ob_end_clean();
    }
    // Non esporre exception message, output Artisan, stack trace o configurazione.
    if (! $authorized) {
        $respond(404, 'Not found');
    }
    $respond(500, 'Installazione non completata: '.$stage.'. Contattare il supporto hosting.');
}

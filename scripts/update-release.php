<?php

// Private, temporary cPanel Cron/CLI entry point. Never expose this file through HTTP.
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Console\Output\BufferedOutput;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
ini_set('display_errors', '0');
$root = dirname(__DIR__);
$stage = 'preflight';
$lock = null;
$receipt = null;
$journal = [];
$exit = 1;
ob_start();
try {
    $options = getopt('', ['commit:', 'backup:', 'run']);
    $stage = 'manifest';
    $manifest = json_decode(file_get_contents($root.'/deployment/release.json'), true, 512, JSON_THROW_ON_ERROR);
    $commit = $options['commit'] ?? '';
    $backup = $options['backup'] ?? '';
    if (($manifest['version'] ?? null) !== 1 || ! is_string($commit) || ! preg_match('/^[a-f0-9]{40}$/D', $commit) || ! hash_equals($manifest['commit'], $commit) || ($manifest['source_dirty'] ?? true)) {
        throw new RuntimeException('Invalid release.');
    }
    if (! is_string($backup) || ! preg_match('/^[a-zA-Z0-9_.-]{8,100}$/D', $backup)) {
        throw new RuntimeException('Backup reference required.');
    }
    require $root.'/vendor/autoload.php';
    $app = require $root.'/bootstrap/app.php';
    $kernel = $app->make(Kernel::class);
    $kernel->bootstrap();
    $stage = 'environment';
    if (! $app->environment('production') || config('app.debug') || DB::getDriverName() !== 'mysql') {
        throw new RuntimeException('Production MySQL required.');
    }
    if (! Schema::hasTable('migrations') || ! Schema::hasTable('users') || ! Schema::hasTable('retailers')) {
        throw new RuntimeException('Existing application required.');
    }
    if (! is_dir($root.'/storage/app/release-updates') && ! mkdir($root.'/storage/app/release-updates', 0700, true)) {
        throw new RuntimeException('Private journal unavailable.');
    }
    $lock = fopen($root.'/storage/app/release-updates/update.lock', 'c');
    if (! $lock || ! flock($lock, LOCK_EX | LOCK_NB)) {
        throw new RuntimeException('Update in progress.');
    }
    $receipt = $root.'/storage/app/release-updates/'.$commit.'.json';
    if (file_exists($receipt)) {
        throw new RuntimeException('Release already attempted.');
    }
    $stage = 'migration_manifest';
    $applied = DB::table('migrations')->orderBy('id')->pluck('migration')->all();
    $files = [];
    foreach (glob($root.'/database/migrations/*.php') as $path) {
        $files[pathinfo($path, PATHINFO_FILENAME)] = hash_file('sha256', $path);
    }
    if ($files !== $manifest['migrations'] || array_diff($applied, array_keys($files))) {
        throw new RuntimeException('Migration manifest mismatch.');
    }
    $pending = array_values(array_diff(array_keys($files), $applied));
    $stage = 'migration_allowlist';
    if (array_diff($pending, $manifest['approved_additive'])) {
        throw new RuntimeException('Unreviewed or non-additive pending migrations.');
    }
    // If a previous managed update exists, applied migration files must remain unchanged.
    foreach (glob($root.'/storage/app/release-updates/*.json') as $previous) {
        $history = json_decode(file_get_contents($previous), true, 512, JSON_THROW_ON_ERROR);
        foreach ($history['migration_hashes'] ?? [] as $name => $hash) {
            if (! isset($files[$name]) || ! hash_equals($hash, $files[$name])) {
                throw new RuntimeException('Historical migration changed.');
            }
        }
    }
    if (! array_key_exists('run', $options)) {
        ob_end_clean();
        echo json_encode(['commit' => $commit, 'mode' => 'preflight', 'pending' => $pending], JSON_THROW_ON_ERROR)."\n";
        exit(0);
    }
    $stage = 'maintenance';
    if (! $app->isDownForMaintenance()) {
        throw new RuntimeException('Maintenance mode required.');
    }
    $journal = ['commit' => $commit, 'backup_reference' => $backup, 'started_at' => gmdate(DATE_ATOM), 'status' => 'started', 'pending' => $pending, 'migration_hashes' => $files];
    $stage = 'journal';
    $handle = fopen($receipt, 'x');
    if (! $handle) {
        throw new RuntimeException('Cannot claim release.');
    }
    chmod($receipt, 0600);
    $encoded = json_encode($journal, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    if (fwrite($handle, $encoded) !== strlen($encoded)) {
        throw new RuntimeException('Cannot persist journal.');
    }
    fflush($handle);
    fclose($handle);
    // Fixed command allowlist, no command/path/SQL accepted from the operator.
    $commands = [
        ['config:clear', []], ['route:clear', []], ['view:clear', []],
        ['migrate', ['--force' => true, '--no-interaction' => true]],
        ['config:cache', []], ['route:cache', []], ['view:cache', []], ['event:cache', []],
        ['queue:restart', []],
    ];
    foreach ($commands as [$command, $arguments]) {
        $stage = $command;
        if ($kernel->call($command, $arguments, new BufferedOutput) !== 0) {
            throw new RuntimeException('Release command failed.');
        }
    }
    $journal['status'] = 'completed';
    $journal['completed_at'] = gmdate(DATE_ATOM);
    if (file_put_contents($receipt, json_encode($journal, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), LOCK_EX) === false) {
        throw new RuntimeException('Cannot finalize journal.');
    }
    $exit = 0;
} catch (Throwable) {
    if ($journal && $receipt) {
        $journal['status'] = 'failed';
        $journal['failed_phase'] = $stage;
        file_put_contents($receipt, json_encode($journal, JSON_PRETTY_PRINT), LOCK_EX);
    }
} finally {
    if (is_resource($lock)) {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
    while (ob_get_level()) {
        ob_end_clean();
    }
}
fwrite(STDOUT, $exit === 0 ? "Update completed; verify before artisan up.\n" : "Update refused or failed at $stage; keep maintenance and inspect private journal.\n");
exit($exit);

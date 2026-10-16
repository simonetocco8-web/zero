<?php

// Build-time only; contains no environment values or production credentials.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$root = $argv[1] ?? getcwd();
$target = $argv[2] ?? throw new RuntimeException('Manifest target required.');
$commit = getenv('GITHUB_SHA') ?: trim((string) shell_exec('git rev-parse HEAD 2>/dev/null'));
if (! preg_match('/^[a-f0-9]{40}$/D', $commit)) {
    throw new RuntimeException('Full source commit required.');
}
$migrations = [];
foreach (glob($root.'/database/migrations/*.php') as $path) {
    $migrations[pathinfo($path, PATHINFO_FILENAME)] = hash_file('sha256', $path);
}
$approved = json_decode(file_get_contents($root.'/deployment/additive-migrations.json'), true, 512, JSON_THROW_ON_ERROR);
$gitRoot = trim((string) shell_exec('git rev-parse --show-toplevel 2>/dev/null'));
$actualCommit = trim((string) shell_exec('git rev-parse HEAD 2>/dev/null'));
$dirty = $gitRoot !== realpath($root) || $actualCommit !== $commit || trim((string) shell_exec('git status --porcelain 2>/dev/null')) !== '';
$manifest = ['version' => 1, 'commit' => $commit, 'source_dirty' => $dirty, 'built_at' => gmdate(DATE_ATOM), 'migrations' => $migrations, 'approved_additive' => array_values(array_intersect($approved, array_keys($migrations)))];
file_put_contents($target, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n", LOCK_EX);

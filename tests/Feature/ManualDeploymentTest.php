<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use PDO;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ManualDeploymentTest extends TestCase
{
    private string $directory;

    private string $token;

    private string $key;

    private ?Process $server = null;

    private int $port;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/zero-install-test-'.bin2hex(random_bytes(8));
        $this->token = bin2hex(random_bytes(32));
        $this->key = 'base64:'.base64_encode(random_bytes(32));
        foreach (['public', 'bootstrap/cache', 'storage/app/private', 'storage/framework/cache/data', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs'] as $path) {
            File::ensureDirectoryExists($this->directory.'/'.$path);
        }
        foreach (['app', 'config', 'database', 'resources', 'routes', 'vendor'] as $path) {
            symlink(base_path($path), $this->directory.'/'.$path);
        }
        copy(base_path('bootstrap/app.php'), $this->directory.'/bootstrap/app.php');
        copy(public_path('install-zero.php'), $this->directory.'/public/install-zero.php');
        file_put_contents($this->directory.'/composer.json', file_get_contents(base_path('composer.json')));
        touch($this->directory.'/database.sqlite');
        file_put_contents($this->directory.'/.env', 'ZERO_INSTALL_TOKEN='.$this->token."\n");
        // TLS is simulated only in this private test router, never in the installer.
        file_put_contents($this->directory.'/router.php', <<<'PHP'
<?php
if (getenv('ZERO_TEST_TLS') === 'true') {
    $_SERVER['HTTPS'] = 'on';
}
require __DIR__.'/public/install-zero.php';
PHP);
    }

    protected function tearDown(): void
    {
        $this->server?->stop();
        // Remove symlinks first: never recursively delete the shared application.
        foreach (['app', 'config', 'database', 'resources', 'routes', 'vendor'] as $path) {
            @unlink($this->directory.'/'.$path);
        }
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    private function startServer(array $overrides = []): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $this->port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);
        $environment = array_merge([
            'APP_ENV' => 'production', 'APP_DEBUG' => 'false', 'APP_KEY' => $this->key,
            'APP_URL' => 'https://example.test', 'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => $this->directory.'/database.sqlite', 'DB_URL' => '',
            'APP_CONFIG_CACHE' => $this->directory.'/bootstrap/cache/config.php',
            'APP_ROUTES_CACHE' => $this->directory.'/bootstrap/cache/routes.php',
            'APP_EVENTS_CACHE' => $this->directory.'/bootstrap/cache/events.php',
            'APP_SERVICES_CACHE' => $this->directory.'/bootstrap/cache/services.php',
            'APP_PACKAGES_CACHE' => $this->directory.'/bootstrap/cache/packages.php',
            'VIEW_COMPILED_PATH' => $this->directory.'/storage/framework/views',
            'ZERO_INSTALL_TOKEN' => false, 'ZERO_TEST_TLS' => 'true',
            'LOG_CHANNEL' => 'null', 'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array', 'MAIL_MAILER' => 'array',
            'QUEUE_CONNECTION' => 'sync', 'STORE_DRIVER' => 'fake',
        ], $overrides);
        $this->server = new Process([PHP_BINARY, '-S', '127.0.0.1:'.$this->port, '-t', $this->directory.'/public', $this->directory.'/router.php'], $this->directory, $environment);
        $this->server->start();
        for ($attempt = 0; $attempt < 100; $attempt++) {
            $connection = @fsockopen('127.0.0.1', $this->port);
            if ($connection !== false) {
                fclose($connection);

                return;
            }
            usleep(20000);
        }
        $this->fail('Installer test server did not start.');
    }

    private function request(?string $token = null, string $method = 'POST', string $query = ''): array
    {
        $context = stream_context_create(['http' => [
            'method' => $method,
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => http_build_query($token === null ? [] : ['token' => $token]),
            'ignore_errors' => true,
            'timeout' => 60,
        ]]);
        $body = file_get_contents('http://127.0.0.1:'.$this->port.'/install-zero.php'.$query, false, $context);
        preg_match('/\s(\d{3})\s/', $http_response_header[0], $match);
        $this->assertStringNotContainsString($this->token, $body);
        $this->assertStringNotContainsString($this->key, $body);
        $this->assertStringNotContainsString('Stack trace', $body);

        return [(int) $match[1], $body];
    }

    public function test_missing_or_empty_configuration_disables_installer(): void
    {
        file_put_contents($this->directory.'/.env', 'ZERO_INSTALL_TOKEN='."\n");
        $this->startServer();
        $this->assertSame(404, $this->request($this->token)[0]);
        unlink($this->directory.'/.env');
        $this->assertSame(404, $this->request($this->token)[0]);
        $this->assertFileDoesNotExist($this->directory.'/bootstrap/cache/config.php');
    }

    public function test_invalid_token_missing_token_and_get_are_rejected_without_database_changes(): void
    {
        $this->startServer();
        $this->assertSame(404, $this->request('wrong')[0]);
        $this->assertSame(404, $this->request()[0]);
        $this->assertSame(404, $this->request(null, 'GET', '?token='.$this->token)[0]);
        $this->assertSame(0, filesize($this->directory.'/database.sqlite'));
    }

    public function test_complete_marker_disables_installer(): void
    {
        file_put_contents($this->directory.'/storage/app/installation-complete', 'already installed');
        $this->startServer();
        $this->assertSame(404, $this->request($this->token)[0]);
    }

    public function test_installer_requires_https_and_does_not_disclose_errors(): void
    {
        $this->startServer(['ZERO_TEST_TLS' => 'false']);
        [$status, $body] = $this->request($this->token);
        $this->assertSame(500, $status);
        $this->assertStringNotContainsString('RuntimeException', $body);
        $this->assertFileDoesNotExist($this->directory.'/storage/app/installation-complete');
    }

    public function test_concurrent_execution_is_rejected(): void
    {
        $lock = fopen($this->directory.'/storage/app/installation.lock', 'c');
        flock($lock, LOCK_EX);
        try {
            $this->startServer();
            $this->assertSame(409, $this->request($this->token)[0]);
            $this->assertSame(0, filesize($this->directory.'/database.sqlite'));
        } finally {
            fclose($lock);
        }
    }

    public function test_invalid_app_key_is_rejected_before_migrations(): void
    {
        $this->startServer(['APP_KEY' => 'invalid-key']);
        [$status, $body] = $this->request($this->token);
        $this->assertSame(500, $status);
        $this->assertStringContainsString('bootstrap', $body);
        $this->assertStringNotContainsString('invalid-key', $body);
        $this->assertSame(0, filesize($this->directory.'/database.sqlite'));
        $this->assertFileDoesNotExist($this->directory.'/storage/app/installation-complete');
    }

    public function test_database_connection_failure_has_no_sensitive_details(): void
    {
        $this->startServer(['DB_DATABASE' => $this->directory.'/missing-private-db.sqlite']);
        [$status, $body] = $this->request($this->token);
        $this->assertSame(500, $status);
        $this->assertStringContainsString('database', $body);
        $this->assertStringNotContainsString($this->directory, $body);
        $this->assertStringNotContainsString('SQLSTATE', $body);
        $this->assertFileDoesNotExist($this->directory.'/storage/app/installation-complete');
    }

    public function test_existing_database_is_never_migrated_or_reset(): void
    {
        $db = new PDO('sqlite:'.$this->directory.'/database.sqlite');
        $db->exec('CREATE TABLE existing_data (value TEXT)');
        $db->exec("INSERT INTO existing_data VALUES ('preserve')");
        $this->startServer();
        [$status, $body] = $this->request($this->token);
        $this->assertSame(500, $status);
        $this->assertStringContainsString('database', $body);
        $this->assertSame('preserve', $db->query('SELECT value FROM existing_data')->fetchColumn());
        $this->assertFileDoesNotExist($this->directory.'/storage/app/installation-complete');
    }

    public function test_initial_installation_migrates_seeds_caches_and_cannot_be_replayed(): void
    {
        $this->startServer();
        [$status, $body] = $this->request($this->token);
        $this->assertSame(200, $status, $body);
        $this->assertFileExists($this->directory.'/storage/app/installation-complete');
        foreach (['config', 'routes', 'events'] as $cache) {
            $this->assertFileExists($this->directory.'/bootstrap/cache/'.$cache.'.php');
        }
        $this->assertNotEmpty(glob($this->directory.'/storage/framework/views/*.php'));
        $db = new PDO('sqlite:'.$this->directory.'/database.sqlite');
        $this->assertSame(2, (int) $db->query('SELECT COUNT(*) FROM plans')->fetchColumn());
        $this->assertSame(0, (int) $db->query('SELECT COUNT(*) FROM users')->fetchColumn());
        $this->assertStringNotContainsString($this->token, file_get_contents($this->directory.'/bootstrap/cache/config.php'));
        $this->assertSame(404, $this->request($this->token)[0]);
    }

    public function test_local_key_generator_produces_a_valid_random_key_and_token(): void
    {
        $process = new Process([PHP_BINARY, base_path('scripts/generate-app-key.php')]);
        $process->mustRun();
        $this->assertMatchesRegularExpression('/^APP_KEY=base64:([A-Za-z0-9+\/]{43}=)\nZERO_INSTALL_TOKEN=[a-f0-9]{64}\n$/', $process->getOutput());
        preg_match('/APP_KEY=base64:(.+)/', $process->getOutput(), $key);
        $this->assertSame(32, strlen(base64_decode($key[1], true)));
    }
}

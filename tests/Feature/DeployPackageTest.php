<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class DeployPackageTest extends TestCase
{
    public function test_package_preserves_required_assets_and_excludes_secrets_and_runtime_state(): void
    {
        $temporary = sys_get_temp_dir().'/zero-package-test-'.bin2hex(random_bytes(8));
        $source = $temporary.'/source';
        foreach (['app', 'bootstrap/cache', 'config', 'database', 'public/build', 'resources', 'routes', 'vendor/tests', 'storage/logs', 'tests', '.github', 'node_modules'] as $path) {
            File::ensureDirectoryExists($source.'/'.$path);
        }
        foreach (['vendor/autoload.php', 'public/build/manifest.json', 'public/.htaccess', 'app/Example.php', 'artisan', 'composer.json', 'composer.lock', '.env', '.env.example', 'public/.env.production', 'bootstrap/cache/config.php', 'storage/logs/app.log', 'storage/private-upload.jpg', 'database/database.sqlite', 'tests/example.php', 'vendor/tests/dev.php', '.github/workflow.yml', 'node_modules/module.js'] as $path) {
            file_put_contents($source.'/'.$path, 'fixture');
        }
        try {
            $process = new Process(['bash', base_path('scripts/build-deploy-package.sh'), $temporary.'/output'], $source);
            $process->mustRun();
            $archive = $temporary.'/output/zeromagazzino-deploy.zip';
            $listing = new Process(['unzip', '-Z1', $archive]);
            $listing->mustRun();
            $entries = explode("\n", trim($listing->getOutput()));
            foreach (['vendor/autoload.php', 'public/build/manifest.json', 'public/.htaccess', 'app/Example.php', 'artisan', 'composer.json', 'composer.lock', 'storage/app/', 'storage/app/private/', 'storage/framework/cache/', 'storage/framework/sessions/', 'storage/framework/views/', 'storage/logs/', 'bootstrap/cache/'] as $required) {
                $this->assertContains($required, $entries);
            }
            foreach (['.env', '.env.example', 'public/.env.production', 'bootstrap/cache/config.php', 'storage/logs/app.log', 'storage/private-upload.jpg', 'database/database.sqlite', 'tests/example.php', 'vendor/tests/dev.php', '.github/workflow.yml', 'node_modules/module.js'] as $excluded) {
                $this->assertNotContains($excluded, $entries);
            }
            $this->assertFileExists($source.'/.env');
            $this->assertFileExists($source.'/bootstrap/cache/config.php');
        } finally {
            File::deleteDirectory($temporary);
        }
    }
}

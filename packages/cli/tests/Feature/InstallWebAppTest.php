<?php

use App\Actions\Install\Shared\InstallWebApp;
use App\Data\Install\InstallContext;
use App\Services\ConfigManager;

require_once __DIR__.'/../Helpers/TestLogger.php';

function createMockConfigManager(string $webAppPath): ConfigManager
{
    $configManager = Mockery::mock(ConfigManager::class);
    $configManager->shouldReceive('getWebAppPath')->andReturn($webAppPath);
    $configManager->shouldReceive('getReverbConfig')->andReturn([
        'app_id' => 'test-app-id',
        'app_key' => 'test-app-key',
        'app_secret' => 'test-app-secret',
        'internal_port' => 8080,
    ]);

    return $configManager;
}

it('skips installation when web app source not found', function () {
    $testDir = sys_get_temp_dir().'/orbit-webapp-test-'.uniqid();
    $destDir = "{$testDir}/dest";

    mkdir($testDir, 0755, true);
    mkdir($destDir, 0755, true);

    $configManager = createMockConfigManager($destDir);
    $context = new InstallContext(tld: 'test');
    $logger = createTestLogger();

    $action = new InstallWebApp($configManager);
    $result = $action->handle($context, $logger);

    // Should skip when source not found
    expect($result->isSuccess())->toBeTrue();

    deleteDirectory($testDir);
});

it('skips installation when web app already exists', function () {
    $testDir = sys_get_temp_dir().'/orbit-webapp-test-'.uniqid();
    $destDir = "{$testDir}/dest";

    mkdir($testDir, 0755, true);
    mkdir($destDir, 0755, true);

    $configManager = createMockConfigManager($destDir);
    $context = new InstallContext(tld: 'test');
    $logger = createTestLogger();

    // Create artisan file to simulate existing installation
    file_put_contents("{$destDir}/artisan", "#!/usr/bin/env php\n<?php\n");

    $action = new InstallWebApp($configManager);
    $result = $action->handle($context, $logger);

    expect($result->isSuccess())->toBeTrue();

    deleteDirectory($testDir);
});

it('has database creation before migration in source code', function () {
    $sourceCode = file_get_contents(base_path('app/Actions/Install/Shared/InstallWebApp.php'));

    // Find line numbers
    $lines = explode("\n", $sourceCode);
    $dbCreateLine = null;
    $migrateLine = null;

    foreach ($lines as $index => $line) {
        if (strpos($line, 'File::put($dbPath') !== false) {
            $dbCreateLine = $index + 1;
        }
        if (strpos($line, 'php artisan migrate') !== false) {
            $migrateLine = $index + 1;
        }
    }

    expect($dbCreateLine)->not->toBeNull();
    expect($migrateLine)->not->toBeNull();
    expect($dbCreateLine)->toBeLessThan($migrateLine);
});

it('includes error output in migration failure message', function () {
    $sourceCode = file_get_contents(base_path('app/Actions/Install/Shared/InstallWebApp.php'));

    expect($sourceCode)->toContain('Failed to run web app migrations: \'.$migrateResult->errorOutput()');
});

it('sets APP_DEBUG=true in environment template', function () {
    $sourceCode = file_get_contents(base_path('app/Actions/Install/Shared/InstallWebApp.php'));

    expect($sourceCode)->toContain('APP_DEBUG=true');
});

it('includes error output in orbit:init failure message', function () {
    $sourceCode = file_get_contents(base_path('app/Actions/Install/Shared/InstallWebApp.php'));

    expect($sourceCode)->toContain('Failed to seed web app - it may need manual setup: \'.$seedResult->errorOutput()');
});

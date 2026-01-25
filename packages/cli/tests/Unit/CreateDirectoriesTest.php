<?php

use App\Actions\Install\Shared\CreateDirectories;
use App\Data\Install\InstallContext;

require_once __DIR__.'/../Helpers/TestLogger.php';

beforeEach(function () {
    $this->testDir = sys_get_temp_dir().'/orbit-install-test-'.uniqid();
});

afterEach(function () {
    if (is_dir($this->testDir)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->testDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->testDir);
    }
});

it('creates all required directories', function () {
    $context = new InstallContext(
        configDir: $this->testDir,
        homeDir: dirname($this->testDir),
    );
    $logger = createTestLogger();

    $action = new CreateDirectories;
    $result = $action->handle($context, $logger);

    expect($result->isSuccess())->toBeTrue();
    expect(is_dir("{$this->testDir}/php"))->toBeTrue();
    expect(is_dir("{$this->testDir}/caddy"))->toBeTrue();
    expect(is_dir("{$this->testDir}/dns"))->toBeTrue();
    expect(is_dir("{$this->testDir}/postgres"))->toBeTrue();
    expect(is_dir("{$this->testDir}/redis"))->toBeTrue();
    expect(is_dir("{$this->testDir}/mailpit"))->toBeTrue();
    expect(is_dir("{$this->testDir}/horizon"))->toBeTrue();
    expect(is_dir("{$this->testDir}/logs"))->toBeTrue();
    expect(is_dir("{$this->testDir}/logs/provision"))->toBeTrue();
});

it('skips existing directories', function () {
    mkdir("{$this->testDir}/php", 0755, true);

    $context = new InstallContext(
        configDir: $this->testDir,
        homeDir: dirname($this->testDir),
    );
    $logger = createTestLogger();

    $action = new CreateDirectories;
    $result = $action->handle($context, $logger);

    expect($result->isSuccess())->toBeTrue();
    expect(is_dir("{$this->testDir}/php"))->toBeTrue();
    expect(is_dir("{$this->testDir}/caddy"))->toBeTrue();
});

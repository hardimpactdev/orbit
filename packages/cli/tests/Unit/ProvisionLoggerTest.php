<?php

use App\Services\ProvisionLogger;

beforeEach(function () {
    $this->testHome = sys_get_temp_dir().'/orbit-tests-'.getmypid();
    $this->originalHome = $_SERVER['HOME'] ?? null;
    $_SERVER['HOME'] = $this->testHome;

    // Pre-create the directory structure to avoid permission issues
    $logsDir = $this->testHome.'/.config/orbit/logs/provision';
    if (! is_dir($logsDir)) {
        mkdir($logsDir, 0755, true);
    }
});

afterEach(function () {
    if ($this->originalHome !== null) {
        $_SERVER['HOME'] = $this->originalHome;
    } else {
        unset($_SERVER['HOME']);
    }

    // Clean up test directory
    if (is_dir($this->testHome)) {
        deleteDirectory($this->testHome);
    }
});

it('creates log file when slug is provided', function () {
    $logger = new ProvisionLogger(slug: 'test-project');
    $logger->info('Test message');

    $logFile = $this->testHome.'/.config/orbit/logs/provision/test-project.log';
    expect(file_exists($logFile))->toBeTrue();

    $content = file_get_contents($logFile);
    expect($content)->toBeString()->toContain('Test message');
});

it('logs info messages', function () {
    $logger = new ProvisionLogger(slug: 'test-project');
    $logger->info('Info message');

    $logFile = $this->testHome.'/.config/orbit/logs/provision/test-project.log';
    expect(file_exists($logFile))->toBeTrue();

    $content = file_get_contents($logFile);
    expect($content)->toBeString()->toContain('Info message');
});

it('logs warning messages with prefix', function () {
    $logger = new ProvisionLogger(slug: 'test-project');
    $logger->warn('Warning message');

    $logFile = $this->testHome.'/.config/orbit/logs/provision/test-project.log';
    expect(file_exists($logFile))->toBeTrue();

    $content = file_get_contents($logFile);
    expect($content)->toBeString()->toContain('WARNING: Warning message');
});

it('logs error messages with prefix', function () {
    $logger = new ProvisionLogger(slug: 'test-project');
    $logger->error('Error message');

    $logFile = $this->testHome.'/.config/orbit/logs/provision/test-project.log';
    expect(file_exists($logFile))->toBeTrue();

    $content = file_get_contents($logFile);
    expect($content)->toBeString()->toContain('ERROR: Error message');
});

it('logs status broadcasts', function () {
    $logger = new ProvisionLogger(slug: 'test-project');
    $logger->broadcast('installing_composer');

    $logFile = $this->testHome.'/.config/orbit/logs/provision/test-project.log';
    expect(file_exists($logFile))->toBeTrue();

    $content = file_get_contents($logFile);
    expect($content)->toBeString()->toContain('Status: installing_composer');
});

it('logs broadcast with error', function () {
    $logger = new ProvisionLogger(slug: 'test-project');
    $logger->broadcast('failed', 'Something went wrong');

    $logFile = $this->testHome.'/.config/orbit/logs/provision/test-project.log';
    expect(file_exists($logFile))->toBeTrue();

    $content = file_get_contents($logFile);
    expect($content)->toBeString()->toContain('Status: failed - Error: Something went wrong');
});

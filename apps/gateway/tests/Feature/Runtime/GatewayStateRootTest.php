<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

it('resolves gateway env database and storage under ORBIT_CONFIG_ROOT', function (): void {
    $configRoot = sys_get_temp_dir().'/orbit-config-root-'.bin2hex(random_bytes(4));

    $process = new Process([
        PHP_BINARY,
        '-r',
        'putenv("ORBIT_CONFIG_ROOT='.$configRoot.'"); $paths = require "apps/gateway/bootstrap/orbit_paths.php"; echo json_encode($paths, JSON_THROW_ON_ERROR);',
    ], repo_path());

    $process->mustRun();

    $paths = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

    expect($paths['config_root'])->toBe($configRoot)
        ->and($paths['env_path'])->toBe($configRoot.'/gateway/.env')
        ->and($paths['database_path'])->toBe($configRoot.'/gateway/database')
        ->and($paths['storage_path'])->toBe($configRoot.'/gateway/storage');
});

it('falls back to HOME config root when ORBIT_CONFIG_ROOT is absent', function (): void {
    $home = sys_get_temp_dir().'/orbit-home-'.bin2hex(random_bytes(4));

    $process = new Process([
        PHP_BINARY,
        '-r',
        'putenv("ORBIT_CONFIG_ROOT"); putenv("HOME='.$home.'"); $paths = require "apps/gateway/bootstrap/orbit_paths.php"; echo json_encode($paths, JSON_THROW_ON_ERROR);',
    ], repo_path());

    $process->mustRun();

    $paths = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

    expect($paths['config_root'])->toBe($home.'/.config/orbit')
        ->and($paths['env_path'])->toBe($home.'/.config/orbit/gateway/.env')
        ->and($paths['database_path'])->toBe($home.'/.config/orbit/gateway/database')
        ->and($paths['storage_path'])->toBe($home.'/.config/orbit/gateway/storage');
});

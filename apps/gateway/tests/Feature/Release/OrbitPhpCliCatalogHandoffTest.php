<?php

declare(strict_types=1);

use Orbit\Core\Php\PhpCliArtifactCatalog;

it('populates intentional null catalog slots from manifests and is idempotent', function (): void {
    $fixtureDir = sys_get_temp_dir().'/orbit-php-cli-handoff-'.bin2hex(random_bytes(4));
    $manifestDir = $fixtureDir.'/manifests';
    $catalogPath = $fixtureDir.'/artifact-catalog.build.json';
    mkdir($manifestDir, 0o777, true);

    $catalog = [
        'schema_version' => 1,
        'tool' => 'php-cli',
        'catalog_role' => 'build',
        'artifact_base_url' => 'https://s3.hardimpact.dev/orbit/runtimes/php-cli/sqlite-3.44.6',
        'static_php_cli_version' => '2.8.5',
        'static_php_cli_ext_json_sha256' => '0fe7716d8cb199f34076c06a601b3ff9c8ffbce11d92a0bbd455d9d4f2d18d42',
        'sqlite_version' => '3.44.6',
        'sqlite_source_id' => '863c171b76cd36e0c71662d4a50d84da531cc7fbead893d02459577febdf6396',
        'sqlite_archive_sha256' => 'c25cd42f803d5fb0af5f1a2863c9f529a8fd35177cb24b0f6e970b1cc96f00f0',
        'patch_pins' => [
            '8.5' => '8.5.8',
            '8.4' => '8.4.21',
            '8.3' => '8.3.31',
        ],
        'php_source_sha256' => [
            '8.5.8' => '58910198d19e873048fe87cdfe16bc790025417ede3d1651bfa1c4b533d573f2',
            '8.4.21' => '7cf5d8ab12c3b2016875bcfaec71bef1ef0b07bed6148f2c447577074431f984',
            '8.3.31' => '66410cee07f4b2baeb0843140bb2a2b52ef930b5cf9b3d6e6d158b33aae8fa37',
        ],
        'spc_archive_sha256' => [
            'linux-x86_64' => '523ba4279c54c7a377156c0dd3a36adf92ee64b01e9a7f5e9e2ec084b8e458e5',
            'macos-aarch64' => 'acf2f25d56d0cbf8e65aa82e5054fef555f7be7c5c38046c6e0819f266d83225',
        ],
        'extensions' => [
            'base' => ['bcmath', 'sqlite3'],
            'coverage_extra' => ['pcov'],
        ],
        'artifacts' => [
            '8.5.8' => [
                'coverage' => [
                    'linux-x86_64' => null,
                    'macos-aarch64' => null,
                ],
                'standard' => [
                    'linux-x86_64' => null,
                ],
            ],
            '8.4.21' => [
                'coverage' => [
                    'linux-x86_64' => null,
                    'macos-aarch64' => null,
                ],
                'standard' => [
                    'linux-x86_64' => null,
                ],
            ],
            '8.3.31' => [
                'coverage' => [
                    'linux-x86_64' => null,
                    'macos-aarch64' => null,
                ],
                'standard' => [
                    'linux-x86_64' => null,
                ],
            ],
        ],
        'publication' => [
            'status' => 'unpublished',
            'published_count' => 0,
            'total_count' => 9,
        ],
    ];

    file_put_contents($catalogPath, json_encode($catalog, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

    $sha = str_repeat('ab', 32);
    file_put_contents($manifestDir.'/cell.manifest.json', json_encode([
        'tool' => 'php-cli',
        'php_version' => '8.5.8',
        'variant' => 'coverage',
        'platform' => 'linux-x86_64',
        'filename' => 'php-8.5.8-cli-coverage-linux-x86_64.tar.gz',
        'sha256' => $sha,
    ], JSON_THROW_ON_ERROR));

    $command = sprintf(
        'ORBIT_PHP_CLI_CATALOG=%s %s --manifest-dir %s',
        escapeshellarg($catalogPath),
        escapeshellarg(repo_path('bin/orbit-php-cli-catalog-handoff')),
        escapeshellarg($manifestDir),
    );

    exec($command.' 2>&1', $output, $exitCode);
    expect($exitCode)
        ->toBe(0)
        ->and(implode("\n", $output))
        ->toContain('Updated 1 catalog checksum slots (1/9 published)');

    $after = json_decode((string) file_get_contents($catalogPath), true, flags: JSON_THROW_ON_ERROR);
    expect($after['artifacts']['8.5.8']['coverage']['linux-x86_64'])
        ->toBe($sha)
        ->and($after['artifacts']['8.5.8']['coverage']['macos-aarch64'])
        ->toBeNull()
        ->and($after['publication']['status'])
        ->toBe('partial')
        ->and($after['publication']['published_count'])
        ->toBe(1)
        ->and($after['publication']['total_count'])
        ->toBe(9);

    // Idempotent re-run
    exec($command.' 2>&1', $output2, $exitCode2);
    expect($exitCode2)->toBe(0);
    $again = json_decode((string) file_get_contents($catalogPath), true, flags: JSON_THROW_ON_ERROR);
    expect($again['artifacts']['8.5.8']['coverage']['linux-x86_64'])
        ->toBe($sha)
        ->and($again['publication']['published_count'])
        ->toBe(1);

    // Unknown cell fails clearly
    file_put_contents($manifestDir.'/bad.manifest.json', json_encode([
        'tool' => 'php-cli',
        'php_version' => '9.0.0',
        'variant' => 'coverage',
        'platform' => 'linux-x86_64',
        'filename' => 'php-9.0.0-cli-coverage-linux-x86_64.tar.gz',
        'sha256' => $sha,
    ], JSON_THROW_ON_ERROR));

    exec($command.' 2>&1', $badOutput, $badExit);
    expect($badExit)
        ->not
        ->toBe(0)
        ->and(implode("\n", $badOutput))
        ->toContain('Catalog has no slot for 9.0.0/coverage/linux-x86_64');
});

it('rejects manifests with wrong tool, missing filename, filename mismatch, or duplicate cells', function (): void {
    $fixtureDir = sys_get_temp_dir().'/orbit-php-cli-handoff-neg-'.bin2hex(random_bytes(4));
    $manifestDir = $fixtureDir.'/manifests';
    $catalogPath = $fixtureDir.'/artifact-catalog.build.json';
    mkdir($manifestDir, 0o777, true);

    $catalog = json_decode(
        (string) file_get_contents(repo_path(PhpCliArtifactCatalog::BUILD_CATALOG_RELATIVE_PATH)),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    file_put_contents($catalogPath, json_encode($catalog, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

    $sha = str_repeat('ef', 32);
    $baseCommand = sprintf(
        'ORBIT_PHP_CLI_CATALOG=%s %s --manifest-dir %s',
        escapeshellarg($catalogPath),
        escapeshellarg(repo_path('bin/orbit-php-cli-catalog-handoff')),
        escapeshellarg($manifestDir),
    );

    file_put_contents($manifestDir.'/wrong-tool.manifest.json', json_encode([
        'tool' => 'composer',
        'php_version' => '8.5.8',
        'variant' => 'coverage',
        'platform' => 'linux-x86_64',
        'filename' => 'php-8.5.8-cli-coverage-linux-x86_64.tar.gz',
        'sha256' => $sha,
    ], JSON_THROW_ON_ERROR));

    exec($baseCommand.' 2>&1', $toolOut, $toolExit);
    expect($toolExit)
        ->not
        ->toBe(0)
        ->and(implode("\n", $toolOut))
        ->toContain('Manifest tool must be php-cli');

    unlink($manifestDir.'/wrong-tool.manifest.json');

    // Missing filename must fail closed — never invent the cell name silently.
    file_put_contents($manifestDir.'/no-filename.manifest.json', json_encode([
        'tool' => 'php-cli',
        'php_version' => '8.5.8',
        'variant' => 'coverage',
        'platform' => 'linux-x86_64',
        'sha256' => $sha,
    ], JSON_THROW_ON_ERROR));

    exec($baseCommand.' 2>&1', $missingOut, $missingExit);
    expect($missingExit)
        ->not
        ->toBe(0)
        ->and(implode("\n", $missingOut))
        ->toContain(
            'Manifest filename must be a non-empty string equal to php-8.5.8-cli-coverage-linux-x86_64.tar.gz',
        );

    unlink($manifestDir.'/no-filename.manifest.json');
    file_put_contents($manifestDir.'/empty-filename.manifest.json', json_encode([
        'tool' => 'php-cli',
        'php_version' => '8.5.8',
        'variant' => 'coverage',
        'platform' => 'linux-x86_64',
        'filename' => '',
        'sha256' => $sha,
    ], JSON_THROW_ON_ERROR));

    exec($baseCommand.' 2>&1', $emptyOut, $emptyExit);
    expect($emptyExit)
        ->not
        ->toBe(0)
        ->and(implode("\n", $emptyOut))
        ->toContain(
            'Manifest filename must be a non-empty string equal to php-8.5.8-cli-coverage-linux-x86_64.tar.gz',
        );

    unlink($manifestDir.'/empty-filename.manifest.json');
    file_put_contents($manifestDir.'/bad-name.manifest.json', json_encode([
        'tool' => 'php-cli',
        'php_version' => '8.5.8',
        'variant' => 'coverage',
        'platform' => 'linux-x86_64',
        'filename' => 'php-8.5.8-cli-linux-x86_64.tar.gz',
        'sha256' => $sha,
    ], JSON_THROW_ON_ERROR));

    exec($baseCommand.' 2>&1', $nameOut, $nameExit);
    expect($nameExit)
        ->not
        ->toBe(0)
        ->and(implode("\n", $nameOut))
        ->toContain('Manifest filename mismatch');

    unlink($manifestDir.'/bad-name.manifest.json');
    $cell = [
        'tool' => 'php-cli',
        'php_version' => '8.5.8',
        'variant' => 'standard',
        'platform' => 'linux-x86_64',
        'filename' => 'php-8.5.8-cli-standard-linux-x86_64.tar.gz',
        'sha256' => $sha,
    ];
    file_put_contents($manifestDir.'/a.manifest.json', json_encode($cell, JSON_THROW_ON_ERROR));
    file_put_contents($manifestDir.'/b.manifest.json', json_encode($cell, JSON_THROW_ON_ERROR));

    exec($baseCommand.' 2>&1', $dupOut, $dupExit);
    expect($dupExit)
        ->not
        ->toBe(0)
        ->and(implode("\n", $dupOut))
        ->toContain('Duplicate manifest cell');
});

it('refuses runtime promotion when build catalog lacks artifact_base_url', function (): void {
    $fixtureDir = sys_get_temp_dir().'/orbit-php-cli-promote-base-'.bin2hex(random_bytes(4));
    $manifestDir = $fixtureDir.'/manifests';
    $buildPath = $fixtureDir.'/build.json';
    $runtimePath = $fixtureDir.'/runtime.json';
    mkdir($manifestDir, 0o777, true);

    $build = json_decode(
        (string) file_get_contents(repo_path(PhpCliArtifactCatalog::BUILD_CATALOG_RELATIVE_PATH)),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $sha = str_repeat('11', 32);
    foreach ($build['artifacts'] as $patch => $variants) {
        foreach ($variants as $variant => $platforms) {
            foreach (array_keys($platforms) as $platform) {
                $build['artifacts'][$patch][$variant][$platform] = $sha;
            }
        }
    }
    unset($build['artifact_base_url']);
    $build['publication'] = [
        'status' => 'published',
        'published_count' => 9,
        'total_count' => 9,
    ];

    $runtime = json_decode(
        (string) file_get_contents(repo_path(PhpCliArtifactCatalog::DEFAULT_CATALOG_RELATIVE_PATH)),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    // Seed runtime as compatibility so a refused promote cannot leave a half-applied cutover.
    $runtime['install_contract'] = 'compatibility';
    file_put_contents($buildPath, json_encode($build, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    file_put_contents($runtimePath, json_encode($runtime, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

    // Empty manifest dir: catalog already fully published.
    $command = sprintf(
        'ORBIT_PHP_CLI_CATALOG=%s ORBIT_PHP_CLI_RUNTIME_CATALOG=%s %s --manifest-dir %s --promote-runtime 2>&1',
        escapeshellarg($buildPath),
        escapeshellarg($runtimePath),
        escapeshellarg(repo_path('bin/orbit-php-cli-catalog-handoff')),
        escapeshellarg($manifestDir),
    );

    exec($command, $output, $exitCode);
    expect($exitCode)
        ->not
        ->toBe(0)
        ->and(implode("\n", $output))
        ->toContain('build catalog is missing artifact_base_url');

    $runtimeAfter = json_decode((string) file_get_contents($runtimePath), true, flags: JSON_THROW_ON_ERROR);
    expect($runtimeAfter['install_contract'])->toBe('compatibility');
});

it('refuses runtime promotion until the full matrix is published', function (): void {
    $fixtureDir = sys_get_temp_dir().'/orbit-php-cli-promote-'.bin2hex(random_bytes(4));
    $manifestDir = $fixtureDir.'/manifests';
    $buildPath = $fixtureDir.'/build.json';
    $runtimePath = $fixtureDir.'/runtime.json';
    mkdir($manifestDir, 0o777, true);

    $build = json_decode(
        (string) file_get_contents(repo_path(PhpCliArtifactCatalog::BUILD_CATALOG_RELATIVE_PATH)),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    // Incomplete matrix: clear all slots, then apply a single cell via manifest.
    foreach ($build['artifacts'] as $patch => $variants) {
        foreach ($variants as $variant => $platforms) {
            foreach (array_keys($platforms) as $platform) {
                $build['artifacts'][$patch][$variant][$platform] = null;
            }
        }
    }
    $build['publication'] = [
        'status' => 'unpublished',
        'published_count' => 0,
        'total_count' => 9,
    ];

    $runtime = json_decode(
        (string) file_get_contents(repo_path(PhpCliArtifactCatalog::DEFAULT_CATALOG_RELATIVE_PATH)),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $runtime['install_contract'] = 'compatibility';
    file_put_contents($buildPath, json_encode($build, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    file_put_contents($runtimePath, json_encode($runtime, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

    $sha = str_repeat('cd', 32);
    file_put_contents($manifestDir.'/one.manifest.json', json_encode([
        'tool' => 'php-cli',
        'php_version' => '8.5.8',
        'variant' => 'standard',
        'platform' => 'linux-x86_64',
        'filename' => 'php-8.5.8-cli-standard-linux-x86_64.tar.gz',
        'sha256' => $sha,
    ], JSON_THROW_ON_ERROR));

    $command = sprintf(
        'ORBIT_PHP_CLI_CATALOG=%s ORBIT_PHP_CLI_RUNTIME_CATALOG=%s %s --manifest-dir %s --promote-runtime 2>&1',
        escapeshellarg($buildPath),
        escapeshellarg($runtimePath),
        escapeshellarg(repo_path('bin/orbit-php-cli-catalog-handoff')),
        escapeshellarg($manifestDir),
    );

    exec($command, $output, $exitCode);
    expect($exitCode)
        ->not
        ->toBe(0)
        ->and(implode("\n", $output))
        ->toContain('Refusing runtime promotion');

    $runtimeAfter = json_decode((string) file_get_contents($runtimePath), true, flags: JSON_THROW_ON_ERROR);
    expect($runtimeAfter['install_contract'])->toBe('compatibility');
});

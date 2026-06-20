<?php

declare(strict_types=1);

/**
 * Binary update:all progress liveness lane.
 *
 * Validates that the packaged host-arch orbit binary alternates the active
 * cyan spinner on the gateway row while blocked on a silent gateway SSE gap.
 */
pest()->group('e2e-binary');

it('alternates the check-updates spinner while the start POST is delayed', function (): void {
    $binary = getenv('ORBIT_E2E_BINARY_PATH');

    if (! is_string($binary) || $binary === '') {
        $binary = repo_path('apps/cli/builds/dist/mac/mac-arm');
    }

    if (! file_exists($binary)) {
        test()->markTestSkipped('orbit binary not built; run `composer test:e2e:binary`.');
    }

    $root = repo_path('');
    $validator = "{$root}/bin/orbit-update-all-binary-liveness";

    expect($validator)->toBeFile();

    $environment = array_filter(getenv(), is_string(...));

    $process = proc_open(
        [$validator, $binary, 'start-post'],
        [
            ['pipe', 'r'],
            ['pipe', 'w'],
            ['pipe', 'w'],
        ],
        $pipes,
        $root,
        [
            ...$environment,
            'ORBIT_UPDATE_ALL_LIVENESS_START_DELAY_US' => '6000000',
            'TERM' => 'xterm-256color',
            'PATH' => $environment['PATH'] ?? getenv('PATH') ?: '/usr/bin:/bin:/usr/sbin:/sbin',
        ],
    );

    if (! is_resource($process)) {
        throw new RuntimeException('Could not start the update:all start-post liveness validator.');
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    $exitCode = proc_close($process);
    $combined = trim($stdout."\n".$stderr);

    expect($exitCode)->toBe(
        0,
        "Binary update:all start-post liveness validator failed.\n{$combined}",
    );

    expect($combined)->toContain('PASS:')
        ->toContain('Checking for updates')
        ->toMatch('/distinct_states=\[(cyan-open,cyan-filled|cyan-filled,cyan-open)\]/');
});

it('alternates the gateway spinner on the virtual screen during a silent SSE gap', function (): void {
    $binary = getenv('ORBIT_E2E_BINARY_PATH');

    if (! is_string($binary) || $binary === '') {
        $binary = repo_path('apps/cli/builds/dist/mac/mac-arm');
    }

    if (! file_exists($binary)) {
        test()->markTestSkipped('orbit binary not built; run `composer test:e2e:binary`.');
    }

    $root = repo_path('');
    $validator = "{$root}/bin/orbit-update-all-binary-liveness";

    expect($validator)->toBeFile();

    $environment = array_filter(getenv(), is_string(...));

    $process = proc_open(
        [$validator, $binary, 'gateway-sse'],
        [
            ['pipe', 'r'],
            ['pipe', 'w'],
            ['pipe', 'w'],
        ],
        $pipes,
        $root,
        [
            ...$environment,
            'ORBIT_UPDATE_ALL_LIVENESS_SILENT_US' => '6000000',
            'TERM' => 'xterm-256color',
            'PATH' => $environment['PATH'] ?? getenv('PATH') ?: '/usr/bin:/bin:/usr/sbin:/sbin',
        ],
    );

    if (! is_resource($process)) {
        throw new RuntimeException('Could not start the update:all binary liveness validator.');
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    $exitCode = proc_close($process);
    $combined = trim($stdout."\n".$stderr);

    expect($exitCode)->toBe(
        0,
        "Binary update:all liveness validator failed.\n{$combined}",
    );

    expect($combined)->toContain('PASS:')
        ->toMatch('/distinct_states=\[(cyan-open,cyan-filled|cyan-filled,cyan-open)\]/');
});

<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

it('generates a release manifest with gateway digest cli hashes and role image metadata', function (): void {
    $root = sys_get_temp_dir().'/orbit-release-manifest-'.bin2hex(random_bytes(6));
    $linux = "{$root}/orbit-linux-x64";
    $mac = "{$root}/orbit-macos-arm64";
    $agentLinux = "{$root}/orbit-agent-linux-x64";
    $agentMac = "{$root}/orbit-agent-macos-arm64";
    $output = "{$root}/orbit-release-manifest.json";

    mkdir($root, 0700, true);
    file_put_contents($linux, 'linux-binary');
    file_put_contents($mac, 'mac-binary');
    file_put_contents($agentLinux, 'agent-linux-binary');
    file_put_contents($agentMac, 'agent-mac-binary');

    try {
        $process = new Process([
            PHP_BINARY,
            repo_path('bin/orbit-release-manifest'),
            '--version=1.2.3',
            '--released-at=2026-06-17T13:08:41Z',
            '--gateway-image=ghcr.io/hardimpactdev/orbit-gateway:1.2.3',
            '--gateway-digest=sha256:'.str_repeat('a', times: 64),
            '--repository=hardimpactdev/orbit',
            "--cli-artifact=linux-amd64=orbit-linux-x64={$linux}",
            "--cli-artifact=darwin-arm64=orbit-macos-arm64={$mac}",
            "--agent-artifact=linux-amd64=orbit-agent-linux-x64={$agentLinux}",
            "--agent-artifact=darwin-arm64=orbit-agent-macos-arm64={$agentMac}",
            '--role-image=orbit-caddy=caddy:2-alpine',
            '--role-image=orbit-websocket=hardimpact/orbit-reverb:1.2.3',
            "--output={$output}",
        ], repo_path());
        $process->run();

        expect($process->getExitCode())->toBe(0, $process->getErrorOutput());

        $manifest = json_decode(file_get_contents($output), true);

        expect($manifest)->toMatchArray([
            'schema_version' => 1,
            'version' => '1.2.3',
            'released_at' => '2026-06-17T13:08:41+00:00',
            'source' => 'github-release',
            'images' => [
                'gateway' => 'ghcr.io/hardimpactdev/orbit-gateway:1.2.3@sha256:'.str_repeat('a', times: 64),
            ],
            'cli_artifacts' => [
                'linux-amd64' => [
                    'url' => 'https://github.com/hardimpactdev/orbit/releases/download/v1.2.3/orbit-linux-x64',
                    'sha256' => hash_file('sha256', $linux),
                ],
                'darwin-arm64' => [
                    'url' => 'https://github.com/hardimpactdev/orbit/releases/download/v1.2.3/orbit-macos-arm64',
                    'sha256' => hash_file('sha256', $mac),
                ],
            ],
            'agent_artifacts' => [
                'linux-amd64' => [
                    'url' => 'https://github.com/hardimpactdev/orbit/releases/download/v1.2.3/orbit-agent-linux-x64',
                    'sha256' => hash_file('sha256', $agentLinux),
                ],
                'darwin-arm64' => [
                    'url' => 'https://github.com/hardimpactdev/orbit/releases/download/v1.2.3/orbit-agent-macos-arm64',
                    'sha256' => hash_file('sha256', $agentMac),
                ],
            ],
            'role_images' => [
                'orbit-caddy' => 'caddy:2-alpine',
                'orbit-websocket' => 'hardimpact/orbit-reverb:1.2.3',
            ],
        ]);
    } finally {
        new Process(['rm', '-rf', $root])->run();
    }
});

it('generates a topology candidate manifest with candidate asset urls and build identity', function (): void {
    $root = sys_get_temp_dir().'/orbit-candidate-manifest-'.bin2hex(random_bytes(6));
    $linux = "{$root}/orbit-linux-x64";
    $mac = "{$root}/orbit-macos-arm64";
    $agentLinux = "{$root}/orbit-agent-linux-x64";
    $agentMac = "{$root}/orbit-agent-macos-arm64";
    $reverbImage = "{$root}/orbit-reverb-linux-amd64.tar";
    $output = "{$root}/orbit-release-manifest.json";

    mkdir($root, 0700, true);
    file_put_contents($linux, 'candidate-linux-binary');
    file_put_contents($mac, 'candidate-mac-binary');
    file_put_contents($agentLinux, 'candidate-agent-linux-binary');
    file_put_contents($agentMac, 'candidate-agent-mac-binary');
    file_put_contents($reverbImage, data: 'candidate-reverb-image');

    try {
        $process = new Process([
            PHP_BINARY,
            repo_path('bin/orbit-release-manifest'),
            '--version=1.2.3',
            '--source=topology-candidate',
            '--build-id=2026-06-21T120000Z-abc123',
            '--asset-base-url=https://artifacts.orbit/releases/candidates/2026-06-21T120000Z-abc123',
            '--gateway-image=ghcr.io/hardimpactdev/orbit-gateway:1.2.3@sha256:'.str_repeat('c', times: 64),
            "--cli-artifact=linux-amd64=orbit-linux-x64={$linux}",
            "--cli-artifact=darwin-arm64=orbit-macos-arm64={$mac}",
            "--agent-artifact=linux-amd64=orbit-agent-linux-x64={$agentLinux}",
            "--agent-artifact=darwin-arm64=orbit-agent-macos-arm64={$agentMac}",
            '--role-image=orbit-caddy=caddy:2-alpine',
            '--role-image=orbit-websocket=hardimpact/orbit-reverb:1.2.3',
            "--role-image-artifact=orbit-websocket=orbit-reverb-linux-amd64.tar={$reverbImage}",
            "--output={$output}",
        ], repo_path());
        $process->run();

        expect($process->getExitCode())->toBe(0, $process->getErrorOutput());

        $manifest = json_decode(file_get_contents($output), true);

        expect($manifest)->toMatchArray([
            'schema_version' => 1,
            'version' => '1.2.3',
            'source' => 'topology-candidate',
            'build_id' => '2026-06-21T120000Z-abc123',
            'cli_artifacts' => [
                'linux-amd64' => [
                    'url' => 'https://artifacts.orbit/releases/candidates/2026-06-21T120000Z-abc123/orbit-linux-x64',
                    'sha256' => hash_file('sha256', $linux),
                ],
                'darwin-arm64' => [
                    'url' => 'https://artifacts.orbit/releases/candidates/2026-06-21T120000Z-abc123/orbit-macos-arm64',
                    'sha256' => hash_file('sha256', $mac),
                ],
            ],
            'agent_artifacts' => [
                'linux-amd64' => [
                    'url' => 'https://artifacts.orbit/releases/candidates/2026-06-21T120000Z-abc123/orbit-agent-linux-x64',
                    'sha256' => hash_file('sha256', $agentLinux),
                ],
                'darwin-arm64' => [
                    'url' => 'https://artifacts.orbit/releases/candidates/2026-06-21T120000Z-abc123/orbit-agent-macos-arm64',
                    'sha256' => hash_file('sha256', $agentMac),
                ],
            ],
            'role_image_artifacts' => [
                'orbit-websocket' => [
                    'url' => 'https://artifacts.orbit/releases/candidates/2026-06-21T120000Z-abc123/orbit-reverb-linux-amd64.tar',
                    'sha256' => hash_file('sha256', $reverbImage),
                ],
            ],
        ]);
    } finally {
        new Process(['rm', '-rf', $root])->run();
    }
});

it('rejects gateway images that cannot be pinned to a digest', function (): void {
    $process = new Process([
        PHP_BINARY,
        repo_path('bin/orbit-release-manifest'),
        '--version=1.2.3',
        '--gateway-image=ghcr.io/hardimpactdev/orbit-gateway:1.2.3',
        '--cli-artifact=linux-amd64=orbit-linux-x64=/tmp/missing',
        '--role-image=orbit-caddy=caddy:2-alpine',
    ], repo_path());
    $process->run();

    expect($process->getExitCode())->toBe(1)->and($process->getErrorOutput())->toContain('gateway digest');
});

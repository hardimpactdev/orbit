<?php

declare(strict_types=1);

use Orbit\Sdk\Responses\Instances\InstanceRemovalProgressResponse;
use Orbit\Sdk\Responses\Instances\InstanceResponse;

describe(InstanceResponse::class, function (): void {
    it('maps every public Instance field from gateway data', function (): void {
        $response = InstanceResponse::fromGatewayData([
            'id' => 7,
            'project_id' => 3,
            'node_id' => 4,
            'vite_port' => null,
            'ssr_port' => null,
            'name' => 'main',
            'source_layout' => 'checkout',
            'checkout_path' => '/home/orbit/apps/orbit-docs',
            'production_user' => null,
            'production_home' => null,
            'root' => null,
            'effective_root' => 'public',
            'selected_branch' => 'main',
            'branch_override' => 'main',
            'starting_commit' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            'detached' => false,
            'status' => 'active',
            'seed_path' => '/fast/apps/orbit/default/releases/initial',
            'seed_commit' => str_repeat('b', 40),
        ], '0198e15c-bf97-7c23-8f1f-61b8fe67a844');

        expect($response->toArray())->toBe([
            'id' => 7,
            'project_id' => 3,
            'node_id' => 4,
            'project' => null,
            'node' => null,
            'vite_port' => null,
            'ssr_port' => null,
            'annotator_port' => null,
            'annotator_url' => null,
            'name' => 'main',
            'source_layout' => 'checkout',
            'checkout_path' => '/home/orbit/apps/orbit-docs',
            'production_user' => null,
            'production_home' => null,
            'root' => null,
            'effective_root' => 'public',
            'selected_branch' => 'main',
            'branch_override' => 'main',
            'starting_commit' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            'seed_path' => '/fast/apps/orbit/default/releases/initial',
            'seed_commit' => str_repeat('b', 40),
            'detached' => false,
            'status' => 'active',
            'route' => null,
            'routes' => [],
            'domain' => null,
            'url' => null,
            'removal' => null,
            'transfer' => null,
            'deploy_steps' => [],
            'auto_deploy' => null,
            'request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844',
        ]);
    });

    it('uses safe values for invalid gateway fields', function (): void {
        $response = InstanceResponse::fromGatewayData([
            'id' => 'invalid',
            'root' => ['invalid'],
            'seed_path' => ['invalid'],
            'seed_commit' => false,
        ], 'request-id');

        expect($response->id)
            ->toBe(0)
            ->and($response->root)
            ->toBeNull()
            ->and($response->seedPath)->toBeNull()
            ->and($response->seedCommit)->toBeNull();
    });

    it('maps bounded removal progress and rejects an unsafe error code', function (): void {
        $response = InstanceRemovalProgressResponse::fromGatewayData([
            'operation_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a845',
            'id' => 7,
            'name' => 'main',
            'force' => false,
            'status' => 'failed',
            'current_step' => 'runtime_cleanup',
            'total' => 1,
            'completed' => 0,
            'remaining' => 1,
            'failed_step' => 'runtime_cleanup',
            'error_code' => "token=secret\r\nX-Control: injected",
        ]);

        expect($response->toArray())->toBe([
            'operation_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a845',
            'id' => 7,
            'name' => 'main',
            'force' => false,
            'status' => 'failed',
            'current_step' => 'runtime_cleanup',
            'total' => 1,
            'completed' => 0,
            'remaining' => 1,
            'failed_step' => 'runtime_cleanup',
            'error_code' => null,
        ]);
    });
});

it('exposes the annotator URL and bounds its port', function (): void {
    $response = InstanceResponse::fromGatewayData(['annotator_port' => 4848, 'annotator_url' => 'https://site.test/__orbit/annotator'], 'request-id');
    expect($response->annotatorPort)->toBe(4848)->and($response->annotatorUrl)->toBe('https://site.test/__orbit/annotator')->and($response->toArray()['annotator_url'])->toBe($response->annotatorUrl);
    foreach ([0, 1023, 65536, '4848', false] as $invalid) {
        expect(InstanceResponse::fromGatewayData(['annotator_port' => $invalid, 'annotator_url' => []], 'request-id')->annotatorPort)->toBeNull();
    }
});

it('exposes a valid assigned Vite port and rejects invalid transport values', function (): void {
    $response = InstanceResponse::fromGatewayData(['id' => 7, 'vite_port' => 5210], 'request-id');
    expect($response->vitePort)->toBe(5210)->and($response->toArray()['vite_port'])->toBe(5210);
    foreach ([0, 1023, 65536, '5173', false] as $invalid) {
        expect(InstanceResponse::fromGatewayData(['vite_port' => $invalid], 'request-id')->vitePort)->toBeNull();
    }
});

it('exposes a valid assigned SSR port and rejects invalid transport values', function (): void {
    $response = InstanceResponse::fromGatewayData(['id' => 7, 'ssr_port' => 13715], 'request-id');
    expect($response->ssrPort)->toBe(13715)->and($response->toArray()['ssr_port'])->toBe(13715);
    foreach ([0, 1023, 65536, '13714', false] as $invalid) {
        expect(InstanceResponse::fromGatewayData(['ssr_port' => $invalid], 'request-id')->ssrPort)->toBeNull();
    }
});

it('preserves effective shared paths and omits unavailable or malformed values', function (): void {
    foreach ([['storage', 'var/uploads'], []] as $paths) {
        $response = InstanceResponse::fromGatewayData(['shared_paths' => $paths], 'request-id');
        expect($response->sharedPaths)->toBe($paths)
            ->and($response->toArray()['shared_paths'])->toBe($paths);
    }
    foreach ([null, 'storage', ['storage', false], ['path' => 'storage']] as $invalid) {
        $response = InstanceResponse::fromGatewayData(['shared_paths' => $invalid], 'request-id');
        expect($response->sharedPaths)->toBeNull()
            ->and($response->toArray())->not->toHaveKey('shared_paths');
    }
});

it('maps automatic deployment and drops a malformed pause', function (): void {
    $auto = [
        'enabled' => true, 'gate' => 'Required checks', 'warning' => null, 'result' => 'paused', 'commit' => str_repeat('a', 40),
        'head' => str_repeat('c', 40), 'since' => '2026-10-15T10:00:00+00:00', 'error_code' => null, 'message' => 'Rollback 41 paused automatic deployment.',
        'pause' => ['reason' => 'rollback', 'deployment_id' => 41, 'commit' => str_repeat('a', 40), 'paused_at' => '2026-10-15T10:00:00+00:00'],
    ];
    $response = InstanceResponse::fromGatewayData(['id' => 7, 'auto_deploy' => $auto], 'request-id');

    expect($response->autoDeploy?->enabled)->toBeTrue()
        ->and($response->autoDeploy?->gate)->toBe('Required checks')
        ->and($response->toArray()['auto_deploy'])->toBe($auto)
        ->and(InstanceResponse::fromGatewayData(['auto_deploy' => [...$auto, 'pause' => ['reason' => 'rollback']]], 'request-id')->autoDeploy?->pause)->toBeNull()
        ->and(InstanceResponse::fromGatewayData(['auto_deploy' => 'on'], 'request-id')->autoDeploy)->toBeNull();
});

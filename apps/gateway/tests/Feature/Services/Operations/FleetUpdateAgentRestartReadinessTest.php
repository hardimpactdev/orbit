<?php

declare(strict_types=1);

use App\Data\Operations\OperationUpdatePlanSnapshot;
use App\Models\Node;
use App\Services\Operations\FleetUpdateAgentRestartReadiness;
use App\Services\Operations\OperationRunRecorder;
use App\Services\Operations\OperationUpdatePlanStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('waits for Agent restart on a newly selected roleless unmanaged Linux node', function (): void {
    config()->set('orbit.updates.agent_restart_settle_milliseconds', 0);

    Http::fake([
        'http://10.6.0.96:9477/*' => Http::response('', 405),
    ]);

    Node::factory()
        ->gateway()
        ->create([
            'name' => 'gateway-1',
            'platform' => 'debian_12',
            'wireguard_address' => '10.6.0.2',
        ]);
    $node = Node::factory()
        ->operator()
        ->create([
            'name' => 'operator-linux',
            'managed' => false,
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.96',
        ]);
    $run = app(OperationRunRecorder::class)->queued(
        operationId: (string) Str::uuid(),
        lane: 'gateway',
        operationType: 'update:all',
    );
    $cliArtifacts = [
        'linux-amd64' => [
            'url' => 'https://github.com/hardimpactdev/orbit/releases/download/v2.0.0/orbit-linux-amd64',
            'sha256' => str_repeat('b', times: 64),
        ],
    ];
    $agentArtifacts = [
        'linux-amd64' => [
            'url' => 'https://artifacts.orbit/candidates/build/orbit-agent-linux-x64',
            'sha256' => str_repeat('9', times: 64),
        ],
    ];
    $gatewayImage = 'ghcr.io/hardimpactdev/orbit-gateway:2.0.0@sha256:'.str_repeat('a', times: 64);
    $plan = app(OperationUpdatePlanStore::class)->create(
        $run,
        new OperationUpdatePlanSnapshot(
            targetVersion: '2.0.0',
            gatewayImage: $gatewayImage,
            manifestSource: 'github-release',
            manifestVersion: '2.0.0',
            manifestSnapshot: [
                'version' => '2.0.0',
                'source' => 'github-release',
                'images' => ['gateway' => $gatewayImage],
                'cli_artifacts' => $cliArtifacts,
                'agent_artifacts' => $agentArtifacts,
                'role_images' => [
                    'orbit-caddy' => 'caddy:2-alpine',
                ],
            ],
            cliArtifacts: $cliArtifacts,
            agentArtifacts: $agentArtifacts,
            roleImages: [
                'orbit-caddy' => 'caddy:2-alpine',
            ],
        ),
    );

    app(FleetUpdateAgentRestartReadiness::class)->wait($run, $plan);

    expect($node->isAgentEligible())
        ->toBeFalse();

    Http::assertSent(
        fn (Request $request): bool => $request->url() === 'http://10.6.0.96:9477/v1/commands',
    );
});

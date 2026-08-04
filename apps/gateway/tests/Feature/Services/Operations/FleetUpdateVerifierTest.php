<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\Operations\OperationUpdatePlanSnapshot;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\Node;
use App\Models\OperationRun;
use App\Models\OperationUpdatePlan;
use App\Services\Ca\OrbitCaService;
use App\Services\Operations\FleetUpdateVerificationFailed;
use App\Services\Operations\FleetUpdateVerifier;
use App\Services\Operations\GatewayCliArtifactRelay;
use App\Services\Operations\OperationRunRecorder;
use App\Services\Operations\OperationUpdatePlanStore;
use App\Services\Operations\UpdateRunner;
use App\Services\RemoteShell\RemoteShellMetadata;
use App\Tools\CaddyTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Orbit\Core\Enums\OperationStatus;

uses(RefreshDatabase::class);

function fleet_update_verifier_use_agent_push(): void
{
    app()->instance(
        \App\Services\RemoteShell\RunsInternalCommands::class,
        app(\App\Services\RemoteShell\RemoteLocalExecutor::class),
    );
}

beforeEach(function (): void {
    Process::preventStrayProcesses();
    config()->set('orbit.updates.agent_restart_settle_milliseconds', 0);
    app()->instance(OrbitCaService::class, new FleetVerifierFakeCa);
    app()->instance(GatewayCliArtifactRelay::class, new class extends GatewayCliArtifactRelay {
        /**
         * @return array{url: string, sha256: string, source_url: string}
         */
        #[Override]
        public function artifactFor(OperationRun $operationRun, OperationUpdatePlan $plan, string $platform): array
        {
            $artifact = $plan->cli_artifacts[$platform] ?? null;

            if (
                ! is_array($artifact)
                || ! is_string($artifact['url'] ?? null)
                || ! is_string($artifact['sha256'] ?? null)
            ) {
                throw new RuntimeException("Missing test artifact for [{$platform}].");
            }

            return [
                'url' => "http://gateway.test/artifacts/{$platform}",
                'sha256' => $artifact['sha256'],
                'source_url' => $artifact['url'],
            ];
        }

        #[Override]
        public function stage(OperationRun $operationRun, OperationUpdatePlan $plan): void
        {
            //
        }

        #[Override]
        public function cleanup(OperationRun $operationRun): void
        {
            //
        }
    });
});

afterEach(function (): void {});

it('verifies gateway scheduler workload CLI and required role images', function (): void {
    fleet_update_verifier_use_agent_push();
    Process::fake([
        "docker service inspect --format '{{.Spec.TaskTemplate.ContainerSpec.Image}}' 'orbit_orbit-gateway'" => Process::result(
            output: "ghcr.io/hardimpactdev/orbit-gateway:1.2.3@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa\n",
        ),
        "docker service inspect --format '{{.Spec.TaskTemplate.ContainerSpec.Image}}' 'orbit_orbit-scheduler'" => Process::result(
            output: "ghcr.io/hardimpactdev/orbit-gateway:1.2.3@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa\n",
        ),
    ]);

    Http::preventStrayRequests();
    Http::fake(fn (Request $request): mixed => fleet_verifier_agent_response($request));

    $run = fleetVerifierRun();
    Node::factory()
        ->agent()
        ->managed()
        ->create([
            'name' => 'agent-1',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.44.0.11',
        ]);
    Node::factory()
        ->appDev()
        ->managed()
        ->create([
            'name' => 'app-dev-1',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.44.0.12',
        ]);
    Node::factory()
        ->database()
        ->managed()
        ->create([
            'name' => 'database-1',
            'platform' => 'ubuntu',
            'wireguard_address' => '10.44.0.13',
        ]);
    Node::factory()->gateway()->create(['name' => 'gateway-1', 'platform' => 'debian_12']);
    Node::factory()
        ->ingress()
        ->managed()
        ->create([
            'name' => 'ingress-1',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.44.0.14',
        ]);
    Node::factory()->operator()->create(['name' => 'operator-1']);
    $plan = app(OperationUpdatePlanStore::class)->create($run, fleetVerifierSnapshot());

    app(FleetUpdateVerifier::class)->verify($run, $plan);

    $requests = fleet_verifier_agent_requests();

    expect($requests)
        ->toHaveCount(7)
        ->and($requests[0]['node'])
        ->toBe('10.44.0.11')
        ->and($requests[0]['argv'])
        ->toMatchArray([
            'internal:fleet-update:verify',
            'cli',
        ])
        ->and($requests[0]['input'])
        ->toBe(json_encode(['bin_path' => '/home/orbit/.local/bin/orbit'], JSON_THROW_ON_ERROR))
        ->and(agentPushRequestOperationIdMatchesToken($requests[0]))
        ->toBeTrue()
        ->and(array_column($requests, 'node'))
        ->toBe([
            '10.44.0.11',
            '10.44.0.12',
            '10.44.0.13',
            '10.44.0.14',
            '10.44.0.11',
            '10.44.0.12',
            '10.44.0.14',
        ])
        ->and($requests[4]['argv'])
        ->toMatchArray([
            'internal:fleet-update:verify',
            'role-images',
        ])
        ->and($requests[4]['input'])
        ->toBe(json_encode(['images' => ['caddy:2-alpine']], JSON_THROW_ON_ERROR))
        ->and($requests[5]['input'])
        ->toBe(json_encode([
            'images' => [
                'caddy:2-alpine',
                'ghcr.io/hardimpactdev/orbit-frankenphp:2-php8.5-bookworm@sha256:eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee',
            ],
        ], JSON_THROW_ON_ERROR));
});

it('verifies the stable runtime alias for a topology candidate', function (): void {
    fleet_update_verifier_use_agent_push();
    Process::fake([
        "docker service inspect --format '{{.Spec.TaskTemplate.ContainerSpec.Image}}' 'orbit_orbit-gateway'" => Process::result(
            output: "ghcr.io/hardimpactdev/orbit-gateway:1.2.3@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa\n",
        ),
        "docker service inspect --format '{{.Spec.TaskTemplate.ContainerSpec.Image}}' 'orbit_orbit-scheduler'" => Process::result(
            output: "ghcr.io/hardimpactdev/orbit-gateway:1.2.3@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa\n",
        ),
    ]);

    Http::preventStrayRequests();
    Http::fake(fn (Request $request): mixed => fleet_verifier_agent_response($request));

    $run = fleetVerifierRun();
    Node::factory()
        ->appDev()
        ->managed()
        ->create([
            'name' => 'app-dev-1',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.44.0.12',
        ]);
    Node::factory()->gateway()->create(['name' => 'gateway-1', 'platform' => 'debian_12']);
    $candidateImage = 'ghcr.io/hardimpactdev/orbit-frankenphp:2-php8.5-bookworm-candidate-test@sha256:'
    .str_repeat(
        'e',
        times: 64,
    );
    $plan = app(OperationUpdatePlanStore::class)->create(
        $run,
        fleetVerifierSnapshot(
            roleImages: [
                'orbit-caddy' => 'caddy:2-alpine',
                'orbit-frankenphp' => $candidateImage,
            ],
            manifestSource: 'topology-candidate',
        ),
    );

    app(FleetUpdateVerifier::class)->verify($run, $plan);

    $requests = fleet_verifier_agent_requests();

    expect($requests)
        ->toHaveCount(2)
        ->and($requests[1]['argv'])
        ->toMatchArray([
            'internal:fleet-update:verify',
            'role-images',
        ])
        ->and($requests[1]['input'])
        ->toBe(json_encode([
            'images' => [
                'caddy:2-alpine',
                'ghcr.io/hardimpactdev/orbit-frankenphp:2-php8.5-bookworm',
            ],
        ], JSON_THROW_ON_ERROR));
});

it('verifies macos workload CLI through the user launcher and skips required role images', function (): void {
    fleet_update_verifier_use_agent_push();
    Process::fake([
        "docker service inspect --format '{{.Spec.TaskTemplate.ContainerSpec.Image}}' 'orbit_orbit-gateway'" => Process::result(
            output: "ghcr.io/hardimpactdev/orbit-gateway:1.2.3@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa\n",
        ),
        "docker service inspect --format '{{.Spec.TaskTemplate.ContainerSpec.Image}}' 'orbit_orbit-scheduler'" => Process::result(
            output: "ghcr.io/hardimpactdev/orbit-gateway:1.2.3@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa\n",
        ),
    ]);

    Http::preventStrayRequests();
    Http::fake(fn (Request $request): mixed => fleet_verifier_agent_response($request));

    $run = fleetVerifierRun();
    Node::factory()
        ->appDev()
        ->managed()
        ->create([
            'name' => 'mini',
            'platform' => 'darwin',
            'user' => 'nckrtl',
            'wireguard_address' => '10.44.0.8',
        ]);
    Node::factory()->gateway()->create(['name' => 'gateway-1', 'platform' => 'debian_12']);
    $plan = app(OperationUpdatePlanStore::class)->create($run, fleetVerifierSnapshot());

    app(FleetUpdateVerifier::class)->verify($run, $plan);

    $requests = fleet_verifier_agent_requests();

    expect($requests)
        ->toHaveCount(1)
        ->and($requests[0]['node'])
        ->toBe('10.44.0.8')
        ->and(array_slice($requests[0]['argv'], offset: 0, length: 2))
        ->toBe([
            'internal:fleet-update:verify',
            'cli',
        ])
        ->and($requests[0]['input'])
        ->toBe(json_encode(['bin_path' => '/Users/nckrtl/.local/bin/orbit'], JSON_THROW_ON_ERROR));
});

it('waits for freshly restarted workload agents before verifying their artifacts', function (): void {
    fleet_update_verifier_use_agent_push();
    Process::fake([
        "docker service inspect --format '{{.Spec.TaskTemplate.ContainerSpec.Image}}' 'orbit_orbit-gateway'" => Process::result(
            output: "gateway-image\n",
        ),
        "docker service inspect --format '{{.Spec.TaskTemplate.ContainerSpec.Image}}' 'orbit_orbit-scheduler'" => Process::result(
            output: "scheduler-image\n",
        ),
    ]);

    Http::preventStrayRequests();
    Http::fake(fn (Request $request): mixed => fleet_verifier_agent_response($request));

    $run = fleetVerifierRun();
    Node::factory()
        ->appDev()
        ->managed()
        ->create([
            'name' => 'app-dev-1',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.44.0.12',
        ]);
    Node::factory()->gateway()->create(['name' => 'gateway-1', 'platform' => 'debian_12']);
    $plan = app(OperationUpdatePlanStore::class)->create(
        $run,
        fleetVerifierSnapshot(agentArtifacts: [
            'linux-amd64' => [
                'url' => 'https://artifacts.orbit/candidates/build/orbit-agent-linux-x64',
                'sha256' => str_repeat('9', times: 64),
            ],
        ]),
    );

    app(FleetUpdateVerifier::class)->verify($run, $plan);

    $requests = Http::recorded()->map(fn (array $record): array => [
        'method' => $record[0]->method(),
        'url' => $record[0]->url(),
    ])->all();

    expect($requests[0])
        ->toBe([
            'method' => 'GET',
            'url' => 'http://10.44.0.12:9477/v1/commands',
        ])
        ->and($requests[1]['method'])
        ->toBe('POST');
});

it('fails verification when an updated workload agent does not become ready', function (): void {
    fleet_update_verifier_use_agent_push();
    config()->set('orbit.node_bootstrap.readiness_attempts', 1);
    Process::fake([
        "docker service inspect --format '{{.Spec.TaskTemplate.ContainerSpec.Image}}' 'orbit_orbit-gateway'" => Process::result(
            output: "gateway-image\n",
        ),
        "docker service inspect --format '{{.Spec.TaskTemplate.ContainerSpec.Image}}' 'orbit_orbit-scheduler'" => Process::result(
            output: "scheduler-image\n",
        ),
    ]);

    Http::preventStrayRequests();
    Http::fake(fn (): mixed => Http::response(status: 503));

    $run = fleetVerifierRun();
    Node::factory()
        ->appDev()
        ->managed()
        ->create([
            'name' => 'app-dev-1',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.44.0.12',
        ]);
    Node::factory()->gateway()->create(['name' => 'gateway-1', 'platform' => 'debian_12']);
    $plan = app(OperationUpdatePlanStore::class)->create(
        $run,
        fleetVerifierSnapshot(agentArtifacts: [
            'linux-amd64' => [
                'url' => 'https://artifacts.orbit/candidates/build/orbit-agent-linux-x64',
                'sha256' => str_repeat('9', times: 64),
            ],
        ]),
    );

    expect(fn () => app(FleetUpdateVerifier::class)->verify($run, $plan))
        ->toThrow(FleetUpdateVerificationFailed::class, 'Orbit Agent readiness verification failed')
        ->and(fleetVerifierStepEvents($run))
        ->toContain(['verification.cli', 'fail']);
});

it('verifies Orbit Agent artifacts on agent-capable workload nodes and excludes the gateway', function (): void {
    fleet_update_verifier_use_agent_push();
    Process::fake([
        "docker service inspect --format '{{.Spec.TaskTemplate.ContainerSpec.Image}}' 'orbit_orbit-gateway'" => Process::result(
            output: "ghcr.io/hardimpactdev/orbit-gateway:1.2.3@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa\n",
        ),
        "docker service inspect --format '{{.Spec.TaskTemplate.ContainerSpec.Image}}' 'orbit_orbit-scheduler'" => Process::result(
            output: "ghcr.io/hardimpactdev/orbit-gateway:1.2.3@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa\n",
        ),
    ]);

    Http::preventStrayRequests();
    Http::fake(fn (Request $request): mixed => fleet_verifier_agent_response($request));

    $run = fleetVerifierRun();
    Node::factory()
        ->gateway()
        ->managed()
        ->create([
            'name' => 'gateway-1',
            'platform' => 'debian_12',
            'wireguard_address' => '10.44.0.1',
        ]);
    Node::factory()
        ->appDev()
        ->managed()
        ->create([
            'name' => 'app-dev-1',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.44.0.12',
        ]);
    Node::factory()
        ->appDev()
        ->managed()
        ->create([
            'name' => 'mini',
            'platform' => 'darwin',
            'user' => 'nckrtl',
            'wireguard_address' => '10.44.0.8',
        ]);
    $plan = app(OperationUpdatePlanStore::class)->create(
        $run,
        fleetVerifierSnapshot(
            agentArtifacts: [
                'linux-amd64' => [
                    'url' => 'https://artifacts.orbit/candidates/build/orbit-agent-linux-x64',
                    'sha256' => str_repeat('9', times: 64),
                ],
                'darwin-arm64' => [
                    'url' => 'https://artifacts.orbit/candidates/build/orbit-agent-macos-arm64',
                    'sha256' => str_repeat('7', times: 64),
                ],
            ],
        ),
    );

    app(FleetUpdateVerifier::class)->verify($run, $plan);

    $agentRequests = array_values(array_filter(
        fleet_verifier_agent_requests(),
        fn (array $request): bool => ($request['argv'][1] ?? null) === 'agent',
    ));

    expect($agentRequests)
        ->toHaveCount(2)
        ->and(array_column($agentRequests, 'node'))
        ->toBe(['10.44.0.12', '10.44.0.8'])
        ->and($agentRequests[0]['input'])
        ->toBe(json_encode([
            'bin_path' => '/home/orbit/.local/bin/orbit-agent',
            'sha256' => str_repeat('9', times: 64),
        ], JSON_THROW_ON_ERROR))
        ->and($agentRequests[1]['input'])
        ->toBe(json_encode([
            'bin_path' => '/Users/nckrtl/.local/bin/orbit-agent',
            'sha256' => str_repeat('7', times: 64),
        ], JSON_THROW_ON_ERROR));
});

it('fails when Orbit Agent artifact verification fails', function (): void {
    fleet_update_verifier_use_agent_push();
    Process::fake([
        "docker service inspect --format '{{.Spec.TaskTemplate.ContainerSpec.Image}}' 'orbit_orbit-gateway'" => Process::result(
            output: "gateway-image\n",
        ),
        "docker service inspect --format '{{.Spec.TaskTemplate.ContainerSpec.Image}}' 'orbit_orbit-scheduler'" => Process::result(
            output: "scheduler-image\n",
        ),
    ]);

    Http::preventStrayRequests();
    Http::fake(fn (Request $request): mixed => fleet_verifier_agent_response($request, failCheck: 'agent'));

    $run = fleetVerifierRun();
    $plan = app(OperationUpdatePlanStore::class)->create(
        $run,
        fleetVerifierSnapshot(
            agentArtifacts: [
                'linux-amd64' => [
                    'url' => 'https://artifacts.orbit/candidates/build/orbit-agent-linux-x64',
                    'sha256' => str_repeat('9', times: 64),
                ],
            ],
        ),
    );
    Node::factory()
        ->appDev()
        ->managed()
        ->create([
            'name' => 'app-dev-1',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.44.0.12',
        ]);

    expect(fn () => app(FleetUpdateVerifier::class)->verify($run, $plan))
        ->toThrow(FleetUpdateVerificationFailed::class, 'Orbit Agent verification failed');
});

it('fails when workload CLI verification fails', function (): void {
    fleet_update_verifier_use_agent_push();
    Process::fake([
        "docker service inspect --format '{{.Spec.TaskTemplate.ContainerSpec.Image}}' 'orbit_orbit-gateway'" => Process::result(
            output: "gateway-image\n",
        ),
        "docker service inspect --format '{{.Spec.TaskTemplate.ContainerSpec.Image}}' 'orbit_orbit-scheduler'" => Process::result(
            output: "scheduler-image\n",
        ),
    ]);

    Http::preventStrayRequests();
    Http::fake(fn (Request $request): mixed => fleet_verifier_agent_response($request, failCheck: 'cli'));

    $run = fleetVerifierRun();
    $plan = app(OperationUpdatePlanStore::class)->create($run, fleetVerifierSnapshot());
    Node::factory()
        ->appDev()
        ->managed()
        ->create([
            'name' => 'app-dev-1',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.44.0.12',
        ]);

    expect(fn () => app(FleetUpdateVerifier::class)->verify($run, $plan))
        ->toThrow(FleetUpdateVerificationFailed::class, 'CLI verification failed');
});

it('fails when a required role image is missing on a workload node', function (): void {
    fleet_update_verifier_use_agent_push();
    Process::fake([
        "docker service inspect --format '{{.Spec.TaskTemplate.ContainerSpec.Image}}' 'orbit_orbit-gateway'" => Process::result(
            output: "gateway-image\n",
        ),
        "docker service inspect --format '{{.Spec.TaskTemplate.ContainerSpec.Image}}' 'orbit_orbit-scheduler'" => Process::result(
            output: "scheduler-image\n",
        ),
    ]);

    Http::preventStrayRequests();
    Http::fake(fn (Request $request): mixed => fleet_verifier_agent_response($request, failCheck: 'role-images'));

    $run = fleetVerifierRun();
    $plan = app(OperationUpdatePlanStore::class)->create($run, fleetVerifierSnapshot());
    Node::factory()
        ->appDev()
        ->managed()
        ->create([
            'name' => 'app-dev-1',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.44.0.12',
        ]);

    expect(fn () => app(FleetUpdateVerifier::class)->verify($run, $plan))
        ->toThrow(FleetUpdateVerificationFailed::class, 'Required role image verification failed');
});

it('emits terminal success only after runner verification passes', function (): void {
    Http::preventStrayRequests();
    Http::fake(fn (Request $request): mixed => fleet_verifier_agent_response($request));

    $run = fleetVerifierRun();
    Node::factory()
        ->gateway()
        ->create([
            'name' => 'gateway-1',
            'platform' => 'debian_12',
            'wireguard_address' => '10.44.0.2',
        ]);
    Node::factory()
        ->appDev()
        ->managed()
        ->create([
            'name' => 'app-dev-1',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.44.0.12',
        ]);
    $plan = app(OperationUpdatePlanStore::class)->create($run, fleetVerifierSnapshot(targetVersion: '1.2.3'));

    fakeFleetVerifierGatewayUpdateProcesses($plan->gateway_image);
    fakeFleetVerifierGatewayMigrations();
    app()->instance(FleetUpdateVerifier::class, new class extends FleetUpdateVerifier {
        public function __construct() {}

        #[\Override]
        public function verify(OperationRun $operationRun, OperationUpdatePlan $plan): void
        {
            //
        }
    });

    app(UpdateRunner::class)->run($run->id);

    $run->refresh();

    expect($run->status)
        ->toBe(OperationStatus::Succeeded)
        ->and(fleetVerifierStepEvents($run))
        ->toBe([
            ['runner',               'running'],
            ['check-updates',        'running'],
            ['check-updates',        'done'],
            ['check-fleet-versions', 'running'],
            ['check-fleet-versions', 'done'],
            ['lease.fleet',          'done'],
            ['update-artifacts',     'running'],
            ['update-artifacts',     'done'],
            ['gateway',              'running'],
            ['lease.gateway',        'done'],
            ['scheduler.stop',       'running'],
            ['scheduler.stop',       'done'],
            ['migrations',           'running'],
            ['migrations',           'done'],
            ['gateway.host-cli',     'running'],
            ['gateway.host-cli',     'done'],
            ['gateway.service',      'running'],
            ['gateway.service',      'done'],
            ['scheduler.start',      'running'],
            ['scheduler.start',      'done'],
            ['gateway.stack',        'running'],
            ['gateway.stack',        'done'],
            ['gateway',              'done'],
            ['workload-nodes',       'running'],
            ['workload.app-dev-1',   'running'],
            ['workload.app-dev-1',   'running'],
            ['workload.app-dev-1',   'running'],
            ['workload.app-dev-1',   'running'],
            ['workload.app-dev-1',   'running'],
            ['workload.app-dev-1',   'done'],
            ['workload-nodes',       'done'],
            ['verification',         'running'],
            ['verification',         'done'],
        ])
        ->and($run->events()->where('event_type', 'complete')->first()?->payload)
        ->toMatchArray([
            'exit_code' => 0,
            'data' => [
                'target_version' => '1.2.3',
                'manifest_source' => 'github-release',
                'manifest_version' => '1.2.3',
                'status' => 'succeeded',
            ],
        ]);
});

it('emits terminal failure when runner verification fails', function (): void {
    Http::preventStrayRequests();
    Http::fake(fn (Request $request): mixed => fleet_verifier_agent_response($request, failCheck: 'cli'));

    $run = fleetVerifierRun();
    Node::factory()
        ->gateway()
        ->create([
            'name' => 'gateway-1',
            'platform' => 'debian_12',
            'wireguard_address' => '10.44.0.2',
        ]);
    Node::factory()
        ->appDev()
        ->managed()
        ->create([
            'name' => 'app-dev-1',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.44.0.12',
        ]);
    $plan = app(OperationUpdatePlanStore::class)->create($run, fleetVerifierSnapshot());

    fakeFleetVerifierGatewayUpdateProcesses($plan->gateway_image);
    fakeFleetVerifierGatewayMigrations();
    app()->instance(FleetUpdateVerifier::class, new class extends FleetUpdateVerifier {
        public function __construct() {}

        #[\Override]
        public function verify(OperationRun $operationRun, OperationUpdatePlan $plan): void
        {
            throw new FleetUpdateVerificationFailed('cli_verification_failed', 'CLI verification failed.');
        }
    });

    expect(fn () => app(UpdateRunner::class)->run($run->id))
        ->toThrow(FleetUpdateVerificationFailed::class);

    $run->refresh();

    expect($run->status)
        ->toBe(OperationStatus::Failed)
        ->and($run->error)
        ->toMatchArray([
            'code' => 'cli_verification_failed',
            'message' => 'CLI verification failed.',
        ])
        ->and(fleetVerifierStepEvents($run))
        ->toContain(
            ['verification', 'fail'],
        )
        ->and($run->events()->where('event_type', 'error')->first()?->payload)
        ->toMatchArray([
            'data' => [
                'code' => 'cli_verification_failed',
            ],
        ]);
});

function fleetVerifierRun(): OperationRun
{
    return app(OperationRunRecorder::class)->queued(
        operationId: (string) Str::uuid(),
        lane: 'gateway',
        operationType: 'update:all',
    );
}

function fakeFleetVerifierGatewayUpdateProcesses(string $gatewayImage): void
{
    Process::fake([
        "docker service inspect --format '{{.Spec.TaskTemplate.ContainerSpec.Image}}' 'orbit_orbit-scheduler'" => Process::result(
            output: "{$gatewayImage}\n",
        ),
        "docker service inspect --format '{{.Spec.TaskTemplate.ContainerSpec.Image}}' 'orbit_orbit-runtime-hibernator'" => Process::result(
            output: "{$gatewayImage}\n",
        ),
        "docker service scale --detach=true 'orbit_orbit-scheduler=0'" => Process::result(),
        "docker service scale --detach=true 'orbit_orbit-runtime-hibernator=0'" => Process::result(),
        fleet_verifier_gateway_migration_command($gatewayImage) => Process::result(),
        fleet_verifier_gateway_host_cli_command($gatewayImage) => Process::result(),
        fleet_verifier_root_ca_subject_command() => Process::result(
            output: "subject=CN = Orbit Root CA\n",
        ),
        "docker service update --detach=true --force --image '{$gatewayImage}' --update-order 'start-first' --update-failure-action rollback --update-monitor 60s 'orbit_orbit-gateway'" =>
            Process::result(),
        "docker service inspect --format '{{.UpdateStatus.State}}' 'orbit_orbit-gateway'" => Process::result(
            output: "completed\n",
        ),
        "docker service update --detach=true --image '{$gatewayImage}' --update-order 'stop-first' --update-failure-action rollback --update-monitor 60s 'orbit_orbit-scheduler'" =>
            Process::result(),
        "docker service scale --detach=true 'orbit_orbit-scheduler=1'" => Process::result(),
        "docker service update --detach=true --image '{$gatewayImage}' --update-order 'stop-first' --update-failure-action rollback --update-monitor 60s 'orbit_orbit-runtime-hibernator'" =>
            Process::result(),
        "docker service scale --detach=true 'orbit_orbit-runtime-hibernator=1'" => Process::result(),
        ...array_fill_keys(fleet_verifier_gateway_leaf_converge_commands(), Process::result()),
        fleet_verifier_gateway_stack_deploy_command() => Process::result(),
        "docker service ls --filter 'name=orbit_orbit-scheduler' --format '{{.Replicas}}'" => Process::result(
            output: "1/1\n",
        ),
        "docker service ls --filter 'name=orbit_orbit-runtime-hibernator' --format '{{.Replicas}}'" => Process::result(
            output: "1/1\n",
        ),
        "docker service ls --filter 'name=orbit_orbit-operations-reverb' --format '{{.Replicas}}'" => Process::result(
            output: "1/1\n",
        ),
        "docker service inspect --format '{{.Spec.TaskTemplate.ContainerSpec.Image}}' 'orbit_orbit-gateway'" => Process::result(
            output: "{$gatewayImage}\n",
        ),
    ]);
}

/**
 * @return list<string>
 */
function fleet_verifier_gateway_leaf_converge_commands(): array
{
    $configRoot = config(key: 'orbit.paths.config_root');

    if (! is_string($configRoot) || trim($configRoot) === '') {
        throw new RuntimeException('Test config root is not configured.');
    }

    $configRoot = rtrim($configRoot, characters: '/');

    return [
        'sudo install -d -m 0755 /etc/orbit/certs',
        'sudo install -m 0644 '.escapeshellarg("{$configRoot}/certs/gateway.crt")." '/etc/orbit/certs/gateway.crt'",
        'sudo install -m 0600 '.escapeshellarg("{$configRoot}/certs/gateway.key")." '/etc/orbit/certs/gateway.key'",
        'sudo tee /etc/caddy/orbit/orbit-gateway.caddy > /dev/null',
        "docker exec 'orbit-caddy' test -r '/etc/orbit/certs/gateway.crt'",
        "docker exec 'orbit-caddy' test -r '/etc/orbit/certs/gateway.key'",
        CaddyTool::reloadCommand('orbit-caddy'),
    ];
}

function fleet_verifier_root_ca_subject_command(): string
{
    $rootCertificate = config('orbit.paths.config_root').'/ca/root.crt';

    return sprintf(
        'openssl x509 -in %s -noout -subject',
        escapeshellarg($rootCertificate),
    );
}

function fleet_verifier_gateway_host_cli_command(string $gatewayImage): string
{
    return implode(' ', [
        'docker run --rm --interactive',
        '--entrypoint '.escapeshellarg('bash'),
        '--mount '.escapeshellarg('type=bind,source=/home/orbit/orbit,target=/mnt/orbit-install'),
        '--mount '.escapeshellarg('type=bind,source=/home/orbit,target=/mnt/orbit-home'),
        escapeshellarg($gatewayImage),
        escapeshellarg('-s'),
    ]);
}

function fleet_verifier_gateway_stack_deploy_command(): string
{
    $configRoot = config(key: 'orbit.paths.config_root');

    if (! is_string($configRoot) || trim($configRoot) === '') {
        throw new RuntimeException('Test config root is not configured.');
    }

    return (
        'docker stack deploy -c '
        .escapeshellarg(rtrim($configRoot, characters: '/').'/swarm/orbit-gateway-stack.yml')
        ." 'orbit'"
    );
}

function fakeFleetVerifierGatewayMigrations(): void
{
    Artisan::shouldReceive('call')->never();
}

function fleet_verifier_gateway_migration_command(string $gatewayImage): string
{
    $configRoot = config(key: 'orbit.paths.config_root');

    if (! is_string($configRoot) || trim($configRoot) === '') {
        throw new RuntimeException('Test config root is not configured.');
    }

    $configRoot = rtrim($configRoot, characters: '/');

    return implode(' ', [
        'docker run',
        '--rm',
        '--network '.escapeshellarg('orbit-network'),
        '--mount '.escapeshellarg("type=bind,source={$configRoot},target={$configRoot}"),
        '--env '.escapeshellarg('APP_ENV=production'),
        '--env '.escapeshellarg('APP_DEBUG=false'),
        '--env '.escapeshellarg('DB_BUSY_TIMEOUT=5000'),
        '--env '.escapeshellarg('DB_JOURNAL_MODE=wal'),
        '--env '.escapeshellarg('DB_SYNCHRONOUS=NORMAL'),
        '--env '.escapeshellarg("ORBIT_CONFIG_ROOT={$configRoot}"),
        escapeshellarg($gatewayImage),
        escapeshellarg('php'),
        escapeshellarg('artisan'),
        escapeshellarg('migrate'),
        escapeshellarg('--force'),
        escapeshellarg('--no-interaction'),
    ]);
}

/**
 * @return list<array{0: string, 1: string}>
 */
function fleetVerifierStepEvents(OperationRun $run): array
{
    return $run
        ->events()
        ->where('event_type', 'step')
        ->get()
        ->map(fn ($event): array => [$event->payload['key'], $event->payload['status']])
        ->all();
}

function fleet_verifier_agent_response(Request $request, ?string $failCheck = null): mixed
{
    if ($request->method() === 'GET') {
        return Http::response(status: 405);
    }

    $argv = $request['argv'];
    $command = is_array($argv) && is_string($argv[0] ?? null) ? $argv[0] : 'unknown';
    $check = is_array($argv) && is_string($argv[1] ?? null) ? $argv[1] : 'unknown';
    $failed = $failCheck === $check;

    return Http::response([
        'transport' => 'agent-push',
        'operation_id' => (string) $request['operation_id'],
        'binary' => 'orbit',
        'status' => $failed ? 'failed' : 'succeeded',
        'exit_code' => $failed ? 1 : 0,
        'frames' => [
            [
                'type' => $failed ? 'stderr' : 'stdout',
                'message' => $failed
                    ? fleet_verifier_failure_envelope($check)
                    : (
                        $command === 'internal:fleet-update:install-cli'
                            ? fleet_verifier_install_success_envelope()
                            : fleet_verifier_success_envelope($check, $request['input'] ?? null)
                    ),
            ],
            [
                'type' => 'exit',
                'message' => $failed ? '1' : '0',
            ],
        ],
    ]);
}

readonly class FleetVerifierFakeCa extends OrbitCaService
{
    #[Override]
    public function rootCert(): string
    {
        return "-----BEGIN CERTIFICATE-----\ndGVzdA==\n-----END CERTIFICATE-----\n";
    }

    /**
     * @param  list<string>  $additionalSans
     * @return array{cert: string, key: string}
     */
    #[Override]
    public function issueLeaf(string $host, array $additionalSans = []): array
    {
        $configRoot = config(key: 'orbit.paths.config_root');

        if (! is_string($configRoot) || trim($configRoot) === '') {
            throw new RuntimeException('Test config root is not configured.');
        }

        $certsDir = rtrim($configRoot, characters: '/').'/certs';
        File::ensureDirectoryExists($certsDir);
        File::put("{$certsDir}/{$host}.crt", "issued-cert\n");
        File::put("{$certsDir}/{$host}.key", "issued-key\n");
        File::put("{$certsDir}/{$host}.sans", implode("\n", [$host, ...$additionalSans])."\n");

        return [
            'cert' => "{$certsDir}/{$host}.crt",
            'key' => "{$certsDir}/{$host}.key",
        ];
    }
}

function fleet_verifier_install_success_envelope(): string
{
    return json_encode([
        'success' => [
            'data' => [
                'exit_code' => 0,
                'stdout' => "ok\n",
                'stderr' => '',
                'duration_ms' => 1,
            ],
            'meta' => [],
        ],
    ], JSON_THROW_ON_ERROR);
}

function fleet_verifier_success_envelope(string $check, mixed $input): string
{
    return json_encode([
        'success' => [
            'data' => [
                'check' => $check,
                'verified' => true,
                'input' => is_string($input) ? $input : null,
            ],
            'meta' => [],
        ],
    ], JSON_THROW_ON_ERROR);
}

function fleet_verifier_failure_envelope(string $check): string
{
    $code = match ($check) {
        'role-images' => 'fleet_update.required_image_missing',
        'agent' => 'fleet_update.agent_verification_failed',
        default => 'fleet_update.cli_verification_failed',
    };
    $message = match ($check) {
        'role-images' => 'Required role image verification failed.',
        'agent' => 'Orbit Agent verification failed.',
        default => 'CLI verification failed.',
    };

    return json_encode([
        'error' => [
            'code' => $code,
            'message' => $message,
            'meta' => [],
        ],
    ], JSON_THROW_ON_ERROR);
}

/**
 * @return list<array{node: string, operation_id: string, argv: list<string>, input: string|null}>
 */
function fleet_verifier_agent_requests(): array
{
    return array_map(
        function (array $record): array {
            /** @var Request $request */
            $request = $record[0];
            $argv = $request['argv'];

            $host = parse_url($request->url(), PHP_URL_HOST);

            return [
                'node' => is_string($host) ? $host : '',
                'operation_id' => is_string($request['operation_id']) ? $request['operation_id'] : '',
                'argv' => is_array($argv) ? array_values(array_filter($argv, is_string(...))) : [],
                'input' => is_string($request['input'] ?? null) ? $request['input'] : null,
            ];
        },
        Http::recorded()
            ->filter(fn (array $record): bool => $record[0]->method() === 'POST')
            ->all(),
    );
}

/**
 * @param  array<string, array{url: string, sha256: string}>  $cliArtifacts
 * @param  array<string, array{url: string, sha256: string}>  $agentArtifacts
 * @param  array<string, string>  $roleImages
 */
function fleetVerifierSnapshot(
    string $targetVersion = '1.2.3',
    array $cliArtifacts = [],
    array $agentArtifacts = [],
    array $roleImages = [],
    string $manifestSource = 'github-release',
): OperationUpdatePlanSnapshot {
    $gatewayImage = 'ghcr.io/hardimpactdev/orbit-gateway:1.2.3@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    $cliArtifacts = $cliArtifacts === []
        ? [
            'linux-amd64' => [
                'url' => 'https://github.com/hardimpactdev/orbit/releases/download/v1.2.3/orbit-linux-amd64',
                'sha256' => str_repeat('b', times: 64),
            ],
        ] : $cliArtifacts;
    $roleImages = $roleImages === []
        ? [
            'orbit-caddy' => 'caddy:2-alpine',
            'orbit-frankenphp' => 'ghcr.io/hardimpactdev/orbit-frankenphp:2-php8.5-bookworm@sha256:eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee',
            'orbit-websocket' => 'hardimpact/orbit-reverb:1.2.3@sha256:dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd',
        ] : $roleImages;

    return new OperationUpdatePlanSnapshot(
        targetVersion: $targetVersion,
        gatewayImage: $gatewayImage,
        manifestSource: $manifestSource,
        manifestVersion: $targetVersion,
        manifestSnapshot: [
            'version' => $targetVersion,
            'source' => $manifestSource,
            ...($manifestSource === 'topology-candidate' ? ['build_id' => 'candidate-test'] : []),
            'images' => [
                'gateway' => $gatewayImage,
            ],
            'cli_artifacts' => $cliArtifacts,
            'agent_artifacts' => $agentArtifacts,
            'role_images' => $roleImages,
        ],
        cliArtifacts: $cliArtifacts,
        agentArtifacts: $agentArtifacts,
        roleImages: $roleImages,
    );
}

final class FleetVerifierFakeShell implements RemoteShell
{
    /**
     * @var list<array{node: string, script: string, options: array<string, mixed>}>
     */
    public array $calls = [];

    /**
     * @param  array<string, RemoteShellResult>  $failScriptsContaining
     */
    public function __construct(
        private array $failScriptsContaining = [],
    ) {}

    #[Override]
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        new RemoteShellMetadata()->prologue($options['metadata'] ?? []);

        $this->calls[] = [
            'node' => $node->name,
            'script' => $script,
            'options' => $options,
        ];

        foreach ($this->failScriptsContaining as $needle => $result) {
            if (str_contains($script, $needle)) {
                return $result;
            }
        }

        return new RemoteShellResult(exitCode: 0, stdout: "ok\n", stderr: '', durationMs: 10);
    }
}

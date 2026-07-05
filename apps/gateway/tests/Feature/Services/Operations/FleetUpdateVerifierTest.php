<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\Operations\OperationUpdatePlanSnapshot;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\Node;
use App\Models\OperationRun;
use App\Models\OperationUpdatePlan;
use App\Services\NodeCommandTransport\NodeTransportPreference;
use App\Services\Operations\FleetUpdateVerificationFailed;
use App\Services\Operations\FleetUpdateVerifier;
use App\Services\Operations\GatewayCliArtifactRelay;
use App\Services\Operations\OperationRunRecorder;
use App\Services\Operations\OperationUpdatePlanStore;
use App\Services\Operations\UpdateRunner;
use App\Services\RemoteShell\ExplicitRemoteShellFallback;
use App\Services\RemoteShell\RemoteShellMetadata;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Orbit\Core\Enums\OperationStatus;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    request()->headers->set(ExplicitRemoteShellFallback::HEADER, NodeTransportPreference::AgentPush->value);

    Process::preventStrayProcesses();
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

afterEach(function (): void {
    request()->headers->remove(ExplicitRemoteShellFallback::HEADER);
});

it('verifies gateway scheduler workload CLI and required role images', function (): void {
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
        ->orbitAgentCapable()
        ->create([
            'name' => 'agent-1',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.44.0.11',
        ]);
    Node::factory()
        ->appDev()
        ->orbitAgentCapable()
        ->create([
            'name' => 'app-dev-1',
            'platform' => 'linux',
            'wireguard_address' => '10.44.0.12',
        ]);
    Node::factory()
        ->database()
        ->orbitAgentCapable()
        ->create([
            'name' => 'database-1',
            'platform' => 'ubuntu',
            'wireguard_address' => '10.44.0.13',
        ]);
    Node::factory()->gateway()->create(['name' => 'gateway-1', 'platform' => 'debian_12']);
    Node::factory()
        ->ingress()
        ->orbitAgentCapable()
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
        ->and($requests[0]['operation_id'])
        ->toBe($run->id)
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
        ->toBe(json_encode(['images' => ['caddy:2-alpine']], JSON_THROW_ON_ERROR));
});

it('fails when workload CLI verification fails', function (): void {
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
        ->orbitAgentCapable()
        ->create([
            'name' => 'app-dev-1',
            'platform' => 'linux',
            'wireguard_address' => '10.44.0.12',
        ]);

    expect(fn () => app(FleetUpdateVerifier::class)->verify($run, $plan))
        ->toThrow(FleetUpdateVerificationFailed::class, 'CLI verification failed');
});

it('fails when a required role image is missing on a workload node', function (): void {
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
        ->orbitAgentCapable()
        ->create([
            'name' => 'app-dev-1',
            'platform' => 'linux',
            'wireguard_address' => '10.44.0.12',
        ]);

    expect(fn () => app(FleetUpdateVerifier::class)->verify($run, $plan))
        ->toThrow(FleetUpdateVerificationFailed::class, 'Required role image verification failed');
});

it('emits terminal success only after runner verification passes', function (): void {
    request()->headers->set(ExplicitRemoteShellFallback::HEADER, ExplicitRemoteShellFallback::REQUIRED);
    Http::preventStrayRequests();
    Http::fake(fn (Request $request): mixed => fleet_verifier_agent_response($request));

    $run = fleetVerifierRun();
    Node::factory()
        ->appDev()
        ->orbitAgentCapable()
        ->create([
            'name' => 'app-dev-1',
            'platform' => 'linux',
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
            ['cli-artifacts',        'running'],
            ['cli-artifacts',        'done'],
            ['gateway',              'running'],
            ['lease.gateway',        'done'],
            ['scheduler.stop',       'running'],
            ['scheduler.stop',       'done'],
            ['migrations',           'running'],
            ['migrations',           'done'],
            ['gateway.service',      'running'],
            ['gateway.service',      'done'],
            ['scheduler.start',      'running'],
            ['scheduler.start',      'done'],
            ['gateway',              'done'],
            ['workload-nodes',       'running'],
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
    request()->headers->set(ExplicitRemoteShellFallback::HEADER, ExplicitRemoteShellFallback::REQUIRED);
    Http::preventStrayRequests();
    Http::fake(fn (Request $request): mixed => fleet_verifier_agent_response($request, failCheck: 'cli'));

    $run = fleetVerifierRun();
    Node::factory()
        ->appDev()
        ->orbitAgentCapable()
        ->create([
            'name' => 'app-dev-1',
            'platform' => 'linux',
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
        "docker service scale --detach=true 'orbit_orbit-scheduler=0'" => Process::result(),
        "docker service update --detach=true --image '{$gatewayImage}' --update-order 'start-first' --update-failure-action rollback --update-monitor 60s 'orbit_orbit-gateway'" =>
            Process::result(),
        "docker service inspect --format '{{.UpdateStatus.State}}' 'orbit_orbit-gateway'" => Process::result(
            output: "completed\n",
        ),
        "docker service update --detach=true --image '{$gatewayImage}' --update-order 'stop-first' --update-failure-action rollback --update-monitor 60s 'orbit_orbit-scheduler'" =>
            Process::result(),
        "docker service scale --detach=true 'orbit_orbit-scheduler=1'" => Process::result(),
        "docker service inspect --format '{{.Spec.TaskTemplate.ContainerSpec.Image}}' 'orbit_orbit-gateway'" => Process::result(
            output: "{$gatewayImage}\n",
        ),
    ]);
}

function fakeFleetVerifierGatewayMigrations(): void
{
    Artisan::shouldReceive('call')
        ->once()
        ->with('migrate', ['--force' => true, '--no-interaction' => true])
        ->andReturn(0);
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
    $argv = $request['argv'];
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
                    : fleet_verifier_success_envelope($check, $request['input'] ?? null),
            ],
            [
                'type' => 'exit',
                'message' => $failed ? '1' : '0',
            ],
        ],
    ]);
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
    return json_encode([
        'error' => [
            'code' => $check === 'role-images'
                ? 'fleet_update.required_image_missing'
                : 'fleet_update.cli_verification_failed',
            'message' => $check === 'role-images'
                ? 'Required role image verification failed.'
                : 'CLI verification failed.',
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
        Http::recorded()->all(),
    );
}

/**
 * @param  array<string, array{url: string, sha256: string}>  $cliArtifacts
 * @param  array<string, string>  $roleImages
 */
function fleetVerifierSnapshot(
    string $targetVersion = '1.2.3',
    array $cliArtifacts = [],
    array $roleImages = [],
): OperationUpdatePlanSnapshot {
    $gatewayImage = 'ghcr.io/hardimpactdev/orbit-gateway:1.2.3@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    $cliArtifacts = $cliArtifacts === []
        ? [
            'linux-amd64' => [
                'url' => 'https://github.com/hardimpactdev/orbit/releases/download/v1.2.3/orbit-linux-amd64',
                'sha256' => str_repeat('b', 64),
            ],
        ] : $cliArtifacts;
    $roleImages = $roleImages === []
        ? [
            'orbit-caddy' => 'caddy:2-alpine',
            'orbit-websocket' => 'hardimpact/orbit-reverb:1.2.3@sha256:dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd',
        ] : $roleImages;

    return new OperationUpdatePlanSnapshot(
        targetVersion: $targetVersion,
        gatewayImage: $gatewayImage,
        manifestSource: 'github-release',
        manifestVersion: $targetVersion,
        manifestSnapshot: [
            'version' => $targetVersion,
            'source' => 'github-release',
            'images' => [
                'gateway' => $gatewayImage,
            ],
            'cli_artifacts' => $cliArtifacts,
            'role_images' => $roleImages,
        ],
        cliArtifacts: $cliArtifacts,
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

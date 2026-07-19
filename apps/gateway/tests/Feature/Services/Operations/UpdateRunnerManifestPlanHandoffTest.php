<?php

declare(strict_types=1);

use App\Data\RemoteShell\RemoteShellResult;
use App\Models\Node;
use App\Models\OperationRun;
use App\Models\OperationUpdatePlan;
use App\Services\Ca\OrbitCaService;
use App\Services\Operations\FleetUpdateVerifier;
use App\Services\Operations\GatewayCliArtifactRelay;
use App\Services\Operations\GatewayServiceUpdater;
use App\Services\Operations\OperationRunRecorder;
use App\Services\Operations\OperationUpdatePlanStore;
use App\Services\Operations\UpdatePlanBuilder;
use App\Services\Operations\UpdateRunner;
use App\Services\RemoteShell\RunsInternalCommands;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app()->instance(OrbitCaService::class, new UpdateRunnerManifestPlanFakeCa);
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

it('hands the manifest backed plan to gateway and workload update phases exactly once', function (): void {
    $manifest = updateRunnerManifestPlanHandoffManifest();
    $gatewayUpdater = new UpdateRunnerManifestPlanGatewayUpdater;
    $remoteShell = new UpdateRunnerManifestPlanShell;

    Http::fake([
        'github.com/*' => Http::response($manifest, 200),
    ]);
    app()->instance(GatewayServiceUpdater::class, $gatewayUpdater);
    app()->instance(RunsInternalCommands::class, $remoteShell);
    app()->instance(FleetUpdateVerifier::class, new UpdateRunnerManifestPlanNoopVerifier);

    $run = updateRunnerManifestPlanRun();
    $snapshot = app(UpdatePlanBuilder::class)->fromRequest($run, Request::create('/api/update/all/start', 'POST'));
    app(OperationUpdatePlanStore::class)->create($run, $snapshot);

    Node::factory()
        ->gateway()
        ->create([
            'name' => 'gateway-1',
            'platform' => 'debian_12',
            'wireguard_address' => '10.6.0.2',
        ]);
    Node::factory()
        ->appDev()
        ->create([
            'name' => 'app-dev-1',
            'platform' => 'ubuntu_24-04',
            'orbit_path' => '/opt/orbit-app-dev',
        ]);

    app(UpdateRunner::class)->run($run->id);

    $updateScripts = array_values(array_filter(
        $remoteShell->calls,
        fn (array $call): bool => (
            ! str_starts_with($call['script'], 'orbit --version') && ! str_contains($call['script'], 'doctor')
        ),
    ));

    expect($gatewayUpdater->gatewayImages)
        ->toBe([
            'ghcr.io/hardimpactdev/orbit-gateway:2.1.0@sha256:ffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff',
        ])
        ->and($gatewayUpdater->manifestSnapshots)
        ->toBe([$manifest])
        ->and($updateScripts)
        ->toHaveCount(1)
        ->and($updateScripts[0]['script'])
        ->toContain('internal:fleet-update:install-cli')
        ->not->toContain('https://github.com/hardimpactdev/orbit/releases/download/v2.1.0/orbit-linux-amd64');

    $installPayload = json_decode($updateScripts[0]['options']['input'], associative: true, flags: JSON_THROW_ON_ERROR);

    expect($installPayload)
        ->toMatchArray([
            'artifact_url' => 'http://gateway.test/artifacts/linux-amd64',
            'sha256' => str_repeat('e', 64),
            'role_images' => ['caddy:2.9-alpine'],
        ])
        ->and(json_encode($installPayload, JSON_THROW_ON_ERROR))
        ->not->toContain('https://github.com/hardimpactdev/orbit/releases/download/v2.1.0/orbit-linux-amd64');

    Http::assertSentCount(1);
});

function updateRunnerManifestPlanRun(): OperationRun
{
    return app(OperationRunRecorder::class)->queued(
        operationId: (string) Str::uuid(),
        lane: 'gateway',
        operationType: 'update:all',
    );
}

/**
 * @return array<string, mixed>
 */
function updateRunnerManifestPlanHandoffManifest(): array
{
    return [
        'schema_version' => 1,
        'version' => '2.1.0',
        'source' => 'github-release',
        'images' => [
            'gateway' => 'ghcr.io/hardimpactdev/orbit-gateway:2.1.0@sha256:ffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff',
        ],
        'cli_artifacts' => [
            'linux-amd64' => [
                'url' => 'https://github.com/hardimpactdev/orbit/releases/download/v2.1.0/orbit-linux-amd64',
                'sha256' => str_repeat('e', 64),
            ],
        ],
        'role_images' => [
            'orbit-caddy' => 'caddy:2.9-alpine',
            'orbit-websocket' => 'hardimpact/orbit-reverb:2.1.0@sha256:dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd',
        ],
    ];
}

class UpdateRunnerManifestPlanGatewayUpdater extends GatewayServiceUpdater
{
    /**
     * @var list<string>
     */
    public array $gatewayImages = [];

    /**
     * @var list<array<string, mixed>>
     */
    public array $manifestSnapshots = [];

    #[Override]
    public function update(OperationRun $operationRun, OperationUpdatePlan $plan): void
    {
        $this->gatewayImages[] = $plan->gateway_image;
        $this->manifestSnapshots[] = $plan->manifest_snapshot;
    }
}

final class UpdateRunnerManifestPlanNoopVerifier extends FleetUpdateVerifier
{
    public function __construct() {}

    #[Override]
    public function verify(OperationRun $operationRun, OperationUpdatePlan $plan): void
    {
        //
    }
}

readonly class UpdateRunnerManifestPlanFakeCa extends OrbitCaService
{
    #[Override]
    public function rootCert(): string
    {
        return "-----BEGIN CERTIFICATE-----\ndGVzdA==\n-----END CERTIFICATE-----\n";
    }
}

final class UpdateRunnerManifestPlanShell implements RunsInternalCommands
{
    /**
     * @var list<array{node: string, script: string, options: array<string, mixed>}>
     */
    public array $calls = [];

    /**
     * @param  array<int|string, mixed>  $arguments
     * @param  array<int|string, mixed>  $commandOptions
     * @param  array<string, mixed>  $transportOptions
     */
    public function runInternal(
        Node $node,
        string $commandName,
        array $arguments = [],
        array $commandOptions = [],
        array $transportOptions = [],
    ): RemoteShellResult {
        $this->calls[] = [
            'node' => $node->name,
            'script' => $commandName,
            'options' => $transportOptions,
        ];

        return new RemoteShellResult(
            exitCode: 0,
            stdout: json_encode([
                'success' => [
                    'data' => [
                        'exit_code' => 0,
                        'stdout' => "ok\n",
                        'stderr' => '',
                        'duration_ms' => 1,
                    ],
                ],
            ], JSON_THROW_ON_ERROR),
            stderr: '',
            durationMs: 1,
        );
    }
}

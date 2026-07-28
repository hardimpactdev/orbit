<?php

declare(strict_types=1);

use App\Models\Node;
use App\Models\OperationEvent;
use App\Models\OperationRun;
use App\Models\OperationUpdatePlan;
use App\Services\Operations\GatewayCliArtifactRelay;
use App\Services\Operations\GatewayServiceUpdater;
use App\Services\Operations\OperationRunRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Process::preventStrayProcesses();
    $this->configRoot = sys_get_temp_dir().'/orbit-gateway-service-updater-'.Str::random(8);
    config()->set('orbit.paths.config_root', $this->configRoot);
});

afterEach(function (): void {
    if (isset($this->configRoot) && is_dir($this->configRoot)) {
        File::deleteDirectory($this->configRoot);
    }
});

it('updates gateway and scheduler services to the plan image after target image migrations and gateway health', function (): void {
    $run = gatewayServiceUpdaterRun();
    $plan = gateway_service_updater_plan_with_reverb_artifact($run);
    $previousImage = gatewayServiceUpdaterPreviousImage();
    $operations = [];
    $gateway = Node::factory()
        ->gateway()
        ->create([
            'name' => 'gateway-1',
            'wireguard_address' => '10.6.0.2',
            'platform' => 'debian_12',
            'orbit_path' => '/home/orbit/orbit',
            'managed' => false,
        ]);

    Process::fake(function ($process) use (&$operations, $plan, $previousImage) {
        $command = (string) $process->command;
        $operations[] = $command;

        $result = gateway_service_updater_common_process_result($command, $plan, $previousImage);

        if ($result !== null) {
            return $result;
        }

        return match ($command) {
            "docker service inspect --format '{{.UpdateStatus.State}}' 'orbit_orbit-gateway'" => Process::result(
                output: "completed\n",
            ),
            default => throw new RuntimeException("Unexpected process command [{$command}]."),
        };
    });

    app(GatewayServiceUpdater::class)->update($run, $plan);

    expect($operations)
        ->toBe([
            "docker service inspect --format '{{.Spec.TaskTemplate.ContainerSpec.Image}}' 'orbit_orbit-scheduler'",
            "docker service scale --detach=true 'orbit_orbit-scheduler=0'",
            gateway_service_updater_migration_command($plan),
            gateway_service_updater_host_cli_command($plan),
            "docker service update --detach=true --force --image '{$plan->gateway_image}' --update-order 'start-first' --update-failure-action rollback --update-monitor 60s 'orbit_orbit-gateway'",
            "docker service inspect --format '{{.UpdateStatus.State}}' 'orbit_orbit-gateway'",
            "docker service update --detach=true --image '{$plan->gateway_image}' --update-order 'stop-first' --update-failure-action rollback --update-monitor 60s 'orbit_orbit-scheduler'",
            "docker service scale --detach=true 'orbit_orbit-scheduler=1'",
            'bash -s',
            gateway_service_updater_stack_deploy_command(),
            "docker service inspect --format '{{.UpdateStatus.State}}' 'orbit_orbit-gateway'",
            "docker service ls --filter 'name=orbit_orbit-scheduler' --format '{{.Replicas}}'",
            "docker service ls --filter 'name=orbit_orbit-runtime-hibernator' --format '{{.Replicas}}'",
            "docker service ls --filter 'name=orbit_orbit-operations-reverb' --format '{{.Replicas}}'",
        ])
        ->and(array_values(array_filter(
            $operations,
            fn (string $operation): bool => str_starts_with($operation, 'docker run'),
        )))
        ->toBe([
            gateway_service_updater_migration_command($plan),
            gateway_service_updater_host_cli_command($plan),
        ]);

    Process::assertRan(function ($process) use ($plan): bool {
        if ((string) $process->command !== 'bash -s') {
            return false;
        }

        $input = (string) $process->input;

        return (
            str_contains($input, 'https://artifacts.example.test/orbit-reverb-linux-amd64.tar')
            && str_contains($input, str_repeat('e', times: 64))
            && str_contains($input, 'docker load -i "$archive"')
        );
    });

    expect(
        OperationEvent::query()
            ->where('operation_run_id', $run->id)
            ->where('event_type', 'error')
            ->exists(),
    )->toBeFalse();

    expect($gateway->fresh()->installed_gateway_image?->image)
        ->toBe($plan->gateway_image)
        ->and($gateway->fresh()->installed_gateway_image?->digest)
        ->toBe('sha256:'.str_repeat('a', times: 64))
        ->and($gateway->fresh()->installed_gateway_image?->operationRunId)
        ->toBe($run->id)
        ->and($gateway->fresh()->managed)
        ->toBeFalse()
        ->and($gateway->fresh()->installed_cli?->version)
        ->toBe('1.2.3')
        ->and($gateway->fresh()->installed_cli?->sha256)
        ->toBe(str_repeat('c', times: 64))
        ->and($gateway->fresh()->installed_agent)
        ->toBeNull();

    Process::assertRan(function ($process) use ($plan): bool {
        return (
            (string) $process->command === gateway_service_updater_host_cli_command($plan)
            && str_contains((string) $process->input, 'orbit-binary-$sha_prefix')
            && str_contains((string) $process->input, '/mnt/orbit-install/bin/orbit-binary')
        );
    });

    $hostCliIndex = array_search(gateway_service_updater_host_cli_command($plan), $operations, true);
    $gatewayServiceIndex = array_search(
        "docker service update --detach=true --force --image '{$plan->gateway_image}' --update-order 'start-first' --update-failure-action rollback --update-monitor 60s 'orbit_orbit-gateway'",
        $operations,
        true,
    );

    if (! is_int($hostCliIndex) || ! is_int($gatewayServiceIndex)) {
        throw new RuntimeException('Expected host CLI install and gateway service replacement operations.');
    }

    expect($hostCliIndex)->toBeLessThan($gatewayServiceIndex);

    $stack = File::get("{$this->configRoot}/swarm/orbit-gateway-stack.yml");

    expect(File::get("{$this->configRoot}/.env"))
        ->toContain('ORBIT_OPERATIONS_REVERB_APP_ID=orbit-operations')
        ->toContain('ORBIT_OPERATIONS_REVERB_APP_KEY=')
        ->toContain('ORBIT_OPERATIONS_REVERB_APP_SECRET=')
        ->and(File::get("{$this->configRoot}/operations-websocket/apps.php"))
        ->toContain("'app_id' => 'orbit-operations'")
        ->and($stack)
        ->toContain('orbit-operations-reverb:')
        ->toContain(
            'image: "hardimpact/orbit-reverb:1.2.3@sha256:dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd"',
        )
        ->toContain(
            '${ORBIT_CONFIG_ROOT:-'.$this->configRoot.'}/operations-websocket:/etc/orbit/operations-websocket:ro',
        )
        ->not->toContain('ORBIT_OPERATIONS_REVERB_APP_SECRET');

    expect(File::exists("{$this->configRoot}/agent.toml"))->toBeFalse();
});

it('runs gateway migrations through the target gateway image before replacing the gateway service', function (): void {
    $run = gatewayServiceUpdaterRun();
    $plan = gatewayServiceUpdaterPlan($run);
    $previousImage = gatewayServiceUpdaterPreviousImage();
    $operations = [];
    Node::factory()
        ->gateway()
        ->create([
            'name' => 'gateway-1',
            'wireguard_address' => '10.6.0.2',
            'platform' => 'debian_12',
            'orbit_path' => '/home/orbit/orbit',
        ]);

    Artisan::shouldReceive('call')->never();

    Process::fake(function ($process) use (&$operations, $plan, $previousImage) {
        $command = (string) $process->command;
        $operations[] = $command;

        $result = gateway_service_updater_common_process_result($command, $plan, $previousImage);

        if ($result !== null) {
            return $result;
        }

        return match ($command) {
            gateway_service_updater_migration_command($plan) => Process::result(output: "migrated\n"),
            "docker service inspect --format '{{.UpdateStatus.State}}' 'orbit_orbit-gateway'" => Process::result(
                output: "completed\n",
            ),
            default => throw new RuntimeException("Unexpected process command [{$command}]."),
        };
    });

    app(GatewayServiceUpdater::class)->update($run, $plan);

    $migrationIndex = array_search(gateway_service_updater_migration_command($plan), $operations, true);
    $gatewayUpdateIndex = array_search(
        "docker service update --detach=true --force --image '{$plan->gateway_image}' --update-order 'start-first' --update-failure-action rollback --update-monitor 60s 'orbit_orbit-gateway'",
        $operations,
        true,
    );

    expect($migrationIndex)
        ->not->toBeFalse()->and($gatewayUpdateIndex)
        ->not->toBeFalse()->and($migrationIndex)->toBeLessThan($gatewayUpdateIndex)->and(
            $operations[$migrationIndex],
        )->toContain("'{$plan->gateway_image}'")->toContain("'migrate'")->toContain("'--force'")->toContain(
            "'--no-interaction'",
        );
});

it('installs the gateway host CLI once through the local Docker helper', function (): void {
    $run = gatewayServiceUpdaterRun();
    $plan = gatewayServiceUpdaterPlan($run);
    $previousImage = gatewayServiceUpdaterPreviousImage();
    $hostCliInstalls = 0;
    $hostCliScript = '';
    $relayUrl = 'https://10.6.0.2/api/update/artifacts/run/cli/linux-amd64?token=relay-token';
    $artifactRelay = Mockery::mock(GatewayCliArtifactRelay::class);
    $artifactRelay
        ->shouldReceive('artifactFor')
        ->once()
        ->andReturn([
            'url' => $relayUrl,
            'sha256' => str_repeat('c', times: 64),
            'source_url' => 'file:///tmp/orbit-linux-amd64',
        ]);
    app()->instance(GatewayCliArtifactRelay::class, $artifactRelay);
    $gateway = Node::factory()
        ->gateway()
        ->create([
            'name' => 'gateway-1',
            'platform' => 'debian_12',
            'orbit_path' => '/home/orbit/orbit',
        ]);

    Process::fake(function ($process) use ($plan, $previousImage, &$hostCliInstalls, &$hostCliScript) {
        $command = (string) $process->command;

        if ($command === gateway_service_updater_host_cli_command($plan)) {
            $hostCliInstalls++;
            $hostCliScript = (string) $process->input;
        }

        $result = gateway_service_updater_common_process_result($command, $plan, $previousImage);

        if ($result !== null) {
            return $result;
        }

        return match ($command) {
            "docker service inspect --format '{{.UpdateStatus.State}}' 'orbit_orbit-gateway'" => Process::result(
                output: "completed\n",
            ),
            default => throw new RuntimeException("Unexpected process command [{$command}]."),
        };
    });

    app(GatewayServiceUpdater::class)->update($run, $plan);

    expect($hostCliInstalls)
        ->toBe(1)
        ->and($hostCliScript)
        ->toContain($relayUrl)
        ->not
        ->toContain('file:///tmp/orbit-linux-amd64')
        ->and($gateway->fresh()->installed_cli?->version)
        ->toBe('1.2.3');
});

it('forces gateway task replacement after refreshing an immutable CLI artifact', function (): void {
    $run = gatewayServiceUpdaterRun();
    $plan = gatewayServiceUpdaterPlan($run);
    $previousImage = gatewayServiceUpdaterPreviousImage();
    $operations = [];
    Node::factory()
        ->gateway()
        ->create([
            'name' => 'gateway-1',
            'platform' => 'debian_12',
            'orbit_path' => '/home/orbit/orbit',
        ]);

    Process::fake(function ($process) use ($plan, $previousImage, &$operations) {
        $command = (string) $process->command;
        $operations[] = $command;

        $result = gateway_service_updater_common_process_result($command, $plan, $previousImage);

        if ($result !== null) {
            return $result;
        }

        return match ($command) {
            "docker service inspect --format '{{.UpdateStatus.State}}' 'orbit_orbit-gateway'" => Process::result(
                output: "completed\n",
            ),
            default => throw new RuntimeException("Unexpected process command [{$command}]."),
        };
    });

    app(GatewayServiceUpdater::class)->update($run, $plan);

    expect($operations)->toContain(
        "docker service update --detach=true --force --image '{$plan->gateway_image}' --update-order 'start-first' --update-failure-action rollback --update-monitor 60s 'orbit_orbit-gateway'",
    );
});

it('fails gateway host CLI install when the local Docker helper fails', function (): void {
    $run = gatewayServiceUpdaterRun();
    $plan = gatewayServiceUpdaterPlan($run);
    $previousImage = gatewayServiceUpdaterPreviousImage();
    $operations = [];
    $gateway = Node::factory()
        ->gateway()
        ->create([
            'name' => 'gateway-1',
            'platform' => 'debian_12',
            'orbit_path' => '/home/orbit/orbit',
        ]);

    Process::fake(function ($process) use ($plan, $previousImage, &$operations) {
        $command = (string) $process->command;
        $operations[] = $command;

        if ($command === gateway_service_updater_host_cli_command($plan)) {
            return Process::result(exitCode: 1, errorOutput: "artifact install failed\n");
        }

        $result = gateway_service_updater_common_process_result($command, $plan, $previousImage);

        if ($result !== null) {
            return $result;
        }

        return match ($command) {
            "docker service inspect --format '{{.UpdateStatus.State}}' 'orbit_orbit-gateway'" => Process::result(
                output: "completed\n",
            ),
            "docker service update --detach=true --image '{$previousImage}' --update-order 'stop-first' --update-failure-action rollback --update-monitor 60s 'orbit_orbit-scheduler'"
                => Process::result(),
            default => throw new RuntimeException("Unexpected process command [{$command}]."),
        };
    });

    expect(fn () => app(GatewayServiceUpdater::class)->update($run, $plan))
        ->toThrow(RuntimeException::class, 'Failed to install gateway host CLI artifact: artifact install failed');

    expect($gateway->fresh()->installed_cli)
        ->toBeNull();

    expect($operations)
        ->toContain(
            "docker service update --detach=true --image '{$previousImage}' --update-order 'stop-first' --update-failure-action rollback --update-monitor 60s 'orbit_orbit-scheduler'",
            "docker service scale --detach=true 'orbit_orbit-scheduler=1'",
        );
});

it('restores the scheduler previous image and replica when gateway migrations fail', function (): void {
    $run = gatewayServiceUpdaterRun();
    $plan = gatewayServiceUpdaterPlan($run);
    $previousImage = gatewayServiceUpdaterPreviousImage();

    Process::fake([
        "docker service inspect --format '{{.Spec.TaskTemplate.ContainerSpec.Image}}' 'orbit_orbit-scheduler'" => Process::result(
            output: "{$previousImage}\n",
        ),
        "docker service scale --detach=true 'orbit_orbit-scheduler=0'" => Process::result(),
        gateway_service_updater_migration_command($plan) => Process::result(
            exitCode: 1,
            errorOutput: "migration failed\n",
        ),
        "docker service update --detach=true --image '{$previousImage}' --update-order 'stop-first' --update-failure-action rollback --update-monitor 60s 'orbit_orbit-scheduler'" =>
            Process::result(),
        "docker service scale --detach=true 'orbit_orbit-scheduler=1'" => Process::result(),
    ]);

    expect(fn () => app(GatewayServiceUpdater::class)->update($run, $plan))
        ->toThrow(RuntimeException::class, 'migration failed');

    Process::assertRan("docker service scale --detach=true 'orbit_orbit-scheduler=0'");
    Process::assertRan(
        "docker service update --detach=true --image '{$previousImage}' --update-order 'stop-first' --update-failure-action rollback --update-monitor 60s 'orbit_orbit-scheduler'",
    );
    Process::assertRan("docker service scale --detach=true 'orbit_orbit-scheduler=1'");
    Process::assertNotRan(
        fn ($process): bool => (
            str_contains((string) $process->command, $plan->gateway_image)
            && str_contains((string) $process->command, "'orbit_orbit-gateway'")
        ),
    );
});

it('waits for a detached gateway service update to complete before starting the scheduler', function (): void {
    Sleep::fake();

    $run = gatewayServiceUpdaterRun();
    $plan = gatewayServiceUpdaterPlan($run);
    $previousImage = gatewayServiceUpdaterPreviousImage();
    $gatewayStates = ['updating', 'completed'];
    $gatewayStateChecks = 0;

    Process::fake(function ($process) use (&$gatewayStates, &$gatewayStateChecks, $plan, $previousImage) {
        $command = (string) $process->command;

        $result = gateway_service_updater_common_process_result($command, $plan, $previousImage);

        if ($result !== null) {
            return $result;
        }

        if (
            $command
            === "docker service inspect --format '{{.Spec.TaskTemplate.ContainerSpec.Image}}' 'orbit_orbit-scheduler'"
        ) {
            return Process::result(output: "{$previousImage}\n");
        }

        if ($command === "docker service inspect --format '{{.UpdateStatus.State}}' 'orbit_orbit-gateway'") {
            $gatewayStateChecks++;

            return Process::result(output: (array_shift($gatewayStates) ?? 'completed')."\n");
        }

        if (str_starts_with($command, 'docker service scale ')) {
            return Process::result();
        }

        if (str_starts_with($command, 'docker service update ') && str_contains($command, $plan->gateway_image)) {
            return Process::result();
        }

        throw new RuntimeException("Unexpected process command [{$command}].");
    });

    app(GatewayServiceUpdater::class)->update($run, $plan);

    expect($gatewayStateChecks)->toBe(3);

    Sleep::assertSleptTimes(1);
});

it('treats a same-image gateway service update with no Docker update status as healthy when the service is converged', function (): void {
    Sleep::fake();

    $run = gatewayServiceUpdaterRun();
    $plan = gatewayServiceUpdaterPlan($run);
    $previousImage = gatewayServiceUpdaterPreviousImage();
    $operations = [];

    Process::fake(function ($process) use (&$operations, $plan, $previousImage) {
        $command = (string) $process->command;
        $operations[] = $command;

        $result = gateway_service_updater_common_process_result($command, $plan, $previousImage);

        if ($result !== null) {
            return $result;
        }

        return match ($command) {
            "docker service inspect --format '{{.UpdateStatus.State}}' 'orbit_orbit-gateway'" => Process::result(
                errorOutput: "template: :1:15: executing \"\" at <.UpdateStatus.State>: map has no entry for key \"UpdateStatus\"\n",
                exitCode: 1,
            ),
            "docker service inspect --format '{{.Spec.TaskTemplate.ContainerSpec.Image}}' 'orbit_orbit-gateway'"
                => Process::result(output: "{$plan->gateway_image}\n"),
            "docker service ls --filter 'name=orbit_orbit-gateway' --format '{{.Replicas}}'" => Process::result(
                output: "1/1\n",
            ),
            default => throw new RuntimeException("Unexpected process command [{$command}]."),
        };
    });

    app(GatewayServiceUpdater::class)->update($run, $plan);

    expect($operations)->toBe([
        "docker service inspect --format '{{.Spec.TaskTemplate.ContainerSpec.Image}}' 'orbit_orbit-scheduler'",
        "docker service scale --detach=true 'orbit_orbit-scheduler=0'",
        gateway_service_updater_migration_command($plan),
        "docker service update --detach=true --force --image '{$plan->gateway_image}' --update-order 'start-first' --update-failure-action rollback --update-monitor 60s 'orbit_orbit-gateway'",
        "docker service inspect --format '{{.UpdateStatus.State}}' 'orbit_orbit-gateway'",
        "docker service inspect --format '{{.Spec.TaskTemplate.ContainerSpec.Image}}' 'orbit_orbit-gateway'",
        "docker service ls --filter 'name=orbit_orbit-gateway' --format '{{.Replicas}}'",
        "docker service update --detach=true --image '{$plan->gateway_image}' --update-order 'stop-first' --update-failure-action rollback --update-monitor 60s 'orbit_orbit-scheduler'",
        "docker service scale --detach=true 'orbit_orbit-scheduler=1'",
        gateway_service_updater_stack_deploy_command(),
        "docker service inspect --format '{{.UpdateStatus.State}}' 'orbit_orbit-gateway'",
        "docker service inspect --format '{{.Spec.TaskTemplate.ContainerSpec.Image}}' 'orbit_orbit-gateway'",
        "docker service ls --filter 'name=orbit_orbit-gateway' --format '{{.Replicas}}'",
        "docker service ls --filter 'name=orbit_orbit-scheduler' --format '{{.Replicas}}'",
        "docker service ls --filter 'name=orbit_orbit-runtime-hibernator' --format '{{.Replicas}}'",
        "docker service ls --filter 'name=orbit_orbit-operations-reverb' --format '{{.Replicas}}'",
    ]);

    Sleep::assertSleptTimes(0);
});

it('restores the scheduler previous image and replica when the updated gateway fails health', function (): void {
    $run = gatewayServiceUpdaterRun();
    $plan = gatewayServiceUpdaterPlan($run);
    $previousImage = gatewayServiceUpdaterPreviousImage();

    Process::fake([
        "docker service inspect --format '{{.Spec.TaskTemplate.ContainerSpec.Image}}' 'orbit_orbit-scheduler'" => Process::result(
            output: "{$previousImage}\n",
        ),
        "docker service scale --detach=true 'orbit_orbit-scheduler=0'" => Process::result(),
        gateway_service_updater_migration_command($plan) => Process::result(),
        "docker service update --detach=true --force --image '{$plan->gateway_image}' --update-order 'start-first' --update-failure-action rollback --update-monitor 60s 'orbit_orbit-gateway'" =>
            Process::result(),
        "docker service inspect --format '{{.UpdateStatus.State}}' 'orbit_orbit-gateway'" => Process::result(
            output: "rollback_completed\n",
        ),
        "docker service update --detach=true --image '{$previousImage}' --update-order 'stop-first' --update-failure-action rollback --update-monitor 60s 'orbit_orbit-scheduler'" =>
            Process::result(),
        "docker service scale --detach=true 'orbit_orbit-scheduler=1'" => Process::result(),
    ]);

    expect(fn () => app(GatewayServiceUpdater::class)->update($run, $plan))
        ->toThrow(RuntimeException::class, 'Gateway service health check failed');

    Process::assertRan(
        "docker service update --detach=true --force --image '{$plan->gateway_image}' --update-order 'start-first' --update-failure-action rollback --update-monitor 60s 'orbit_orbit-gateway'",
    );
    Process::assertRan("docker service inspect --format '{{.UpdateStatus.State}}' 'orbit_orbit-gateway'");
    Process::assertRan(
        "docker service update --detach=true --image '{$previousImage}' --update-order 'stop-first' --update-failure-action rollback --update-monitor 60s 'orbit_orbit-scheduler'",
    );
    Process::assertRan("docker service scale --detach=true 'orbit_orbit-scheduler=1'");
});

it('records a recovery failed event when the scheduler cannot be scaled back to one replica', function (): void {
    $run = gatewayServiceUpdaterRun();
    $plan = gatewayServiceUpdaterPlan($run);
    $previousImage = gatewayServiceUpdaterPreviousImage();

    Process::fake([
        "docker service inspect --format '{{.Spec.TaskTemplate.ContainerSpec.Image}}' 'orbit_orbit-scheduler'" => Process::result(
            output: "{$previousImage}\n",
        ),
        "docker service scale --detach=true 'orbit_orbit-scheduler=0'" => Process::result(),
        gateway_service_updater_migration_command($plan) => Process::result(
            exitCode: 1,
            errorOutput: "migration failed\n",
        ),
        "docker service update --detach=true --image '{$previousImage}' --update-order 'stop-first' --update-failure-action rollback --update-monitor 60s 'orbit_orbit-scheduler'" =>
            Process::result(),
        "docker service scale --detach=true 'orbit_orbit-scheduler=1'" => Process::result(
            exitCode: 1,
            errorOutput: "scheduler scale failed\n",
        ),
    ]);

    expect(fn () => app(GatewayServiceUpdater::class)->update($run, $plan))
        ->toThrow(RuntimeException::class);

    $event = OperationEvent::query()
        ->where('operation_run_id', $run->id)
        ->where('event_type', 'error')
        ->latest('sequence')
        ->first();

    expect($event)
        ->not
        ->toBeNull()
        ->and($event?->payload)
        ->toMatchArray([
            'exit_code' => 1,
        ])
        ->and($event?->payload['message'])
        ->toContain('Scheduler recovery failed')
        ->and($event?->payload['data']['code'])
        ->toBe('update.scheduler_recovery_failed')
        ->and($event?->payload['data']['recovery_command'])
        ->toContain($previousImage)
        ->and($event?->payload['data']['recovery_command'])
        ->toContain('docker service update --detach=true')
        ->and($event?->payload['data']['recovery_command'])
        ->toContain("docker service scale --detach=true 'orbit_orbit-scheduler=1'");
});

function gatewayServiceUpdaterRun(): OperationRun
{
    return app(OperationRunRecorder::class)->queued(
        operationId: (string) Str::uuid(),
        lane: 'gateway',
        operationType: 'update:all',
    );
}

function gateway_service_updater_plan_with_reverb_artifact(OperationRun $run): OperationUpdatePlan
{
    return gatewayServiceUpdaterPlan($run, [
        'role_image_artifacts' => [
            'orbit-websocket' => [
                'url' => 'https://artifacts.example.test/orbit-reverb-linux-amd64.tar',
                'sha256' => str_repeat('e', times: 64),
            ],
        ],
    ]);
}

/**
 * @param  array<string, mixed>  $manifestSnapshotOverrides
 */
function gatewayServiceUpdaterPlan(OperationRun $run, array $manifestSnapshotOverrides = []): OperationUpdatePlan
{
    $gatewayImage = 'ghcr.io/hardimpactdev/orbit-gateway:1.2.3@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    $cliArtifact = [
        'url' => 'https://github.com/hardimpactdev/orbit/releases/download/v1.2.3/orbit-linux-amd64',
        'sha256' => str_repeat('c', times: 64),
    ];
    $agentArtifact = [
        'url' => 'https://github.com/hardimpactdev/orbit/releases/download/v1.2.3/orbit-agent-linux-x64',
        'sha256' => str_repeat('d', times: 64),
    ];
    $roleImages = [
        'orbit-websocket' => 'hardimpact/orbit-reverb:1.2.3@sha256:dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd',
    ];

    $manifestSnapshot = [
        'version' => '1.2.3',
        'images' => [
            'gateway' => $gatewayImage,
        ],
        'cli_artifacts' => [
            'linux-amd64' => $cliArtifact,
        ],
        'agent_artifacts' => [
            'linux-amd64' => $agentArtifact,
        ],
        'role_images' => $roleImages,
    ];

    $manifestSnapshot = array_replace_recursive($manifestSnapshot, $manifestSnapshotOverrides);

    return OperationUpdatePlan::query()->create([
        'operation_run_id' => $run->id,
        'target_version' => '1.2.3',
        'gateway_image' => $gatewayImage,
        'manifest_source' => 'github-release',
        'manifest_version' => '1.2.3',
        'manifest_snapshot' => $manifestSnapshot,
        'cli_artifacts' => [
            'linux-amd64' => $cliArtifact,
        ],
        'agent_artifacts' => [
            'linux-amd64' => $agentArtifact,
        ],
        'role_images' => $roleImages,
    ]);
}

function gatewayServiceUpdaterPreviousImage(): string
{
    return 'ghcr.io/hardimpactdev/orbit-gateway:1.2.2@sha256:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
}

function gateway_service_updater_stack_deploy_command(): string
{
    return (
        'docker stack deploy -c '
        .escapeshellarg(config('orbit.paths.config_root').'/swarm/orbit-gateway-stack.yml')
        ." 'orbit'"
    );
}

function gateway_service_updater_migration_command(OperationUpdatePlan $plan): string
{
    $configRoot = config('orbit.paths.config_root');

    if (! is_string($configRoot) || trim($configRoot) === '') {
        throw new RuntimeException('Test config root is not configured.');
    }

    $configRoot = rtrim($configRoot, '/');

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
        escapeshellarg($plan->gateway_image),
        escapeshellarg('php'),
        escapeshellarg('artisan'),
        escapeshellarg('migrate'),
        escapeshellarg('--force'),
        escapeshellarg('--no-interaction'),
    ]);
}

function gateway_service_updater_host_cli_command(OperationUpdatePlan $plan): string
{
    return implode(' ', [
        'docker run --rm --interactive',
        '--entrypoint '.escapeshellarg('bash'),
        '--mount '.escapeshellarg('type=bind,source=/home/orbit/orbit,target=/mnt/orbit-install'),
        '--mount '.escapeshellarg('type=bind,source=/home/orbit,target=/mnt/orbit-home'),
        escapeshellarg($plan->gateway_image),
        escapeshellarg('-s'),
    ]);
}

function gateway_service_updater_common_process_result(
    string $command,
    OperationUpdatePlan $plan,
    string $previousImage,
): mixed {
    if ($command === gateway_service_updater_migration_command($plan)) {
        return Process::result();
    }

    if ($command === gateway_service_updater_host_cli_command($plan)) {
        return Process::result();
    }

    return match ($command) {
        "docker service inspect --format '{{.Spec.TaskTemplate.ContainerSpec.Image}}' 'orbit_orbit-scheduler'"
            => Process::result(output: "{$previousImage}\n"),
        "docker service scale --detach=true 'orbit_orbit-scheduler=0'" => Process::result(),
        "docker service update --detach=true --force --image '{$plan->gateway_image}' --update-order 'start-first' --update-failure-action rollback --update-monitor 60s 'orbit_orbit-gateway'"
            => Process::result(),
        "docker service update --detach=true --image '{$plan->gateway_image}' --update-order 'stop-first' --update-failure-action rollback --update-monitor 60s 'orbit_orbit-scheduler'"
            => Process::result(),
        "docker service scale --detach=true 'orbit_orbit-scheduler=1'" => Process::result(),
        'bash -s' => Process::result(),
        gateway_service_updater_stack_deploy_command() => Process::result(),
        "docker service ls --filter 'name=orbit_orbit-scheduler' --format '{{.Replicas}}'" => Process::result(
            output: "1/1\n",
        ),
        "docker service ls --filter 'name=orbit_orbit-runtime-hibernator' --format '{{.Replicas}}'" => Process::result(
            output: "1/1\n",
        ),
        "docker service ls --filter 'name=orbit_orbit-operations-reverb' --format '{{.Replicas}}'" => Process::result(
            output: "1/1\n",
        ),
        default => null,
    };
}

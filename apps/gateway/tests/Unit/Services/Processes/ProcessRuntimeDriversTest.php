<?php

declare(strict_types=1);

use App\Enums\Apps\AppRuntimeKind;
use App\Enums\Processes\ProcessRuntime;
use App\Enums\ProcessRestartPolicy;
use App\Models\Node;
use App\Models\Process;
use App\Models\Project;
use App\Services\Processes\ProcessRuntimeDrivers\DockerProcessRuntimeDriver;
use App\Services\Processes\ProcessRuntimeDrivers\DockerSwarmProcessRuntimeDriver;
use App\Services\Processes\ProcessRuntimeDrivers\LaunchdProcessRuntimeDriver;
use App\Services\Processes\ProcessRuntimeDrivers\SystemdProcessRuntimeDriver;
use App\Services\RemoteShell\RemoteLocalExecutor;
use App\Services\RemoteShell\RunsInternalCommands;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    app()->instance(
        RunsInternalCommands::class,
        app(RemoteLocalExecutor::class),
    );
});

it('runs docker process lifecycle through the docker runtime driver', function (): void {
    fake_docker_process_driver_agent_responses();

    $node = Node::factory()
        ->appDev()
        ->managed()
        ->create([
            'name' => 'app-dev-1',
            'wireguard_address' => '10.44.0.71',
        ]);
    $app = Project::factory()->create(['name' => 'docs', 'node_id' => $node->id, 'path' => '/srv/docs']);
    $process = Process::factory()
        ->forOwner($app)
        ->create([
            'name' => 'queue',
            'command' => 'php artisan queue:work',
            'runtime' => ProcessRuntime::Docker,
        ]);

    $driver = app(DockerProcessRuntimeDriver::class);
    $runtimeUnit = $driver->runtimeUnitName($app, $process);

    expect($runtimeUnit)
        ->toBe('orbit_docs_development_main_queue')
        ->and($driver->start($node, $runtimeUnit))
        ->toBeTrue()
        ->and($driver->stop($node, $runtimeUnit))
        ->toBeTrue()
        ->and($driver->restart($node, $runtimeUnit))
        ->toBeTrue()
        ->and($driver->logScript($app, $process, null, $runtimeUnit, 25, false))
        ->toBe("docker logs --tail 25 'orbit_docs_development_main_queue' 2>&1")
        ->and($driver->logScript($app, $process, null, $runtimeUnit, 25, true))
        ->toBe("docker logs --tail 25 --follow 'orbit_docs_development_main_queue' 2>&1");

    expect(docker_process_driver_agent_actions())->toBe(['start', 'stop', 'restart']);
});

it('applies, removes, and cleans up docker process runtime units through the docker runtime driver', function (): void {
    fake_docker_process_driver_agent_responses();

    $node = Node::factory()
        ->appDev()
        ->managed()
        ->create([
            'name' => 'app-dev-1',
            'user' => 'orbit',
            'wireguard_address' => '10.44.0.71',
        ]);
    $app = Project::factory()->create([
        'name' => 'docs',
        'node_id' => $node->id,
        'path' => '/srv/docs',
        'php_version' => '8.5',
        'runtime' => AppRuntimeKind::Php,
    ]);
    $process = Process::factory()
        ->forOwner($app)
        ->create([
            'name' => 'queue',
            'command' => 'php artisan queue:work',
            'runtime' => ProcessRuntime::Docker,
        ]);

    $driver = app(DockerProcessRuntimeDriver::class);
    $runtimeUnit = $driver->runtimeUnitName($app, $process);

    expect($driver->cleanupScript($runtimeUnit))
        ->toBe("docker rm -f 'orbit_docs_development_main_queue' 2>/dev/null || true")
        ->and($driver->apply($node, $app, $process))
        ->toBeTrue()
        ->and($driver->remove($node, $runtimeUnit))
        ->toBeTrue();

    $payloads = docker_process_driver_agent_payloads();

    expect($payloads)
        ->toHaveCount(2)
        ->and($payloads[0]['action'])
        ->toBe('apply')
        ->and($payloads[0]['prepare_prerequisites'])
        ->toBeFalse()
        ->and($payloads[0]['spec']['name'])
        ->toBe('orbit_docs_development_main_queue')
        ->and($payloads[1])
        ->toMatchArray([
            'action' => 'remove',
            'container' => 'orbit_docs_development_main_queue',
        ]);
});

it('keeps stopped always-restart app containers stopped across Docker restarts', function (): void {
    fake_docker_process_driver_agent_responses();

    $node = Node::factory()
        ->appDev()
        ->managed()
        ->create([
            'name' => 'app-dev-1',
            'wireguard_address' => '10.44.0.71',
        ]);
    $app = Project::factory()->for($node, 'node')->create([
        'name' => 'docs',
        'path' => '/srv/docs',
    ]);
    $process = Process::factory()
        ->forOwner($app)
        ->create([
            'name' => 'vite',
            'runtime' => ProcessRuntime::Docker,
            'restart_policy' => ProcessRestartPolicy::Always,
        ]);

    expect(app(DockerProcessRuntimeDriver::class)->apply($node, $app, $process))
        ->toBeTrue()
        ->and(docker_process_driver_agent_payloads()[0]['spec']['restart_policy'])
        ->toBe('unless-stopped');
});

it('applies node owned docker service processes from runtime config', function (): void {
    fake_docker_process_driver_agent_responses();

    $node = Node::factory()
        ->database()
        ->managed()
        ->create([
            'name' => 'database-1',
            'wireguard_address' => '10.44.0.72',
        ]);
    $app = Project::factory()->create(['name' => 'docs', 'node_id' => $node->id, 'path' => '/srv/docs']);
    $process = Process::factory()
        ->forOwner($node)
        ->create([
            'name' => 'valkey',
            'command' => 'valkey-server --bind 0.0.0.0 --protected-mode no',
            'runtime' => ProcessRuntime::Docker,
            'runtime_config' => [
                'image' => 'valkey/valkey:8.1',
                'environment' => [
                    'ALLOW_EMPTY_PASSWORD' => 'yes',
                ],
                'mounts' => [
                    [
                        'source' => '/var/lib/orbit/valkey',
                        'target' => '/data',
                    ],
                ],
                'network_aliases' => ['valkey'],
            ],
        ]);

    $driver = app(DockerProcessRuntimeDriver::class);
    $runtimeUnit = $driver->runtimeUnitName($app, $process);

    expect($runtimeUnit)
        ->toBe('valkey')
        ->and($driver->apply($node, $app, $process))
        ->toBeTrue()
        ->and($driver->start($node, $runtimeUnit))
        ->toBeTrue()
        ->and($driver->stop($node, $runtimeUnit))
        ->toBeTrue()
        ->and($driver->restart($node, $runtimeUnit))
        ->toBeTrue()
        ->and($driver->logScript($app, $process, null, $runtimeUnit, 25, false))
        ->toBe("docker logs --tail 25 'valkey' 2>&1");

    $payloads = docker_process_driver_agent_payloads();
    $apply = $payloads[0];

    expect($apply['prepare_prerequisites'])
        ->toBeTrue()
        ->and($apply['spec']['name'])
        ->toBe('valkey')
        ->and($apply['spec']['image'])
        ->toBe('valkey/valkey:8.1')
        ->and($apply['spec']['environment'])
        ->toBe([implode('_', ['ALLOW', 'EMPTY', 'PASSWORD']) => implode('', ['y', 'e', 's'])])
        ->and($apply['spec']['mounts'][0])
        ->toMatchArray([
            'source' => '/var/lib/orbit/valkey',
            'target' => '/data',
        ]);

    expect(docker_process_driver_agent_actions())->toBe(['apply', 'start', 'stop', 'restart']);
});

it('publishes managed service ports when applying node owned docker processes', function (): void {
    fake_docker_process_driver_agent_responses();

    $node = Node::factory()
        ->database()
        ->managed()
        ->create([
            'name' => 'beast',
            'wireguard_address' => '10.6.0.7',
        ]);
    $app = Project::factory()->create(['name' => 'docs', 'node_id' => $node->id, 'path' => '/srv/docs']);
    $process = Process::factory()
        ->forOwner($node)
        ->create([
            'name' => 'mailpit',
            'command' => '/mailpit',
            'runtime' => ProcessRuntime::Docker,
            'runtime_config' => [
                'command_mode' => 'image_entrypoint',
                'image' => 'axllent/mailpit:latest',
                'ports' => [
                    ['published' => 1025, 'target' => 1025, 'protocol' => 'tcp'],
                ],
            ],
        ]);

    expect(app(DockerProcessRuntimeDriver::class)->apply($node, $app, $process))->toBeTrue();

    $apply = docker_process_driver_agent_payloads()[0];

    expect($apply['prepare_prerequisites'])
        ->toBeTrue()
        ->and($apply['spec']['ports'])
        ->toBe([
            ['host' => null, 'published' => 1025, 'target' => 1025, 'protocol' => 'tcp'],
        ])
        ->and($apply['spec']['command_mode'])
        ->toBe('image_entrypoint');
});

it('renders managed service data paths as docker named volumes for node owned docker processes', function (): void {
    fake_docker_process_driver_agent_responses();

    $node = Node::factory()
        ->database()
        ->managed()
        ->create([
            'name' => 'database-1',
            'wireguard_address' => '10.44.0.72',
        ]);
    $app = Project::factory()->create(['name' => 'docs', 'node_id' => $node->id, 'path' => '/srv/docs']);
    $process = Process::factory()
        ->forOwner($node)
        ->create([
            'name' => 'mysql8',
            'command' => 'mysqld',
            'runtime' => ProcessRuntime::Docker,
            'runtime_config' => [
                'command_mode' => 'image_entrypoint',
                'image' => 'mysql:8.4',
                'mounts' => [
                    [
                        'source' => '/var/lib/orbit/processes/mysql8',
                        'target' => '/var/lib/mysql',
                    ],
                ],
                'volumes' => [
                    [
                        'name' => 'orbit-mysql8',
                        'target' => '/var/lib/mysql',
                    ],
                ],
                'ports' => [
                    [
                        'published' => 3308,
                        'target' => 3306,
                        'protocol' => 'tcp',
                    ],
                ],
            ],
        ]);

    expect(app(DockerProcessRuntimeDriver::class)->apply($node, $app, $process))->toBeTrue();

    $apply = docker_process_driver_agent_payloads()[0];

    expect($apply['prepare_prerequisites'])
        ->toBeTrue()
        ->and($apply['spec']['ports'])
        ->toBe([
            ['host' => null, 'published' => 3308, 'target' => 3306, 'protocol' => 'tcp'],
        ])
        ->and($apply['spec']['volumes'])
        ->toBe([
            [
                'source' => 'orbit-mysql8',
                'target' => '/var/lib/mysql',
                'read_only' => false,
            ],
        ])
        ->and($apply['spec']['mounts'])
        ->toBeEmpty();
});

it('runs docker swarm process lifecycle through the docker swarm runtime driver', function (): void {
    fake_process_driver_agent_responses();

    $node = Node::factory()
        ->database()
        ->managed()
        ->create([
            'name' => 'database-1',
            'wireguard_address' => '10.44.0.72',
        ]);
    $app = Project::factory()->create(['name' => 'docs', 'node_id' => $node->id, 'path' => '/srv/docs']);
    $process = Process::factory()
        ->forOwner($node)
        ->create([
            'name' => 'valkey7',
            'command' => 'valkey-server --bind 0.0.0.0 --protected-mode no',
            'runtime' => ProcessRuntime::DockerSwarm,
            'runtime_config' => [
                'service_name' => 'orbit-valkey-7',
            ],
        ]);

    $driver = app(DockerSwarmProcessRuntimeDriver::class);
    $runtimeUnit = $driver->runtimeUnitName($app, $process);

    expect($runtimeUnit)
        ->toBe('orbit-valkey-7')
        ->and($driver->start($node, $runtimeUnit))
        ->toBeTrue()
        ->and($driver->stop($node, $runtimeUnit))
        ->toBeTrue()
        ->and($driver->restart($node, $runtimeUnit))
        ->toBeTrue()
        ->and($driver->logScript($app, $process, null, $runtimeUnit, 25, false))
        ->toBe("docker service logs --tail 25 'orbit-valkey-7' 2>&1")
        ->and($driver->logScript($app, $process, null, $runtimeUnit, 25, true))
        ->toBe("docker service logs --tail 25 --follow 'orbit-valkey-7' 2>&1");

    expect(process_driver_agent_actions('internal:process-docker-swarm-service'))
        ->toBe(['start', 'stop', 'restart']);
});

it('applies, removes, and cleans up docker swarm process runtime services from runtime config', function (): void {
    fake_process_driver_agent_responses();

    $node = Node::factory()
        ->database()
        ->managed()
        ->create([
            'name' => 'database-1',
            'wireguard_address' => '10.44.0.72',
        ]);
    $app = Project::factory()->create(['name' => 'docs', 'node_id' => $node->id, 'path' => '/srv/docs']);
    $process = Process::factory()
        ->forOwner($node)
        ->create([
            'name' => 'mysql8',
            'command' => 'mysqld',
            'restart_policy' => ProcessRestartPolicy::Always,
            'runtime' => ProcessRuntime::DockerSwarm,
            'runtime_config' => [
                'image' => 'mysql:8.4',
                'labels' => [
                    'orbit.managed' => 'true',
                    'orbit.process' => 'mysql8',
                    'orbit.process.service' => 'mysql',
                    'orbit.process.spec_hash' => 'abc123',
                    'orbit.process.version' => '8.4',
                    'orbit.process.version_family' => '8',
                ],
                'ports' => [
                    [
                        'published' => 3308,
                        'target' => 3306,
                        'protocol' => 'tcp',
                    ],
                ],
                'bind_mounts' => [
                    [
                        'source' => '/var/lib/orbit/processes/mysql8/mysql.cnf',
                        'target' => '/etc/mysql/conf.d/orbit.cnf',
                        'read_only' => true,
                    ],
                ],
                'service_name' => 'orbit-mysql-8',
                'replaces_runtime_unit' => 'orbit-mysql-legacy',
                'update_strategy' => [
                    'order' => 'stop-first',
                    'parallelism' => 1,
                ],
                'volumes' => [
                    [
                        'name' => 'orbit-mysql-8',
                        'target' => '/var/lib/mysql',
                    ],
                ],
            ],
        ]);

    $driver = app(DockerSwarmProcessRuntimeDriver::class);
    $runtimeUnit = $driver->runtimeUnitName($app, $process);

    expect($driver->cleanupScript($runtimeUnit))
        ->toBe("docker service rm 'orbit-mysql-8' 2>/dev/null || true")
        ->and($driver->apply($node, $app, $process))
        ->toBeTrue()
        ->and($process->fresh()->runtime_config)
        ->not
        ->toHaveKey('replaces_runtime_unit')
        ->and($driver->remove($node, $runtimeUnit))
        ->toBeTrue();

    $payload = process_driver_agent_payloads('internal:process-docker-swarm-service')[0];

    expect($payload)
        ->toMatchArray([
            'image' => 'mysql:8.4',
            'command' => 'mysqld',
            'command_mode' => 'shell',
            'restart_condition' => 'any',
            'labels' => [
                'orbit.managed' => 'true',
                'orbit.process' => 'mysql8',
                'orbit.process.service' => 'mysql',
                'orbit.process.spec_hash' => 'abc123',
                'orbit.process.version' => '8.4',
                'orbit.process.version_family' => '8',
            ],
            'ports' => [
                'published=3308,target=3306,protocol=tcp',
            ],
            'mounts' => [
                'type=volume,source=orbit-mysql-8,target=/var/lib/mysql',
                'type=bind,source=/var/lib/orbit/processes/mysql8/mysql.cnf,target=/etc/mysql/conf.d/orbit.cnf,readonly',
            ],
            'update_order' => 'stop-first',
            'update_parallelism' => '1',
            'expected_hash' => 'abc123',
        ]);

    expect(process_driver_agent_arguments('internal:process-docker-swarm-service'))
        ->toBe([
            ['remove', 'orbit-mysql-legacy'],
            ['apply',  'orbit-mysql-8'],
            ['remove', 'orbit-mysql-8'],
        ]);
});

it('does not exit early from docker swarm apply scripts when the service spec already matches', function (): void {
    fake_process_driver_agent_responses();

    $node = Node::factory()
        ->database()
        ->managed()
        ->create([
            'name' => 'metrics-1',
            'wireguard_address' => '10.44.0.72',
        ]);
    $app = Project::factory()->create(['name' => 'docs', 'node_id' => $node->id, 'path' => '/srv/docs']);
    $process = Process::factory()
        ->forOwner($node)
        ->create([
            'name' => 'prometheus',
            'command' => 'prometheus --config.file=/etc/prometheus/prometheus.yml',
            'restart_policy' => ProcessRestartPolicy::Always,
            'runtime' => ProcessRuntime::DockerSwarm,
            'runtime_config' => [
                'image' => 'prom/prometheus:v3.12.0',
                'labels' => [
                    'orbit.process.spec_hash' => 'abc123',
                ],
                'service_name' => 'orbit-prometheus',
            ],
        ]);

    $driver = app(DockerSwarmProcessRuntimeDriver::class);

    expect($driver->apply($node, $app, $process))->toBeTrue();

    expect(process_driver_agent_payloads('internal:process-docker-swarm-service')[0]['expected_hash'])
        ->toBe('abc123');
});

it('uses the image entrypoint for docker swarm service processes configured for it', function (): void {
    fake_process_driver_agent_responses();

    $node = Node::factory()
        ->database()
        ->managed()
        ->create([
            'name' => 'metrics-1',
            'wireguard_address' => '10.44.0.72',
        ]);
    $app = Project::factory()->create(['name' => 'docs', 'node_id' => $node->id, 'path' => '/srv/docs']);
    $process = Process::factory()
        ->forOwner($node)
        ->create([
            'name' => 'grafana',
            'command' => '/run.sh',
            'restart_policy' => ProcessRestartPolicy::Always,
            'runtime' => ProcessRuntime::DockerSwarm,
            'runtime_config' => [
                'command_mode' => 'image_entrypoint',
                'image' => 'grafana/grafana:13.0.2',
                'service_name' => 'orbit-grafana',
            ],
        ]);

    $driver = app(DockerSwarmProcessRuntimeDriver::class);

    expect($driver->apply($node, $app, $process))->toBeTrue();

    expect(process_driver_agent_payloads('internal:process-docker-swarm-service')[0])
        ->toMatchArray([
            'command_mode' => 'image_entrypoint',
            'image' => 'grafana/grafana:13.0.2',
        ]);
});

it('runs systemd process lifecycle through the systemd runtime driver', function (): void {
    fake_process_driver_agent_responses();

    $node = Node::factory()
        ->appDev()
        ->managed()
        ->create([
            'name' => 'app-dev-1',
            'user' => 'orbit',
            'orbit_path' => '/home/orbit/orbit',
            'wireguard_address' => '10.44.0.71',
        ]);
    $app = Project::factory()->create(['name' => 'docs', 'node_id' => $node->id, 'path' => '/srv/docs']);
    $process = Process::factory()
        ->forOwner($node)
        ->create([
            'name' => 'opencode-server',
            'command' => 'opencode serve -a',
            'runtime' => ProcessRuntime::Systemd,
            'tool' => 'opencode-cli',
        ]);

    $driver = app(SystemdProcessRuntimeDriver::class);
    $runtimeUnit = $driver->runtimeUnitName($app, $process);

    expect($runtimeUnit)
        ->toBe('opencode-server')
        ->and($driver->start($node, $runtimeUnit))
        ->toBeTrue()
        ->and($driver->stop($node, $runtimeUnit))
        ->toBeTrue()
        ->and($driver->restart($node, $runtimeUnit))
        ->toBeTrue()
        ->and($driver->logScript($app, $process, null, $runtimeUnit, 25, false))
        ->toBe("sudo journalctl -u 'opencode-server.service' -n 25 --no-pager --output=short-iso 2>&1")
        ->and($driver->logScript($app, $process, null, $runtimeUnit, 25, true))
        ->toBe("sudo journalctl -u 'opencode-server.service' -n 25 -f --no-pager --output=short-iso 2>&1");

    expect(process_driver_agent_actions('internal:process-systemd-service'))
        ->toBe(['start', 'stop', 'restart']);
});

it('applies, removes, and cleans up systemd process runtime units through the systemd runtime driver', function (): void {
    fake_process_driver_agent_responses();

    $node = Node::factory()
        ->appDev()
        ->managed()
        ->create([
            'name' => 'app-dev-1',
            'user' => 'orbit',
            'orbit_path' => '/home/orbit/orbit',
            'wireguard_address' => '10.44.0.71',
        ]);
    $app = Project::factory()->create(['name' => 'docs', 'node_id' => $node->id, 'path' => '/srv/docs']);
    $process = Process::factory()
        ->forOwner($node)
        ->create([
            'name' => 'opencode-server',
            'command' => 'opencode serve -a',
            'restart_policy' => ProcessRestartPolicy::OnFailure,
            'runtime' => ProcessRuntime::Systemd,
            'tool' => 'opencode-cli',
        ]);

    $driver = app(SystemdProcessRuntimeDriver::class);
    $runtimeUnit = $driver->runtimeUnitName($app, $process);

    expect($driver->cleanupScript($runtimeUnit))
        ->toBe(
            "sudo systemctl stop 'opencode-server.service' >/dev/null 2>&1 || true; sudo systemctl disable 'opencode-server.service' >/dev/null 2>&1 || true; sudo rm -f '/etc/systemd/system/opencode-server.service'; sudo systemctl daemon-reload; sudo systemctl reset-failed 'opencode-server.service' >/dev/null 2>&1 || true; true",
        )
        ->and($driver->apply($node, $app, $process))
        ->toBeTrue()
        ->and($driver->remove($node, $runtimeUnit))
        ->toBeTrue();

    expect(process_driver_agent_arguments('internal:process-systemd-service'))
        ->toBe([
            ['apply', 'opencode-server.service'],
            ['remove', 'opencode-server.service', '/etc/systemd/system/opencode-server.service'],
        ]);
});

it('installs app-dev systemd units disabled while app-prod and node process units remain enabled', function (): void {
    fake_process_driver_agent_responses();

    $node = Node::factory()
        ->appDev()
        ->managed()
        ->create([
            'name' => 'app-dev-1',
            'user' => 'orbit',
            'wireguard_address' => '10.44.0.71',
        ]);
    $app = Project::factory()->for($node, 'node')->create([
        'name' => 'docs',
        'path' => '/srv/docs',
    ]);
    $appProcess = Process::factory()
        ->forOwner($app)
        ->create([
            'name' => 'queue',
            'runtime' => ProcessRuntime::Systemd,
        ]);
    $nodeProcess = Process::factory()
        ->forOwner($node)
        ->create([
            'name' => 'opencode-server',
            'runtime' => ProcessRuntime::Systemd,
        ]);
    $productionNode = Node::factory()
        ->appProd()
        ->managed()
        ->create([
            'name' => 'app-prod-1',
            'user' => 'orbit',
            'wireguard_address' => '10.44.0.72',
        ]);
    $productionApp = Project::factory()->for($productionNode, 'node')->create([
        'name' => 'docs-production',
        'path' => '/srv/docs-production',
    ]);
    $productionProcess = Process::factory()
        ->forOwner($productionApp)
        ->create([
            'name' => 'queue',
            'runtime' => ProcessRuntime::Systemd,
        ]);
    $driver = app(SystemdProcessRuntimeDriver::class);

    expect($driver->apply($node, $app, $appProcess))
        ->toBeTrue()
        ->and($driver->apply($node, $app, $nodeProcess))
        ->toBeTrue()
        ->and($driver->apply($productionNode, $productionApp, $productionProcess))
        ->toBeTrue();

    expect(array_column(
        process_driver_agent_payloads('internal:process-systemd-service'),
        'enabled',
    ))->toBe([false, true, true]);
});

it('installs app-dev launchd units disabled while app-prod and node process units remain enabled', function (): void {
    fake_process_driver_agent_responses();

    $node = Node::factory()
        ->appDev()
        ->managed()
        ->create([
            'name' => 'mac-app',
            'platform' => 'macos_26',
            'user' => 'orbit',
            'wireguard_address' => '10.44.0.81',
        ]);
    $app = Project::factory()->for($node, 'node')->create([
        'name' => 'docs',
        'path' => '/Users/orbit/docs',
    ]);
    $appProcess = Process::factory()
        ->forOwner($app)
        ->create([
            'name' => 'vite',
            'runtime' => ProcessRuntime::Launchd,
        ]);
    $nodeProcess = Process::factory()
        ->forOwner($node)
        ->create([
            'name' => 'opencode-server',
            'runtime' => ProcessRuntime::Launchd,
        ]);
    $productionNode = Node::factory()
        ->appProd()
        ->managed()
        ->create([
            'name' => 'mac-production',
            'platform' => 'macos_26',
            'user' => 'orbit',
            'wireguard_address' => '10.44.0.82',
        ]);
    $productionApp = Project::factory()->for($productionNode, 'node')->create([
        'name' => 'docs-production',
        'path' => '/Users/orbit/docs-production',
    ]);
    $productionProcess = Process::factory()
        ->forOwner($productionApp)
        ->create([
            'name' => 'queue',
            'runtime' => ProcessRuntime::Launchd,
        ]);
    $driver = app(LaunchdProcessRuntimeDriver::class);

    expect($driver->apply($node, $app, $appProcess))
        ->toBeTrue()
        ->and($driver->apply($node, $app, $nodeProcess))
        ->toBeTrue()
        ->and($driver->apply($productionNode, $productionApp, $productionProcess))
        ->toBeTrue();

    expect(array_column(
        process_driver_agent_payloads('internal:process-launchd-service'),
        'enabled',
    ))->toBe([false, true, true]);
});

function fake_docker_process_driver_agent_responses(): void
{
    fake_process_driver_agent_responses();
}

function fake_process_driver_agent_responses(): void
{
    Http::preventStrayRequests();
    Http::fake([
        '*' => Http::response([
            'transport' => 'agent-push',
            'operation_id' => 'process.docker.test',
            'binary' => 'orbit',
            'status' => 'succeeded',
            'exit_code' => 0,
            'frames' => [
                [
                    'type' => 'stdout',
                    'message' => json_encode([
                        'success' => [
                            'data' => [
                                'action' => 'test',
                                'outcome' => 'created',
                                'changed' => true,
                            ],
                            'meta' => [],
                        ],
                    ], JSON_THROW_ON_ERROR),
                ],
                [
                    'type' => 'exit',
                    'message' => '0',
                ],
            ],
        ]),
    ]);
}

/**
 * @return list<array<string, mixed>>
 */
function docker_process_driver_agent_payloads(): array
{
    return collect(Http::recorded())
        ->map(function (array $record): array {
            /** @var Request $request */
            [$request] = $record;

            /** @var mixed $payload */
            $payload = json_decode((string) $request['input'], associative: true, flags: JSON_THROW_ON_ERROR);

            if (! is_array($payload)) {
                return [];
            }

            foreach (array_keys($payload) as $key) {
                if (! is_string($key)) {
                    return [];
                }
            }

            /** @var array<string, mixed> $payload */
            return $payload;
        })
        ->all();
}

/**
 * @return list<string>
 */
function docker_process_driver_agent_actions(): array
{
    return collect(docker_process_driver_agent_payloads())
        ->map(fn (array $payload): mixed => $payload['action'] ?? null)
        ->filter(fn (mixed $action): bool => is_string($action))
        ->values()
        ->all();
}

/**
 * @return list<list<string>>
 */
function process_driver_agent_arguments(string $commandName): array
{
    return collect(Http::recorded())
        ->map(function (array $record) use ($commandName): array {
            /** @var Request $request */
            [$request] = $record;
            $argv = $request['argv'];

            if (! is_array($argv) || ($argv[0] ?? null) !== $commandName) {
                return [];
            }

            return collect(array_slice($argv, offset: 1))
                ->filter(
                    fn (mixed $argument): bool => (
                        is_string($argument)
                        && ! str_starts_with($argument, '--operation-token=')
                        && $argument !== '--json'
                    ),
                )
                ->values()
                ->all();
        })
        ->filter(fn (array $arguments): bool => $arguments !== [])
        ->values()
        ->all();
}

/**
 * @return list<array<string, mixed>>
 */
function process_driver_agent_payloads(string $commandName): array
{
    return collect(Http::recorded())
        ->map(function (array $record) use ($commandName): array {
            /** @var Request $request */
            [$request] = $record;
            $argv = $request['argv'];

            if (! is_array($argv) || ($argv[0] ?? null) !== $commandName) {
                return [];
            }

            $input = $request['input'] ?? null;

            if (! is_string($input) || trim($input) === '') {
                return [];
            }

            /** @var mixed $payload */
            $payload = json_decode($input, associative: true, flags: JSON_THROW_ON_ERROR);

            if (! is_array($payload)) {
                return [];
            }

            foreach (array_keys($payload) as $key) {
                if (! is_string($key)) {
                    return [];
                }
            }

            /** @var array<string, mixed> $payload */
            return $payload;
        })
        ->filter(fn (array $payload): bool => $payload !== [])
        ->values()
        ->all();
}

/**
 * @return list<string>
 */
function process_driver_agent_actions(string $commandName): array
{
    return collect(process_driver_agent_arguments($commandName))
        ->map(fn (array $arguments): mixed => $arguments[0] ?? null)
        ->filter(fn (mixed $action): bool => is_string($action))
        ->values()
        ->all();
}

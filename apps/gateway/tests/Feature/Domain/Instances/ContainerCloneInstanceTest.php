<?php

declare(strict_types=1);

use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Domain\DatabaseServers\DatabaseStatements;
use App\Domain\Extensions\ExtensionStore;
use App\Domain\Instances\ContainerInstanceDestroy;
use App\Domain\Instances\DevelopmentInstanceCreateProjection;
use App\Domain\Instances\Environment\DevelopmentCreateEnvironment;
use App\Domain\Instances\Environment\InstanceEnvironmentRenderer;
use App\Domain\Instances\Environment\InstanceEnvironmentStore;
use App\Domain\Instances\InstanceContainer;
use App\Domain\Instances\InstanceLifecycleContributions;
use App\Domain\Instances\InstanceRuntimePowerState;
use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\RoleName;
use App\Domain\Processes\VpDevPreset;
use App\Domain\Projects\ProjectEnvironmentTemplateRefresh;
use App\Domain\Projects\ProjectType;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmModelRoute;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketEndpoint;
use App\Infrastructure\Swamp\NativeStepFailure;
use App\Models\DatabaseConnection;
use App\Models\DatabaseConnectionTarget;
use App\Models\DatabaseServer;
use App\Models\Instance;
use App\Models\InstanceRuntime;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Process;
use App\Models\ProcessDefinition;
use App\Models\ProjectDevelopmentDeployStep;
use App\Models\Route;
use App\Support\ValidatedData;
use Dotenv\Dotenv;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Orbit\Extensions\Conn\Domain\ConnInstanceLifecycle;
use Orbit\Extensions\Conn\Models\ConnEnvironment;

uses(LazilyRefreshDatabase::class);

require_once __DIR__.'/ContainerInstanceHelpers.php';

beforeEach(function (): void {
    Queue::fake();
    Http::preventStrayRequests();
    $swamp = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'control', 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.209', 'public_ssh_host' => '10.44.0.209', 'user' => 'orbit',
    ]));
    $swamp->roles()->create(['role' => RoleName::Swamp, 'status' => 'active']);
    app(ExtensionStore::class)->set('conn', true);
    app()->instance(TaskVmModelRoute::class, new class implements TaskVmModelRoute
    {
        public bool $ready = true;

        public function assertReady(): void
        {
            if (! $this->ready) {
                throw new ResourceOperationException('ai_router.disabled', 'The model router has no guard.', 409);
            }
        }

        public function origin(): string
        {
            return 'http://10.44.0.3:8318';
        }

        public function key(): string
        {
            return 'placeholder-model-key';
        }
    });
});

/** The Project's container default on the Node, active with its seed snapshot, as its finished create leaves it. */
function seededDefault(Node $node, ?ProjectType $type = null): Instance
{
    $project = containerDefaultProject($type ?? ProjectType::NodePackage);
    $default = Instance::query()->findOrFail(containerCreate($project, $node)->target_id);
    $default->update(['status' => InstanceState::Active, 'first_setup_pending' => false]);
    InstanceRuntime::query()->where('instance_id', $default->id)->firstOrFail()
        ->update(['seed_commit' => str_repeat('c', 40), 'power_state' => InstanceRuntimePowerState::Running]);

    return $default->refresh();
}

describe('a clone of the container default', function (): void {
    it('limits every default and clone to the same CPU memory process and root disk budget', function (): void {
        $node = containerDefaultNode();
        $default = seededDefault($node);
        $operation = containerCreate($default->project, $node, 'bounded');
        $runtime = InstanceRuntime::query()->where('instance_id', $default->id)->sole();
        $defaultIntent = app(InstanceContainer::class)->intent($default, $runtime);

        foreach ([$defaultIntent, containerIntentOf($operation)] as $container) {
            expect($container)->toMatchArray([
                'project' => 'orbit-instances',
                'config' => ['limits.cpu' => '4', 'limits.memory' => '8GiB', 'limits.processes' => '4096'],
                'root_disk_pool' => 'orbit-instances', 'root_disk_size' => '40GiB',
            ]);
        }
    });

    it('keeps the Project display name on one safe metadata line and titles its T3 project', function (): void {
        $node = containerDefaultNode();
        $default = seededDefault($node);
        $name = "Nick's \"App\" \$HOME\\next\nline";
        $default->project->update(['name' => $name]);
        $container = containerIntentOf(containerCreate($default->project->fresh(), $node, 'named'));
        $metadata = $container['clone']['files'][0]['content'];
        $values = Dotenv::parse($metadata);
        expect($container['clone']['t3']['title'])->toBe($name)
            ->and($values['ORBIT_PROJECT_ID'])->toBe((string) $default->project_id)
            ->and($values['ORBIT_PROJECT_NAME'])->toBe(str_replace(["\r", "\n"], ' ', $name))
            ->and($values['ORBIT_NODE'])->toBe($node->name)
            ->and(substr_count($metadata, "\n"))->toBe(8);
    });

    it('takes the next address, the lowest free T3 port and the default as its clone source', function (): void {
        $node = containerDefaultNode();
        $default = seededDefault($node);

        $first = containerCreate($default->project, $node, 'feature');
        $second = containerCreate($default->project, $node, 'other');

        $runtimes = InstanceRuntime::query()->whereIn('instance_id', [$first->target_id, $second->target_id])->orderBy('instance_id')->get();
        expect($runtimes->map->only(['bridge_address', 't3_port', 'clone_source_instance_id'])->all())->toBe([
            ['bridge_address' => '10.253.0.3', 't3_port' => 3800, 'clone_source_instance_id' => $default->id],
            ['bridge_address' => '10.253.0.4', 't3_port' => 3801, 'clone_source_instance_id' => $default->id],
        ]);
    });

    it('runs its own records of the Project\'s development Processes, not the default\'s units from the seed', function (): void {
        $node = containerDefaultNode();
        $project = containerDefaultProject();
        ProcessDefinition::query()->create(['project_id' => $project->id, 'name' => 'worker', 'environments' => ['development'],
            'spec' => ['runtime' => 'systemd', 'command' => ['/usr/bin/env', 'node', 'worker.js'], 'environment' => ['QUEUE' => 'high']]]);
        $default = Instance::query()->findOrFail(containerCreate($project, $node)->target_id);
        $default->update(['status' => InstanceState::Active, 'first_setup_pending' => false]);
        InstanceRuntime::query()->where('instance_id', $default->id)->firstOrFail()
            ->update(['seed_commit' => str_repeat('c', 40), 'power_state' => InstanceRuntimePowerState::Running]);

        $operation = containerCreate($project, $node, 'feature');

        $vault = vaultReadingSocket([]);
        $run = app(DevelopmentInstanceCreateProjection::class)->input($operation);
        $workspace = json_decode(collect($vault->puts)->firstWhere('key', 'instance-create-processes-'.$operation->id)['value'], true, 512, JSON_THROW_ON_ERROR);
        $environment = collect($vault->puts)->firstWhere('key', 'instance-create-environment-input-'.$operation->id)['value'];
        $own = Process::query()->where('owner_id', $operation->target_id)->sole();
        $units = $run['intent']['container']['processes'];
        expect($workspace['environment'])->toBe($environment)
            ->and(array_keys($workspace['processes']))->toBe([$units[0]['environment']])
            ->and(Dotenv::parse($workspace['processes'][$units[0]['environment']])['QUEUE'])->toBe('high')
            ->and(json_encode($run['intent']))->not->toContain('QUEUE=high');
        expect($own->id)->not->toBe(Process::query()->where('owner_id', $default->id)->sole()->id)
            ->and(array_column($units, 'name'))->toBe(["orbit-process-{$own->id}-worker.service"])
            ->and($units[0]['environment'])->toStartWith("/etc/orbit/processes/{$own->id}/");
    });

    it('hands the run the seed snapshot, T3\'s port and files, its firewall rules and no deploy step', function (): void {
        $node = containerDefaultNode();
        $default = seededDefault($node);

        $operation = containerCreate($default->project, $node, 'feature');

        $container = containerIntentOf($operation);
        $clone = ValidatedData::object($container['clone']);
        $id = $operation->target_id;
        expect($container['steps'])->toBe([])
            ->and($container['branch'])->toBe('feature')
            ->and($clone['source'])->toBe('instance-'.$default->id)
            ->and($clone['snapshot'])->toBe('seed-'.str_repeat('c', 40))
            ->and($clone['source_mirror'])->toBe('/var/lib/orbit/instance-repositories/'.$default->id.'.git')
            ->and($clone['t3'])->toMatchArray(['port' => 3800, 'listen' => '10.44.0.7', 'device' => 't3', 'internal_port' => 3773,
                'url' => 'http://10.44.0.7:3800', 'label' => 'tool / feature', 'title' => 'tool'])
            ->and(collect($clone['files'])->pluck('path')->all())->toBe(['/etc/orbit/instance', '/home/agent/.codex/config.toml', '/home/agent/.claude.json', '/home/agent/.t3/userdata/settings.json'])
            ->and($clone['files'][0]['content'])->toBe("# Managed by Orbit.\nORBIT_INSTANCE_ID={$id}\nORBIT_INSTANCE=feature\nORBIT_PROJECT=tool\nORBIT_PROJECT_ID={$default->project_id}\nORBIT_PROJECT_NAME=\"tool\"\nORBIT_NODE={$node->name}\nORBIT_URL=\n")
            ->and($clone['files'][1]['content'])->toContain('base_url = "http://10.253.0.1:8319/v1"')
            ->and($clone['files'][2])->toBe(['path' => '/home/agent/.claude.json', 'uid' => 2750, 'gid' => 2750, 'mode' => '0600', 'json' => ['hasCompletedOnboarding' => true]])
            ->and($clone['files'][3])->toBe(['path' => '/home/agent/.t3/userdata/settings.json', 'uid' => 2750, 'gid' => 2750, 'mode' => '0600', 'json' => ['linkedEnvironmentsEnabled' => true]])
            ->and($clone['model_environment'])->toBe(['path' => '/home/agent/.config/orbit/model.env', 'uid' => 2750, 'gid' => 2750, 'mode' => '0600'])
            ->and($clone['links'][0])->toBe(['path' => '/home/agent/.claude/CLAUDE.md', 'target' => '/opt/orbit-agent/current/notes/package.md'])
            // WireGuard peers reach T3, the task VM range first refused.
            ->and($container['firewall'])->toBe([
                ['route' => 'yes', 'interface_in' => 'orbit', 'interface_out' => 'orbitinst0', 'to_ip' => '10.253.0.3', 'to_port' => '3773', 'proto' => 'tcp',
                    'rule' => 'deny', 'from_ip' => '10.44.0.128/25', 'comment' => "orbit:instance-{$id}-t3-task-vms"],
                ['route' => 'yes', 'interface_in' => 'orbit', 'interface_out' => 'orbitinst0', 'to_ip' => '10.253.0.3', 'to_port' => '3773', 'proto' => 'tcp',
                    'rule' => 'allow', 'from_ip' => 'any', 'comment' => "orbit:instance-{$id}-t3"],
            ])
            ->and($container['nic_acl'])->toBe('orbitinst0-proxy')
            ->and($container['acl_rules']['ingress'][1])->toBe(['action' => 'allow', 'protocol' => 'tcp', 'destination_port' => '3773', 'state' => 'enabled'])
            ->and(json_encode($operation->input))->not->toContain('placeholder-model-key');
    });

    it('keeps the default\'s APP_KEY, because the cloned data is encrypted with it', function (): void {
        $node = containerDefaultNode();
        $default = seededDefault($node);
        app(InstanceEnvironmentStore::class)->seedForCreate($default, ['APP_KEY' => 'base64:default-key']);

        $operation = containerCreate($default->project, $node, 'feature');

        expect(app(InstanceEnvironmentStore::class)->values(Instance::query()->findOrFail($operation->target_id))['APP_KEY'] ?? null)->toBe('base64:default-key');
    });

    it('is refused while its default runs elsewhere, has no seed yet, or Conn or the model router is off', function (Closure $arrange, string $code): void {
        $node = containerDefaultNode();
        $default = seededDefault($node);
        $target = $arrange($default) ?? $node;

        expect(containerRefusal(fn () => containerCreate($default->project, $target, 'feature')))->toBe($code);
    })->with([
        'the default on another Node' => [fn (Instance $default): Node => containerDefaultNode('shark', '10.44.0.8'), 'instance.default_missing'],
        'no seed snapshot yet' => [fn (Instance $default) => InstanceRuntime::query()->where('instance_id', $default->id)->update(['seed_commit' => null]) && false ?: null, 'instance.default_missing'],
        'a failed default' => [fn (Instance $default) => $default->update(['status' => InstanceState::Reserved]) && false ?: null, 'instance.default_missing'],
        'Conn off' => [fn () => app(ExtensionStore::class)->set('conn', false) ?: null, 'operation.kind_unavailable'],
        'no model router guard' => [function (): null {
            app(TaskVmModelRoute::class)->ready = false;

            return null;
        }, 'ai_router.disabled'],
    ]);

    it('lets the default and its clones reach their Database server; a clone clones on the default\'s server only', function (): void {
        $node = containerDefaultNode();
        $server = databaseServerOn($node, 'pg', DatabaseDriver::Pgsql, 5432);
        $default = Instance::query()->findOrFail(containerCreate(containerDefaultProject(), $node, 'default', 'pg')->target_id);
        $default->update(['status' => InstanceState::Active, 'first_setup_pending' => false]);
        InstanceRuntime::query()->where('instance_id', $default->id)->update(['seed_commit' => str_repeat('c', 40)]);
        $defaultOperation = Operation::query()->where('target_id', $default->id)->where('kind', 'instance.create')->firstOrFail();

        $operation = containerCreate($default->project, $node, 'feature');

        $database = DatabaseConnection::query()->where('owner_instance_id', $operation->target_id)->firstOrFail();
        $source = DatabaseConnection::query()->where('owner_instance_id', $default->id)->firstOrFail();
        $rule = ['rule' => 'allow', 'interface_in' => 'orbitinst0', 'to_ip' => '10.44.0.7', 'to_port' => '5432', 'proto' => 'tcp'];
        expect(containerIntentOf($defaultOperation)['firewall'])->toEqual([[...$rule, 'from_ip' => '10.253.0.2', 'comment' => "orbit:instance-{$default->id}-database"]])
            ->and(containerIntentOf($operation)['acl_rules']['egress'])->toBe([['action' => 'allow', 'destination' => '10.44.0.7/32', 'protocol' => 'tcp', 'destination_port' => '5432', 'state' => 'enabled']])
            ->and(containerIntentOf($operation)['firewall'][2])->toMatchArray([...$rule, 'from_ip' => '10.253.0.3'])
            ->and($database->database_server_id)->toBe($server->id)
            ->and(ValidatedData::object($operation->input['direct_provisioning'])['server_database'])->toMatchArray(['source' => $source->database,
                'source_owner' => $source->username]);

        $other = databaseServerOn(containerDefaultNode('shark', '10.44.0.8', listed: false), 'pg2', DatabaseDriver::Pgsql, 5432);
        expect(containerRefusal(fn () => containerCreate($default->project, $node, 'third', $other->slug)))->toBe('instance.database_clone_unsupported');
    });
});

it('starts a clone without copying the Project development deploy commands that its default already ran', function (): void {
    $node = containerDefaultNode();
    $default = seededDefault($node);
    ProjectDevelopmentDeployStep::query()->create(['project_id' => $default->project_id, 'name' => 'install', 'position' => 1,
        'command' => 'bun install', 'required' => true, 'timeout_seconds' => 300]);
    $operation = containerCreate($default->project, $node, 'feature');
    $vault = vaultReadingSocket([]);

    $run = app(DevelopmentInstanceCreateProjection::class)->input($operation);

    expect($run['intent']['container']['steps'])->toBe([])
        ->and(array_keys($run['execution_secrets']))->not->toContain('step_0')
        ->and(collect($vault->puts)->pluck('key')->all())->not->toContain('development-deploy-operation-'.$operation->id.'-step-0')
        ->and($run['execution_secrets']['model_environment']['key'])->toBe('instance-create-model-'.$operation->id);
});

it('starts the run with the placeholder model key in the vault and the template copy of the default database', function (): void {
    $node = containerDefaultNode();
    databaseServerOn($node, 'pg', DatabaseDriver::Pgsql, 5432);
    $default = Instance::query()->findOrFail(containerCreate(containerDefaultProject(), $node, 'default', 'pg')->target_id);
    $default->update(['status' => InstanceState::Active, 'first_setup_pending' => false]);
    InstanceRuntime::query()->where('instance_id', $default->id)->update(['seed_commit' => str_repeat('c', 40)]);
    $operation = containerCreate($default->project, $node, 'feature');
    $vault = vaultReadingSocket([]);

    $run = app(DevelopmentInstanceCreateProjection::class)->input($operation);

    $put = collect($vault->puts)->firstWhere('key', 'instance-create-model-'.$operation->id);
    $fill = ValidatedData::stringList(ValidatedData::object(ValidatedData::object($run['intent'])['server_database'])['fill']);
    expect($run['execution_secrets']['model_environment'])->toBe(['vault' => 'orbit-control-inputs', 'key' => 'instance-create-model-'.$operation->id])
        ->and($put['value'])->toContain("ANTHROPIC_BASE_URL=http://10.253.0.1:8319\n")->toContain("ORBIT_MODEL_KEY=placeholder-model-key\n")
        ->and(json_encode($run['intent']))->not->toContain('placeholder-model-key')
        ->and($fill[13])->toStartWith('DROP DATABASE IF EXISTS')
        ->and($fill[14])->toContain(' TEMPLATE ');
});

describe('the template copy of a clone\'s PostgreSQL database', function (): void {
    it('closes the default database, copies it, gives the copy to the clone\'s owner and opens the default again whatever happens', function (): void {
        $argv = DatabaseStatements::template('orbit-db-pg', 'postgres', 'tool_default', 'tool_default', 'tool_feature', 'tool_feature');

        expect(array_slice($argv, 0, 6))->toBe(['docker', 'exec', '-i', 'orbit-db-pg', 'sh', '-c'])
            ->and($argv[6])->toContain('IFS= read -r PGPASSWORD')->toContain('trap \'pg --dbname=postgres --command="$4"\' EXIT')
            ->and(array_slice($argv, 7, 4))->toBe(['sh', 'postgres', 'tool_default', 'tool_feature'])
            ->and($argv[11])->toBe('ALTER DATABASE "tool_default" ALLOW_CONNECTIONS true')
            ->and($argv[12])->toStartWith('ALTER DATABASE "tool_default" ALLOW_CONNECTIONS false; SELECT pg_terminate_backend(pid)')
            ->and($argv[13])->toBe('DROP DATABASE IF EXISTS "tool_feature" WITH (FORCE)')
            ->and($argv[14])->toBe('CREATE DATABASE "tool_feature" TEMPLATE "tool_default" OWNER "tool_feature"')
            ->and($argv[15])->toContain("c.relowner = 'tool_default'::regrole")->toContain("'tool_feature'")
            ->and($argv[15])->toContain('REVOKE ALL ON DATABASE "tool_feature" FROM PUBLIC');
    });

    it('refuses a name that is not a plain identifier', function (): void {
        expect(fn () => DatabaseStatements::template('c', 'postgres', 'a"; DROP', 'o', 't', 'u'))->toThrow(ResourceOperationException::class)
            // An owner is named as '<name>'::regrole, which folds case.
            ->and(fn () => DatabaseStatements::template('c', 'postgres', 'tool', 'Tool_Owner', 't', 'u'))->toThrow(ResourceOperationException::class);
    });
});

describe('the clone\'s create result', function (): void {
    it('shows the failed clone command in the Operation error and keeps its stable code', function (): void {
        $node = containerDefaultNode();
        $operation = containerCreate(seededDefault($node)->project, $node, 'feature');
        $operation->forceFill(['status' => 'running', 'state' => ['swamp_run_id' => 'run-1']])->save();
        $caller = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'reader', 'status' => 'active',
            'platform' => 'linux', 'wireguard_ip' => '10.44.0.2', 'public_ssh_host' => '10.44.0.2', 'user' => 'orbit']));
        $caller->accessibleNodes()->attach($node->id);
        $diagnostic = 'instance.container_sessions_failed: command php exited 1';
        $failure = app(NativeStepFailure::class)->fromRun(['status' => 'failed', 'jobs' => [['steps' => [[
            'name' => 'clone-sessions', 'status' => 'failed',
            'error' => 'exec failed on 1/1 host(s): target (exit 1: '.$diagnostic.')',
        ]]]]], $operation);

        app(DevelopmentInstanceCreateProjection::class)->apply($operation, [
            'status' => 'failed', 'run_id' => 'run-1', 'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(),
            'error_code' => 'instance.create_register_failed', 'failure' => $failure,
            'result' => ['instance_id' => $operation->target_id, 'ready' => false, 'failed_step' => 'register'],
        ]);

        $this->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip])->getJson('/api/v1/operations/'.$operation->id)
            ->assertOk()->assertJsonPath('data.error_code', 'instance.create_register_failed')
            ->assertJsonPath('data.error.code', 'instance.create_register_failed')
            ->assertJsonPath('data.error.detail', 'exec failed on 1/1 host(s): target (exit 1: '.$diagnostic.')')
            ->assertJsonPath('data.failed_step', 'clone-sessions')
            ->assertJsonPath('data.result.recovery.state', 'retained_for_retry')
            ->assertJsonPath('data.result.recovery.retry.command', 'instance:create')
            ->assertJsonPath('data.result.recovery.destroy.command', 'instance:destroy');
    });

    it('records the T3 server from the run\'s result: its Node, its Instance and the Gateway session from the vault', function (): void {
        $node = containerDefaultNode();
        $operation = containerCreate(seededDefault($node)->project, $node, 'feature');
        $operation->forceFill(['status' => 'running', 'finished_at' => null, 'error_code' => null, 'result' => null, 'state' => ['swamp_run_id' => 'run-1']])->save();
        $vault = vaultReadingSocket(['nckrtl-instance-t3-model-register-session-gateway-1-token' => 'gateway-session-value']);

        app(DevelopmentInstanceCreateProjection::class)->apply($operation->refresh(), cloneSucceeded($operation));

        $environment = ConnEnvironment::query()->where('environment_id', 'env-feature')->firstOrFail();
        expect($environment->instance_id)->toBe($operation->target_id)
            ->and($environment->node_id)->toBe($node->id)
            ->and($environment->url)->toBe('http://10.44.0.7:3800')
            ->and($environment->label)->toBe('tool / feature')
            ->and($environment->server_version)->toBe('0.1.7')
            ->and($environment->admin_session)->toBe('gateway-session-value')
            ->and($environment->cleanup_version)->toBe($environment->cleanup_completed_version)
            ->and($vault->reads)->toBe(['nckrtl-instance-t3-model-register-session-gateway-1-token'])
            ->and(Instance::query()->findOrFail($operation->target_id)->status)->toBe(InstanceState::Active)
            ->and(InstanceRuntime::query()->where('instance_id', $operation->target_id)->firstOrFail()->only(['power_state', 'seed_commit']))
            ->toBe(['power_state' => InstanceRuntimePowerState::Running, 'seed_commit' => null]);
    });

    it('refuses a result that names no registered server, and a server that belongs to another Instance', function (): void {
        $node = containerDefaultNode();
        $default = seededDefault($node);
        $operation = containerCreate($default->project, $node, 'feature');
        $operation->forceFill(['status' => 'running', 'finished_at' => null, 'error_code' => null, 'result' => null, 'state' => ['swamp_run_id' => 'run-1']])->save();
        vaultReadingSocket(['nckrtl-instance-t3-model-register-session-gateway-1-token' => 'gateway-session-value']);
        $succeeded = cloneSucceeded($operation);
        $without = $succeeded;
        unset($without['result']['t3']);

        expect(fn () => app(DevelopmentInstanceCreateProjection::class)->apply($operation->refresh(), $without))
            ->toThrow(ResourceOperationException::class, 'The clone did not prove its registered T3 server.');

        $other = ConnEnvironment::query()->forceCreate(['environment_id' => 'env-feature', 'label' => 'x', 'url' => 'http://10.44.0.7:3999',
            'node_id' => $node->id, 'instance_id' => $default->id,
            'admin_session' => 'other', 'admin_session_expires_at' => now()->addDay(), 'registered_at' => now()]);
        expect(fn () => app(DevelopmentInstanceCreateProjection::class)->apply($operation->refresh(), $succeeded))
            ->toThrow(ResourceOperationException::class)
            ->and($other->refresh()->admin_session)->toBe('other')
            ->and(Instance::query()->findOrFail($operation->target_id)->status)->not->toBe(InstanceState::Active);
    });
});

it('wires Conn into the create flow of a container Instance', function (): void {
    $contributions = (new ReflectionProperty(InstanceLifecycleContributions::class, 'contributions'))->getValue(app(InstanceLifecycleContributions::class));

    expect(collect($contributions)->map(fn (object $contribution): string => $contribution::class)->all())->toBe([ConnInstanceLifecycle::class]);
});

/** @return array<string, mixed> */
function cloneSucceeded(Operation $operation): array
{
    return ['status' => 'succeeded', 'run_id' => 'run-1', 'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(),
        'result' => ['ready' => true, 'instance_id' => $operation->target_id, 'commit' => str_repeat('c', 40), 't3' => [
            'environment_id' => 'env-feature', 'server_version' => '0.1.7', 'expires_at' => '2026-12-10T10:00:00.000Z',
            'gateway_session' => ['vault' => 'orbit-control-inputs', 'key' => 'nckrtl-instance-t3-model-register-session-gateway-1-token'],
            'swamp_session' => ['vault' => 'orbit-control-inputs', 'key' => 'nckrtl-instance-t3-model-register-session-swamp-1-token']]]];
}

function databaseServerOn(Node $node, string $slug, DatabaseDriver $driver, int $port): DatabaseServer
{
    $process = Process::query()->forceCreate(['owner_type' => 'node', 'owner_id' => $node->id, 'name' => 'db-'.$slug, 'runtime' => 'docker',
        'runtime_config' => ['image' => 'postgres:18', 'ports' => [$node->wireguard_ip.':'.$port.':5432']], 'status' => 'active']);

    return DatabaseServer::query()->forceCreate(['slug' => $slug, 'driver' => $driver, 'node_id' => $node->id, 'process_id' => $process->id, 'tag' => '18', 'port' => $port,
        'status' => 'active', 'root_password' => 'root-secret']);
}

/**
 * A Swamp vault behind the WebSocket: it keeps what the Gateway puts and answers reads of `$secrets`.
 *
 * @param  array<string, string>  $secrets
 */
function vaultReadingSocket(array $secrets): object
{
    $socket = new class($secrets) implements WebSocketClient
    {
        /** @var list<string> */
        public array $reads = [];

        /** @var list<array{key: string, value: string}> */
        public array $puts = [];

        /** @var list<array<string, mixed>> */
        private array $frames = [];

        private bool $open = false;

        /** @param array<string, string> $secrets */
        public function __construct(private array $secrets) {}

        public function connect(WebSocketEndpoint $endpoint, float $timeoutSeconds): void
        {
            $this->open = true;
        }

        public function send(array $message): void
        {
            $this->frames[] = $message;
        }

        public function receive(float $timeoutSeconds): array
        {
            $frame = end($this->frames);
            if ($frame['type'] === 'vault.read-secret') {
                $this->reads[] = $frame['payload']['secretKey'];

                return [['id' => $frame['id'], 'type' => 'vault.read-secret', 'payload' => ['data' => ['vaultName' => $frame['payload']['vaultName'],
                    'secretKey' => $frame['payload']['secretKey'], 'vaultType' => 'local_encryption', 'value' => $this->secrets[$frame['payload']['secretKey']] ?? '']]]];
            }
            $this->puts[] = ['key' => $frame['payload']['key'], 'value' => (string) ($frame['payload']['value'] ?? '')];

            return [['id' => $frame['id'], 'type' => 'vault.put', 'payload' => ['data' => [
                'vaultName' => $frame['payload']['vaultName'], 'secretKey' => $frame['payload']['key'], 'vaultType' => 'local_encryption']]]];
        }

        public function close(): void
        {
            $this->open = false;
        }

        public function isConnected(): bool
        {
            return $this->open;
        }
    };
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.address' => 'swamp.test', 'swamp.ca_path' => '/tmp/ca.pem', 'swamp.run_token' => 'run-token']);

    return $socket;
}

it('captures every attached server target once, including remote Redis, in the container intent', function (): void {
    $node = containerDefaultNode();
    $default = seededDefault($node);
    $remote = containerDefaultNode('services', '10.44.0.3', listed: false);
    $sql = databaseServerOn($node, 'mysql-target', DatabaseDriver::Mysql, 3306);
    $redis = databaseServerOn($remote, 'redis-target', DatabaseDriver::Redis, 6379);
    foreach ([['DB', $sql], ['REDIS', $redis], ['SQL_ALIAS', $sql]] as [$prefix, $server]) {
        $connection = DatabaseConnection::query()->forceCreate(['slug' => strtolower($prefix), 'driver' => $server->driver,
            'node_id' => $server->node_id, 'database_server_id' => $server->id, 'host' => $server->node->wireguard_ip,
            'port' => $server->port, 'database' => 'tool', 'username' => 'tool']);
        DatabaseConnectionTarget::query()->create(['instance_id' => $default->id, 'prefix' => $prefix,
            'database_connection_id' => $connection->id, 'network' => 'wireguard']);
    }
    $sqlite = DatabaseConnection::query()->forceCreate(['slug' => 'workspace', 'driver' => DatabaseDriver::Sqlite,
        'node_id' => $node->id, 'path' => '/srv/workspace/database/database.sqlite']);
    DatabaseConnectionTarget::query()->create(['instance_id' => $default->id, 'prefix' => 'LOCAL',
        'database_connection_id' => $sqlite->id, 'network' => 'wireguard']);

    $intent = app(InstanceContainer::class)->intent($default, $default->runtime()->firstOrFail());

    expect($intent['acl_rules']['egress'])->toBe([
        ['action' => 'allow', 'destination' => '10.44.0.7/32', 'protocol' => 'tcp', 'destination_port' => '3306', 'state' => 'enabled'],
        ['action' => 'allow', 'destination' => '10.44.0.3/32', 'protocol' => 'tcp', 'destination_port' => '6379', 'state' => 'enabled'],
    ])->and($intent['firewall'])->toHaveCount(2)
        ->and($intent['firewall'][0])->toMatchArray(['interface_in' => 'orbitinst0', 'from_ip' => '10.253.0.2', 'to_ip' => '10.44.0.7', 'to_port' => '3306'])
        ->and($intent['firewall'][1])->toMatchArray(['route' => 'yes', 'interface_in' => 'orbitinst0', 'interface_out' => 'orbit',
            'from_ip' => '10.253.0.2', 'to_ip' => '10.44.0.3', 'to_port' => '6379', 'proto' => 'tcp']);
});

it('keeps stock Laravel references live for the clone and lets its dev Process load them', function (): void {
    $node = containerDefaultNode();
    $default = seededDefault($node);
    $project = $default->project;
    $project->update(['type' => ProjectType::LaravelApp, 'root' => 'public', 'php_version' => '8.5', 'is_laravel' => true]);
    $node->update(['tld' => 'beast.test']);
    Node::query()->where('name', 'control')->update(['ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key']);
    $this->mock(LeafCertificateSigner::class)->shouldReceive('rootCertificate')->andReturn('root-certificate');
    $example = file_get_contents(dirname(__DIR__, 6).'/swamp/tests/fixtures/laravel-stock.env.example');
    $example .= <<<'ENV'

VITE_APP_URL="${APP_URL}"
UNQUOTED_URL=${APP_URL}
VITE_LATER=${Z_LATER}
Z_LATER="${VITE_APP_URL}/nested"
PASSWORD=pa$$word
HASH=$2y$10$abcdef
PRICE=price$5
ESCAPED="\${APP_NAME}"
SINGLE='${APP_NAME}'
ENV;
    app(ProjectEnvironmentTemplateRefresh::class)->recordRead($project, str_repeat('d', 40), 'fixture', $example);
    app(InstanceEnvironmentStore::class)->seedForCreate($default, ['APP_NAME' => 'Default only']);
    ProcessDefinition::query()->create(['project_id' => $project->id, 'name' => 'dev', 'environments' => ['development'],
        'spec' => ['runtime' => 'systemd', 'preset' => 'vp-dev', 'command' => VpDevPreset::definitionCommand()]]);

    $operation = containerCreate($project, $node, 'references');
    $clone = Instance::query()->findOrFail($operation->target_id);
    $vault = vaultReadingSocket([]);
    $run = app(DevelopmentInstanceCreateProjection::class)->input($operation);
    $workspace = json_decode(collect($vault->puts)->firstWhere('key', 'instance-create-processes-'.$operation->id)['value'], true, 512, JSON_THROW_ON_ERROR);
    $file = $workspace['environment'];
    $values = Dotenv::parse($file);
    $domain = $clone->routes()->whereNull('web_root')->sole()->domain;

    expect($values)->toMatchArray([
        'APP_NAME' => 'Laravel', 'VITE_APP_NAME' => 'Laravel', 'MAIL_FROM_NAME' => 'Laravel',
        'APP_URL' => 'https://'.$domain, 'VITE_APP_URL' => 'https://'.$domain, 'UNQUOTED_URL' => 'https://'.$domain,
        'VITE_LATER' => '${Z_LATER}', 'Z_LATER' => '${VITE_APP_URL}/nested',
        'PASSWORD' => 'pa$$word', 'HASH' => '$2y$10$abcdef', 'PRICE' => 'price$5',
        'ESCAPED' => '${APP_NAME}', 'SINGLE' => '${APP_NAME}',
    ]);
    expect($run['intent']['container']['processes'][0]['content'])
        ->not->toContain('EnvironmentFile=-/srv/workspace/.env');
    expect($file)->toContain('VITE_APP_NAME="${APP_NAME}"', 'VITE_APP_URL="${APP_URL}"');
    expect($file)->toBe(collect($vault->puts)->firstWhere('key', 'instance-create-environment-input-'.$operation->id)['value']);
});

it('keeps an undefined template reference literal in the file inherited by Processes', function (): void {
    $node = containerDefaultNode();
    $default = seededDefault($node);
    app(ProjectEnvironmentTemplateRefresh::class)->recordRead($default->project, str_repeat('e', 40), 'fixture', 'VITE_UNDEFINED="${NOT_DEFINED}"');

    $operation = containerCreate($default->project, $node, 'undefined');
    $contents = app(DevelopmentCreateEnvironment::class)->content(Instance::query()->findOrFail($operation->target_id));

    expect(Dotenv::parse($contents)['VITE_UNDEFINED'])->toBe('${NOT_DEFINED}');
});

it('keeps circular whole-value references live without an Orbit resolver', function (): void {
    $default = seededDefault(containerDefaultNode());
    app(ProjectEnvironmentTemplateRefresh::class)->recordRead($default->project, str_repeat('c', 40), 'fixture', 'FIRST=${SECOND}'."\n".'SECOND=${FIRST}');
    $operation = containerCreate($default->project, $default->node, 'circular');
    $contents = app(DevelopmentCreateEnvironment::class)->content(Instance::query()->findOrFail($operation->target_id));

    expect($contents)->toContain('FIRST="${SECOND}"', 'SECOND="${FIRST}"');
});

it('keeps old persisted secret references literal without refreshing their source metadata', function (): void {
    $node = containerDefaultNode();
    $default = seededDefault($node);
    $head = str_repeat('a', 40);
    $default->project->environmentTemplate()->create(['source_path' => '.env.example', 'source_commit' => $head,
        'source_blob' => 'same-blob', 'checked_head' => $head, 'entries' => [
            ['key' => 'DB_PASSWORD', 'value' => 'synthetic-password', 'example' => 'synthetic-password', 'removed' => false],
            ['key' => 'VITE_LITERAL', 'value' => '${DB_PASSWORD}', 'example' => '${DB_PASSWORD}', 'removed' => false],
        ]]);
    app(ProjectEnvironmentTemplateRefresh::class)->recordRead($default->project, $head, 'same-blob', <<<'ENV'
DB_PASSWORD=synthetic-password
VITE_LITERAL='${DB_PASSWORD}'
ENV);

    $operation = containerCreate($default->project, $node, 'old-template');
    $contents = app(DevelopmentCreateEnvironment::class)->content(Instance::query()->findOrFail($operation->target_id));

    expect(Dotenv::parse($contents)['VITE_LITERAL'])->toBe('${DB_PASSWORD}');
});

it('leaves Process port and origin reference expansion to dotenv', function (): void {
    $node = containerDefaultNode();
    $default = seededDefault($node);
    app(ProjectEnvironmentTemplateRefresh::class)->recordRead($default->project, str_repeat('b', 40), 'fixture', <<<'ENV'
ORBIT_DEV_SERVER_PORT=9999
ORBIT_DEV_SERVER_ORIGIN=https://default.test
PORT_COPY=${ORBIT_DEV_SERVER_PORT}
ORIGIN_COPY=${ORBIT_DEV_SERVER_ORIGIN}
ENV);
    ProcessDefinition::query()->create(['project_id' => $default->project_id, 'name' => 'dev', 'environments' => ['development'],
        'spec' => ['runtime' => 'systemd', 'preset' => 'vp-dev', 'command' => VpDevPreset::definitionCommand()]]);

    $operation = containerCreate($default->project, $node, 'runtime-refs');
    $clone = Instance::query()->findOrFail($operation->target_id);
    $contents = app(DevelopmentCreateEnvironment::class)->content($clone);

    expect($clone->vite_port)->toBe(5173);
    expect(Dotenv::parse($contents))->toMatchArray([
        'ORBIT_DEV_SERVER_PORT' => '9999', 'ORBIT_DEV_SERVER_ORIGIN' => 'https://default.test',
        'PORT_COPY' => '9999', 'ORIGIN_COPY' => 'https://default.test',
    ]);
});

it('matches main rendering byte for byte for awkward values without live provenance', function (): void {
    // Expected bytes were captured from the renderer at main 1720d2e6fe4f76cb5b87edb6ea12ef30fd7ea271.
    $node = containerDefaultNode();
    $default = seededDefault($node);
    app(ProjectEnvironmentTemplateRefresh::class)->recordRead($default->project, str_repeat('f', 40), 'fixture',
        file_get_contents(__DIR__.'/../../../Fixtures/instance-environment-awkward.env.example'));
    $instance = Instance::query()->create(['project_id' => $default->project_id, 'node_id' => $node->id, 'name' => 'awkward',
        'checkout_path' => '/workspace/awkward', 'root' => '.', 'status' => 'reserved']);

    $template = $default->project->environmentTemplate()->sole();
    $template->update(['entries' => array_map(static function (array $entry): array {
        unset($entry['reference']);

        return $entry;
    }, $template->entries)]);
    app(DevelopmentCreateEnvironment::class)->admit($instance, $default->project);
    $contents = app(DevelopmentCreateEnvironment::class)->content($instance);

    expect($contents)->toBe(file_get_contents(__DIR__.'/../../../Fixtures/instance-environment-awkward.rendered'));
});

it('records provenance only for captured live whole-value references', function (): void {
    $node = containerDefaultNode();
    $default = seededDefault($node);
    $instanceBefore = $default->fresh()->getAttributes();
    $environmentBefore = app(InstanceEnvironmentStore::class)->values($default);

    app(ProjectEnvironmentTemplateRefresh::class)->recordRead($default->project, str_repeat('9', 40), 'fixture', <<<'ENV'
APP_NAME=Laravel
VITE_APP_NAME="${APP_NAME}"
VITE_LITERAL="\${APP_NAME}"
SECRET_LITERAL='${DB_PASSWORD}'
ENV);

    expect($default->project->environmentTemplate()->sole()->entries)->toBe([
        ['key' => 'APP_NAME', 'value' => 'Laravel', 'example' => 'Laravel', 'removed' => false],
        ['key' => 'VITE_APP_NAME', 'value' => '${APP_NAME}', 'example' => '${APP_NAME}', 'removed' => false, 'reference' => 'APP_NAME'],
        ['key' => 'VITE_LITERAL', 'value' => '${APP_NAME}', 'example' => '${APP_NAME}', 'removed' => false],
        ['key' => 'SECRET_LITERAL', 'value' => '${DB_PASSWORD}', 'example' => '${DB_PASSWORD}', 'removed' => false],
    ]);
    expect($default->fresh()->getAttributes())->toBe($instanceBefore);
    expect(app(InstanceEnvironmentStore::class)->values($default))->toBe($environmentBefore);
});

it('leaves every old entry unchanged until source capture records live reference provenance', function (): void {
    $node = containerDefaultNode();
    $default = seededDefault($node);
    $head = str_repeat('8', 40);
    $entries = [];
    foreach (['APP_NAME' => 'Laravel', 'VITE_APP_NAME' => '${APP_NAME}', 'SINGLE' => '${APP_NAME}', 'ESCAPED' => '${APP_NAME}'] as $key => $value) {
        $entries[] = ['key' => $key, 'value' => $value, 'example' => $value, 'removed' => false];
    }
    $default->project->environmentTemplate()->create(['source_path' => '.env.example', 'source_commit' => $head,
        'source_blob' => 'same-blob', 'checked_head' => $head, 'entries' => $entries]);
    $defaultBefore = $default->fresh()->getAttributes();
    $environmentBefore = app(InstanceEnvironmentStore::class)->values($default);
    $fileBefore = app(DevelopmentCreateEnvironment::class)->content($default);
    $old = containerCreate($default->project, $node, 'old');
    $oldInstance = Instance::query()->findOrFail($old->target_id);
    $oldFile = app(DevelopmentCreateEnvironment::class)->content($oldInstance);
    $expected = ['APP_DEBUG' => 'true', 'APP_ENV' => 'development', 'APP_KEY' => '', 'APP_NAME' => 'Laravel',
        'ESCAPED' => '${APP_NAME}', 'SINGLE' => '${APP_NAME}', 'VITE_APP_NAME' => '${APP_NAME}'];
    $mainBytes = '';
    foreach ($expected as $key => $value) {
        $mainBytes .= $key.'='.InstanceEnvironmentRenderer::quote($value)."\n";
    }
    expect($oldFile)->toBe($mainBytes);

    // An existing source-capture event reads the same head/blob again, with source quoting available.
    app(ProjectEnvironmentTemplateRefresh::class)->recordRead($default->project, $head, 'same-blob', <<<'ENV'
APP_NAME=Laravel
VITE_APP_NAME="${APP_NAME}"
SINGLE='${APP_NAME}'
ESCAPED="\${APP_NAME}"
ENV);
    $fresh = containerCreate($default->project, $node, 'refreshed');
    $contents = app(DevelopmentCreateEnvironment::class)->content(Instance::query()->findOrFail($fresh->target_id));

    expect(Dotenv::parse($contents))->toMatchArray(['VITE_APP_NAME' => 'Laravel', 'SINGLE' => '${APP_NAME}', 'ESCAPED' => '${APP_NAME}']);
    expect($default->fresh()->getAttributes())->toBe($defaultBefore);
    expect(app(InstanceEnvironmentStore::class)->values($default))->toBe($environmentBefore);
    expect(app(DevelopmentCreateEnvironment::class)->content($default))->toBe($fileBefore);
    expect(app(InstanceEnvironmentStore::class)->values($oldInstance)['VITE_APP_NAME'])->toBe('${APP_NAME}');
});

it('leaves complex names as data while keeping their reference live', function (string $name): void {
    $node = containerDefaultNode();
    $default = seededDefault($node);
    app(ProjectEnvironmentTemplateRefresh::class)->recordRead($default->project, str_repeat('7', 40), 'fixture',
        'APP_NAME='.InstanceEnvironmentRenderer::quote($name)."\n".'VITE_APP_NAME="${APP_NAME}"');

    $operation = containerCreate($default->project, $node, 'data-name');
    $contents = app(DevelopmentCreateEnvironment::class)->content(Instance::query()->findOrFail($operation->target_id));

    expect($contents)->toContain('APP_NAME='.InstanceEnvironmentRenderer::quote($name)."\n",
        'VITE_APP_NAME="${APP_NAME}"'."\n");
    expect(Dotenv::parse($contents)['VITE_APP_NAME'])->toBe($name);
})->with(['Acme Shop', 'Acme # Shop', 'Acme $ale', 'C:\Shop', '${DB_PASSWORD}', "first\nsecond"]);

it('gives the clone PHP runtime its own Vite SSR origin instead of the default port', function (): void {
    $node = containerDefaultNode();
    $node->update(['tld' => 'beast.test']);
    Node::query()->where('name', 'control')->update(['ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key']);
    $this->mock(LeafCertificateSigner::class)->shouldReceive('rootCertificate')->andReturn('root-certificate');
    $default = seededDefault($node);
    $default->project->update(['type' => ProjectType::LaravelApp, 'php_version' => '8.5', 'root' => 'public']);
    $default->update(['vite_port' => 5195]);
    ProcessDefinition::query()->create(['project_id' => $default->project_id, 'name' => 'dev', 'environments' => ['development'],
        'spec' => ['runtime' => 'systemd', 'preset' => 'vp-dev', 'command' => VpDevPreset::definitionCommand()]]);

    $operation = containerCreate($default->project, $node, 'ssr-origin');
    $clone = Instance::query()->findOrFail($operation->target_id);
    $container = containerIntentOf($operation);

    expect($clone->vite_port)->toBe(5173);
    expect($container['units'][0]['content'])->toContain("Environment=INERTIA_SSR_HOT_URL=http://127.0.0.1:5173\n")
        ->not->toContain('http://127.0.0.1:5195');
    expect($container['processes'][0]['content'])->toContain('"--port=5173"');
    expect($clone->environmentValues()->where('env_key', 'INERTIA_SSR_HOT_URL')->exists())->toBeFalse();
});

it('renders database removal fence recovery with PostgreSQL boolean keywords', function (): void {
    $statements = app(DatabaseStatements::class)->destroy(DatabaseDriver::Pgsql, ['fixture'], ['owner']);

    expect($statements['restore'])
        ->toContain("format('ALTER DATABASE %I ALLOW_CONNECTIONS %s', d.datname, CASE WHEN j.allow_connections THEN 'true' ELSE 'false' END)")
        ->not->toContain("format('ALTER DATABASE %I ALLOW_CONNECTIONS %s', d.datname, j.allow_connections)");
});

it('names retained create resources and proves the advertised recovery paths', function (bool $clone, string $phase, bool $hasCommit): void {
    $node = containerDefaultNode();
    $node->update(['tld' => 'beast.test']);
    Node::query()->where('name', 'control')->update(['ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key']);
    $this->mock(LeafCertificateSigner::class)->shouldReceive('rootCertificate')->andReturn('root-certificate');
    $project = containerDefaultProject(ProjectType::LaravelApp, '8.5');
    if ($clone) {
        $seed = containerCreate($project, $node);
        $seed->forceFill(['status' => 'running', 'state' => ['swamp_run_id' => 'seed-run']])->save();
        app(DevelopmentInstanceCreateProjection::class)->apply($seed, [
            'status' => 'succeeded', 'run_id' => 'seed-run', 'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(),
            'result' => ['instance_id' => $seed->target_id, 'ready' => true, 'commit' => str_repeat('c', 40)],
        ]);
    }
    $name = $clone ? 'retry-feature' : 'default';
    $operation = containerCreate($project, $node, $name);
    $instance = Instance::query()->findOrFail($operation->target_id);
    $routeId = $operation->input['route_id'];
    $runtime = $instance->runtime()->firstOrFail();
    $operation->forceFill(['status' => 'running', 'state' => ['swamp_run_id' => 'failure-run']])->save();
    $native = ['instance_id' => $instance->id, 'ready' => false, 'failed_step' => $phase];
    if ($hasCommit) {
        $native['commit'] = str_repeat('a', 40);
    }
    app(DevelopmentInstanceCreateProjection::class)->apply($operation, [
        'status' => 'failed', 'run_id' => 'failure-run', 'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(), 'error_code' => 'instance.create_'.$phase.'_failed',
        'result' => $native,
    ]);
    $recovery = $operation->refresh()->result['recovery'];
    expect($recovery)->toBe([
        'state' => 'retained_for_retry',
        'retry' => ['command' => 'instance:create', 'project_id' => $project->id, 'node_id' => $node->id, 'name' => $name,
            'reuse_original_options' => true, 'new_idempotency_key' => true],
        'destroy' => ['command' => 'instance:destroy', 'instance_id' => $instance->id,
            'available' => $clone, 'options' => ['yes' => true, 'force' => true],
            ...($clone ? [] : ['error_code' => 'operation.kind_unavailable', 'reason' => 'container_default_destroy_unavailable'])],
    ])->and($instance->fresh()->status)->toBe($hasCommit ? InstanceState::SourceResolved : InstanceState::Reserved)
        ->and($instance->fresh()->failed_step)->toBe($phase)
        ->and(Route::query()->findOrFail($routeId)->status->value)->toBe('pending');

    // The command is a real new admission: it reuses the exact reservation, Route and runtime.
    $repeat = containerCreate($project, $node, $name);
    expect($repeat->id)->not->toBe($operation->id)
        ->and($repeat->target_id)->toBe($instance->id)
        ->and($repeat->input['route_id'])->toBe($routeId)
        ->and($instance->runtime()->firstOrFail()->id)->toBe($runtime->id);
    expect(containerRefusal(fn () => app(ContainerInstanceDestroy::class)->assertAvailable($instance->fresh(), true)))
        ->toBe($clone ? 'admitted' : 'operation.kind_unavailable');
})->with([
    'clone-input' => [true, 'clone', false],
    'clone-container' => [true, 'clone', false],
    'clone-workspace' => [true, 'workspace', false],
    'clone-agent' => [true, 'agent', true],
    'clone-t3' => [true, 't3', true],
    'clone-sessions' => [true, 'register', true],
    'clone-register' => [true, 'register', true],
    'default-container' => [false, 'container', false],
    'default-workspace' => [false, 'workspace', false],
    'default-deploy' => [false, 'deploy', true],
    'default-processes' => [false, 'processes', true],
    'default-route' => [false, 'route', true],
    'default-seed' => [false, 'seed', true],
]);

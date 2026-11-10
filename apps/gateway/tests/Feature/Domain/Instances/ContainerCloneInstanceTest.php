<?php

declare(strict_types=1);

use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Domain\DatabaseServers\DatabaseStatements;
use App\Domain\Extensions\ExtensionStore;
use App\Domain\Instances\DevelopmentInstanceCreateProjection;
use App\Domain\Instances\Environment\InstanceEnvironmentStore;
use App\Domain\Instances\InstanceContainer;
use App\Domain\Instances\InstanceLifecycleContributions;
use App\Domain\Instances\InstanceRuntimePowerState;
use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\RoleName;
use App\Domain\Projects\ProjectType;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmModelRoute;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketEndpoint;
use App\Models\DatabaseConnection;
use App\Models\DatabaseServer;
use App\Models\Instance;
use App\Models\InstanceRuntime;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Process;
use App\Models\ProcessDefinition;
use App\Models\ProjectDevelopmentDeployStep;
use App\Support\ValidatedData;
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

        $own = Process::query()->where('owner_id', $operation->target_id)->sole();
        $units = ValidatedData::object(containerIntentOf($operation))['processes'];
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
                'url' => 'http://10.44.0.7:3800', 'label' => 'tool / feature'])
            ->and(collect($clone['files'])->pluck('path')->all())->toBe(['/etc/orbit/instance', '/home/agent/.codex/config.toml', '/home/agent/.claude.json', '/home/agent/.t3/userdata/settings.json'])
            ->and($clone['files'][0]['content'])->toBe("# Managed by Orbit.\nORBIT_INSTANCE_ID={$id}\nORBIT_INSTANCE=feature\nORBIT_PROJECT=tool\nORBIT_URL=\n")
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

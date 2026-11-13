<?php

declare(strict_types=1);

use App\Actions\Instances\ProjectDirectInstanceDestroyAction;
use App\Actions\Instances\StartInstanceDeployAction;
use App\Actions\Instances\StartInstanceDestroyAction;
use App\Actions\Operations\CancelOperationAction;
use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\DatabaseConnections\DatabaseConnectionEnvProjection;
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
use App\Domain\Operations\OperationStatus;
use App\Domain\Processes\VitePreset;
use App\Domain\Processes\VpDevPreset;
use App\Domain\Projects\ProjectEnvironmentTemplateRefresh;
use App\Domain\Projects\ProjectSourceReader;
use App\Domain\Projects\ProjectType;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmModelRoute;
use App\Domain\WebSocket\WebSocketAppEnvProjection;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketEndpoint;
use App\Infrastructure\AppDev\DevelopmentSiteRepository;
use App\Infrastructure\Swamp\NativeStepFailure;
use App\Models\Activity;
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
use App\Models\WebSocketApp;
use App\Models\WebSocketAppTarget;
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

it('always sends the server_valkey key in the create run intent, before any workflow reads it', function (): void {
    $node = containerDefaultNode();
    $operation = containerCreate(seededDefault($node)->project, $node, 'feature');
    vaultReadingSocket([]);

    $run = app(DevelopmentInstanceCreateProjection::class)->input($operation);

    expect($run['intent'])->toHaveKey('server_valkey')
        ->and($run['intent']['server_valkey'])->toBeNull();
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

describe('a clone of a default with a Valkey target', function (): void {
    it('gets its own Valkey user on the same server, never the default\'s user, password or keys', function (): void {
        $node = containerDefaultNode();
        $default = seededDefault($node);
        $default->project->update(['is_laravel' => true]);
        $shared = defaultValkeyTarget($default, databaseServerOn($node, 'kv', DatabaseDriver::Redis, 6379));
        $operation = containerCreate($default->project->fresh(), $node, 'feature');
        $clone = Instance::query()->findOrFail($operation->target_id);
        $vault = vaultReadingSocket([]);

        $own = DatabaseConnection::query()->where('owner_instance_id', $clone->id)->sole();
        $privileges = $own->users()->sole()->privileges;
        $values = app(InstanceEnvironmentStore::class)->values($clone);
        $run = app(DevelopmentInstanceCreateProjection::class)->input($operation);
        $create = collect($vault->puts)->firstWhere('key', $run['execution_secrets']['server_valkey_create']['key'])['value'];
        $prefix = 'orbit_instance_'.$clone->id.'_';

        expect($own->only(['slug', 'username', 'database_server_id', 'host', 'port', 'database']))->toBe(['slug' => 'tool-feature-valkey',
            'username' => 'tool-feature-valkey', 'database_server_id' => $shared->database_server_id, 'host' => '10.44.0.7', 'port' => 6379, 'database' => '3'])
            ->and($own->password)->not->toBe($shared->password)
            ->and($privileges)->toBe("on ~{$prefix}* resetchannels -@all +@read +@write +@keyspace +@connection +eval -@admin -@dangerous -function -script")
            ->and(DatabaseConnectionTarget::query()->where('instance_id', $clone->id)->where('prefix', 'REDIS')->sole()->database_connection_id)->toBe($own->id)
            ->and($values)->toMatchArray(['REDIS_CONNECTION' => 'redis', 'REDIS_HOST' => '10.44.0.7', 'REDIS_PORT' => '6379', 'REDIS_DATABASE' => '3',
                'REDIS_USERNAME' => 'tool-feature-valkey', 'REDIS_PASSWORD' => $own->password,
                'REDIS_PREFIX' => $prefix.'database_', 'HORIZON_PREFIX' => $prefix.'horizon:'])
            ->and($values)->not->toHaveKey('QUEUE_CONNECTION')
            ->and(containerIntentOf($operation)['firewall'])->toContain(['rule' => 'allow', 'interface_in' => 'orbitinst0', 'from_ip' => '10.253.0.3',
                'to_ip' => '10.44.0.7', 'to_port' => '6379', 'proto' => 'tcp', 'comment' => "orbit:instance-{$clone->id}-database"])
            ->and($run['intent']['server_valkey']['intent']['username'])->toBe('tool-feature-valkey')
            ->and($run['intent']['server_valkey']['fill'])->toBeNull()
            ->and($run['intent']['server_database'])->toBeNull()
            ->and($create)->toContain('ACL SETUSER tool-feature-valkey reset on #'.hash('sha256', $own->password)." ~{$prefix}* resetchannels -@all")
            ->and($create)->not->toContain('tool_default')->not->toContain($shared->password)->not->toContain('shared-redis')
            ->and(json_encode($run['intent']))->not->toContain($own->password);
    });

    it('never takes FLUSHDB, which would empty the index it shares with the default', function (): void {
        $node = containerDefaultNode();
        $default = seededDefault($node);
        $shared = defaultValkeyTarget($default, databaseServerOn($node, 'kv', DatabaseDriver::Redis, 6379));
        $shared->users()->update(['privileges' => 'on ~tool_* resetchannels -@all +@read +@write +flushdb +FLUSHDB -@admin -@dangerous -function -script +flushdb']);
        $operation = containerCreate($default->project, $node, 'feature');
        $vault = vaultReadingSocket([]);
        $run = app(DevelopmentInstanceCreateProjection::class)->input($operation);
        $create = collect($vault->puts)->firstWhere('key', $run['execution_secrets']['server_valkey_create']['key'])['value'];

        expect(DatabaseConnection::query()->where('owner_instance_id', $operation->target_id)->sole()->users()->sole()->privileges)
            ->toBe('on ~orbit_instance_'.$operation->target_id.'_* resetchannels -@all +@read +@write -@admin -@dangerous -function -script')
            ->and(strtolower($create))->not->toContain('flushdb');
    });

    it('gives a Project that Orbit does not record as Laravel only the access keys', function (): void {
        $node = containerDefaultNode();
        $default = seededDefault($node);
        defaultValkeyTarget($default, databaseServerOn($node, 'kv', DatabaseDriver::Redis, 6379));
        $clone = Instance::query()->findOrFail(containerCreate($default->project, $node, 'feature')->target_id);

        expect(app(InstanceEnvironmentStore::class)->values($clone))->toHaveKeys(['REDIS_HOST', 'REDIS_USERNAME', 'REDIS_PASSWORD'])
            ->not->toHaveKeys(['REDIS_PREFIX', 'HORIZON_PREFIX']);
    });

    it('keeps the SQL database clone and adds the Valkey user beside it', function (): void {
        $node = containerDefaultNode();
        databaseServerOn($node, 'pg', DatabaseDriver::Pgsql, 5432);
        $default = Instance::query()->findOrFail(containerCreate(containerDefaultProject(), $node, 'default', 'pg')->target_id);
        $default->update(['status' => InstanceState::Active, 'first_setup_pending' => false]);
        InstanceRuntime::query()->where('instance_id', $default->id)->update(['seed_commit' => str_repeat('c', 40)]);
        defaultValkeyTarget($default, databaseServerOn($node, 'kv', DatabaseDriver::Redis, 6379));
        $operation = containerCreate($default->project, $node, 'feature');
        vaultReadingSocket([]);
        $run = app(DevelopmentInstanceCreateProjection::class)->input($operation);

        expect(DatabaseConnection::query()->where('owner_instance_id', $operation->target_id)->orderBy('id')->pluck('slug')->all())->toBe(['tool-feature', 'tool-feature-valkey'])
            ->and(array_column(containerIntentOf($operation)['firewall'], 'to_port'))->toContain('5432', '6379')
            ->and($run['intent']['server_database']['fill'])->not->toBeNull()
            ->and($run['execution_secrets']['server_database_create']['key'])->not->toBe($run['execution_secrets']['server_valkey_create']['key']);
    });

    it('is refused when the default has two Valkey targets, no Orbit-made user, or a user with pub/sub', function (Closure $arrange): void {
        $node = containerDefaultNode();
        $default = seededDefault($node);
        $arrange($default, defaultValkeyTarget($default, databaseServerOn($node, 'kv', DatabaseDriver::Redis, 6379)));

        expect(containerRefusal(fn () => containerCreate($default->project, $node, 'feature')))->toBe('instance.database_clone_unsupported')
            ->and(Instance::query()->where('name', 'feature')->exists())->toBeFalse();
    })->with([
        'two targets' => [fn (Instance $default, DatabaseConnection $shared) => DatabaseConnectionTarget::query()->create(
            ['instance_id' => $default->id, 'prefix' => 'CACHE_REDIS', 'database_connection_id' => $shared->id, 'network' => 'wireguard'])],
        'no user' => [fn (Instance $default, DatabaseConnection $shared) => $shared->users()->delete()],
        'pub/sub' => [fn (Instance $default, DatabaseConnection $shared) => $shared->users()->update(['privileges' => 'on ~app_* resetchannels -@all +@read +publish -@admin'])],
    ]);
});

it('sends the clone the names that merge its render into the default\'s .env of the seed copy, never a value', function (): void {
    $node = containerDefaultNode();
    databaseServerOn($node, 'pg', DatabaseDriver::Pgsql, 5432);
    $default = Instance::query()->findOrFail(containerCreate(containerDefaultProject(), $node, 'default', 'pg')->target_id);
    $default->update(['status' => InstanceState::Active, 'first_setup_pending' => false]);
    $default->project->update(['is_laravel' => true]);
    InstanceRuntime::query()->where('instance_id', $default->id)->update(['seed_commit' => str_repeat('c', 40)]);
    $shared = defaultValkeyTarget($default, databaseServerOn($node, 'kv', DatabaseDriver::Redis, 6379));
    $operation = containerCreate($default->project->fresh(), $node, 'feature');
    $vault = vaultReadingSocket([]);
    app(DevelopmentInstanceCreateProjection::class)->input($operation);
    $workspace = json_decode(collect($vault->puts)->firstWhere('key', 'instance-create-processes-'.$operation->id)['value'], true, 512, JSON_THROW_ON_ERROR);
    $defaultDatabase = DatabaseConnectionTarget::query()->where('instance_id', $default->id)->where('prefix', 'DB')->sole()->databaseConnection;
    $managed = static fn (string $prefix): array => array_map(static fn (string $suffix): string => $prefix.'_'.$suffix, DatabaseConnectionEnvProjection::Suffixes);
    $sorted = static function (array $keys): array {
        sort($keys, SORT_STRING);

        return $keys;
    };

    expect($workspace['merge'])->toBe($operation->input['environment_clone'])
        ->and($workspace['merge']['drop'])->toBe($sorted([...$managed('DB'), 'DB_URL', ...$managed('REDIS'), 'REDIS_URL']))
        // A Redis database is an index such as 0, never a credential.
        ->and($workspace['merge']['guard'])->toBe(['DB_DATABASE', 'DB_PASSWORD', 'DB_USERNAME', 'REDIS_PASSWORD', 'REDIS_USERNAME'])
        ->and($workspace['merge']['own'])->toContain('DB_DATABASE', 'DB_PASSWORD', 'DB_USERNAME', 'DB_HOST',
            'REDIS_USERNAME', 'REDIS_PASSWORD', 'REDIS_PREFIX', 'HORIZON_PREFIX')
        ->and($workspace['merge']['own'])->not->toContain('APP_KEY', 'APP_NAME')
        ->and(json_encode($operation->input['environment_clone']))->not->toContain((string) $defaultDatabase->password)
        ->not->toContain((string) $shared->password)->not->toContain((string) $defaultDatabase->database);
});

it('never copies the default\'s Reverb app keys or a copy of its secret into a clone', function (): void {
    $node = containerDefaultNode();
    $default = seededDefault($node);
    $app = WebSocketApp::query()->create(['name' => 'tool', 'app_id' => '1001', 'key' => 'reverb-key', 'secret' => 'reverb-secret-value', 'credential_generation' => 1]);
    WebSocketAppTarget::query()->create(['websocket_app_id' => $app->id, 'instance_id' => $default->id]);
    $operation = containerCreate($default->project, $node, 'feature');

    expect($operation->input['environment_clone']['drop'])->toContain(...WebSocketAppEnvProjection::Keys)
        ->and($operation->input['environment_clone']['guard'])->toContain('REVERB_APP_SECRET')
        ->and(json_encode($operation->input))->not->toContain('reverb-secret-value');
});

it('starts a clone that a Gateway admitted before the merge names existed, with no names: the clone writes the render', function (): void {
    $node = containerDefaultNode();
    $operation = containerCreate(seededDefault($node)->project, $node, 'feature');
    $input = $operation->input;
    unset($input['environment_clone']);
    $operation->update(['input' => $input]);
    $vault = vaultReadingSocket([]);

    app(DevelopmentInstanceCreateProjection::class)->input($operation->fresh());
    $workspace = json_decode(collect($vault->puts)->firstWhere('key', 'instance-create-processes-'.$operation->id)['value'], true, 512, JSON_THROW_ON_ERROR);

    expect($workspace)->toHaveKey('merge')->and($workspace['merge'])->toBeNull()
        ->and($workspace['environment'])->toBe(collect($vault->puts)->firstWhere('key', 'instance-create-environment-input-'.$operation->id)['value']);
});

it('sends no merge names for a container default, which has no seed copy to keep', function (): void {
    $node = containerDefaultNode();
    $operation = containerCreate(containerDefaultProject(), $node, 'default');

    expect($operation->input)->not->toHaveKey('environment_clone');
});

it('gives a clone of a default without a Valkey target no Valkey user', function (): void {
    $node = containerDefaultNode();
    $operation = containerCreate(seededDefault($node)->project, $node, 'feature');

    expect(DatabaseConnection::query()->where('driver', 'redis')->exists())->toBeFalse()
        ->and(ValidatedData::object($operation->input['direct_provisioning'])['server_valkey'])->toBeNull()
        ->and(app(InstanceEnvironmentStore::class)->values(Instance::query()->findOrFail($operation->target_id)))->not->toHaveKeys(['REDIS_HOST', 'REDIS_PREFIX', 'HORIZON_PREFIX']);
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

/** The default's shared Valkey connection, as an operator makes and attaches it: a user over other apps' keys, index 3. */
function defaultValkeyTarget(Instance $default, DatabaseServer $server): DatabaseConnection
{
    $shared = DatabaseConnection::query()->create(['slug' => 'shared-redis', 'driver' => 'redis', 'node_id' => $server->node_id,
        'database_server_id' => $server->id, 'host' => '10.44.0.7', 'port' => 6379, 'database' => '3', 'username' => 'shared-redis', 'password' => 'default-secret']);
    $shared->users()->create(['username' => 'shared-redis', 'created_by' => 'beast',
        'privileges' => 'on ~tool_default_* ~other_app_* resetchannels -@all +@read +@write +@keyspace +@connection +eval -@admin -@dangerous -function -script']);
    DatabaseConnectionTarget::query()->create(['instance_id' => $default->id, 'prefix' => 'REDIS', 'database_connection_id' => $shared->id, 'network' => 'wireguard']);

    return $shared;
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
    // The default answers on its own Routes, which need not have the clone's form.
    foreach (['tool.test' => null, 'docs.tool.test' => 'docs'] as $routeDomain => $webRoot) {
        $route = Route::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'provenance' => 'explicit',
            'publication' => 'private', 'domain' => $routeDomain, 'web_root' => $webRoot, 'status' => 'pending']);
        $default->routes()->attach($route->id, ['position' => 0]);
    }

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
    // The clone's Route URL is its own; the default's file keeps the rest, APP_KEY included.
    expect($workspace['merge']['own'])->toContain('APP_URL')->not->toContain('APP_KEY');
    // A copied value on one of the default's own Route hosts moves to the clone's Route host; a Route with a web root serves
    // other content and stays. Only host names travel, no .env value.
    expect($workspace['merge']['hosts'])->toBe(['from' => ['tool.test'], 'to' => $domain])
        ->and($operation->input['environment_clone']['hosts'])->toBe($workspace['merge']['hosts']);
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

it('names retained create resources and proves the advertised recovery paths', function (bool $clone, string $phase, bool $hasCommit, string $status): void {
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
        'status' => $status, 'run_id' => 'failure-run', 'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(), 'error_code' => 'instance.create_'.$phase.'_failed',
        'result' => $native,
    ]);
    $recovery = $operation->refresh()->result['recovery'] ?? null;
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
    'clone-input' => [true, 'clone', false, 'failed'],
    'clone-container' => [true, 'clone', false, 'failed'],
    'clone-workspace' => [true, 'workspace', false, 'failed'],
    'clone-agent' => [true, 'agent', true, 'failed'],
    'clone-t3' => [true, 't3', true, 'failed'],
    'clone-sessions' => [true, 'register', true, 'failed'],
    'clone-register' => [true, 'register', true, 'failed'],
    'default-container' => [false, 'container', false, 'failed'],
    'default-workspace' => [false, 'workspace', false, 'failed'],
    'default-deploy' => [false, 'deploy', true, 'failed'],
    'default-processes' => [false, 'processes', true, 'failed'],
    'default-route' => [false, 'route', true, 'failed'],
    'default-seed' => [false, 'seed', true, 'failed'],
    'cancelled-clone' => [true, 'register', true, 'cancelled'],
    'cancelled-default' => [false, 'container', false, 'cancelled'],
]);

it('names retained resources when a container create is cancelled before its run starts', function (bool $clone): void {
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
    $name = $clone ? 'queued-feature' : 'default';
    $operation = containerCreate($project, $node, $name);
    $instance = Instance::query()->findOrFail($operation->target_id);
    $routeId = $operation->input['route_id'];
    $runtimeId = $instance->runtime()->firstOrFail()->id;
    expect($operation->status)->toBe(OperationStatus::Queued)
        ->and($operation->attempts)->toBe(0)
        ->and($operation->state)->not->toHaveKey('swamp_run_id');

    app(CancelOperationAction::class)->execute($operation);

    expect($operation->refresh()->status)->toBe(OperationStatus::Cancelled)
        ->and($operation->result)->toBe(['recovery' => [
            'state' => 'retained_for_retry',
            'retry' => ['command' => 'instance:create', 'project_id' => $project->id, 'node_id' => $node->id, 'name' => $name,
                'reuse_original_options' => true, 'new_idempotency_key' => true],
            'destroy' => ['command' => 'instance:destroy', 'instance_id' => $instance->id,
                'available' => $clone, 'options' => ['yes' => true, 'force' => true],
                ...($clone ? [] : ['error_code' => 'operation.kind_unavailable', 'reason' => 'container_default_destroy_unavailable'])],
        ]])
        ->and($instance->fresh()->status)->toBe(InstanceState::Reserved)
        ->and(Route::query()->findOrFail($routeId)->status->value)->toBe('pending')
        ->and($instance->runtime()->firstOrFail()->id)->toBe($runtimeId);

    $retry = containerCreate($project, $node, $name);
    expect($retry->id)->not->toBe($operation->id)
        ->and($retry->target_id)->toBe($instance->id)
        ->and($retry->input['route_id'])->toBe($routeId)
        ->and($instance->runtime()->firstOrFail()->id)->toBe($runtimeId);
})->with(['clone' => true, 'default' => false]);

it('keeps another Operation kind cancellation result unchanged', function (?array $result): void {
    $operation = Operation::query()->create([
        'kind' => 'instance.deploy', 'target_type' => 'instance', 'target_id' => 1,
        'status' => OperationStatus::Queued, 'attempts' => 0, 'state' => [],
        'input' => ['project_id' => 1, 'node_id' => 2, 'name' => 'feature', 'direct_provisioning' => ['container' => []]],
        'result' => $result,
    ]);

    app(CancelOperationAction::class)->execute($operation);

    expect($operation->refresh()->status)->toBe(OperationStatus::Cancelled)
        ->and($operation->result)->toBe($result);
})->with(['no result' => [null], 'existing result' => [['kept' => true]]]);

$recoveryInvalidTargets = [
    'deleted' => [function (Instance $instance): void {
        $destroy = app(StartInstanceDestroyAction::class)->execute($instance, true);
        app(ProjectDirectInstanceDestroyAction::class)->execute($destroy->input, []);
        expect(Instance::query()->find($instance->id))->toBeNull();
    }],
    'active' => [fn (Instance $instance) => $instance->update(['status' => InstanceState::Active])],
    'removing' => [fn (Instance $instance) => app(StartInstanceDestroyAction::class)->execute($instance, true)],
    'another reservation' => [fn (Instance $instance) => $instance->update(['source_prepare_id' => '2af21576-58f7-46df-b971-f88e269b6688'])],
];

it('omits queued cancellation recovery when the create no longer owns a retained Instance', function (Closure $invalidate): void {
    $node = containerDefaultNode();
    $operation = containerCreate(seededDefault($node)->project, $node, 'removed-feature');
    $instance = Instance::query()->findOrFail($operation->target_id);
    $invalidate($instance);

    app(CancelOperationAction::class)->execute($operation);

    expect($operation->refresh()->status)->toBe(OperationStatus::Cancelled)
        ->and($operation->result ?? [])->not->toHaveKey('recovery');
})->with($recoveryInvalidTargets);

it('omits terminal recovery when the create no longer owns a retained Instance', function (Closure $invalidate): void {
    $node = containerDefaultNode();
    $operation = containerCreate(seededDefault($node)->project, $node, 'removed-feature');
    $operation->forceFill(['status' => 'running', 'state' => ['swamp_run_id' => 'removed-run']])->save();
    $instance = Instance::query()->findOrFail($operation->target_id);
    $invalidate($instance);
    $state = Instance::query()->find($instance->id)?->status;
    $result = ['instance_id' => $instance->id, 'ready' => false, 'failed_step' => 'register', 'commit' => str_repeat('a', 40)];

    app(DevelopmentInstanceCreateProjection::class)->apply($operation, [
        'status' => 'failed', 'run_id' => 'removed-run', 'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(),
        'error_code' => 'instance.container_process_inactive', 'result' => $result,
    ]);

    expect($operation->refresh()->status)->toBe(OperationStatus::Failed)
        ->and($operation->result)->toBe($result)
        ->and(Instance::query()->find($instance->id)?->status)->toBe($state);
})->with($recoveryInvalidTargets);

it('rederives retained create Processes at retry admission from current definitions', function (bool $clone, string $change): void {
    $node = containerDefaultNode();
    $node->update(['tld' => 'beast.test']);
    Node::query()->where('name', 'control')->update(['ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key']);
    $this->mock(LeafCertificateSigner::class)->shouldReceive('rootCertificate')->andReturn('root-certificate');
    $project = containerDefaultProject(ProjectType::LaravelApp, '8.5');
    $definition = ProcessDefinition::query()->create(['project_id' => $project->id, 'name' => 'inactive-fixture', 'environments' => ['development'],
        'spec' => ['runtime' => 'systemd', 'command' => ['/bin/false'], 'environment' => ['RETRY_VALUE' => 'before']]]);
    $projection = app(DevelopmentInstanceCreateProjection::class);
    if ($clone) {
        $seed = containerCreate($project, $node);
        $seed->forceFill(['status' => 'running', 'state' => ['swamp_run_id' => 'seed-run']])->save();
        $projection->apply($seed, ['status' => 'succeeded', 'run_id' => 'seed-run', 'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(),
            'result' => ['ready' => true, 'instance_id' => $seed->target_id, 'commit' => str_repeat('c', 40)]]);
    }
    $name = $clone ? 'retry-process' : 'default';
    $first = containerCreate($project, $node, $name);
    $instance = Instance::query()->findOrFail($first->target_id);
    $runtimeId = $instance->runtime()->sole()->id;
    $process = $instance->processes()->sole();
    $first->forceFill(['status' => 'running', 'state' => ['swamp_run_id' => 'run-1']])->save();
    $projection->apply($first, ['status' => 'failed', 'run_id' => 'run-1', 'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(), 'error_code' => 'instance.container_process_inactive',
        'result' => ['ready' => false, 'instance_id' => $instance->id, 'commit' => str_repeat('c', 40), 'failed_step' => $clone ? 'register' : 'processes',
            'process_failure' => ['process_id' => $process->id, 'unit' => 'orbit-process-'.$process->id.'-inactive-fixture.service', 'active_state' => 'failed']]]);
    expect($instance->fresh()->status)->toBe(InstanceState::SourceResolved);
    if ($change === 'fixed') {
        $definition->update(['spec' => ['runtime' => 'systemd', 'command' => ['/bin/sleep', '3600'], 'environment' => ['RETRY_VALUE' => 'after']]]);
    } elseif ($change === 'removed') {
        $definition->delete();
    } elseif ($change === 'production-only') {
        $definition->update(['environments' => ['production']]);
    }
    $retry = containerCreate($project, $node, $name);
    expect($retry->target_id)->toBe($first->target_id)
        ->and($retry->input['route_id'])->toBe($first->input['route_id'])
        ->and($instance->runtime()->sole()->id)->toBe($runtimeId);
    $vault = vaultReadingSocket(['nckrtl-instance-t3-model-register-session-gateway-1-token' => 'gateway-session-value']);
    $run = $projection->input($retry);
    $units = $run['intent']['container']['processes'];
    if (in_array($change, ['removed', 'production-only'], true)) {
        expect($instance->processes()->count())->toBe(0)->and($units)->toBe([])
            ->and(Process::query()->find($process->id))->toBeNull();
    } else {
        $current = $instance->processes()->sole();
        $command = $change === 'fixed' ? '/bin/sleep' : '/bin/false';
        $value = $change === 'fixed' ? 'after' : 'before';
        $workspace = json_decode(collect($vault->puts)->firstWhere('key', 'instance-create-processes-'.$retry->id)['value'], true, 512, JSON_THROW_ON_ERROR);
        expect($current->id)->toBe($process->id)
            ->and($current->runtime_config['command'][0])->toBe($command)
            ->and($current->desired_state->value)->toBe('running')
            ->and($units[0]['content'])->toContain($command)
            ->and(Dotenv::parse(($clone ? $workspace['processes'] : $workspace)[$units[0]['environment']])['RETRY_VALUE'])->toBe($value);
        if ($change === 'fixed') {
            expect($units[0]['environment'])->not->toBe(containerIntentOf($first)['processes'][0]['environment']);
        }
    }
    // Unchanged intent still cannot pass readiness; a valid receipt covers the repaired set.
    if ($change === 'unchanged') {
        return;
    }
    $retry->forceFill(['status' => 'running', 'state' => ['swamp_run_id' => 'run-1']])->save();
    $receipt = cloneSucceeded($retry);
    if (! $clone) {
        unset($receipt['result']['t3']);
    }
    $projection->apply($retry, $receipt);
    expect($retry->fresh()->status)->toBe(OperationStatus::Succeeded)
        ->and($instance->fresh()->status)->toBe(InstanceState::Active)
        ->and(Route::query()->findOrFail($retry->input['route_id'])->status->value)->toBe('active');
})->with([true, false])->with(['fixed', 'removed', 'production-only', 'unchanged']);

it('marks retry as needing a definition change only for an admitted Project Process failure', function (string $failure): void {
    $node = containerDefaultNode();
    $default = seededDefault($node);
    $definition = ProcessDefinition::query()->create(['project_id' => $default->project_id, 'name' => 'worker', 'environments' => ['development'],
        'spec' => ['runtime' => 'systemd', 'command' => ['/bin/false']]]);
    $operation = containerCreate($default->project, $node, 'feature');
    $process = Process::query()->where('owner_id', $operation->target_id)->sole();
    $operation->forceFill(['status' => 'running', 'state' => ['swamp_run_id' => 'run-1']])->save();
    $facts = match ($failure) {
        'project', 'cancelled' => ['process_id' => $process->id],
        'foreign' => ['process_id' => $process->id + 1000],
        default => [],
    };
    app(DevelopmentInstanceCreateProjection::class)->apply($operation, ['status' => $failure === 'cancelled' ? 'cancelled' : 'failed', 'run_id' => 'run-1', 'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(),
        'error_code' => $failure === 'cancelled' ? null : ($failure === 'infrastructure' ? 'instance.create_clone_failed' : 'instance.container_process_inactive'),
        'result' => ['instance_id' => $operation->target_id, 'failed_step' => 'register', 'process_failure' => $facts, 'error_code' => 'instance.container_process_inactive']]);
    $retry = $operation->fresh()->result['recovery']['retry'];
    if ($failure === 'project') {
        expect($retry['requires'] ?? null)->toBe('project_process_definition');
    } else {
        expect($retry)->not->toHaveKey('requires');
    }
})->with(['project', 'builtin', 'foreign', 'infrastructure', 'cancelled']);

it('rolls back a refused retry Process set and admits new definitions on a reserved retry', function (): void {
    $node = containerDefaultNode();
    $project = containerDefaultProject();
    $old = ProcessDefinition::query()->create(['project_id' => $project->id, 'name' => 'old', 'environments' => ['development'],
        'spec' => ['runtime' => 'systemd', 'command' => ['/bin/false']]]);
    $first = containerCreate($project, $node);
    $instance = Instance::query()->findOrFail($first->target_id);
    $original = $instance->processes()->sole();
    app(CancelOperationAction::class)->execute($first);
    $old->delete();
    $new = ProcessDefinition::query()->create(['project_id' => $project->id, 'name' => 'new', 'environments' => ['development'],
        'spec' => ['runtime' => 'docker', 'image' => 'example/worker', 'command' => []]]);
    expect(containerRefusal(fn () => containerCreate($project, $node)))->toBe('process.target_unsupported')
        ->and($instance->processes()->sole()->id)->toBe($original->id)
        ->and($instance->fresh()->status)->toBe(InstanceState::Reserved)
        ->and(Operation::query()->where('target_id', $instance->id)->where('kind', 'instance.create')->count())->toBe(1);
    $new->update(['spec' => ['runtime' => 'systemd', 'command' => ['/bin/sleep', '3600']]]);
    $retry = containerCreate($project, $node);
    expect($retry->target_id)->toBe($instance->id)
        ->and($instance->processes()->sole()->source_definition_id)->toBe($new->id)
        ->and(Process::query()->find($original->id))->toBeNull()
        ->and(containerIntentOf($retry)['processes'][0]['content'])->toContain('/bin/sleep');
});

it('runs the vite preset only in a clone; a deployment of the default removes the copy it recorded before', function (): void {
    $node = containerDefaultNode();
    $node->update(['tld' => 'beast.test']);
    Node::query()->where('name', 'control')->update(['ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key']);
    $this->mock(LeafCertificateSigner::class)->shouldReceive('rootCertificate')->andReturn('root-certificate');
    $default = seededDefault($node);
    $default->project->update(['type' => ProjectType::LaravelApp, 'php_version' => '8.5', 'root' => 'public']);
    ProcessDefinition::query()->create(['project_id' => $default->project_id, 'name' => 'vite', 'environments' => ['development'],
        'spec' => ['runtime' => 'systemd', 'preset' => 'vite', 'preset_version' => 1, 'command' => VitePreset::definitionCommand()]]);
    // The copy an earlier Gateway recorded on the default, which its deployments installed and started.
    $default->processes()->create(['name' => 'vite', 'runtime' => 'systemd', 'working_directory' => '/srv/workspace',
        'runtime_config' => ['preset' => 'vite', 'preset_version' => 1, 'command' => VitePreset::command($default)],
        'restart_policy' => 'on-failure', 'desired_state' => 'running', 'status' => 'active']);
    $this->mock(ProjectSourceReader::class)->shouldReceive('read')->once()->andReturn(['head' => str_repeat('c', 40), 'files' => [
        '.env.example' => ['mode' => '100644', 'object' => str_repeat('d', 40), 'content' => "APP_NAME=tool\n"],
        'composer.json' => ['mode' => '100644', 'object' => str_repeat('d', 40), 'content' => '{"require":{"php":"^8.5","laravel/framework":"^13.0"}}'],
        'artisan' => ['mode' => '100644', 'object' => str_repeat('d', 40), 'content' => 'entry']]]);

    $settle = static fn () => Operation::query()->update(['status' => OperationStatus::Succeeded, 'lock_key' => null]);
    $settle();
    $deployment = app(StartInstanceDeployAction::class)->execute($default, 'manual', null);
    $deployed = $deployment->input['deployment']['container'];
    $settle();

    expect($default->processes()->count())->toBe(0)
        ->and($deployed['processes'])->toBe([])
        ->and($deployed['units'][0]['content'])->not->toContain('INERTIA_SSR_HOT_URL');

    $operation = containerCreate($default->project, $node, 'feature');
    $clone = Instance::query()->findOrFail($operation->target_id);
    $container = containerIntentOf($operation);
    $site = app(DevelopmentSiteRepository::class)->workloadSite($clone, $clone->routes()->sole());

    expect($clone->processes()->sole()->runtime_config['preset'])->toBe('vite')
        ->and($container['processes'][0]['content'])->toContain('"/srv/workspace/node_modules/.bin/vite" "--host=0.0.0.0" "--port=5173" "--strictPort"',
            'Environment=VITE_APP_URL=https://'.$site->domain."\n")
        ->and($container['units'][0]['content'])->toContain("Environment=INERTIA_SSR_HOT_URL=http://127.0.0.1:5173\n")
        ->and(implode("\n", $container['environment']))->not->toContain('INERTIA_SSR_HOT_URL')
        ->and(array_column($container['acl_rules']['ingress'], 'destination_port'))->not->toContain('5173')
        ->and($site->devUpstream)->toBe($clone->runtime()->firstOrFail()->bridge_address);
});

it('preserves an active container Process after definition removal on create and deploy', function (): void {
    $node = containerDefaultNode();
    $project = containerDefaultProject();
    $definition = ProcessDefinition::query()->create(['project_id' => $project->id, 'name' => 'worker', 'environments' => ['development'],
        'spec' => ['runtime' => 'systemd', 'command' => ['/bin/sleep', '3600']]]);
    $projection = app(DevelopmentInstanceCreateProjection::class);
    $first = containerCreate($project, $node);
    $first->update(['status' => 'running', 'state' => ['swamp_run_id' => 'run-1']]);
    $receipt = ['status' => 'succeeded', 'run_id' => 'run-1', 'started_at' => now()->toISOString(), 'finished_at' => now()->toISOString(),
        'result' => ['ready' => true, 'instance_id' => $first->target_id, 'commit' => str_repeat('c', 40)]];
    $projection->apply($first, $receipt);
    $instance = Instance::query()->findOrFail($first->target_id);
    $before = $instance->processes()->sole()->getAttributes();
    $definition->delete();
    $repeat = containerCreate($project, $node);
    expect($repeat->target_id)->toBe($instance->id)
        ->and($instance->processes()->sole()->getAttributes())->toBe($before);
    $repeat->update(['status' => 'running', 'state' => ['swamp_run_id' => 'run-1']]);
    $projection->apply($repeat, $receipt);
    $this->mock(ProjectSourceReader::class)->shouldReceive('read')->once()->andReturn([
        'head' => str_repeat('c', 40), 'files' => [
            '.env.example' => ['mode' => '100644', 'object' => str_repeat('d', 40), 'content' => "APP_NAME=tool\n"],
            'composer.json' => null, 'artisan' => null,
        ],
    ]);
    $deployment = app(StartInstanceDeployAction::class)->execute($instance, 'manual', null);
    expect($deployment->kind)->toBe('instance.deploy')
        ->and($instance->processes()->sole()->getAttributes())->toBe($before);
});

it('keeps create dotenv byte-identical while the public origin belongs only to the dev-server unit', function (): void {
    $node = containerDefaultNode();
    $node->update(['tld' => 'beast.test']);
    Node::query()->where('name', 'control')->update(['ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key']);
    $this->mock(LeafCertificateSigner::class)->shouldReceive('rootCertificate')->andReturn('root-certificate');
    $default = seededDefault($node);
    $project = $default->project;
    $project->update(['type' => ProjectType::LaravelApp, 'php_version' => '8.5', 'root' => 'public', 'is_laravel' => true]);
    app(ProjectEnvironmentTemplateRefresh::class)->recordRead($project, str_repeat('d', 40), 'fixture',
        "APP_NAME=Fixture\nAPP_KEY=fixture-key\nASSET_MARKER=kept\n");
    ProcessDefinition::query()->create(['project_id' => $project->id, 'name' => 'dev', 'environments' => ['development'],
        'spec' => ['runtime' => 'systemd', 'preset' => 'vp-dev', 'command' => VpDevPreset::definitionCommand()]]);

    $operation = containerCreate($project, $node, 'origin-alias');
    $clone = Instance::query()->findOrFail($operation->target_id);
    $domain = $clone->routes()->whereNull('web_root')->sole()->domain;
    $contents = app(DevelopmentCreateEnvironment::class)->content($clone);
    $expected = 'APP_DEBUG="true"'."\n".'APP_ENV="development"'."\n".'APP_KEY="fixture-key"'."\n"
        .'APP_NAME="Fixture"'."\n".'APP_URL="https://'.$domain.'"'."\n".'ASSET_MARKER="kept"'."\n";

    expect($contents)->toBe($expected)->not->toContain('DEV_SERVER_ORIGIN');
    $unit = containerIntentOf($operation)['processes'][0]['content'];
    expect($unit)->toContain('Environment=DEV_SERVER_ORIGIN=https://'.$domain.'/__orbit/vite',
        'Environment=ORBIT_DEV_SERVER_ORIGIN=https://'.$domain.'/__orbit/vite');
    expect($clone->environmentValues()->whereIn('env_key', ['DEV_SERVER_ORIGIN', 'ORBIT_DEV_SERVER_ORIGIN'])->count())->toBe(0);
});

/**
 * A read-only connection on another Node with no Orbit Database server behind it, attached to the default under FOOD_CATALOG, as
 * hauzer's default has its food catalogue (docs/reference/database-connections.md#share-a-connection-with-clones).
 */
function foodCatalogue(Instance $default, bool $shared): DatabaseConnection
{
    $remote = containerDefaultNode('services', '10.44.0.3', listed: false);
    $connection = DatabaseConnection::query()->forceCreate(['slug' => 'food-reader', 'driver' => DatabaseDriver::Pgsql, 'node_id' => $remote->id,
        'host' => '10.44.0.3', 'port' => 5434, 'database' => 'food_catalog', 'username' => 'food_catalog_reader', 'password' => 'reader-secret-sentinel']);
    DatabaseConnectionTarget::query()->create(['instance_id' => $default->id, 'prefix' => 'FOOD_CATALOG',
        'database_connection_id' => $connection->id, 'network' => 'wireguard', 'shared' => $shared]);

    return $connection;
}

describe('a connection the default shares with its clones', function (): void {
    it('is attached to the clone with its keys and its network path, named shared, and its password reaches only the .env', function (): void {
        $node = containerDefaultNode();
        $default = seededDefault($node);
        $catalogue = foodCatalogue($default, shared: true);

        $operation = containerCreate($default->project, $node, 'feature');

        $clone = Instance::query()->findOrFail($operation->target_id);
        $target = DatabaseConnectionTarget::query()->where('instance_id', $clone->id)->sole();
        $keys = ['FOOD_CATALOG_CONNECTION', 'FOOD_CATALOG_DATABASE', 'FOOD_CATALOG_HOST', 'FOOD_CATALOG_PASSWORD', 'FOOD_CATALOG_PORT', 'FOOD_CATALOG_USERNAME'];
        $values = app(InstanceEnvironmentStore::class)->values($clone);
        $merge = $operation->input['environment_clone'];
        $container = containerIntentOf($operation);
        // The copy carries the mark, so the clone holds the attachment exactly as its default does.
        expect($target->only(['database_connection_id', 'prefix', 'network', 'shared']))
            ->toBe(['database_connection_id' => $catalogue->id, 'prefix' => 'FOOD_CATALOG', 'network' => 'wireguard', 'shared' => true])
            ->and(array_intersect_key($values, array_flip(['FOOD_CATALOG_HOST', 'FOOD_CATALOG_PORT', 'FOOD_CATALOG_DATABASE', 'FOOD_CATALOG_USERNAME', 'FOOD_CATALOG_PASSWORD'])))
            ->toEqual(['FOOD_CATALOG_HOST' => '10.44.0.3', 'FOOD_CATALOG_PORT' => '5434', 'FOOD_CATALOG_DATABASE' => 'food_catalog',
                'FOOD_CATALOG_USERNAME' => 'food_catalog_reader', 'FOOD_CATALOG_PASSWORD' => 'reader-secret-sentinel'])
            ->and($merge['shared'])->toBe($keys)
            ->and($merge['own'])->toContain(...$keys)
            ->and(array_intersect($merge['drop'], $keys))->toBe([])
            ->and(array_intersect($merge['guard'], $keys))->toBe([])
            // The path a migrate opens: the ACL egress, the bridge endpoint and the forward rule over WireGuard.
            ->and($container['acl_rules']['egress'])->toContain(['action' => 'allow', 'destination' => '10.44.0.3/32', 'protocol' => 'tcp', 'destination_port' => '5434', 'state' => 'enabled'])
            ->and($container['bridge_endpoints'])->toContain(['host' => '10.44.0.3', 'port' => 5434, 'docker' => false])
            ->and(collect($container['firewall'])->firstWhere('to_port', '5434'))->toMatchArray(['route' => 'yes', 'interface_in' => 'orbitinst0',
                'interface_out' => 'orbit', 'from_ip' => '10.253.0.3', 'to_ip' => '10.44.0.3', 'proto' => 'tcp']);

        // The password leaves the Gateway only in the .env the run writes: never in the Operation input or Activity.
        $vault = vaultReadingSocket([]);
        $run = app(DevelopmentInstanceCreateProjection::class)->input($operation);
        expect(collect($vault->puts)->firstWhere('key', 'instance-create-environment-input-'.$operation->id)['value'])->toContain('reader-secret-sentinel')
            ->and(json_encode($operation->refresh()->input))->not->toContain('reader-secret-sentinel')
            ->and(json_encode($run['intent']))->not->toContain('reader-secret-sentinel')
            ->and(Activity::query()->get()->toJson())->not->toContain('reader-secret-sentinel');
    });

    it('is left alone by a clone without the mark: the clone drops the keys exactly as before and gets no path', function (): void {
        $node = containerDefaultNode();
        $default = seededDefault($node);
        foodCatalogue($default, shared: false);

        $operation = containerCreate($default->project, $node, 'feature');

        $merge = $operation->input['environment_clone'];
        expect(DatabaseConnectionTarget::query()->where('instance_id', $operation->target_id)->exists())->toBeFalse()
            ->and($merge['drop'])->toBe(['FOOD_CATALOG_CONNECTION', 'FOOD_CATALOG_DATABASE', 'FOOD_CATALOG_HOST', 'FOOD_CATALOG_PASSWORD',
                'FOOD_CATALOG_PORT', 'FOOD_CATALOG_URL', 'FOOD_CATALOG_USERNAME'])
            ->and($merge['guard'])->toBe(['FOOD_CATALOG_DATABASE', 'FOOD_CATALOG_PASSWORD', 'FOOD_CATALOG_USERNAME'])
            ->and($merge['shared'])->toBe([])
            ->and(app(InstanceEnvironmentStore::class)->values(Instance::query()->findOrFail($operation->target_id)))->not->toHaveKey('FOOD_CATALOG_PASSWORD')
            ->and(json_encode(containerIntentOf($operation)['acl_rules']['egress']))->not->toContain('10.44.0.3');
    });

    it('stays when the clone is destroyed: the destroy only detaches it and never drops the database or its login', function (): void {
        $node = containerDefaultNode();
        $default = seededDefault($node);
        $catalogue = foodCatalogue($default, shared: true);
        // The clone as its create left it, with the shared attachment; a destroy treats every attachment the same.
        $clone = Instance::query()->findOrFail(containerCreate($default->project, $node, 'feature')->target_id);
        expect(DatabaseConnectionTarget::query()->where('instance_id', $clone->id)->value('shared'))->toBeTrue();

        $destroy = app(StartInstanceDestroyAction::class)->execute($clone, true);
        $database = ValidatedData::object(ValidatedData::object($destroy->input['direct_destroy'])['database']);
        app(ProjectDirectInstanceDestroyAction::class)->execute($destroy->input, []);

        expect($database['connection_ids'])->not->toContain($catalogue->id)
            ->and(json_encode($database))->not->toContain('food_catalog')
            ->and(Instance::query()->find($clone->id))->toBeNull()
            ->and(DatabaseConnection::query()->find($catalogue->id)?->username)->toBe('food_catalog_reader')
            ->and(DatabaseConnectionTarget::query()->where('database_connection_id', $catalogue->id)->pluck('instance_id')->all())->toBe([$default->id]);
    });
});

/** A seeded container default with the given development definitions, as its finished create leaves it. */
function seededDefaultWith(Node $node, array $definitions): Instance
{
    $project = containerDefaultProject();
    foreach ($definitions as $name => $spec) {
        ProcessDefinition::query()->create(['project_id' => $project->id, 'name' => $name, 'environments' => ['development'], 'spec' => $spec]);
    }
    $default = Instance::query()->findOrFail(containerCreate($project, $node)->target_id);
    $default->update(['status' => InstanceState::Active, 'first_setup_pending' => false]);
    $default->processes()->update(['status' => 'active']);
    InstanceRuntime::query()->where('instance_id', $default->id)->firstOrFail()
        ->update(['seed_commit' => str_repeat('c', 40), 'power_state' => InstanceRuntimePowerState::Running]);

    return $default->refresh();
}

describe('a Process that is stopped on the default', function (): void {
    it('is recorded in the clone with the default\'s intent: stopped only when stopped on purpose', function (string $desired, string $status, string $expected): void {
        $node = containerDefaultNode();
        $default = seededDefaultWith($node, ['worker' => ['runtime' => 'systemd', 'command' => ['/usr/bin/env', 'node', 'worker.js']]]);
        $default->processes()->update(['desired_state' => $desired, 'status' => $status]);

        $operation = containerCreate($default->project, $node, 'feature');

        $own = Process::query()->where('owner_id', $operation->target_id)->sole();
        expect($own->desired_state->value)->toBe($expected)
            ->and(array_column(containerIntentOf($operation)['processes'], 'desired'))->toBe([$expected]);
    })->with([
        'stopped on the default' => ['stopped', 'active', 'stopped'],
        'running on the default' => ['running', 'active', 'running'],
        'failed on the default, still desired running' => ['running', 'failed', 'running'],
    ]);

    it('stays stopped beside a dev-server preset on the same port, which still runs', function (): void {
        $node = containerDefaultNode();
        $default = seededDefaultWith($node, [
            'dev' => ['runtime' => 'systemd', 'command' => ['/usr/bin/env', 'vp', 'run', 'dev', '--', '--host=0.0.0.0']],
            'vp-dev' => ['runtime' => 'systemd', 'preset' => 'vp-dev', 'command' => VpDevPreset::definitionCommand()],
        ]);
        $default->processes()->where('name', 'dev')->update(['desired_state' => 'stopped']);

        $operation = containerCreate($default->project, $node, 'feature');

        $own = Process::query()->where('owner_id', $operation->target_id)->orderBy('name')->get();
        $units = collect(containerIntentOf($operation)['processes'])->mapWithKeys(static fn (array $unit): array => [$unit['name'] => $unit['desired']]);
        expect($own->map(static fn (Process $process): array => [$process->name, $process->desired_state->value])->all())
            ->toBe([['dev', 'stopped'], ['vp-dev', 'running']])
            ->and($units->all())->toBe([
                "orbit-process-{$own[0]->id}-dev.service" => 'stopped',
                "orbit-process-{$own[1]->id}-vp-dev.service" => 'running',
            ]);
    });

    it('stays stopped when a failed clone is created again', function (): void {
        $node = containerDefaultNode();
        $default = seededDefaultWith($node, ['worker' => ['runtime' => 'systemd', 'command' => ['/usr/bin/env', 'node', 'worker.js']]]);
        $default->processes()->update(['desired_state' => 'stopped']);
        $operation = containerCreate($default->project, $node, 'feature');
        $operation->forceFill(['status' => 'running', 'state' => ['swamp_run_id' => 'failure-run']])->save();
        app(DevelopmentInstanceCreateProjection::class)->apply($operation, [
            'status' => 'failed', 'run_id' => 'failure-run', 'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(),
            'error_code' => 'instance.create_workspace_failed', 'result' => ['instance_id' => $operation->target_id, 'ready' => false, 'failed_step' => 'workspace'],
        ]);

        $retry = containerCreate($default->project, $node, 'feature');

        expect($retry->target_id)->toBe($operation->target_id)
            ->and(Process::query()->where('owner_id', $retry->target_id)->sole()->desired_state->value)->toBe('stopped')
            ->and(array_column(containerIntentOf($retry)['processes'], 'desired'))->toBe(['stopped']);
    });

    it('is named in the create result; a clone whose Processes all run names none', function (string $desired, array $expected): void {
        $node = containerDefaultNode();
        $default = seededDefaultWith($node, [
            'queue' => ['runtime' => 'systemd', 'command' => ['/usr/bin/env', 'node', 'queue.js']],
            'worker' => ['runtime' => 'systemd', 'command' => ['/usr/bin/env', 'node', 'worker.js']],
        ]);
        $default->processes()->where('name', 'worker')->update(['desired_state' => $desired]);
        $operation = containerCreate($default->project, $node, 'feature');
        $operation->forceFill(['status' => 'running', 'finished_at' => null, 'error_code' => null, 'result' => null, 'state' => ['swamp_run_id' => 'run-1']])->save();
        vaultReadingSocket(['nckrtl-instance-t3-model-register-session-gateway-1-token' => 'gateway-session-value']);

        app(DevelopmentInstanceCreateProjection::class)->apply($operation->refresh(), cloneSucceeded($operation));

        expect($operation->refresh()->status)->toBe(OperationStatus::Succeeded)
            ->and($operation->result['processes_stopped'])->toBe($expected)
            ->and(Process::query()->where('owner_id', $operation->target_id)->pluck('status')->map->value->unique()->all())->toBe(['active']);
    })->with([
        'one stopped on the default' => ['stopped', ['worker']],
        'all running on the default' => ['running', []],
    ]);
});

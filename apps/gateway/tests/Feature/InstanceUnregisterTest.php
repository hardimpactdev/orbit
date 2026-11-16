<?php

declare(strict_types=1);

use App\Actions\Instances\StartInstanceDestroyAction;
use App\Actions\Instances\StartInstanceRestoreAction;
use App\Actions\Instances\StartInstanceUnregisterAction;
use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Instances\InstanceRemovalKind;
use App\Domain\Instances\InstanceRemovalStatus;
use App\Domain\Instances\InstanceRemovalStep;
use App\Domain\Instances\InstanceState;
use App\Domain\Instances\InstanceUnregisterProjection;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\RoutePublicationHolds;
use App\Domain\RemotePrograms\ProgramResult;
use App\Domain\RemotePrograms\RemoteProgramRunner;
use App\Domain\Routes\RouteStatus;
use App\Domain\Schedules\NativeSchedules;
use App\Domain\Schedules\ScheduleRuntimeAccount;
use App\Domain\Schedules\ScheduleRuntimeAccountResolver;
use App\Domain\Shared\ResourceOperationException;
use App\Http\Mcp\ApiDispatcher;
use App\Http\Mcp\ApiOperationTool;
use App\Http\Mcp\ToolManifest;
use App\Models\Activity;
use App\Models\DatabaseConnection;
use App\Models\DatabaseServer;
use App\Models\Instance;
use App\Models\InstanceRemoval;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Process;
use App\Models\Project;
use App\Models\Route;
use App\Models\Schedule;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Mcp\Request as McpRequest;
use Symfony\Component\Yaml\Yaml;

uses(LazilyRefreshDatabase::class);

const UNREGISTER_RUN = '7c1d2e3f-4a5b-4c6d-8e9f-0a1b2c3d4e5f';

/**
 * A host checkout Instance with a published Route, a Process, a Schedule, auto-deploy, an environment value and a SQLite record.
 * Its checkout is a real folder, so a test can prove that it survives.
 */
beforeEach(function (): void {
    Queue::fake();
    Http::preventStrayRequests();
    $this->mock(LeafCertificateSigner::class)->shouldReceive('rootCertificate')->andReturn('fixture-root-certificate');
    $this->mock(RemoteProgramRunner::class)->shouldReceive('run')->never();
    // Schedule trigger removal is Swamp's; the test records which Instances admission asked it for.
    $this->scheduleCalls = new ArrayObject;
    app()->instance(NativeSchedules::class, new class($this->scheduleCalls)
    {
        public function __construct(private ArrayObject $calls) {}

        /** @param list<int> $instanceIds */
        public function removeForInstances(array $instanceIds): void
        {
            $this->calls->append($instanceIds);
        }
    });
    // The Schedule's runtime account, as the Node agent reports it.
    $this->mock(ScheduleRuntimeAccountResolver::class)->shouldReceive('resolve')->andReturn(new ScheduleRuntimeAccount('orbit', 'orbit', '/home/orbit', '/bin/bash'));
    $this->root = sys_get_temp_dir().'/orbit-unregister-'.bin2hex(random_bytes(6));
    $this->checkout = $this->root.'/apps/fixture/default';
    mkdir($this->checkout.'/.git', 0o755, true);
    file_put_contents($this->checkout.'/.git/HEAD', "ref: refs/heads/main\n");
    file_put_contents($this->checkout.'/.env', "APP_NAME=fixture\n");
    file_put_contents($this->checkout.'/README.md', "kept\n");
    $this->node = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'unregister-node', 'status' => 'active', 'platform' => 'linux',
        'architecture' => 'x86_64', 'wireguard_ip' => '10.44.0.9', 'public_ssh_host' => '10.44.0.9', 'user' => 'orbit',
        'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key', 'ssh_host_fingerprint' => 'fixture']));
    $this->node->roles()->create(['role' => RoleName::AppDev, 'status' => 'active']);
    $this->swamp = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'control', 'status' => 'active', 'platform' => 'linux',
        'wireguard_ip' => '10.44.0.209', 'public_ssh_host' => '10.44.0.209', 'user' => 'orbit', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-control-key']));
    $this->swamp->roles()->create(['role' => RoleName::Swamp, 'status' => 'active']);
    $this->project = Project::query()->create(['name' => 'Unregister fixture', 'slug' => 'unregister-fixture', 'type' => 'node-package',
        'repository_url' => 'https://github.com/example/unregister-fixture.git', 'default_branch' => 'main', 'root' => 'public']);
    $this->instance = Instance::query()->create(['project_id' => $this->project->id, 'node_id' => $this->node->id, 'name' => 'default',
        'checkout_path' => $this->checkout, 'status' => 'active', 'source_layout' => 'checkout', 'root' => 'public',
        'branch' => 'main', 'starting_commit' => str_repeat('a', 40), 'auto_deploy' => true]);
    $this->route = Route::query()->create(['kind' => 'app', 'project_id' => $this->project->id, 'node_id' => $this->node->id,
        'provenance' => 'explicit', 'publication' => 'private', 'domain' => 'default.unregister.test', 'status' => 'pending']);
    $this->route->targets()->create(['instance_id' => $this->instance->id, 'position' => 0]);
    $this->route->update(['status' => 'active', 'sites_published' => true]);
    $this->process = Process::query()->create(['owner_type' => 'instance', 'owner_id' => $this->instance->id, 'name' => 'worker',
        'runtime' => 'systemd', 'runtime_config' => ['command' => ['/usr/bin/true']], 'desired_state' => 'running', 'status' => 'active']);
    $this->schedule = Schedule::query()->create(['target_type' => 'instance', 'target_id' => $this->instance->id, 'host_node_id' => $this->node->id,
        'name' => 'nightly', 'calendar' => '*-*-* 03:00:00', 'command' => 'true', 'timeout_seconds' => 60,
        'desired_timer_state' => 'enabled', 'status' => 'active', 'trigger' => 'swamp', 'swamp_workflow_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844']);
    DB::table('instance_environment_values')->insert(['instance_id' => $this->instance->id, 'env_key' => 'APP_KEY', 'env_value' => 'fixture']);
    DB::table('instance_deployments')->insert(['instance_id' => $this->instance->id, 'status' => 'succeeded', 'kind' => 'deploy', 'started_at' => now()]);
    $this->sqlite = DatabaseConnection::query()->create(['slug' => 'fixture-sqlite', 'driver' => 'sqlite', 'node_id' => $this->node->id,
        'path' => $this->checkout.'/database/database.sqlite', 'owner_instance_id' => $this->instance->id]);
    $this->unregister = fn (?Instance $instance = null): Operation => app(StartInstanceUnregisterAction::class)->execute(($instance ?? $this->instance)->fresh());
    $this->terminal = function (Operation $operation, string $status, array $result = []): Operation {
        $operation->forceFill(['status' => OperationStatus::Running, 'started_at' => now(), 'state' => [...($operation->state ?? []), 'swamp_run_id' => UNREGISTER_RUN]])->save();

        return app(InstanceUnregisterProjection::class)->apply($operation->fresh(), ['status' => $status, 'run_id' => UNREGISTER_RUN, 'result' => $result,
            'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String()]);
    };
});

afterEach(function (): void {
    new Filesystem()->deleteDirectory($this->root);
});

/**
 * The inputs schema of `operation-instance-unregister`, read from the workflow the Gateway starts.
 *
 * @return array<string, mixed>
 */
function unregisterWorkflowSchema(): array
{
    return Yaml::parseFile(base_path('../../swamp/workflows/workflow-operation-instance-unregister.yaml'))['inputs'];
}

function assertFitsUnregisterSchema(mixed $value, array $schema, string $path = 'inputs'): void
{
    $types = (array) ($schema['type'] ?? []);
    // A property without a type accepts any value, as Swamp's schema does.
    if ($types === [] && ! isset($schema['properties']) && ! isset($schema['items'])) {
        return;
    }
    if ($value instanceof stdClass) {
        expect(in_array('object', $types, true))->toBeTrue("{$path} is an object");
        $value = (array) $value;
    } elseif (is_array($value) && array_is_list($value) && $value !== []) {
        expect(in_array('array', $types, true))->toBeTrue("{$path} is an array");
    } elseif (is_int($value)) {
        expect(in_array('integer', $types, true))->toBeTrue("{$path} is an integer");
    } elseif (is_string($value)) {
        expect(in_array('string', $types, true))->toBeTrue("{$path} is a string");
    } elseif (is_bool($value)) {
        expect(in_array('boolean', $types, true))->toBeTrue("{$path} is a boolean");
    } elseif ($value === null) {
        expect(in_array('null', $types, true))->toBeTrue("{$path} may be null");
    }
    if (isset($schema['const'])) {
        expect($value)->toBe($schema['const'], "{$path} is {$schema['const']}");
    }
    if (in_array('object', $types, true) && is_array($value) && ! array_is_list($value)) {
        foreach ($schema['required'] ?? [] as $key) {
            expect(array_key_exists($key, $value))->toBeTrue("{$path}.{$key} is required");
        }
        foreach ($value as $key => $item) {
            $child = $schema['properties'][$key] ?? (is_array($schema['additionalProperties'] ?? null) ? $schema['additionalProperties'] : null);
            expect($child !== null || ($schema['additionalProperties'] ?? true) !== false)->toBeTrue("{$path}.{$key} is not in the workflow schema");
            if (is_array($child)) {
                assertFitsUnregisterSchema($item, $child, "{$path}.{$key}");
            }
        }
    }
    if (in_array('array', $types, true) && is_array($value) && array_is_list($value) && isset($schema['items'])) {
        foreach ($value as $index => $item) {
            assertFitsUnregisterSchema($item, $schema['items'], "{$path}[{$index}]");
        }
    }
}

function expectUnregisterRefusal(Closure $call, string $code): void
{
    try {
        $call();
        test()->fail("Expected {$code}.");
    } catch (ResourceOperationException $exception) {
        expect($exception->errorCode)->toBe($code);
    }
}

describe('a successful unregister', function (): void {
    it('deletes the Instance with its Route, Process, Schedule, auto-deploy and environment records, and keeps the checkout folder', function (): void {
        $operation = ($this->unregister)();
        expect($this->scheduleCalls->getArrayCopy())->toBe([[$this->instance->id]]);
        $removal = InstanceRemoval::query()->findOrFail($operation->input['removal_id']);
        expect($operation->kind)->toBe('instance.unregister')
            ->and($removal->kind)->toBe(InstanceRemovalKind::Unregister)
            ->and($this->instance->fresh()->status)->toBe(InstanceState::Removing)
            ->and($this->route->fresh()->status)->toBe(RouteStatus::Retiring)
            ->and(RoutePublicationHolds::nodes($operation))->toBe([$this->node->id])
            // 60 s for the steps, 330 s for the one Route, 180 s for the database records.
            ->and($operation->input['run_budget_seconds'])->toBe(570);

        ($this->terminal)($operation, 'succeeded', ['route_hold_release' => "{\"route_hold_released\":true,\"http_status\":\"200\"}\n"]);

        expect($operation->fresh()->status)->toBe(OperationStatus::Succeeded)
            ->and($operation->fresh()->result)->toMatchArray(['removed' => true])
            ->and(Instance::query()->find($this->instance->id))->toBeNull()
            ->and(Route::query()->find($this->route->id))->toBeNull()
            ->and(Process::query()->find($this->process->id))->toBeNull()
            ->and(Schedule::query()->find($this->schedule->id))->toBeNull()
            ->and(DatabaseConnection::query()->find($this->sqlite->id))->toBeNull()
            ->and(DB::table('instance_environment_values')->where('instance_id', $this->instance->id)->exists())->toBeFalse()
            ->and(DB::table('instance_deployments')->where('instance_id', $this->instance->id)->exists())->toBeFalse()
            ->and($removal->fresh()->status)->toBe(InstanceRemovalStatus::Completed)
            // The recorded checkout and its files stay as they were.
            ->and(is_dir($this->checkout))->toBeTrue()
            ->and(file_get_contents($this->checkout.'/README.md'))->toBe("kept\n")
            ->and(file_get_contents($this->checkout.'/.env'))->toBe("APP_NAME=fixture\n")
            ->and(file_get_contents($this->checkout.'/.git/HEAD'))->toBe("ref: refs/heads/main\n");
    });

    it('gives its Route Nodes back after the withdrawal, as destroy does', function (): void {
        $operation = ($this->unregister)();
        $operation->forceFill(['status' => OperationStatus::Running, 'started_at' => now(), 'state' => ['swamp_run_id' => UNREGISTER_RUN]])->save();

        $this->withServerVariables(['REMOTE_ADDR' => $this->swamp->wireguard_ip])
            ->postJson("/api/v1/direct-operations/{$operation->id}/route-release", ['run_id' => UNREGISTER_RUN])
            ->assertOk()->assertJsonPath('data.released', true);
        expect(DB::table('route_withdrawal_holds')->where('operation_id', $operation->id)->count())->toBe(0)
            ->and(InstanceRemoval::query()->findOrFail($operation->input['removal_id'])->route_withdrawn_at)->not->toBeNull();
    });

    it('answers the API with the Operation, records the removal in the activity, and replays a key after the Instance is gone', function (): void {
        $this->node->accessibleNodes()->attach($this->node);
        $call = fn (string $key) => $this->withServerVariables(['REMOTE_ADDR' => $this->node->wireguard_ip])
            ->postJson("/api/v1/instances/{$this->instance->id}/unregister", [], ['Idempotency-Key' => $key]);

        $first = $call('unregister-key')->assertStatus(202)->assertJsonPath('data.kind', 'instance.unregister')
            ->assertJsonPath('data.target_id', $this->instance->id);
        $operation = Operation::query()->findOrFail($first->json('data.id'));
        expect(Activity::query()->where('command', 'instance:unregister')->sole()->properties['removal']['id'] ?? null)->toBe($operation->id);

        ($this->terminal)($operation, 'succeeded');
        expect(Instance::query()->find($this->instance->id))->toBeNull();

        // The Instance is gone; the same key still finds its Node through the Operation and returns the same Operation.
        $call('unregister-key')->assertStatus(202)->assertJsonPath('data.id', $operation->id);
        $call('another-key')->assertNotFound();
        expect(Operation::query()->where('kind', 'instance.unregister')->count())->toBe(1);
    });
    it('requires an access grant to the Instance Node, before and after the Instance is gone', function (): void {
        $other = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'stranger', 'status' => 'active', 'platform' => 'linux',
            'wireguard_ip' => '10.44.0.77', 'public_ssh_host' => '10.44.0.77', 'user' => 'orbit', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-stranger-key']));
        $call = fn (Node $caller) => $this->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip])
            ->postJson("/api/v1/instances/{$this->instance->id}/unregister", [], ['Idempotency-Key' => 'stranger-key']);

        $call($other)->assertForbidden()->assertJsonPath('error.code', 'node_access.required');
        expect(Operation::query()->count())->toBe(0)->and($this->scheduleCalls->count())->toBe(0);

        $this->node->accessibleNodes()->attach($this->node);
        $operation = Operation::query()->findOrFail($call($this->node)->assertStatus(202)->json('data.id'));
        ($this->terminal)($operation, 'succeeded');
        $call($other)->assertForbidden()->assertJsonPath('error.code', 'node_access.required');
    });
    it('starts through the destructive MCP tool instance-unregister', function (): void {
        $this->node->accessibleNodes()->attach($this->node);
        $tools = collect(ToolManifest::default()->definitions())->keyBy('name');
        $definition = $tools->get('instance-unregister');
        $tool = new ApiOperationTool($definition, app(ApiDispatcher::class));
        expect($definition->method)->toBe('POST')->and($definition->startsOperation)->toBeTrue()
            ->and($tool->toArray()['annotations'])->toMatchArray(['readOnlyHint' => false, 'destructiveHint' => true])
            // The marker comes from the OpenAPI document: every DELETE, and only the listed POSTs.
            ->and($tools->get('instance-destroy')->destructive)->toBeTrue()
            ->and($tools->get('instance-restore')->destructive)->toBeFalse();

        $response = $tool->handle(new McpRequest(['instance' => $this->instance->id]),
            HttpRequest::create('/mcp', 'POST', server: ['REMOTE_ADDR' => $this->node->wireguard_ip]));
        $operation = json_decode((string) $response->content(), true, flags: JSON_THROW_ON_ERROR)['operation'];

        expect($operation)->toMatchArray(['kind' => 'instance.unregister', 'target_id' => $this->instance->id])
            ->and($this->instance->fresh()->status)->toBe(InstanceState::Removing);
    });
});

describe('the run intent', function (): void {
    it('holds the Route, the Processes and the hibernation files, and nothing of the source, teardown, databases or Git', function (): void {
        $operation = ($this->unregister)();
        $input = app(InstanceUnregisterProjection::class)->input($operation);
        $intent = $input['intent'];

        expect($input['execution_secrets'])->toBe([])
            ->and(array_keys($intent))->not->toContain('source_paths', 'push_check_paths', 'teardown_steps', 'source_capture', 'direct_destroy',
                'git_credential', 'repository', 'repository_identity', 'protected_source_roots', 'container_destroy', 'force', 'database')
            ->and(json_encode($intent, JSON_THROW_ON_ERROR))->not->toContain($this->checkout)
            ->and($intent['members'])->toBe([['instance_id' => $this->instance->id, 'route_id' => $this->route->id]])
            ->and(array_column($intent['processes'], 'name'))->toBe(["orbit-process-{$this->process->id}-worker.service"])
            ->and($intent['check_paths'])->toContain("/etc/systemd/system/orbit-process-{$this->process->id}-worker.service")
            ->and(count($intent['hibernation']))->toBe(3)
            ->and($intent['route_mutations'])->not->toBe([]);
    });

    it('drives DirectOperation::workflowInputs end to end into the workflow inputs schema', function (): void {
        $operation = ($this->unregister)();
        $inputs = app(DirectOperation::class)->workflowInputs($operation);

        expect(array_keys($inputs))->toEqualCanonicalizing(['intent', 'execution_secrets', 'operation_id', 'kind', 'node_id'])
            ->and(json_encode($inputs, JSON_THROW_ON_ERROR))->toContain('"execution_secrets":{}');
        assertFitsUnregisterSchema(json_decode(json_encode($inputs, JSON_THROW_ON_ERROR)), unregisterWorkflowSchema());
    });
});

describe('refusals before any change', function (): void {
    beforeEach(function (): void {
        $this->unchanged = function (): void {
            expect($this->scheduleCalls->count())->toBe(0)->and(Operation::query()->count())->toBe(0)->and(InstanceRemoval::query()->count())->toBe(0)
                ->and($this->instance->fresh()->status)->toBe(InstanceState::Active)
                ->and($this->route->fresh()->status)->toBe(RouteStatus::Active);
        };
    });

    it('refuses a container Instance', function (): void {
        $this->node->forceFill(['instance_bridge' => '10.253.0.0/24'])->save();
        $this->instance->runtime()->create(['node_id' => $this->node->id, 'incus_project' => 'orbit-instances',
            'container_name' => 'instance-'.$this->instance->id, 'bridge_address' => '10.253.0.2',
            'runtime_version' => 'v1', 'agent_version' => 'v1', 'power_state' => 'running']);

        expectUnregisterRefusal($this->unregister, 'instance.unregister_container');
        ($this->unchanged)();
    });

    it('refuses an Instance that owns a database on a Database server', function (): void {
        $server = Process::query()->create(['owner_type' => Node::class, 'owner_id' => $this->node->id, 'name' => 'mysql',
            'runtime' => 'docker', 'runtime_config' => ['image' => 'mysql:8.4'], 'desired_state' => 'running', 'status' => 'active']);
        $databaseServer = DatabaseServer::query()->create(['slug' => 'managed-database', 'driver' => 'mysql', 'node_id' => $this->node->id,
            'process_id' => $server->id, 'tag' => '8.4', 'port' => 3306, 'status' => 'active', 'root_password' => 'fixture-only']);
        DatabaseConnection::query()->create(['slug' => 'owned-mysql', 'driver' => 'mysql', 'host' => '10.44.0.9', 'port' => 3306,
            'database' => 'owned', 'username' => 'owned', 'password' => 'fixture', 'database_server_id' => $databaseServer->id,
            'owner_instance_id' => $this->instance->id]);

        expectUnregisterRefusal($this->unregister, 'instance.unregister_owned_database');
        ($this->unchanged)();
    });

    it('refuses a production Instance', function (): void {
        $production = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'prod-node', 'status' => 'active', 'platform' => 'linux',
            'wireguard_ip' => '10.44.0.10', 'public_ssh_host' => '10.44.0.10', 'user' => 'orbit', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-prod-key']));
        $production->roles()->create(['role' => RoleName::AppProd, 'status' => 'active']);
        $instance = Instance::query()->create(['project_id' => $this->project->id, 'node_id' => $production->id, 'name' => 'production',
            'checkout_path' => '/home/orbit/apps/production', 'status' => 'active', 'source_layout' => 'checkout', 'root' => 'public']);

        expectUnregisterRefusal(fn () => ($this->unregister)($instance), 'operation.kind_unavailable');
        expect(Operation::query()->count())->toBe(0);
    });

    it('refuses while another lifecycle Operation holds the Instance', function (): void {
        Operation::query()->create(['kind' => 'instance.create', 'target_type' => 'instance', 'target_id' => $this->instance->id,
            'node_id' => $this->node->id, 'lock_key' => 'instance:'.$this->instance->id, 'input' => [], 'status' => OperationStatus::Running,
            'state' => [], 'sequence' => 1, 'attempts' => 0]);

        expectUnregisterRefusal($this->unregister, 'operation.target_busy');
        expect($this->scheduleCalls->count())->toBe(0)->and(InstanceRemoval::query()->count())->toBe(0)->and($this->instance->fresh()->status)->toBe(InstanceState::Active);
    });
});

describe('a removal keeps its kind', function (): void {
    it('refuses destroy and restore of a failed unregister, and a rerun of unregister finishes it', function (): void {
        $operation = ($this->unregister)();
        ($this->terminal)($operation, 'failed', ['summary' => ['failed_steps' => ['Stop, remove Processes-0']], 'steps' => []]);
        expect($operation->fresh()->error_code)->toBe('instance.removal_incomplete')
            ->and(InstanceRemoval::query()->findOrFail($operation->input['removal_id'])->status)->toBe(InstanceRemovalStatus::Failed)
            ->and($this->instance->fresh()->status)->toBe(InstanceState::Removing)
            ->and(is_dir($this->checkout))->toBeTrue();

        expectUnregisterRefusal(fn () => app(StartInstanceDestroyAction::class)->execute($this->instance->fresh(), true), 'instance.removal_conflict');
        expectUnregisterRefusal(fn () => app(StartInstanceRestoreAction::class)->execute($this->instance->fresh()), 'instance.removal_conflict');

        $retry = ($this->unregister)();
        expect($retry->id)->not->toBe($operation->id)->and($retry->input['removal_id'])->toBe($operation->input['removal_id']);
        ($this->terminal)($retry, 'succeeded');
        expect(Instance::query()->find($this->instance->id))->toBeNull()->and(is_file($this->checkout.'/README.md'))->toBeTrue();
    });

    it('refuses to unregister an Instance whose destroy failed', function (): void {
        $this->app->instance(RemoteProgramRunner::class, Mockery::mock(RemoteProgramRunner::class, fn ($mock) => $mock->shouldReceive('run')
            ->andReturn(new ProgramResult('inspected', ['dependents' => []], ''))));
        $destroy = app(StartInstanceDestroyAction::class)->execute($this->instance, true);
        InstanceRemoval::query()->findOrFail($destroy->input['removal_id'])->update(['status' => InstanceRemovalStatus::Failed,
            'current_step' => InstanceRemovalStep::RuntimeCleanup, 'failed_step' => InstanceRemovalStep::RuntimeCleanup, 'error_code' => 'instance.removal_incomplete']);
        $destroy->update(['status' => OperationStatus::Failed, 'finished_at' => now()]);
        app(RoutePublicationHolds::class)->release($destroy->id);

        expectUnregisterRefusal($this->unregister, 'instance.removal_conflict');
    });
});

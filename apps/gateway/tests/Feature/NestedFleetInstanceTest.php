<?php

declare(strict_types=1);

use App\Actions\Instances\RegisterInstanceAction;
use App\Actions\Instances\StartInstanceCloneAction;
use App\Actions\Schedules\RunScheduleAction;
use App\Data\Instances\CloneInstanceData;
use App\Data\Instances\RegisterInstanceData;
use App\Domain\AgentView\AgentNodeView;
use App\Domain\AgentView\AgentStateView;
use App\Domain\AgentView\AgentViewFreshness;
use App\Domain\AgentView\AgentViewSubscriberHealth;
use App\Domain\Extensions\ExtensionStore;
use App\Domain\Instances\BaselineNormalInstanceContainer;
use App\Domain\Instances\BaselineNormalInstanceHostIntent;
use App\Domain\Instances\BaselineNormalInstanceImage;
use App\Domain\Instances\ContainerInstanceDestroy;
use App\Domain\Instances\InstanceContainer;
use App\Domain\Instances\InstanceHostIntent;
use App\Domain\Instances\InstanceImage;
use App\Domain\Nodes\NodeSettingsOperationCompletion;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmModelRoute;
use App\Http\Middleware\RequireActiveWireGuardPeer;
use App\Http\Middleware\RequireNodeAccess;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Models\Instance;
use App\Models\Node;
use App\Models\NodeFact;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Schedule;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;
use Tests\Support\SwampNodeFixture;

uses(LazilyRefreshDatabase::class);
require_once __DIR__.'/Domain/Instances/ContainerInstanceHelpers.php';
beforeEach(function (): void {
    Queue::fake();
    $this->withoutMiddleware([RequireActiveWireGuardPeer::class, RequireNodeAccess::class]);
});

describe('nested-fleet Project admission', function (): void {
    it('defaults off and updates through the API without source reads or Operations', function (): void {
        $project = containerDefaultProject();
        expect($project->refresh()->nested_fleet)->toBeFalse();
        $this->patchJson('/api/v1/projects/'.$project->id, ['nested_fleet' => true])->assertOk()->assertJsonPath('data.nested_fleet', true);
        $this->patchJson('/api/v1/projects/'.$project->id, ['name' => 'New name'])->assertOk()->assertJsonPath('data.nested_fleet', true);
        $this->patchJson('/api/v1/projects/'.$project->id, ['nested_fleet' => false])->assertOk()->assertJsonPath('data.nested_fleet', false);
        expect($project->refresh()->nested_fleet)->toBeFalse();
        $this->assertDatabaseCount('operations', 0);
    });
    it('requires a JSON boolean', function (mixed $value): void {
        $project = containerDefaultProject();
        $this->patchJson('/api/v1/projects/'.$project->id, ['nested_fleet' => $value])->assertUnprocessable()->assertJsonPath('error.code', 'validation.failed')->assertJsonStructure(['error' => ['details' => ['nested_fleet']]]);
        expect($project->refresh()->nested_fleet)->toBeFalse();
    })->with(['text' => ['true'], 'integer' => [1], 'null' => [null]]);
    it('refuses a placement change once the Project has any Instance', function (bool $container): void {
        $node = containerDefaultNode();
        $project = containerDefaultProject();
        $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'default', 'checkout_path' => '/test/default']);
        if ($container) {
            app(InstanceContainer::class)->reserve($instance, $node);
        }
        $this->patchJson('/api/v1/projects/'.$project->id, ['nested_fleet' => true])->assertStatus(409)->assertJsonPath('error.code', 'project.nested_fleet_in_use');
        expect($project->refresh()->nested_fleet)->toBeFalse();
        $this->patchJson('/api/v1/projects/'.$project->id, ['nested_fleet' => false])->assertOk();
    })->with([false, true]);
});

it('refuses nested checkout migration before reserving a runtime or Operation', function (): void {
    $node = containerDefaultNode();
    $project = containerDefaultProject();
    $project->update(['nested_fleet' => true]);
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'default',
        'checkout_path' => '/test/default', 'status' => 'active', 'first_setup_pending' => false]);
    $this->postJson('/api/v1/instances/'.$instance->id.'/migrate')->assertStatus(503)
        ->assertJsonPath('error.code', 'operation.kind_unavailable')
        ->assertJsonPath('error.message', 'Checkout migration into a nested-fleet container is not available.');
    $this->assertDatabaseCount('instance_runtimes', 0);
    $this->assertDatabaseCount('operations', 0);
    Queue::assertNothingPushed();
});

it('upgrades existing Projects with nested fleet off and rolls back without losing their data', function (): void {
    $previous = config('database.default');
    config(['database.connections.nested_upgrade' => ['driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true],
        'database.default' => 'nested_upgrade', 'queue.batching.database' => 'nested_upgrade']);
    $migration = '2027_02_07_000001_add_nested_fleet_to_projects.php';
    try {
        $paths = array_values(array_filter(glob(database_path('migrations/*.php')), fn (string $path): bool => basename($path) < $migration));
        Artisan::call('migrate', ['--database' => 'nested_upgrade', '--path' => $paths, '--realpath' => true, '--force' => true]);
        expect(Schema::hasColumn('projects', 'nested_fleet'))->toBeFalse();
        $id = DB::table('projects')->insertGetId(['repository_identity' => 'github.com/hardimpactdev/existing', 'code' => 'EXT', 'name' => 'Existing', 'slug' => 'existing', 'repository_url' => 'https://github.com/hardimpactdev/existing',
            'type' => 'node-package', 'default_branch' => 'main', 'root' => '.', 'created_at' => now(), 'updated_at' => now()]);
        $before = (array) DB::table('projects')->find($id);
        Artisan::call('migrate', ['--database' => 'nested_upgrade', '--path' => [database_path('migrations/'.$migration)], '--realpath' => true, '--force' => true]);
        expect((int) DB::table('projects')->where('id', $id)->value('nested_fleet'))->toBe(0);
        DB::table('projects')->where('id', $id)->update(['nested_fleet' => true]);
        Artisan::call('migrate:rollback', ['--database' => 'nested_upgrade', '--step' => 1, '--force' => true]);
        expect(Schema::hasColumn('projects', 'nested_fleet'))->toBeFalse()->and((array) DB::table('projects')->find($id))->toBe($before);
    } finally {
        DB::purge('nested_upgrade');
        config(['database.default' => $previous]);
    }
});

it('requires a JSON boolean for the Node hosting switch', function (mixed $value): void {
    $node = containerDefaultNode();
    $this->patchJson('/api/v1/nodes/'.$node->id, ['nested_fleet' => $value])->assertStatus(422);
    expect($node->refresh()->settings)->not->toHaveKey('nested_fleet');
})->with([['true'], [1], [null]]);

it('stores the Node switch and preserves it through unrelated record updates', function (): void {
    $node = containerDefaultNode();
    foreach ([true, false] as $enabled) {
        $this->patchJson('/api/v1/nodes/'.$node->id, ['nested_fleet' => $enabled])->assertOk();
        $this->patchJson('/api/v1/nodes/'.$node->id, ['name' => $enabled ? 'enabled' : 'disabled'])->assertOk();
        expect((bool) ($node->refresh()->settings['nested_fleet'] ?? false))->toBe($enabled);
    }
    $this->assertDatabaseCount('operations', 0);
});

it('merges only owned nested settings after successful asynchronous storage preparation', function (string $mode): void {
    SwampNodeFixture::ensure();
    $node = containerDefaultNode();
    $before = $mode !== 'enable';
    $node->update(['settings' => [...$node->settings, 'nested_fleet' => $before, 't3' => ['connection_id' => 'fixture']]]);
    $body = ['apps' => ['path' => '/home/orbit/review-apps']];
    if (in_array($mode, ['enable', 'disable'], true)) {
        $body['nested_fleet'] = ! $before;
    }
    $this->patchJson('/api/v1/nodes/'.$node->id, $body)->assertAccepted();
    $operation = Operation::query()->sole();
    expect($operation->input['settings_patch']['nested_fleet'])->toBe(array_key_exists('nested_fleet', $body));
    expect((bool) $node->refresh()->settings['nested_fleet'])->toBe($before);
    if ($mode === 'old capture') {
        $input = $operation->input;
        unset($input['settings_patch']['nested_fleet'], $input['settings']['nested_fleet']);
        $operation->update(['input' => $input]);
    }
    app(NodeSettingsOperationCompletion::class)->complete($operation,
        OperationOutcome::succeeded());
    expect((bool) ($node->refresh()->settings['nested_fleet'] ?? false))->toBe($body['nested_fleet'] ?? $before)
        ->and($node->settings['apps']['path'])->toBe('/home/orbit/review-apps')
        ->and($node->settings['t3'])->toBe(['connection_id' => 'fixture']);
})->with(['enable', 'disable', 'absent', 'old capture']);

it('records one placement for default, clone, image, workspace and destroy', function (bool $nested): void {
    $node = containerDefaultNode();
    $project = containerDefaultProject();
    $project->update(['nested_fleet' => $nested]);
    $node->update(['settings' => [...$node->settings, 'nested_fleet' => $nested]]);
    $default = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'default', 'checkout_path' => '/test/default']);
    $containers = app(InstanceContainer::class);
    $source = $containers->reserve($default, $node);
    $source->update(['seed_commit' => str_repeat('a', 40)]);
    $clone = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'feature', 'checkout_path' => '/test/feature']);
    $runtime = $containers->reserve($clone, $node, $default);
    $intent = $containers->intent($clone, $runtime);
    $expected = $nested ? 'orbit-instances-nested' : 'orbit-instances';
    expect($source->incus_project)->toBe($expected)->and($runtime->incus_project)->toBe($expected)
        ->and($intent['project'])->toBe($expected)->and($intent['image'])->toBe($nested ? 'orbit-instance-nested' : 'orbit-instance')
        ->and($intent['clone']['source'])->toBe($source->container_name)
        ->and(InstanceContainer::workspaceOnNode($clone))->toContain('/'.$expected.'_instance-'.$clone->id.'/rootfs/srv/workspace')
        ->and(app(ContainerInstanceDestroy::class)->capture($clone)['project'])->toBe($expected)
        ->and($intent['config']['limits.memory'])->toBe($nested ? '12GiB' : '8GiB');
    if ($nested) {
        expect($intent['config'])->toMatchArray(['security.nesting' => 'true', 'security.syscalls.intercept.mknod' => 'true',
            'security.syscalls.intercept.setxattr' => 'true', 'security.idmap.isolated' => 'true', 'security.idmap.size' => '101000000'])
            ->and(array_keys($intent['allowed_devices']))->toBe(['root', 'eth0', 'orbit-runtime', 'orbit-agent', 't3'])
            ->and($intent['allowed_devices']['orbit-runtime'])->toBe(['type' => 'disk', 'source' => '/opt/orbit-runtime', 'path' => '/opt/orbit-runtime', 'readonly' => 'true', 'shift' => 'true']);
    } else {
        expect($intent['config'])->not->toHaveKeys(['security.nesting', 'security.syscalls.intercept.mknod', 'security.idmap.size'])
            ->and($intent)->not->toHaveKey('allowed_devices');
    }
})->with([false, true]);

it('prepares both projects and reserves subordinate IDs for inner Incus', function (): void {
    $node = containerDefaultNode();
    $node->update(['settings' => [...$node->settings, 'nested_fleet' => true]]);
    $host = app(InstanceHostIntent::class)->for($node);
    expect($host['project'])->toBe('orbit-instances')->and($host['nested']['project'])->toBe('orbit-instances-nested')

        ->and($host['nested']['image']['alias'])->toStartWith('orbit-instance-nested-')
        ->and($host['nested']['config']['security.idmap.size'])->toBe('101000000')
        ->and($host['pool'])->toBe('orbit-instances')->and($host['network'])->toBe('orbitinst0')
        ->and($host['ids'])->toBe(201 * 65536 + 2 * 101000000);
});

it('grows the host ID budget only for recorded nested runtimes on that Node', function (): void {
    $node = containerDefaultNode();
    $other = containerDefaultNode('shark', '10.44.0.8');
    foreach ([$node, $other] as $host) {
        $host->update(['settings' => [...$host->settings, 'nested_fleet' => true]]);
    }
    $project = containerDefaultProject();
    $project->update(['nested_fleet' => true]);
    $normal = Project::query()->create([...$project->only(['type', 'repository_url', 'default_branch', 'root']), 'name' => 'normal', 'slug' => 'normal', 'repository_url' => 'https://github.com/hardimpactdev/normal']);
    $create = function (Node $host, string $name, string $placement) use ($project, $normal): void {
        $instance = Instance::query()->create(['project_id' => ($placement === 'orbit-instances' ? $normal : $project)->id, 'node_id' => $host->id, 'name' => $name, 'checkout_path' => '/test/'.$name]);
        app(InstanceContainer::class)->reserve($instance, $host);
    };
    $create($node, 'nested-one', 'orbit-instances-nested');
    $create($node, 'nested-two', 'orbit-instances-nested');
    $create($node, 'normal', 'orbit-instances');
    $create($other, 'elsewhere', 'orbit-instances-nested');
    expect(app(InstanceHostIntent::class)->for($node)['ids'])->toBe(201 * 65536 + 4 * 101000000);
    expect(app(InstanceHostIntent::class)->for($other)['ids'])->toBe(201 * 65536 + 3 * 101000000);
});

it('validates a captured Instance Schedule run against the production workflow schema', function (bool $nested): void {
    $node = containerDefaultNode();
    $project = containerDefaultProject();
    $project->update(['nested_fleet' => $nested]);
    $node->update(['settings' => [...$node->settings, 'nested_fleet' => $nested]]);
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'default',
        'checkout_path' => '/test/default', 'status' => 'active', 'provisioning_step' => 'active', 'first_setup_pending' => false]);
    $runtime = app(InstanceContainer::class)->reserve($instance, $node);
    $schedule = Schedule::query()->create(['target_type' => 'instance', 'target_id' => $instance->id, 'host_node_id' => $node->id,
        'name' => 'nightly', 'calendar' => '*-*-* 03:00:00', 'command' => 'true', 'timeout_seconds' => 30, 'trigger' => 'swamp', 'desired_timer_state' => 'enabled', 'status' => 'active']);
    SwampNodeFixture::ensure();
    $frame = [];
    $socket = Mockery::mock(WebSocketClient::class);
    $socket->shouldReceive('connect', 'close')->andReturnNull();
    $socket->shouldReceive('isConnected')->andReturnTrue();
    $socket->shouldReceive('send')->andReturnUsing(function (array $sent) use (&$frame): void {
        $frame = $sent;
    });
    $socket->shouldReceive('receive')->andReturnUsing(function () use (&$frame): array {
        expect($frame['type'])->toBe('vault.put');

        return [['id' => $frame['id'], 'type' => 'vault.put', 'payload' => ['data' => ['vaultName' => $frame['payload']['vaultName'],
            'secretKey' => $frame['payload']['key'], 'vaultType' => 'local_encryption']]]];
    });
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.address' => '192.0.2.209', 'swamp.ca_path' => __FILE__, 'swamp.run_token' => 'fixture']);
    $operation = app(RunScheduleAction::class)->admit($schedule);
    $input = app(DirectOperation::class)->workflowInputs($operation);
    expect($input['intent']['container'])->toBe(['project' => $runtime->incus_project, 'name' => $runtime->container_name]);
    $workflow = Yaml::parseFile(base_path('../../swamp/workflows/workflow-task-command-run.yaml'));
    $validate = new Process(['deno', 'eval', <<<'JS'
import Ajv from "npm:ajv@8.17.1";
import addFormats from "npm:ajv-formats@3.0.1";
const {schema, input} = JSON.parse(await new Response(Deno.stdin.readable).text());
const ajv = new Ajv({strict: false});
addFormats(ajv);
if (!ajv.validate(schema, input)) throw new Error(JSON.stringify(ajv.errors));
JS]);
    $validate->setInput(json_encode(['schema' => $workflow['inputs'], 'input' => $input], JSON_THROW_ON_ERROR));
    $validate->run();
    expect($validate->isSuccessful())->toBeTrue($validate->getErrorOutput());
    expect($workflow['jobs'][0]['steps'][0]['task']['inputs']['argv'])->toContain('inputs.intent.container.project');
})->with([false, true]);

it('keeps a normal Nodes full host intent identical with an absent or false hosting switch', function (): void {
    $this->freezeTime();
    $node = containerDefaultNode();
    $node->update(['settings' => [...$node->settings, 't3' => ['connection_id' => 'fixture']]]);
    $before = app(InstanceHostIntent::class)->for($node);
    expect($before)->not->toHaveKey('nested')->and($before['ids'])->toBe(201 * 65536);
    $this->patchJson('/api/v1/nodes/'.$node->id, ['nested_fleet' => false])->assertOk();
    expect(app(InstanceHostIntent::class)->for($node->refresh()))->toBe($before)
        ->and($node->settings['t3'])->toBe(['connection_id' => 'fixture']);
    $this->patchJson('/api/v1/nodes/'.$node->id, ['nested_fleet' => true])->assertOk();
    expect(app(InstanceHostIntent::class)->for($node->refresh()))->toHaveKey('nested')
        ->and($node->settings['nested_fleet'])->toBeTrue();
    $this->patchJson('/api/v1/nodes/'.$node->id, ['name' => 'renamed'])->assertOk();
    expect($node->refresh()->settings['nested_fleet'])->toBeTrue();
    $this->assertDatabaseCount('operations', 0);
});

it('captures recorded folder refresh scopes after hosting is disabled', function (): void {
    $node = containerDefaultNode();
    $node->update(['settings' => [...$node->settings, 'nested_fleet' => true]]);
    $project = containerDefaultProject();
    $project->update(['nested_fleet' => true]);
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'default', 'checkout_path' => '/test/default']);
    app(InstanceContainer::class)->reserve($instance, $node);
    $node->update(['settings' => [...$node->settings, 'nested_fleet' => false]]);
    $host = app(InstanceHostIntent::class)->for($node);
    expect($host)->not->toHaveKey('nested')->and($host['runtime_projects'])->toBe(['orbit-instances', 'orbit-instances-nested'])
        ->and($host['ids'])->toBe(201 * 65536 + 101000000);
});

it('refuses a nested checkout result on a switched-on Node outside the container build list', function (): void {
    SwampNodeFixture::ensure();
    $node = containerDefaultNode('outside-build-list', '10.44.0.37', false);
    $node->update(['settings' => [...$node->settings, 'nested_fleet' => true]]);
    $project = containerDefaultProject();
    $project->update(['nested_fleet' => true]);
    expect(fn () => containerCreate($project, $node))->toThrow(function (ResourceOperationException $error): void {
        expect($error->errorCode)->toBe('operation.kind_unavailable')
            ->and($error->getMessage())->toContain('requires a container');
    });
    $this->assertDatabaseCount('instances', 0);
    $this->assertDatabaseCount('instance_runtimes', 0);
    $this->assertDatabaseCount('operations', 0);
    Queue::assertNothingPushed();
});

it('refuses existing checkout retries and checkout clone sources before reservation', function (string $name): void {
    SwampNodeFixture::ensure();
    $node = containerDefaultNode();
    $node->update(['settings' => [...$node->settings, 'nested_fleet' => true]]);
    $project = containerDefaultProject();
    $project->update(['nested_fleet' => true]);
    Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'default', 'checkout_path' => '/test/default']);
    expect(fn () => containerCreate($project, $node, $name))->toThrow(function (ResourceOperationException $error): void {
        expect($error->errorCode)->toBe('operation.kind_unavailable')
            ->and($error->getMessage())->toContain('requires a container');
    });
    $this->assertDatabaseCount('instances', 1);
    $this->assertDatabaseCount('instance_runtimes', 0);
    $this->assertDatabaseCount('operations', 0);
    Queue::assertNothingPushed();
})->with(['default', 'feature']);

it('keeps a clones source recorded placement independently of the Project flag', function (): void {
    $node = containerDefaultNode();
    $project = containerDefaultProject();
    $source = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'default', 'checkout_path' => '/test/default']);
    $containers = app(InstanceContainer::class);
    $node->update(['settings' => [...$node->settings, 'nested_fleet' => true]]);
    $project->update(['nested_fleet' => true]);
    $runtime = $containers->reserve($source, $node);
    $project->update(['nested_fleet' => false]);
    $clone = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'feature', 'checkout_path' => '/test/feature']);
    expect($containers->reserve($clone, $node, $source)->incus_project)->toBe('orbit-instances-nested');
});

it('keeps the full normal Node intent and image byte-identical to the pre-nesting contract', function (bool $explicitFalse): void {
    require_once base_path('tests/Fixtures/BaselineNormalInstanceHostIntent.php');
    $this->freezeTime();
    $node = containerDefaultNode();
    $settings = [...$node->settings, 't3' => ['connection_id' => 'fixture']];
    if ($explicitFalse) {
        $settings['nested_fleet'] = false;
    }
    $node->update(['settings' => $settings]);
    $expected = app(BaselineNormalInstanceHostIntent::class)->for($node);
    $actual = app(InstanceHostIntent::class)->for($node);
    expect($actual)->toBe($expected);
    expect(json_encode($actual, JSON_THROW_ON_ERROR))->toBe(json_encode($expected, JSON_THROW_ON_ERROR));
    expect(InstanceImage::for('10.253.0.1'))->toBe(
        BaselineNormalInstanceImage::for('10.253.0.1'));
})->with([false, true]);

it('admits nested default creation on an enabled Node with its image and recorded project', function (): void {
    SwampNodeFixture::ensure();
    $node = containerDefaultNode();
    $node->update(['settings' => [...$node->settings, 'nested_fleet' => true]]);
    $project = containerDefaultProject();
    $project->update(['nested_fleet' => true]);
    $operation = containerCreate($project, $node);
    $intent = containerIntentOf($operation);
    expect($intent['project'])->toBe('orbit-instances-nested')->and($intent['image'])->toBe('orbit-instance-nested')
        ->and($intent['config']['security.nesting'])->toBe('true');
    $this->assertDatabaseHas('instance_runtimes', ['instance_id' => $operation->target_id, 'incus_project' => 'orbit-instances-nested']);
});

it('refuses nested default and clone admission on a Node without the hosting switch', function (string $name): void {
    $node = containerDefaultNode();
    $project = containerDefaultProject();
    $project->update(['nested_fleet' => true]);
    expect(fn () => containerCreate($project, $node, $name))->toThrow(function (ResourceOperationException $error): void {
        expect($error->errorCode)->toBe('instance.nested_fleet_host_unavailable')->and($error->status)->toBe(409);
    });
    $this->assertDatabaseCount('instances', 0);
    $this->assertDatabaseCount('instance_runtimes', 0);
    $this->assertDatabaseCount('operations', 0);
})->with(['default', 'feature']);

it('refuses a nested retry whose existing container is recorded in the normal project', function (): void {
    $node = containerDefaultNode();
    $node->update(['settings' => [...$node->settings, 'nested_fleet' => true]]);
    $project = containerDefaultProject();
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'default', 'checkout_path' => '/test/default']);
    app(InstanceContainer::class)->reserve($instance, $node);
    $project->update(['nested_fleet' => true]);
    expect(fn () => containerCreate($project, $node))->toThrow(function (ResourceOperationException $error): void {
        expect($error->errorCode)->toBe('operation.kind_unavailable')->and($error->getMessage())->toContain('requires a container');
    });
    $this->assertDatabaseCount('instances', 1);
    $this->assertDatabaseCount('instance_runtimes', 1);
    $this->assertDatabaseCount('operations', 0);
    Queue::assertNothingPushed();
});

it('keeps the full normal default and clone intents byte-identical to the pre-nesting contract', function (bool $clone): void {
    require_once base_path('tests/Fixtures/BaselineNormalInstanceHostIntent.php');
    $this->freezeTime();
    $node = containerDefaultNode();
    $project = containerDefaultProject();
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'default', 'checkout_path' => '/test/default']);
    $containers = app(InstanceContainer::class);
    $runtime = $containers->reserve($instance, $node);
    if ($clone) {
        $runtime->update(['seed_commit' => str_repeat('a', 40)]);
        $source = $instance;
        $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'feature', 'checkout_path' => '/test/feature']);
        $runtime = $containers->reserve($instance, $node, $source);
    }
    $expected = app(BaselineNormalInstanceContainer::class)->intent($instance, $runtime);
    expect($containers->intent($instance, $runtime))->toBe($expected);
    expect(json_encode($containers->intent($instance, $runtime), JSON_THROW_ON_ERROR))->toBe(json_encode($expected, JSON_THROW_ON_ERROR));
})->with([false, true]);

it('admits only a clone whose source is recorded in the nested project', function (bool $nestedSource): void {
    SwampNodeFixture::ensure();
    app(ExtensionStore::class)->set('conn', true);
    app()->instance(TaskVmModelRoute::class, new class implements TaskVmModelRoute
    {
        public function assertReady(): void {}

        public function origin(): string
        {
            return 'http://10.44.0.3:8318';
        }

        public function key(): string
        {
            return 'placeholder-model-key';
        }
    });
    $node = containerDefaultNode();
    $node->update(['settings' => [...$node->settings, 'nested_fleet' => true]]);
    $project = containerDefaultProject();
    $project->update(['nested_fleet' => $nestedSource]);
    $default = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'default',
        'checkout_path' => '/test/default', 'status' => 'active', 'first_setup_pending' => false]);
    $source = app(InstanceContainer::class)->reserve($default, $node);
    $source->update(['seed_commit' => str_repeat('c', 40)]);
    $project->update(['nested_fleet' => true]);
    if (! $nestedSource) {
        expect(fn () => containerCreate($project, $node, 'feature'))->toThrow(function (ResourceOperationException $error): void {
            expect($error->errorCode)->toBe('operation.kind_unavailable')->and($error->getMessage())->toContain('requires a container');
        });
        $this->assertDatabaseCount('instances', 1);
        $this->assertDatabaseCount('instance_runtimes', 1);
        $this->assertDatabaseCount('operations', 0);
        Queue::assertNothingPushed();

        return;
    }
    $operation = containerCreate($project, $node, 'feature');
    $intent = containerIntentOf($operation);
    expect($intent['project'])->toBe('orbit-instances-nested')
        ->and($intent['clone']['source'])->toBe($source->container_name)
        ->and($intent['image'])->toBe('orbit-instance-nested');
    $this->assertDatabaseCount('instances', 2);
    $this->assertDatabaseCount('instance_runtimes', 2);
    $this->assertDatabaseCount('operations', 1);
})->with([false, true]);

it('refuses production cloning of a nested checkout before target reservation', function (): void {
    SwampNodeFixture::ensure();
    $node = containerDefaultNode();
    $target = containerDefaultNode('production', '10.44.0.9', false);
    $target->roles()->create(['role' => 'app-prod', 'status' => 'active']);
    $project = containerDefaultProject();
    $project->update(['nested_fleet' => true]);
    $candidate = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'default',
        'checkout_path' => '/test/default', 'status' => 'active', 'provisioning_step' => 'active', 'first_setup_pending' => false,
        'source_is_laravel' => false, 'branch' => 'main']);
    expect(fn () => app(StartInstanceCloneAction::class)->execute($candidate,
        new CloneInstanceData($target->id, 'production', 'production', null, null)))
        ->toThrow(function (ResourceOperationException $error): void {
            expect($error->errorCode)->toBe('operation.kind_unavailable')->and($error->status)->toBe(503)
                ->and($error->getMessage())->toBe('Production cloning of nested-fleet Instances is not available.');
        });
    $this->assertDatabaseCount('instances', 1);
    $this->assertDatabaseCount('instance_runtimes', 0);
    $this->assertDatabaseCount('operations', 0);
    Queue::assertNothingPushed();
});

it('admits normal checkout registration and refuses nested registration at both admission reads', function (string $mode): void {
    $this->freezeTime();
    $node = containerDefaultNode();
    Node::withoutEvents(fn () => $node->update(['ssh_host_fingerprint' => 'fixture']));
    $project = containerDefaultProject();
    $project->update(['nested_fleet' => $mode === 'early']);
    $path = '/home/orbit/apps/tool/default';
    NodeFact::query()->create(['node_id' => $node->id, 'kind' => 'checkout', 'subject' => $path,
        'digest' => str_repeat('a', 64), 'observed_at' => now(), 'received_at' => now(),
        'data' => ['application' => $path, 'top_level' => $path, 'common_dir' => $path.'/.git', 'linked' => false,
            'worktrees' => [$path], 'origin' => $project->repository_url, 'owner' => 'orbit', 'head' => str_repeat('c', 40),
            'branch' => 'main', 'default_branch' => 'main', 'artisan' => 'absent', 'composer' => null, 'env' => null]]);
    $view = Mockery::mock(AgentStateView::class);
    if ($mode === 'early') {
        $view->shouldNotReceive('subscriber');
    } else {
        $clock = (float) now()->format('U.u');
        $view->shouldReceive('subscriber')->andReturn(new AgentViewSubscriberHealth($clock, true, true, 1));
        $view->shouldReceive('node')->with($node->id)->andReturnUsing(function () use ($mode, $project, $node, $clock): AgentNodeView {
            if ($mode === 'during observation') {
                $project->update(['nested_fleet' => true]);
            }

            return new AgentNodeView($node->id, AgentViewFreshness::Fresh, receivedAt: $clock);
        });
    }
    app()->instance(AgentStateView::class, $view);
    $register = fn () => app(RegisterInstanceAction::class)->execute($node,
        new RegisterInstanceData($path, false, $project->id, 'default', null, null));
    if ($mode === 'normal') {
        $result = $register();
        expect($result)->toBeInstanceOf(Instance::class)->and($result->checkout_path)->toBe($path)
            ->and($result->status->value)->toBe('active');
        $this->assertDatabaseCount('instances', 1);
    } else {
        expect($register)->toThrow(function (ResourceOperationException $error): void {
            expect($error->errorCode)->toBe('operation.kind_unavailable')->and($error->status)->toBe(503);
        });
        expect($project->refresh()->nested_fleet)->toBeTrue();
        $this->assertDatabaseCount('instances', 0);
        Queue::assertNothingPushed();
    }
    $this->assertDatabaseCount('instance_runtimes', 0);
    $this->assertDatabaseCount('operations', 0);
})->with(['normal', 'early', 'during observation']);

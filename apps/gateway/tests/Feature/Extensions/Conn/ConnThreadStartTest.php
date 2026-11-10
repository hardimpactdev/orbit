<?php

declare(strict_types=1);

use App\Domain\Extensions\ExtensionStore;
use App\Domain\Instances\InstanceRuntimePowerState;
use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\RoleName;
use App\Domain\Projects\ProjectType;
use App\Domain\TaskVms\TaskVmModelRoute;
use App\Models\Instance;
use App\Models\InstanceRuntime;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Orbit\Extensions\Conn\Models\ConnEnvironment;

uses(LazilyRefreshDatabase::class);

require_once __DIR__.'/../../Domain/Instances/ContainerInstanceHelpers.php';

/* Threads in an Orbit Project (docs/reference/conn.md#threads-in-an-orbit-project): the Project list, the clone's create and its follow. */

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
    $this->beast = containerDefaultNode();
    $this->mini = containerDefaultNode('mini', '10.44.0.9');
    $this->phone = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'phone', 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.77', 'public_ssh_host' => '10.44.0.77', 'user' => 'orbit',
    ]));
    $this->phone->accessibleNodes()->attach($this->beast->id);
});

/** @param array<string, mixed> $body */
function threadCall(Node $caller, string $method, string $path, array $body = []): TestResponse
{
    return test()->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip])->json($method, '/api/v1/conn/'.$path, $body);
}

/** A Project's container default on the Node; `seeded` as its finished create leaves it, with its seed snapshot. */
function threadDefault(Node $node, string $slug, bool $seeded = true): Instance
{
    $project = Project::query()->create(['name' => ucfirst($slug), 'slug' => $slug, 'type' => ProjectType::NodePackage,
        'repository_url' => "https://github.com/nckrtl/{$slug}.git", 'default_branch' => 'main', 'root' => '.', 'is_laravel' => false, 'source_facts_captured_at' => now()]);
    $default = Instance::query()->findOrFail(containerCreate($project, $node)->target_id);
    if ($seeded) {
        $default->update(['status' => InstanceState::Active, 'first_setup_pending' => false]);
        InstanceRuntime::query()->where('instance_id', $default->id)->firstOrFail()
            ->update(['seed_commit' => str_repeat('c', 40), 'power_state' => InstanceRuntimePowerState::Running]);
    }

    return $default->refresh();
}

/** The admitted create; the test has no Swamp, so the start fails after admission recorded the Operation it names. */
function threadCreate(Node $caller, Project $project, string $name): Operation
{
    $response = threadCall($caller, 'POST', "projects/{$project->id}/instances", ['name' => $name])
        ->assertStatus(503)->assertJsonPath('error.code', 'swamp.start_unavailable');

    return Operation::query()->findOrFail($response->json('error.details.operation_id'));
}

describe('the Project list', function (): void {
    it('lists the Projects whose ready container default runs on a Node the caller reaches', function (): void {
        threadDefault($this->beast, 'zeta');
        threadDefault($this->beast, 'alpha');
        threadDefault($this->beast, 'unseeded', seeded: false);
        threadDefault($this->mini, 'elsewhere');

        $data = threadCall($this->phone, 'GET', 'projects')->assertOk()->json('data');

        expect(array_column($data, 'slug'))->toBe(['alpha', 'zeta'])
            ->and($data[0])->toMatchArray(['name' => 'Alpha', 'node_id' => $this->beast->id, 'node_name' => 'beast']);
    });

    it('refuses a caller without node access to any Node', function (): void {
        threadCall($this->mini, 'GET', 'projects')->assertStatus(403)->assertJsonPath('error.code', 'node_access.required');
    });
});

describe("a thread's clone", function (): void {
    it("admits instance.create on the default's Node for the caller, which follows it with Conn Operation show", function (): void {
        $default = threadDefault($this->beast, 'tool');

        $operation = threadCreate($this->phone, $default->project, 'fix-login-k3j9');

        $clone = Instance::query()->findOrFail($operation->target_id);
        expect($operation->kind)->toBe('instance.create')
            ->and($operation->node_id)->toBe($this->beast->id)
            ->and($operation->triggered_by)->toBe('node:'.$this->phone->id)
            ->and($clone->name)->toBe('fix-login-k3j9')
            ->and(InstanceRuntime::query()->where('instance_id', $clone->id)->value('clone_source_instance_id'))->toBe($default->id);
        threadCall($this->phone, 'GET', "operations/{$operation->id}")->assertOk()
            ->assertJsonPath('data.id', $operation->id)->assertJsonPath('data.target_id', $clone->id);
    });

    it('shows the create only to the Node that started it', function (): void {
        $default = threadDefault($this->beast, 'tool');
        $operation = threadCreate($this->phone, $default->project, 'fix-login-k3j9');
        $laptop = Node::withoutEvents(fn (): Node => Node::query()->create([
            'name' => 'laptop', 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.78', 'public_ssh_host' => '10.44.0.78', 'user' => 'orbit',
        ]));
        $laptop->accessibleNodes()->attach($this->beast->id);

        threadCall($laptop, 'GET', "operations/{$operation->id}")->assertNotFound();
    });

    it('refuses a name that means the default Instance', function (string $name): void {
        $default = threadDefault($this->beast, 'tool');

        threadCall($this->phone, 'POST', "projects/{$default->project_id}/instances", ['name' => $name])
            ->assertStatus(422)->assertJsonPath('error.code', 'validation.failed');
    })->with(['default', 'main', 'Not A Slug']);

    it('refuses a Project without a ready container default', function (): void {
        $default = threadDefault($this->beast, 'tool', seeded: false);

        threadCall($this->phone, 'POST', "projects/{$default->project_id}/instances", ['name' => 'fix-login-k3j9'])
            ->assertStatus(409)->assertJsonPath('error.code', 'instance.default_missing');
    });

    it("refuses a caller without node access to the default's Node", function (): void {
        $default = threadDefault($this->mini, 'tool');

        threadCall($this->phone, 'POST', "projects/{$default->project_id}/instances", ['name' => 'fix-login-k3j9'])
            ->assertStatus(403)->assertJsonPath('error.code', 'node_access.required')
            ->assertJsonPath('error.details.serving_node.name', 'mini');
        expect(Operation::query()->where('kind', 'instance.create')->count())->toBe(1);
    });
});

describe('the server list', function (): void {
    it("names a clone's Instance and its container's power state, and null for other servers", function (): void {
        $default = threadDefault($this->beast, 'tool');
        $clone = Instance::query()->findOrFail(threadCreate($this->phone, $default->project, 'fix-login-k3j9')->target_id);
        InstanceRuntime::query()->where('instance_id', $clone->id)->update(['power_state' => InstanceRuntimePowerState::Stopped]);
        $server = ConnEnvironment::query()->create(['environment_id' => 'env-clone', 'label' => 'tool / fix-login-k3j9', 'url' => 'http://10.44.0.7:3800',
            'node_id' => $this->beast->id, 'admin_session' => 'admin-token', 'admin_session_expires_at' => Carbon::now()->addDays(30), 'registered_at' => Carbon::now()]);
        $server->forceFill(['instance_id' => $clone->id])->save();
        ConnEnvironment::query()->create(['environment_id' => 'env-beast', 'label' => 'beast', 'url' => 'http://10.44.0.7:3773',
            'node_id' => $this->beast->id, 'admin_session' => 'admin-token', 'admin_session_expires_at' => Carbon::now()->addDays(30), 'registered_at' => Carbon::now()]);

        $data = collect(threadCall($this->phone, 'GET', 'environments')->assertOk()->json('data'))->keyBy('environment_id');

        expect($data['env-clone'])->toMatchArray(['instance_id' => $clone->id, 'power_state' => 'stopped'])
            ->and($data['env-beast'])->toMatchArray(['instance_id' => null, 'power_state' => null]);
    });
});

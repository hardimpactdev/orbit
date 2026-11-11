<?php

declare(strict_types=1);

use App\Actions\Instances\StartInstanceDestroyAction;
use App\Domain\Extensions\ExtensionStore;
use App\Domain\Instances\InstanceRuntimePowerState;
use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationStatus;
use App\Domain\Projects\ProjectType;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmModelRoute;
use App\Models\Cluster;
use App\Models\Instance;
use App\Models\InstanceRuntime;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
use App\Models\Project;
use App\Models\TaskVm;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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

/**
 * @param  array<string, mixed>  $body
 * @param  array<string, string>  $headers
 */
function threadCall(Node $caller, string $method, string $path, array $body = [], array $headers = []): TestResponse
{
    return test()->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip])->withHeaders($headers)->json($method, '/api/v1/conn/'.$path, $body);
}

function threadNode(string $name, string $ip): Node
{
    return Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => $name, 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => $ip, 'public_ssh_host' => $ip, 'user' => 'orbit',
    ]));
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

/**
 * The admitted create; the test has no Swamp, so the start fails after admission recorded the Operation it names.
 *
 * @param  array<string, string>  $headers
 */
function threadCreate(Node $caller, Project $project, string $name, array $headers = []): Operation
{
    $response = threadCall($caller, 'POST', "projects/{$project->id}/instances", ['name' => $name], $headers)
        ->assertAccepted()->assertJsonPath('data.status', 'queued');

    return Operation::query()->findOrFail($response->json('data.id'));
}

describe('the Project list', function (): void {
    it('lists reachable Projects including defaults that are not ready', function (): void {
        threadDefault($this->beast, 'zeta');
        threadDefault($this->beast, 'alpha');
        threadDefault($this->beast, 'unseeded', seeded: false);
        threadDefault($this->mini, 'elsewhere');

        $data = threadCall($this->phone, 'GET', 'projects')->assertOk()->json('data');

        expect(array_column($data, 'slug'))->toBe(['alpha', 'unseeded', 'zeta'])
            ->and($data[0])->toMatchArray(['name' => 'Alpha', 'node_id' => $this->beast->id, 'node_name' => 'beast']);
    });

    it('lists every ready default for a caller with Gateway access', function (): void {
        threadDefault($this->beast, 'alpha');
        threadDefault($this->mini, 'elsewhere');
        $gateway = threadNode('gateway', '10.44.0.2');
        $gateway->roles()->create(['role' => RoleName::Gateway, 'status' => 'active']);
        $operator = threadNode('orbit-ops', '10.44.0.5');
        $operator->accessibleNodes()->attach($gateway->id);

        expect(array_column(threadCall($operator, 'GET', 'projects')->assertOk()->json('data'), 'slug'))->toBe(['alpha', 'elsewhere']);
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
        $laptop = threadNode('laptop', '10.44.0.78');
        $laptop->accessibleNodes()->attach($this->beast->id);

        threadCall($laptop, 'GET', "operations/{$operation->id}")->assertNotFound();
        test()->withServerVariables(['REMOTE_ADDR' => $this->phone->wireguard_ip])
            ->call('POST', "/api/v1/conn/operations/{$operation->id}/cancel", server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: '{}')
            ->assertNotFound();
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

    it("refuses a caller without node access to the default's Node before it reads the name or the default", function (string $name, bool $seeded): void {
        $default = threadDefault($this->mini, 'tool', $seeded);

        threadCall($this->phone, 'POST', "projects/{$default->project_id}/instances", ['name' => $name])
            ->assertStatus(403)->assertJsonPath('error.code', 'node_access.required')
            ->assertJsonPath('error.details.serving_node.name', 'mini');
        expect(Operation::query()->where('kind', 'instance.create')->count())->toBe(1);
    })->with(['valid name' => ['fix-login-k3j9', true], 'default branch' => ['main', true], 'unseeded default' => ['fix-login-k3j9', false]]);

    it("never replays another Node's create for its Idempotency-Key", function (): void {
        $default = threadDefault($this->beast, 'tool');
        $operation = threadCreate($this->phone, $default->project, 'fix-login-k3j9', ['Idempotency-Key' => 'thread-k3j9']);
        $laptop = threadNode('laptop', '10.44.0.78');
        $laptop->accessibleNodes()->attach($this->mini->id);

        $replay = threadCall($laptop, 'POST', "projects/{$default->project_id}/instances", ['name' => 'fix-login-k3j9'], ['Idempotency-Key' => 'thread-k3j9']);

        expect($replay->status())->toBeIn([403, 409])
            ->and($replay->json('data.id'))->toBeNull()
            ->and($replay->json('error.code'))->not->toBe('swamp.start_unavailable')
            ->and(Operation::query()->where('kind', 'instance.create')->latest('id')->value('id'))->toBe($operation->id);
    });

    it("refuses a name another device's create holds, and resumes the caller's own", function (): void {
        $default = threadDefault($this->beast, 'tool');
        $first = threadCreate($this->phone, $default->project, 'fix-login-k3j9');
        $laptop = threadNode('laptop', '10.44.0.78');
        $laptop->accessibleNodes()->attach($this->beast->id);

        threadCall($laptop, 'POST', "projects/{$default->project_id}/instances", ['name' => 'fix-login-k3j9'])
            ->assertStatus(409)->assertJsonPath('error.code', 'instance.name_taken');
        threadCall($this->phone, 'POST', "projects/{$default->project_id}/instances", ['name' => 'fix-login-k3j9'])
            ->assertConflict()->assertJsonPath('error.code', 'operation.target_busy');

        expect(Operation::query()->where('kind', 'instance.create')->where('target_id', $first->target_id)->count())->toBe(1);
    });
});

describe('clone Operation progress', function (): void {
    it('returns ordered step names, states and safe metadata while running and after success or failure', function (OperationStatus $status, string $lastStatus, ?string $currentStep, ?string $failedStep): void {
        $steps = [
            ['name' => 'clone-container', 'job' => 'clone', 'status' => 'succeeded', 'started_at' => '2026-11-11T12:00:00Z', 'finished_at' => '2026-11-11T12:00:01Z'],
            ['name' => 'clone-workspace', 'job' => 'clone', 'status' => 'succeeded', 'started_at' => '2026-11-11T12:00:01Z', 'finished_at' => '2026-11-11T12:00:02Z'],
            ['name' => 'clone-t3', 'job' => 'clone', 'status' => 'succeeded', 'started_at' => '2026-11-11T12:00:02Z', 'finished_at' => '2026-11-11T12:00:03Z'],
            ['name' => 'clone-register', 'job' => 'clone', 'status' => $lastStatus, 'started_at' => '2026-11-11T12:00:03Z', 'finished_at' => $status->terminal() ? '2026-11-11T12:00:04Z' : null],
        ];
        $recorded = [];
        foreach ($steps as $step) {
            $recorded['clone:'.$step['name']] = [...$step, 'stdout' => 'private-command-output', 'stderr' => 'private-command-error',
                'secret' => 'private-secret', 'env' => ['TOKEN' => 'private-environment-value']];
        }
        $operation = Operation::query()->create([
            'kind' => 'instance.create', 'target_type' => 'instance', 'target_id' => 1, 'node_id' => $this->beast->id,
            'input' => [], 'state' => ['native_progress' => ['steps' => $recorded]], 'status' => $status,
            'sequence' => 8, 'attempts' => 1, 'triggered_by' => 'node:'.$this->phone->id,
            'current_step' => $currentStep, 'failed_step' => $failedStep,
        ]);

        threadCall($this->phone, 'GET', "operations/{$operation->id}?after_sequence=8")->assertOk()
            ->assertJsonPath('data.steps', $steps)
            ->assertJsonPath('data.current_step', $currentStep)
            ->assertJsonPath('data.failed_step', $failedStep);
    })->with([
        'running' => [OperationStatus::Running, 'running', 'clone-register', null],
        'succeeded' => [OperationStatus::Succeeded, 'succeeded', null, null],
        'failed' => [OperationStatus::Failed, 'failed', null, 'clone-register'],
    ]);

    it('returns 404 for a caller-owned kind outside the Conn allowlist', function (): void {
        $operation = Operation::query()->create([
            'kind' => 'instance.destroy', 'target_type' => 'instance', 'target_id' => 1, 'node_id' => $this->beast->id,
            'input' => ['caller_node_id' => $this->phone->id], 'state' => [], 'status' => OperationStatus::Running,
            'sequence' => 1, 'attempts' => 1, 'triggered_by' => 'node:'.$this->phone->id,
        ]);

        threadCall($this->phone, 'GET', "operations/{$operation->id}")->assertNotFound();
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

        expect($data['env-clone'])->toMatchArray(['instance_id' => $clone->id, 'power_state' => 'stopped',
            'project_id' => $default->project_id, 'project_name' => 'Tool', 'project_slug' => 'tool',
            'instance_name' => 'fix-login-k3j9', 'node_name' => 'beast', 'instance_url' => null])
            ->and($data['env-beast'])->toMatchArray(['instance_id' => null, 'power_state' => null,
                'project_id' => null, 'project_name' => null, 'project_slug' => null,
                'instance_name' => null, 'node_name' => null, 'instance_url' => null]);
    });
});

describe('the Instance name check', function (): void {
    it('answers an available name without writing any row or contacting a server', function (string $name): void {
        $default = threadDefault($this->beast, 'tool');
        $writes = [];
        DB::listen(function (QueryExecuted $query) use (&$writes): void {
            if (preg_match('/\A\s*(insert|update|delete|replace)\b/i', $query->sql) === 1) {
                $writes[] = $query->sql;
            }
        });

        threadCall($this->phone, 'GET', "projects/{$default->project_id}/instances/check?".http_build_query(['name' => $name]))
            ->assertOk()->assertJsonPath('data', ['name' => $name, 'available' => true, 'reason' => null, 'domain' => null]);

        expect($writes)->toBe([]);
        Http::assertNothingSent();
    })->with(['single character' => 'a', 'digit' => '0', 'hyphen inside' => 'fix-login', '63 characters' => str_repeat('a', 63)]);

    it('treats every Instance in the Project as taken across Nodes and states', function (InstanceState $state): void {
        $default = threadDefault($this->beast, 'tool');
        Instance::query()->create(['project_id' => $default->project_id, 'node_id' => $this->mini->id, 'name' => 'occupied', 'checkout_path' => '/srv/occupied', 'status' => $state]);

        threadCall($this->phone, 'GET', "projects/{$default->project_id}/instances/check?name=occupied")
            ->assertOk()->assertJsonPath('data.available', false)->assertJsonPath('data.reason', 'taken');
    })->with([InstanceState::Reserved, InstanceState::CheckoutPrepared, InstanceState::SourceResolved, InstanceState::Active]);

    it('keeps the name taken while its Instance is being removed', function (): void {
        $default = threadDefault($this->beast, 'tool');
        $create = threadCreate($this->phone, $default->project, 'occupied');
        $create->forceFill(['status' => 'succeeded', 'finished_at' => now()])->save();
        $instance = Instance::query()->findOrFail($create->target_id);
        $instance->update(['status' => InstanceState::Active, 'starting_commit' => str_repeat('c', 40), 'branch' => 'occupied']);
        app(StartInstanceDestroyAction::class)->execute($instance, true);
        expect($instance->fresh()->status)->toBe(InstanceState::Removing);

        threadCall($this->phone, 'GET', "projects/{$default->project_id}/instances/check?name=occupied")
            ->assertOk()->assertJsonPath('data.available', false)->assertJsonPath('data.reason', 'taken');
    });

    it('treats a pending create as taken even for the caller who can resume it', function (): void {
        $default = threadDefault($this->beast, 'tool');
        threadCreate($this->phone, $default->project, 'occupied');
        $default->project->update(['type' => ProjectType::LaravelApp, 'root' => 'public', 'php_version' => '8.5', 'root' => 'public']);
        $this->beast->update(['tld' => 'test']);
        $laptop = threadNode('laptop', '10.44.0.78');
        $laptop->accessibleNodes()->attach($this->beast->id);

        threadCall($this->phone, 'GET', "projects/{$default->project_id}/instances/check?name=occupied")
            ->assertOk()->assertJsonPath('data.reason', 'taken')->assertJsonPath('data.available', false)
            ->assertJsonPath('data.domain', 'occupied.tool.test');
        threadCall($laptop, 'GET', "projects/{$default->project_id}/instances/check?name=occupied")
            ->assertOk()->assertJsonPath('data.reason', 'taken')->assertJsonPath('data.available', false)
            ->assertJsonPath('data.domain', 'occupied.tool.test');
    });

    it('keeps names in other Projects available', function (): void {
        $default = threadDefault($this->beast, 'tool');
        $other = threadDefault($this->mini, 'other');
        Instance::query()->create(['project_id' => $other->project_id, 'node_id' => $this->mini->id, 'name' => 'free', 'checkout_path' => '/srv/free', 'status' => InstanceState::Active]);

        threadCall($this->phone, 'GET', "projects/{$default->project_id}/instances/check?name=free")
            ->assertOk()->assertJsonPath('data.available', true)->assertJsonPath('data.reason', null);
    });

    it('answers default for the reserved name and the Project default-branch alias', function (string $name): void {
        $default = threadDefault($this->beast, 'tool');
        $default->project->update(['default_branch' => 'trunk']);

        threadCall($this->phone, 'GET', "projects/{$default->project_id}/instances/check?name={$name}")
            ->assertOk()->assertJsonPath('data.available', false)->assertJsonPath('data.reason', 'default');
    })->with(['default', 'trunk']);

    it('answers invalid for each name format failure', function (string $name): void {
        $default = threadDefault($this->beast, 'tool');
        $default->project->update(['type' => ProjectType::LaravelApp, 'root' => 'public', 'php_version' => '8.5', 'root' => 'public']);
        $this->beast->update(['tld' => 'test']);

        threadCall($this->phone, 'GET', "projects/{$default->project_id}/instances/check?".http_build_query(['name' => $name]))
            ->assertOk()->assertJsonPath('data', ['name' => $name, 'available' => false, 'reason' => 'invalid', 'domain' => null]);
    })->with(['empty' => '', 'uppercase' => 'Fix', 'leading hyphen' => '-fix', 'trailing hyphen' => 'fix-',
        'underscore' => 'fix_login', 'dot' => 'fix.login', 'space' => 'fix login', 'padded' => ' fix ',
        'newline' => "fix\n", 'non-ASCII' => 'café', '64 characters' => str_repeat('a', 64)]);

    it('previews the Node TLD without creating a Route', function (): void {
        $default = threadDefault($this->beast, 'tool');
        $default->project->update(['type' => ProjectType::LaravelApp, 'root' => 'public', 'php_version' => '8.5', 'root' => 'public']);
        $this->beast->update(['tld' => 'node.test']);

        threadCall($this->phone, 'GET', "projects/{$default->project_id}/instances/check?name=fix")
            ->assertOk()->assertJsonPath('data.domain', 'fix.tool.node.test');

        $this->assertDatabaseCount('routes', 0);
    });

    it('previews the active Cluster TLD before the Node TLD', function (): void {
        $default = threadDefault($this->beast, 'tool');
        $default->project->update(['type' => ProjectType::SymfonyApp, 'php_version' => '8.5', 'root' => 'public']);
        $cluster = Cluster::query()->create(['name' => 'dev', 'tld' => 'cluster.test', 'state' => 'active']);
        $this->beast->update(['cluster_id' => $cluster->id, 'tld' => 'node.test']);

        threadCall($this->phone, 'GET', "projects/{$default->project_id}/instances/check?name=fix")
            ->assertOk()->assertJsonPath('data.domain', 'fix.tool.cluster.test');

        $this->assertDatabaseCount('routes', 0);
    });

    it('answers a null domain without a usable TLD', function (?string $tld): void {
        $default = threadDefault($this->beast, 'tool');
        $default->project->update(['type' => ProjectType::LaravelApp, 'root' => 'public', 'php_version' => '8.5', 'root' => 'public']);
        $this->beast->update(['tld' => $tld]);

        threadCall($this->phone, 'GET', "projects/{$default->project_id}/instances/check?name=fix")
            ->assertOk()->assertJsonPath('data.domain', null)->assertJsonPath('data.available', true);
    })->with([null]);

    it('returns 403 before reading names or readiness on an inaccessible Project', function (string $query, bool $seeded): void {
        $default = threadDefault($this->mini, 'hidden', seeded: $seeded);

        threadCall($this->phone, 'GET', "projects/{$default->project_id}/instances/check{$query}")
            ->assertForbidden()->assertJsonPath('error.code', 'node_access.required')->assertJsonMissingPath('data');
    })->with([['?name=default', true], ['?name=free', false], ['', true]]);

    it('keeps the existing ready-default Project scope', function (): void {
        $default = threadDefault($this->beast, 'tool', seeded: false);

        threadCall($this->phone, 'GET', "projects/{$default->project_id}/instances/check?name=free")
            ->assertConflict()->assertJsonPath('error.code', 'instance.default_missing');
    });

    it('returns 404 for an unknown Project as create does', function (): void {
        threadCall($this->phone, 'GET', 'projects/999999/instances/check?name=free')->assertNotFound();
    });

    it('returns 403 when the caller has no node access', function (): void {
        $default = threadDefault($this->beast, 'tool');

        threadCall($this->mini, 'GET', "projects/{$default->project_id}/instances/check?name=free")
            ->assertForbidden()->assertJsonPath('error.code', 'node_access.required');
    });

    it('returns 422 only for a malformed query', function (string $query): void {
        $default = threadDefault($this->beast, 'tool');

        threadCall($this->phone, 'GET', "projects/{$default->project_id}/instances/check{$query}")
            ->assertUnprocessable()->assertJsonPath('error.code', 'validation.failed');
    })->with(['missing' => '', 'array' => '?name[]=fix', 'unknown field' => '?name=fix&node_id=1']);

    it('keeps the handle registered while Conn is disabled', function (): void {
        $default = threadDefault($this->beast, 'tool');
        app(ExtensionStore::class)->set('conn', false);

        threadCall($this->phone, 'GET', "projects/{$default->project_id}/instances/check?name=free")
            ->assertJsonPath('error.code', 'extension.disabled');
    });
});

describe('the reachable Project directory', function (): void {
    it('keeps unreachable defaults hidden when another Instance makes the Project reachable', function (): void {
        $default = threadDefault($this->mini, 'hidden-default');
        Instance::query()->create(['project_id' => $default->project_id, 'node_id' => $this->beast->id, 'name' => 'visible',
            'checkout_path' => '/srv/visible', 'status' => InstanceState::Active]);

        threadCall($this->phone, 'GET', 'projects')->assertOk()->assertJsonPath('data.0', [
            'id' => $default->project_id, 'slug' => 'hidden-default', 'name' => 'Hidden-default',
            'node_id' => null, 'node_name' => null, 'instance_domain_suffix' => null,
            'cloneable' => false, 'cloneable_reason' => 'node_access.required',
        ]);
    });

    it('lists a reachable Project without a default with nullable Node fields', function (): void {
        $project = Project::query()->create(['name' => 'No default', 'slug' => 'no-default', 'type' => ProjectType::NodePackage,
            'repository_url' => 'https://github.com/hardimpactdev/tool.git', 'default_branch' => 'main', 'root' => '.']);
        Instance::query()->create(['project_id' => $project->id, 'node_id' => $this->beast->id, 'name' => 'feature', 'checkout_path' => '/srv/feature']);

        threadCall($this->phone, 'GET', 'projects')->assertOk()->assertJsonPath('data.0', [
            'id' => $project->id, 'slug' => 'no-default', 'name' => 'No default',
            'node_id' => null, 'node_name' => null, 'instance_domain_suffix' => null,
            'cloneable' => false, 'cloneable_reason' => 'instance.default_missing',
        ]);
        threadCall($this->phone, 'GET', "projects/{$project->id}/instances/check?name=free")
            ->assertConflict()->assertJsonPath('error.code', 'instance.default_missing');
    });

    it('shows Projects without any Instance to Gateway callers as project:list does', function (): void {
        $project = Project::query()->create(['name' => 'Empty', 'slug' => 'empty', 'type' => ProjectType::NodePackage, 'repository_url' => 'https://github.com/hardimpactdev/tool.git']);
        $this->beast->roles()->create(['role' => RoleName::Gateway, 'status' => 'active']);

        threadCall($this->beast, 'GET', 'projects')->assertOk()->assertJsonPath('data.0.id', $project->id)
            ->assertJsonPath('data.0.cloneable', false)->assertJsonPath('data.0.cloneable_reason', 'instance.default_missing')
            ->assertJsonPath('data.0.node_id', null);
    });

    it('keeps checkout defaults visible but refuses the same Project in create and the name check', function (): void {
        $project = Project::query()->create(['name' => 'Checkout', 'slug' => 'checkout', 'type' => ProjectType::NodePackage,
            'repository_url' => 'https://github.com/hardimpactdev/tool.git', 'default_branch' => 'main', 'root' => '.']);
        Instance::query()->create(['project_id' => $project->id, 'node_id' => $this->beast->id, 'name' => 'default',
            'checkout_path' => '/srv/checkout', 'status' => InstanceState::Active]);

        $reason = threadCall($this->phone, 'GET', 'projects')->assertOk()
            ->assertJsonPath('data.0.node_id', $this->beast->id)->assertJsonPath('data.0.cloneable', false)->json('data.0.cloneable_reason');

        expect($reason)->toBe('instance.default_missing');
        threadCall($this->phone, 'POST', "projects/{$project->id}/instances", ['name' => 'free'])
            ->assertConflict()->assertJsonPath('error.code', $reason);
        threadCall($this->phone, 'GET', "projects/{$project->id}/instances/check?name=free")
            ->assertConflict()->assertJsonPath('error.code', $reason);
    });

    it('lists inactive and unseeded defaults with the same refusal as create', function (bool $seeded): void {
        $default = threadDefault($this->beast, 'not-ready');
        if ($seeded) {
            $default->update(['status' => InstanceState::Reserved]);
        } else {
            $default->runtime()->update(['seed_commit' => null]);
        }

        $reason = threadCall($this->phone, 'GET', 'projects')->assertOk()
            ->assertJsonPath('data.0.cloneable', false)->json('data.0.cloneable_reason');

        expect($reason)->toBe('instance.default_missing');
        threadCall($this->phone, 'POST', "projects/{$default->project_id}/instances", ['name' => 'free'])
            ->assertConflict()->assertJsonPath('error.code', $reason);
        threadCall($this->phone, 'GET', "projects/{$default->project_id}/instances/check?name=free")
            ->assertConflict()->assertJsonPath('error.code', $reason);
    })->with(['inactive seeded default' => true, 'unseeded default' => false]);

    it('shares known source and placement refusals across list, create and name check', function (string $change, string $reason): void {
        $default = threadDefault($this->beast, 'tool');
        match ($change) {
            'source' => $default->project->update(['source_access' => 'gh_cli']),
            'defaults' => $default->project->update(['root' => null]),
            'profile' => $default->project->update(['source_profile_error' => 'project.source_defaults_incomplete']),
            'production' => $this->beast->roles()->create(['role' => RoleName::AppProd, 'status' => 'active']),
            'platform' => $this->beast->update(['platform' => 'macos']),
            'app-dev' => $this->beast->roles()->where('role', RoleName::AppDev)->delete(),
            'php' => $default->project->update(['type' => ProjectType::LaravelApp, 'root' => 'public', 'php_version' => null]),
        };

        threadCall($this->phone, 'GET', 'projects')->assertOk()
            ->assertJsonPath('data.0.cloneable', false)->assertJsonPath('data.0.cloneable_reason', $reason);
        threadCall($this->phone, 'POST', "projects/{$default->project_id}/instances", ['name' => 'free'])
            ->assertJsonPath('error.code', $reason);
        threadCall($this->phone, 'GET', "projects/{$default->project_id}/instances/check?name=free")
            ->assertJsonPath('error.code', $reason);
    })->with([
        ['source', 'operation.kind_unavailable'], ['defaults', 'project.source_defaults_incomplete'],
        ['profile', 'project.source_defaults_incomplete'], ['production', 'instance.candidate_required'],
        ['platform', 'instance.node_inactive'], ['app-dev', 'instance.node_not_app_dev'],
        ['php', 'project.source_defaults_incomplete'],
    ]);

    it('shares model router refusals across the three handles', function (): void {
        $default = threadDefault($this->beast, 'tool');
        app()->instance(TaskVmModelRoute::class, new class implements TaskVmModelRoute
        {
            public function assertReady(): void
            {
                throw new ResourceOperationException('ai_router.disabled', 'The model router is off.', 409);
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

        threadCall($this->phone, 'GET', 'projects')->assertOk()
            ->assertJsonPath('data.0.cloneable', false)->assertJsonPath('data.0.cloneable_reason', 'ai_router.disabled');
        threadCall($this->phone, 'POST', "projects/{$default->project_id}/instances", ['name' => 'free'])
            ->assertConflict()->assertJsonPath('error.code', 'ai_router.disabled');
        threadCall($this->phone, 'GET', "projects/{$default->project_id}/instances/check?name=free")
            ->assertConflict()->assertJsonPath('error.code', 'ai_router.disabled');
    });

    it('refuses only live task VM Nodes without changing the default readiness rule', function (string $state, bool $cloneable): void {
        $default = threadDefault($this->beast, 'tool');
        TaskVm::query()->create(['node_id' => $this->beast->id, 'provider' => 'incus', 'name' => 'tvm-test',
            'wireguard_ip' => '10.44.9.1', 'state' => $state]);

        threadCall($this->phone, 'GET', 'projects')->assertOk()
            ->assertJsonPath('data.0.cloneable', $cloneable)
            ->assertJsonPath('data.0.cloneable_reason', $cloneable ? null : 'instance.task_vm_node');
        if (! $cloneable) {
            threadCall($this->phone, 'POST', "projects/{$default->project_id}/instances", ['name' => 'free'])
                ->assertConflict()->assertJsonPath('error.code', 'instance.task_vm_node');
            threadCall($this->phone, 'GET', "projects/{$default->project_id}/instances/check?name=free")
                ->assertConflict()->assertJsonPath('error.code', 'instance.task_vm_node');
        }
    })->with(['live' => ['ready', false], 'destroyed' => ['destroyed', true]]);

    it('returns the shared domain suffix from the Cluster or Node TLD', function (bool $clustered): void {
        $default = threadDefault($this->beast, 'tool');
        $default->project->update(['type' => ProjectType::LaravelApp, 'root' => 'public', 'php_version' => '8.5']);
        $cluster = Cluster::query()->create(['name' => 'dev', 'tld' => 'cluster.test', 'state' => 'active']);
        $this->beast->update(['tld' => 'node.test', 'cluster_id' => $clustered ? $cluster->id : null]);
        $suffix = $clustered ? '.tool.cluster.test' : '.tool.node.test';
        $writes = [];
        DB::listen(function (QueryExecuted $query) use (&$writes): void {
            if (preg_match('/\A\s*(insert|update|delete|replace)\b/i', $query->sql) === 1) {
                $writes[] = $query->sql;
            }
        });

        threadCall($this->phone, 'GET', 'projects')->assertOk()
            ->assertJsonPath('data.0.cloneable', true)->assertJsonPath('data.0.cloneable_reason', null)
            ->assertJsonPath('data.0.instance_domain_suffix', $suffix);
        threadCall($this->phone, 'GET', "projects/{$default->project_id}/instances/check?name=free")
            ->assertOk()->assertJsonPath('data.domain', 'free'.$suffix);
        expect($writes)->toBe([]);
        Http::assertNothingSent();
    })->with(['Cluster TLD' => true, 'Node TLD' => false]);

    it('keeps non-web Projects cloneable with a null domain suffix', function (): void {
        threadDefault($this->beast, 'tool');
        $this->beast->update(['tld' => 'test']);

        threadCall($this->phone, 'GET', 'projects')->assertOk()
            ->assertJsonPath('data.0.cloneable', true)->assertJsonPath('data.0.instance_domain_suffix', null);
    });

    it('keeps queries bounded for 100 Projects on distinct Nodes', function (): void {
        $default = threadDefault($this->beast, 'tool');
        $default->project->update(['type' => ProjectType::LaravelApp, 'root' => 'public', 'php_version' => '8.5']);
        $cluster = Cluster::query()->create(['name' => 'dev', 'tld' => 'test', 'state' => 'active']);
        $this->beast->update(['cluster_id' => $cluster->id]);
        threadCall($this->phone, 'GET', 'projects')->assertOk();
        DB::enableQueryLog();
        DB::flushQueryLog();
        threadCall($this->phone, 'GET', 'projects')->assertOk()->assertJsonPath('data.0.cloneable', true);
        $small = count(DB::getQueryLog());
        DB::disableQueryLog();
        $nodeRows = $projectRows = [];
        for ($i = 1; $i < 100; $i++) {
            $nodeRows[] = ['id' => 1000 + $i, 'name' => 'node-'.$i, 'status' => 'active', 'platform' => 'linux',
                'architecture' => 'x86_64', 'wireguard_ip' => '10.50.0.'.$i, 'public_ssh_host' => '10.50.0.'.$i, 'user' => 'orbit', 'cluster_id' => $cluster->id];
            $projectRows[] = ['id' => 1000 + $i, 'code' => 'Q'.chr(65 + intdiv($i, 26)).chr(65 + $i % 26), 'name' => 'Project '.$i, 'slug' => 'project-'.$i, 'type' => 'laravel-app',
                'repository_url' => 'https://github.com/hardimpactdev/tool-'.$i.'.git', 'repository_identity' => 'github.com/hardimpactdev/tool-'.$i, 'default_branch' => 'main', 'root' => 'public',
                'php_version' => '8.5', 'source_facts_captured_at' => now()];
        }
        Node::query()->insert($nodeRows);
        Project::query()->insert($projectRows);
        $roles = $instances = $runtimes = $edges = [];
        for ($i = 1; $i < 100; $i++) {
            $roles[] = ['node_id' => 1000 + $i, 'role' => 'app-dev', 'status' => 'active'];
            $instances[] = ['id' => 1000 + $i, 'project_id' => 1000 + $i, 'node_id' => 1000 + $i, 'name' => 'default',
                'checkout_path' => '/srv/project-'.$i, 'status' => 'active'];
            $runtime = $default->runtime->getRawOriginal();
            unset($runtime['id'], $runtime['created_at'], $runtime['updated_at']);
            $runtimes[] = [...$runtime, 'instance_id' => 1000 + $i, 'node_id' => 1000 + $i, 'container_name' => 'instance-'.(1000 + $i)];
            $edges[] = 1000 + $i;
        }
        NodeRole::query()->insert($roles);
        Instance::query()->insert($instances);
        InstanceRuntime::query()->insert($runtimes);
        $this->phone->accessibleNodes()->attach($edges);
        DB::enableQueryLog();
        DB::flushQueryLog();

        $response = threadCall($this->phone, 'GET', 'projects')->assertOk()->assertJsonCount(100, 'data');
        $large = count(DB::getQueryLog());
        DB::disableQueryLog();

        expect(array_unique(array_column($response->json('data'), 'cloneable')))->toBe([true])
            ->and($large)->toBeLessThanOrEqual($small + 1);
    });

    it('classifies an invalid default-branch alias before default or taken', function (): void {
        $default = threadDefault($this->beast, 'tool');
        $default->project->update(['default_branch' => 'release/next']);

        threadCall($this->phone, 'GET', "projects/{$default->project_id}/instances/check?name=release%2Fnext")
            ->assertOk()->assertJsonPath('data.available', false)->assertJsonPath('data.reason', 'invalid');
    });

    it('enforces the complete domain length while keeping the free name available', function (int $lastLabelLength, bool $valid): void {
        $default = threadDefault($this->beast, 'tool');
        $default->project->update(['type' => ProjectType::LaravelApp, 'root' => 'public', 'php_version' => '8.5']);
        $tld = str_repeat('a', 63).'.'.str_repeat('b', 63).'.'.str_repeat('c', $lastLabelLength);
        $this->beast->update(['tld' => $tld]);
        $name = str_repeat('x', 63);
        $domain = $name.'.tool.'.$tld;

        threadCall($this->phone, 'GET', "projects/{$default->project_id}/instances/check?name={$name}")
            ->assertOk()->assertJsonPath('data.available', true)->assertJsonPath('data.reason', null)
            ->assertJsonPath('data.domain', $valid ? $domain : null);
        expect(strlen($domain))->toBe($valid ? 253 : 254);
    })->with(['253 characters' => [56, true], '254 characters' => [57, false]]);

    it('rejects a domain label over 63 characters while keeping the free name available', function (): void {
        $default = threadDefault($this->beast, 'tool');
        $default->project->update(['type' => ProjectType::LaravelApp, 'root' => 'public', 'php_version' => '8.5', 'slug' => str_repeat('p', 64)]);
        $this->beast->update(['tld' => 'test']);

        threadCall($this->phone, 'GET', "projects/{$default->project_id}/instances/check?name=free")
            ->assertOk()->assertJsonPath('data.available', true)->assertJsonPath('data.domain', null);
    });
});

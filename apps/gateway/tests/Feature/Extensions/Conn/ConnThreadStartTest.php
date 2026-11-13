<?php

declare(strict_types=1);

use App\Actions\Instances\StartDevelopmentInstanceCreateAction;
use App\Actions\Instances\StartInstanceDestroyAction;
use App\Data\Instances\CreateInstanceData;
use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Extensions\ExtensionStore;
use App\Domain\GitHub\GitHubApi;
use App\Domain\GitHub\GitHubAppCredentials;
use App\Domain\GitHub\GitHubAppStore;
use App\Domain\Instances\DevelopmentInstanceCreateProjection;
use App\Domain\Instances\InstanceContainer;
use App\Domain\Instances\InstanceLifecycleContributions;
use App\Domain\Instances\InstanceRuntimePowerState;
use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationStatus;
use App\Domain\Projects\ProjectType;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\SourceControl\GitBranchName;
use App\Domain\TaskVms\TaskVmModelRoute;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\Certificates\GatewayLeafCertificateSigner;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\NativeProcessRunner;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use App\Models\Cluster;
use App\Models\Instance;
use App\Models\InstanceRuntime;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Route;
use App\Models\TaskVm;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Orbit\Extensions\Conn\Models\ConnEnvironment;
use Symfony\Component\Yaml\Yaml;
use Tests\Support\TestOrbitHome;

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

        $data = threadCall($this->phone, 'GET', 'projects?include_unavailable=1')->assertOk()->json('data');

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

    it('checks readiness before Node access and names after access', function (string $name, bool $seeded, int $status, string $code): void {
        $default = threadDefault($this->mini, 'tool', $seeded);

        threadCall($this->phone, 'POST', "projects/{$default->project_id}/instances", ['name' => $name])
            ->assertStatus($status)->assertJsonPath('error.code', $code)
            ->assertJsonMissingPath('error.details.serving_node');
        expect(Operation::query()->where('kind', 'instance.create')->count())->toBe(1);
    })->with(['valid name' => ['fix-login-k3j9', true, 403, 'node_access.required'], 'default branch' => ['main', true, 403, 'node_access.required'], 'unseeded default' => ['fix-login-k3j9', false, 409, 'instance.default_missing']]);

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
        $default->project->update(['type' => ProjectType::LaravelApp, 'root' => 'public', 'php_version' => '8.5']);
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
        $default->project->update(['type' => ProjectType::LaravelApp, 'root' => 'public', 'php_version' => '8.5']);
        $this->beast->update(['tld' => 'test']);

        threadCall($this->phone, 'GET', "projects/{$default->project_id}/instances/check?".http_build_query(['name' => $name]))
            ->assertOk()->assertJsonPath('data', ['name' => $name, 'available' => false, 'reason' => 'invalid', 'domain' => null]);
    })->with(['empty' => '', 'uppercase' => 'Fix', 'leading hyphen' => '-fix', 'trailing hyphen' => 'fix-',
        'underscore' => 'fix_login', 'dot' => 'fix.login', 'space' => 'fix login', 'padded' => ' fix ',
        'newline' => "fix\n", 'non-ASCII' => 'café', '64 characters' => str_repeat('a', 64)]);

    it('previews the Node TLD without creating a Route', function (): void {
        $default = threadDefault($this->beast, 'tool');
        $default->project->update(['type' => ProjectType::LaravelApp, 'root' => 'public', 'php_version' => '8.5']);
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
        $default->project->update(['type' => ProjectType::LaravelApp, 'root' => 'public', 'php_version' => '8.5']);
        $this->beast->update(['tld' => $tld]);

        threadCall($this->phone, 'GET', "projects/{$default->project_id}/instances/check?name=fix")
            ->assertOk()->assertJsonPath('data.domain', null)->assertJsonPath('data.available', true);
    })->with([null]);

    it('checks readiness before access and access before the name on an inaccessible Project', function (string $query, bool $seeded, int $status, string $code): void {
        $default = threadDefault($this->mini, 'hidden', seeded: $seeded);

        threadCall($this->phone, 'GET', "projects/{$default->project_id}/instances/check{$query}")
            ->assertStatus($status)->assertJsonPath('error.code', $code)->assertJsonMissingPath('data')->assertJsonMissingPath('error.details.serving_node');
    })->with([['?name=default', true, 403, 'node_access.required'], ['?name=free', false, 409, 'instance.default_missing'], ['', true, 403, 'node_access.required']]);

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

        threadCall($this->phone, 'GET', 'projects?include_unavailable=1')->assertOk()->assertJson(['data' => [[
            'id' => $default->project_id, 'slug' => 'hidden-default', 'name' => 'Hidden-default',
            'node_id' => null, 'node_name' => null, 'instance_domain_suffix' => null,
            'cloneable' => false, 'cloneable_reason' => 'node_access.required',
        ]]]);
    });

    it('lists a reachable Project without a default with nullable Node fields', function (): void {
        $project = Project::query()->create(['name' => 'No default', 'slug' => 'no-default', 'type' => ProjectType::NodePackage,
            'repository_url' => 'https://github.com/hardimpactdev/tool.git', 'default_branch' => 'main', 'root' => '.']);
        Instance::query()->create(['project_id' => $project->id, 'node_id' => $this->beast->id, 'name' => 'feature', 'checkout_path' => '/srv/feature']);

        threadCall($this->phone, 'GET', 'projects?include_unavailable=1')->assertOk()->assertJson(['data' => [[
            'id' => $project->id, 'slug' => 'no-default', 'name' => 'No default',
            'node_id' => null, 'node_name' => null, 'instance_domain_suffix' => null,
            'cloneable' => false, 'cloneable_reason' => 'instance.default_missing',
        ]]]);
        threadCall($this->phone, 'GET', "projects/{$project->id}/instances/check?name=free")
            ->assertConflict()->assertJsonPath('error.code', 'instance.default_missing');
    });

    it('shows Projects without any Instance to Gateway callers as project:list does', function (): void {
        $project = Project::query()->create(['name' => 'Empty', 'slug' => 'empty', 'type' => ProjectType::NodePackage, 'repository_url' => 'https://github.com/hardimpactdev/tool.git']);
        $this->beast->roles()->create(['role' => RoleName::Gateway, 'status' => 'active']);

        threadCall($this->beast, 'GET', 'projects?include_unavailable=1')->assertOk()->assertJsonPath('data.0.id', $project->id)
            ->assertJsonPath('data.0.cloneable', false)->assertJsonPath('data.0.cloneable_reason', 'instance.default_missing')
            ->assertJsonPath('data.0.node_id', null);
    });

    it('keeps checkout defaults visible but refuses the same Project in create and the name check', function (): void {
        $project = Project::query()->create(['name' => 'Checkout', 'slug' => 'checkout', 'type' => ProjectType::NodePackage,
            'repository_url' => 'https://github.com/hardimpactdev/tool.git', 'default_branch' => 'main', 'root' => '.']);
        Instance::query()->create(['project_id' => $project->id, 'node_id' => $this->beast->id, 'name' => 'default',
            'checkout_path' => '/srv/checkout', 'status' => InstanceState::Active]);

        $reason = threadCall($this->phone, 'GET', 'projects?include_unavailable=1')->assertOk()
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

        $reason = threadCall($this->phone, 'GET', 'projects?include_unavailable=1')->assertOk()
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
            'unsupported-php' => $default->project->update(['type' => ProjectType::LaravelApp, 'root' => 'public', 'php_version' => '0.0']),
        };

        $response = threadCall($this->phone, 'GET', 'projects?include_unavailable=1')->assertOk()
            ->assertJsonPath('data.0.cloneable', false)->assertJsonPath('data.0.cloneable_reason', $reason);
        $failed = array_values(array_filter($response->json('data.0.checks'), fn (array $check): bool => $check['status'] === 'fail'));
        expect($failed)->toHaveCount(1)->and($failed[0]['message'])->toBeString()->not->toBeEmpty();
        threadCall($this->phone, 'POST', "projects/{$default->project_id}/instances", ['name' => 'free'])
            ->assertJsonPath('error.code', $reason);
        threadCall($this->phone, 'GET', "projects/{$default->project_id}/instances/check?name=free")
            ->assertJsonPath('error.code', $reason);
    })->with([
        ['source', 'operation.kind_unavailable'], ['defaults', 'project.source_defaults_incomplete'],
        ['profile', 'project.source_defaults_incomplete'], ['production', 'instance.candidate_required'],
        ['platform', 'instance.node_inactive'], ['app-dev', 'instance.node_not_app_dev'],
        ['php', 'project.source_defaults_incomplete'], ['unsupported-php', 'instance.host_unavailable'],
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

        $response = threadCall($this->phone, 'GET', 'projects?include_unavailable=1')->assertOk()
            ->assertJsonPath('data.0.cloneable', false)->assertJsonPath('data.0.cloneable_reason', 'ai_router.disabled');
        $failed = array_values(array_filter($response->json('data.0.checks'), fn (array $check): bool => $check['status'] === 'fail'));
        expect($failed)->toHaveCount(1)->and($failed[0]['message'])->toBeString()->not->toBeEmpty();
        threadCall($this->phone, 'POST', "projects/{$default->project_id}/instances", ['name' => 'free'])
            ->assertConflict()->assertJsonPath('error.code', 'ai_router.disabled');
        threadCall($this->phone, 'GET', "projects/{$default->project_id}/instances/check?name=free")
            ->assertConflict()->assertJsonPath('error.code', 'ai_router.disabled');
    });

    it('refuses only live task VM Nodes without changing the default readiness rule', function (string $state, bool $cloneable): void {
        $default = threadDefault($this->beast, 'tool');
        TaskVm::query()->create(['node_id' => $this->beast->id, 'provider' => 'incus', 'name' => 'tvm-test',
            'wireguard_ip' => '10.44.9.1', 'state' => $state]);

        $response = threadCall($this->phone, 'GET', 'projects?include_unavailable=1')->assertOk()
            ->assertJsonPath('data.0.cloneable', $cloneable)
            ->assertJsonPath('data.0.cloneable_reason', $cloneable ? null : 'instance.task_vm_node');
        if (! $cloneable) {
            $response->assertJsonPath('data.0.checks.14.message', fn (string $message): bool => $message !== '');
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

        threadCall($this->phone, 'GET', 'projects?include_unavailable=1')->assertOk()
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

        threadCall($this->phone, 'GET', 'projects?include_unavailable=1')->assertOk()
            ->assertJsonPath('data.0.cloneable', true)->assertJsonPath('data.0.instance_domain_suffix', null);
    });

    it('keeps queries bounded for 100 Projects on distinct Nodes', function (string $query): void {
        $default = threadDefault($this->beast, 'tool');
        $default->project->update(['type' => ProjectType::LaravelApp, 'root' => 'public', 'php_version' => '8.5']);
        $cluster = Cluster::query()->create(['name' => 'dev', 'tld' => 'test', 'state' => 'active']);
        $this->beast->update(['cluster_id' => $cluster->id]);
        threadCall($this->phone, 'GET', 'projects'.$query)->assertOk();
        DB::enableQueryLog();
        DB::flushQueryLog();
        threadCall($this->phone, 'GET', 'projects'.$query)->assertOk()->assertJsonPath('data.0.cloneable', true);
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

        $response = threadCall($this->phone, 'GET', 'projects'.$query)->assertOk()->assertJsonCount(100, 'data');
        $large = count(DB::getQueryLog());
        DB::disableQueryLog();

        expect(array_unique(array_column($response->json('data'), 'cloneable')))->toBe([true])
            ->and($large)->toBeLessThanOrEqual($small + 1)
            ->and(array_unique(array_map('count', array_column($response->json('data'), 'checks'))))->toBe([15]);
    })->with(['compatible default' => '', 'full directory' => '?include_unavailable=1']);

    it('classifies an invalid default-branch alias before default or taken', function (): void {
        $default = threadDefault($this->beast, 'tool');
        $default->project->update(['default_branch' => 'release/next']);

        threadCall($this->phone, 'GET', "projects/{$default->project_id}/instances/check?name=release%2Fnext")
            ->assertOk()->assertJsonPath('data.available', false)->assertJsonPath('data.reason', 'invalid');
    });

    it('refuses an uncreatable domain in the name check and both create admissions before any Operation', function (int $lastLabelLength, bool $valid): void {
        $default = threadDefault($this->beast, 'tool');
        $default->project->update(['type' => ProjectType::LaravelApp, 'root' => 'public', 'php_version' => '8.5']);
        $tld = str_repeat('a', 63).'.'.str_repeat('b', 63).'.'.str_repeat('c', $lastLabelLength);
        $this->beast->update(['tld' => $tld]);
        $name = str_repeat('x', 63);
        $domain = $name.'.tool.'.$tld;

        threadCall($this->phone, 'GET', "projects/{$default->project_id}/instances/check?name={$name}")
            ->assertOk()->assertJsonPath('data.available', $valid)->assertJsonPath('data.reason', $valid ? null : 'domain_invalid')
            ->assertJsonPath('data.domain', $valid ? $domain : null);
        expect(strlen($domain))->toBe($lastLabelLength === 56 ? 253 : 254);
        if (! $valid) {
            $operations = Operation::query()->count();
            $instances = Instance::query()->count();
            threadCall($this->phone, 'POST', "projects/{$default->project_id}/instances", ['name' => $name])
                ->assertUnprocessable()->assertJsonPath('error.code', 'route.domain_invalid');
            $this->withServerVariables(['REMOTE_ADDR' => $this->phone->wireguard_ip])
                ->postJson('/api/v1/instances', ['project_id' => $default->project_id, 'node_id' => $this->beast->id, 'name' => $name])
                ->assertUnprocessable()->assertJsonPath('error.code', 'route.domain_invalid');
            expect(containerRefusal(fn () => containerCreate($default->project, $this->beast, $name)))->toBe('route.domain_invalid')
                ->and(Operation::query()->count())->toBe($operations)
                ->and(Instance::query()->count())->toBe($instances);
        }
    })->with(['253 characters still exceed the live CSR limit' => [56, false], '254 characters exceed DNS' => [57, false]]);

    it('refuses a 65 character domain at the name check and all create boundaries before any Operation', function (): void {
        $default = threadDefault($this->beast, 'tool');
        $default->project->update(['type' => ProjectType::LaravelApp, 'root' => 'public', 'php_version' => '8.5']);
        $this->beast->update(['tld' => 'test']);
        $name = str_repeat('n', 55);
        expect(strlen($name.'.tool.test'))->toBe(65);
        $operations = Operation::query()->count();
        $instances = Instance::query()->count();

        threadCall($this->phone, 'GET', "projects/{$default->project_id}/instances/check?name={$name}")
            ->assertOk()->assertJsonPath('data', ['name' => $name, 'available' => false, 'reason' => 'domain_invalid', 'domain' => null]);
        threadCall($this->phone, 'POST', "projects/{$default->project_id}/instances", ['name' => $name])
            ->assertUnprocessable()->assertJsonPath('error.code', 'route.domain_invalid');
        $this->withServerVariables(['REMOTE_ADDR' => $this->phone->wireguard_ip])
            ->postJson('/api/v1/instances', ['project_id' => $default->project_id, 'node_id' => $this->beast->id, 'name' => $name])
            ->assertUnprocessable()->assertJsonPath('error.code', 'route.domain_invalid');
        expect(containerRefusal(fn () => containerCreate($default->project, $this->beast, $name)))->toBe('route.domain_invalid')
            ->and(Operation::query()->count())->toBe($operations)
            ->and(Instance::query()->count())->toBe($instances);
    });

    it('admits a 64 character domain with the installed-client response and records its Route before the Operation', function (): void {
        $default = threadDefault($this->beast, 'tool');
        $default->project->update(['type' => ProjectType::LaravelApp, 'root' => 'public', 'php_version' => '8.5']);
        $this->beast->update(['tld' => 'test']);
        $name = str_repeat('n', 54);
        $domain = $name.'.tool.test';
        expect(strlen($domain))->toBe(64);
        threadCall($this->phone, 'GET', "projects/{$default->project_id}/instances/check?name={$name}")
            ->assertOk()->assertJsonPath('data', ['name' => $name, 'available' => true, 'reason' => null, 'domain' => $domain]);

        Node::query()->whereHas('roles', static fn (Builder $query) => $query->where('role', RoleName::Swamp))
            ->update(['ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-control-key']);
        $this->mock(LeafCertificateSigner::class)->shouldReceive('rootCertificate')->andReturn('admission-root-certificate');
        $operation = threadCreate($this->phone, $default->project, $name);
        expect($operation->input['route_domain'])->toBe($domain);
        $this->assertDatabaseHas('routes', ['domain' => $domain]);
    });

    it('refuses a generated domain label over 63 characters', function (): void {
        $default = threadDefault($this->beast, 'tool');
        $default->project->update(['type' => ProjectType::LaravelApp, 'root' => 'public', 'php_version' => '8.5', 'slug' => str_repeat('p', 64)]);
        $this->beast->update(['tld' => 'test']);

        threadCall($this->phone, 'GET', "projects/{$default->project_id}/instances/check?name=free")
            ->assertOk()->assertJsonPath('data.available', false)->assertJsonPath('data.reason', 'domain_invalid')->assertJsonPath('data.domain', null);
    });
});

describe('ordered start checks for the client', function (): void {
    it('always returns non-empty checks and enforces the aggregate invariants', function (string $state, ?string $failedKey): void {
        if (in_array($state, ['default_instance', 'container'], true)) {
            $project = Project::query()->create(['name' => 'Tool', 'slug' => 'tool', 'type' => ProjectType::NodePackage,
                'repository_url' => 'https://github.com/hardimpactdev/tool.git', 'default_branch' => 'main', 'root' => '.']);
            Instance::query()->create(['project_id' => $project->id, 'node_id' => $this->beast->id,
                'name' => $state === 'container' ? 'default' : 'feature', 'checkout_path' => '/srv/tool', 'status' => InstanceState::Active]);
        } else {
            $default = threadDefault($state === 'node_access' ? $this->mini : $this->beast, 'tool');
            $project = $default->project;
            if ($state === 'active') {
                $default->update(['status' => InstanceState::Reserved]);
            } elseif ($state === 'seed_snapshot') {
                $default->runtime()->update(['seed_commit' => null]);
            } elseif ($state === 'source_access') {
                $project->update(['source_access' => 'gh_cli']);
            } elseif ($state === 'node_access') {
                Instance::query()->create(['project_id' => $project->id, 'node_id' => $this->beast->id,
                    'name' => 'visible', 'checkout_path' => '/srv/visible']);
            }
        }

        $row = threadCall($this->phone, 'GET', 'projects?include_unavailable=1')->assertOk()->json('data.0');
        $checks = $row['checks'];
        $keys = ['default_instance', 'container', 'active', 'seed_snapshot', 'node_access', 'source_access', 'source_defaults',
            'production_placement', 'node_active_linux', 'app_dev_role', 'source_profile', 'php_readiness',
            'runtime_contributions', 'model_route', 'native_create'];
        expect(array_column($checks, 'key'))->toBe($keys)
            ->and(array_slice(array_column($checks, 'label'), 0, 5))->toBe(['Default Instance', 'Runs in a container', 'Active', 'Seed snapshot', 'Node access']);
        $failures = array_values(array_filter($checks, fn (array $check): bool => $check['status'] === 'fail'));
        expect($row['cloneable'])->toBe($failedKey === null)
            ->and($failures)->toHaveCount($failedKey === null ? 0 : 1);
        if ($failedKey === null) {
            expect(array_unique(array_column($checks, 'status')))->toBe(['pass'])
                ->and($row['cloneable_reason'])->toBeNull();
        } else {
            expect($failures[0]['key'])->toBe($failedKey)
                ->and($failures[0]['code'])->toBeString()->not->toBeEmpty()
                ->and($failures[0]['message'])->toBeString()->not->toBeEmpty()
                ->and($row['cloneable_reason'])->toBe($failures[0]['code']);
            $index = array_search($failedKey, $keys, true);
            expect(array_column(array_slice($checks, 0, $index), 'status'))->each->toBe('pass');
            expect(array_column(array_slice($checks, $index + 1), 'status'))->each->toBe('not_checked');
        }
        foreach ($checks as $check) {
            expect($check['label'])->toBeString()->not->toBeEmpty();
            if ($check['status'] !== 'fail') {
                expect($check)->not->toHaveKeys(['code', 'message']);
            }
        }
        $messages = [
            'default_instance' => "Create this Project's default Instance with instance:create.",
            'container' => "Move this Project's default Instance into a container with instance:migrate.",
            'active' => "Finish or repair this Project's default Instance before starting a thread.",
            'seed_snapshot' => "Deploy this Project's default Instance to create a seed snapshot.",
        ];
        if (isset($messages[$failedKey ?? ''])) {
            expect($failures[0]['message'])->toBe($messages[$failedKey]);
        }
    })->with([
        'missing default' => ['default_instance', 'default_instance'], 'checkout default' => ['container', 'container'],
        'inactive default' => ['active', 'active'], 'no seed' => ['seed_snapshot', 'seed_snapshot'],
        'hidden Node' => ['node_access', 'node_access'], 'technical failure' => ['source_access', 'source_access'],
        'ready' => ['ready', null],
    ]);

    it('returns readiness before access without naming the hidden Node in any of the three handles', function (string $failure): void {
        $default = threadDefault($this->mini, 'tool');
        if ($failure === 'active') {
            $default->update(['status' => InstanceState::Reserved]);
        } else {
            $default->runtime()->update(['seed_commit' => null]);
        }
        Instance::query()->create(['project_id' => $default->project_id, 'node_id' => $this->beast->id,
            'name' => 'visible', 'checkout_path' => '/srv/visible']);

        $row = threadCall($this->phone, 'GET', 'projects?include_unavailable=1')->assertOk()->json('data.0');
        expect($row['name'])->toBe('Tool')
            ->and($row['node_id'])->toBeNull()->and($row['node_name'])->toBeNull()->and($row['instance_domain_suffix'])->toBeNull()
            ->and($row['cloneable'])->toBeFalse()->and($row['cloneable_reason'])->toBe('instance.default_missing');
        $checks = collect($row['checks'])->keyBy('key');
        expect($checks[$failure]['status'])->toBe('fail')
            ->and($checks['node_access']['status'])->toBe('not_checked')
            ->and($checks[$failure]['message'])->not->toContain($this->mini->name);
        foreach (['GET' => "projects/{$default->project_id}/instances/check?name=free", 'POST' => "projects/{$default->project_id}/instances"] as $method => $path) {
            $response = threadCall($this->phone, $method, $path, $method === 'POST' ? ['name' => 'free'] : [])
                ->assertConflict()->assertJsonPath('error.code', 'instance.default_missing')->assertJsonMissingPath('error.details.serving_node');
            expect($response->json('error.message'))->toBe($checks[$failure]['message'])->not->toContain($this->mini->name);
        }
    })->with(['active', 'seed_snapshot']);

    it('keeps a failing technical check as an extra row after the owner checks', function (): void {
        threadDefault($this->beast, 'tool');
        app()->instance(InstanceLifecycleContributions::class,
            new InstanceLifecycleContributions([]));

        $row = threadCall($this->phone, 'GET', 'projects?include_unavailable=1')->assertOk()->json('data.0');
        $owner = array_slice($row['checks'], 0, 5);
        $technical = $row['checks'][12];
        expect(array_column($owner, 'status'))->each->toBe('pass');
        expect($technical['key'])->toBe('runtime_contributions')
            ->and($technical['status'])->toBe('fail')->and($technical['message'])->toBeString()->not->toBeEmpty()
            ->and($row['cloneable_reason'])->toBe($technical['code']);
    });

    it('refreshes Node and Cluster TLDs across Check again reads', function (): void {
        $default = threadDefault($this->beast, 'tool');
        $default->project->update(['type' => ProjectType::LaravelApp, 'root' => 'public', 'php_version' => '8.5']);
        $this->beast->update(['tld' => 'node.test']);
        threadCall($this->phone, 'GET', 'projects?include_unavailable=1')->assertOk()->assertJsonPath('data.0.instance_domain_suffix', '.tool.node.test');
        $cluster = Cluster::query()->create(['name' => 'dev', 'tld' => 'cluster.test', 'state' => 'active']);
        $this->beast->update(['cluster_id' => $cluster->id]);
        threadCall($this->phone, 'GET', 'projects?include_unavailable=1')->assertOk()->assertJsonPath('data.0.instance_domain_suffix', '.tool.cluster.test');
        $cluster->update(['tld' => 'changed.test']);

        threadCall($this->phone, 'GET', 'projects?include_unavailable=1')->assertOk()->assertJsonPath('data.0.instance_domain_suffix', '.tool.changed.test');
    });

    it('refreshes migration, seed and Node facts on every Check again read', function (): void {
        $project = Project::query()->create(['name' => 'Tool', 'slug' => 'tool', 'type' => ProjectType::NodePackage,
            'repository_url' => 'https://github.com/hardimpactdev/tool.git', 'default_branch' => 'main', 'root' => '.']);
        $default = Instance::query()->create(['project_id' => $project->id, 'node_id' => $this->beast->id,
            'name' => 'default', 'checkout_path' => '/srv/tool', 'status' => InstanceState::Active]);
        threadCall($this->phone, 'GET', 'projects?include_unavailable=1')->assertOk()->assertJsonPath('data.0.checks.1.status', 'fail')
            ->assertHeader('Cache-Control', 'no-cache, private');
        $runtime = app(InstanceContainer::class)->reserve($default, $this->beast);
        threadCall($this->phone, 'GET', 'projects?include_unavailable=1')->assertOk()->assertJsonPath('data.0.checks.1.status', 'pass')
            ->assertJsonPath('data.0.checks.3.status', 'fail');
        $runtime->update(['seed_commit' => str_repeat('c', 40)]);
        threadCall($this->phone, 'GET', 'projects?include_unavailable=1')->assertOk()->assertJsonPath('data.0.cloneable', true);
        $this->beast->roles()->where('role', RoleName::AppDev)->delete();

        threadCall($this->phone, 'GET', 'projects?include_unavailable=1')->assertOk()->assertJsonPath('data.0.cloneable', false)
            ->assertJsonPath('data.0.cloneable_reason', 'instance.node_not_app_dev');
    });
});

describe('the compatible Project directory', function (): void {
    it('orders both list forms by slug when display names disagree', function (string $query): void {
        $alpha = threadDefault($this->beast, 'alpha');
        $alpha->project->update(['name' => 'Zulu']);
        $zulu = threadDefault($this->beast, 'zulu');
        $zulu->project->update(['name' => 'Alpha']);

        $rows = threadCall($this->phone, 'GET', 'projects'.$query)->assertOk()->json('data');

        expect(array_column($rows, 'slug'))->toBe(['alpha', 'zulu']);
    })->with(['default' => '', 'full directory' => '?include_unavailable=1']);

    it('ignores unknown Project list query fields and keeps the default set', function (): void {
        $ready = threadDefault($this->beast, 'ready');
        threadDefault($this->beast, 'not-ready', seeded: false);
        $default = threadCall($this->phone, 'GET', 'projects')->assertOk()->json('data');

        $rows = threadCall($this->phone, 'GET', 'projects?_=123&page=1')->assertOk()->json('data');

        expect($rows)->toBe($default)
            ->and(array_column($rows, 'id'))->toBe([$ready->project_id]);
    });

    it('defaults to the pre-directory ready set and keeps the full directory opt-in', function (): void {
        $ready = threadDefault($this->beast, 'ready');
        foreach (['checkout' => 'default', 'missing' => 'feature'] as $slug => $name) {
            $project = Project::query()->create(['name' => ucfirst($slug), 'slug' => $slug, 'type' => ProjectType::NodePackage,
                'repository_url' => "https://github.com/hardimpactdev/{$slug}.git", 'default_branch' => 'main', 'root' => '.']);
            Instance::query()->create(['project_id' => $project->id, 'node_id' => $this->beast->id, 'name' => $name,
                'checkout_path' => '/srv/'.$slug, 'status' => InstanceState::Active]);
        }
        $inactive = threadDefault($this->beast, 'inactive');
        $inactive->update(['status' => InstanceState::Reserved]);
        $oldIds = Instance::query()->where('name', 'default')->where('status', InstanceState::Active)
            ->where('node_id', $this->beast->id)
            ->whereHas('runtime', fn (Builder $query) => $query->whereNotNull('seed_commit'))
            ->pluck('project_id')->all();

        $compatible = threadCall($this->phone, 'GET', 'projects')->assertOk()->assertJsonCount(1, 'data')->json('data');
        expect(array_column($compatible, 'id'))->toBe($oldIds)->toBe([$ready->project_id])
            ->and(array_column($compatible, 'cloneable'))->toBe([true])
            ->and($compatible[0]['checks'])->toHaveCount(15);
        expect(threadCall($this->phone, 'GET', 'projects?include_unavailable=0')->assertOk()->json('data'))->toBe($compatible);
        $wide = threadCall($this->phone, 'GET', 'projects?include_unavailable=1')->assertOk()->assertJsonCount(4, 'data')->json('data');
        expect(array_column($wide, 'slug'))->toBe(['checkout', 'inactive', 'missing', 'ready']);
        foreach ($wide as $row) {
            expect($row['checks'])->toHaveCount(15);
        }
    });

    it('keeps the old ready set even when a later technical check makes cloneable false', function (): void {
        $default = threadDefault($this->beast, 'ready');
        $default->project->update(['source_access' => 'gh_cli']);
        threadDefault($this->beast, 'not-ready', seeded: false);

        threadCall($this->phone, 'GET', 'projects')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $default->project_id)->assertJsonPath('data.0.cloneable', false)
            ->assertJsonPath('data.0.cloneable_reason', 'operation.kind_unavailable');
    });

    it('accepts the four exact boolean spellings', function (string $value, int $count): void {
        threadDefault($this->beast, 'ready');
        threadDefault($this->beast, 'not-ready', seeded: false);
        threadCall($this->phone, 'GET', 'projects')->assertOk()->assertJsonCount(1, 'data');

        threadCall($this->phone, 'GET', 'projects?include_unavailable='.$value)->assertOk()->assertJsonCount($count, 'data');
    })->with(['one' => ['1', 2], 'true' => ['true', 2], 'zero' => ['0', 1], 'false' => ['false', 1]]);

    it('returns 422 for every malformed include_unavailable value', function (string $query): void {
        threadDefault($this->beast, 'ready');

        threadCall($this->phone, 'GET', 'projects?include_unavailable'.$query)
            ->assertUnprocessable()->assertJsonPath('error.code', 'validation.failed');
    })->with(['=yes', '=2', '=TRUE', '=%20true%20', '=', '[]=1']);
});

/** @param list<string> $names */
function threadBranchRemote(array $names = ['main', 'release', 'feature/login'], int $exit = 0, bool $truncated = false, float $elapsed = 0): void
{
    $runner = Mockery::mock(ProcessRunner::class);
    $runner->shouldReceive('run')->once()->with(Mockery::on(static fn ($call): bool => in_array('ls-remote', $call->arguments, true)
        && $call->timeout <= 10 && ! in_array('--exit-code', $call->arguments, true)))
        ->andReturnUsing(function () use ($names, $exit, $truncated, $elapsed) {
            if ($elapsed > 0) {
                test()->travel($elapsed)->seconds();
            }

            return new CommandResult($exit,
                implode('', array_map(static fn (string $name): string => str_repeat('a', 40)."\trefs/heads/{$name}\n", $names)), '', 1, $truncated);
        });
    app()->instance(ProcessRunner::class, $runner);
}

/** No Instance, Operation, runtime, Route or branch reservation survives an admission refusal. */
function threadBranchCounts(): array
{
    return [Instance::query()->count(), Operation::query()->count(), InstanceRuntime::query()->count(), Route::query()->count()];
}

describe('explicit clone branch admission', function (): void {
    it('rejects malformed branch objects without recording anything', function (mixed $branch, string $field): void {
        $default = threadDefault($this->beast, 'tool');
        $before = threadBranchCounts();
        threadCall($this->phone, 'POST', "projects/{$default->project_id}/instances", ['name' => 'fix-login', 'branch' => $branch])
            ->assertUnprocessable()->assertJsonPath('error.code', 'validation.failed')->assertJsonValidationErrors($field, 'error.details');
        expect(threadBranchCounts())->toBe($before);
    })->with([
        'null' => [null, 'branch'], 'scalar' => ['main', 'branch'], 'list' => [['new'], 'branch'],
        'empty' => [[], 'branch'], 'unknown mode' => [['mode' => 'other'], 'branch.mode'],
        'missing mode' => [['from' => 'main'], 'branch.mode'],
        'new missing from' => [['mode' => 'new'], 'branch.from'],
        'existing missing name' => [['mode' => 'existing'], 'branch.name'],
        'new with name' => [['mode' => 'new', 'from' => 'main', 'name' => null], 'branch.name'],
        'existing with from' => [['mode' => 'existing', 'name' => 'feature', 'from' => null], 'branch.from'],
        'extra' => [['mode' => 'new', 'from' => 'main', 'extra' => 1], 'branch'],
        'invalid base' => [['mode' => 'new', 'from' => 'bad..name'], 'branch.from'],
        'invalid existing' => [['mode' => 'existing', 'name' => 'HEAD'], 'branch.name'],
        'null base' => [['mode' => 'new', 'from' => null], 'branch.from'],
        'array name' => [['mode' => 'existing', 'name' => ['main']], 'branch.name'],
        'overlong base' => [['mode' => 'new', 'from' => str_repeat('x', 256)], 'branch.from'],
    ]);

    it('refuses absent sources and existing new targets before any reservation', function (array $branch, array $heads, string $code, array $details): void {
        $default = threadDefault($this->beast, 'tool');
        threadBranchRemote($heads);
        $before = threadBranchCounts();
        threadCall($this->phone, 'POST', "projects/{$default->project_id}/instances", ['name' => 'fix-login', 'branch' => $branch])
            ->assertUnprocessable()->assertJsonPath('error.code', $code)->assertJsonPath('error.details', $details);
        expect(threadBranchCounts())->toBe($before);
    })->with([
        'existing missing' => [['mode' => 'existing', 'name' => 'absent'], ['main'], 'branch.not_found', ['field' => 'branch.name']],
        'base missing' => [['mode' => 'new', 'from' => 'absent'], ['main'], 'branch.not_found', ['field' => 'branch.from']],
        'empty remote' => [['mode' => 'new', 'from' => 'main'], [], 'branch.not_found', ['field' => 'branch.from']],
        'target exists' => [['mode' => 'new', 'from' => 'release'], ['release', 'fix-login'], 'branch.exists', ['field' => 'name', 'branch' => 'fix-login']],
    ]);

    it('refuses a holder in every state on every Node and hides unreachable identities', function (string $state, bool $visible): void {
        $default = threadDefault($this->beast, 'tool');
        $holder = Instance::query()->create(['project_id' => $default->project_id, 'node_id' => $visible ? $this->beast->id : $this->mini->id,
            'name' => 'holder', 'checkout_path' => '/srv/holder', 'status' => $state === 'removing' ? 'active' : ($state === 'failed' ? 'reserved' : $state), 'branch_override' => 'feature/login',
            'failed_step' => $state === 'failed' ? 'clone' : null]);
        if ($state === 'removing') {
            $holder->update(['starting_commit' => str_repeat('a', 40), 'branch' => 'feature/login']);
            app(StartInstanceDestroyAction::class)->execute($holder, true);
        }
        threadBranchRemote();
        $before = threadBranchCounts();
        $response = threadCall($this->phone, 'POST', "projects/{$default->project_id}/instances", ['name' => 'fix-login', 'branch' => ['mode' => 'existing', 'name' => 'feature/login']])
            ->assertConflict()->assertJsonPath('error.code', 'branch.in_use')
            ->assertJsonPath('error.details', $visible ? ['instance' => ['id' => $holder->id, 'name' => 'holder']] : []);
        if (! $visible) {
            expect($response->getContent())->toContain('"details":{}')->not->toContain('holder');
        }
        expect(threadBranchCounts())->toBe($before);
    })->with(['reserved', 'active', 'failed', 'removing'])->with([true, false]);

    it('reports the default as holder of the default branch', function (): void {
        $default = threadDefault($this->beast, 'tool');
        threadBranchRemote(['main']);
        $before = threadBranchCounts();
        threadCall($this->phone, 'POST', "projects/{$default->project_id}/instances", ['name' => 'fix-login', 'branch' => ['mode' => 'existing', 'name' => 'main']])
            ->assertConflict()->assertJsonPath('error.code', 'branch.in_use')
            ->assertJsonPath('error.details', ['instance' => ['id' => $default->id, 'name' => 'default']]);
        expect(threadBranchCounts())->toBe($before);
    });

    it('refuses an unreadable, truncated, malformed or slow remote before reservation', function (array $heads, int $exit, bool $truncated, float $elapsed): void {
        $this->freezeTime();
        $default = threadDefault($this->beast, 'tool');
        threadBranchRemote($heads, $exit, $truncated, $elapsed);
        $before = threadBranchCounts();
        threadCall($this->phone, 'POST', "projects/{$default->project_id}/instances", ['name' => 'fix-login', 'branch' => ['mode' => 'new', 'from' => 'release']])
            ->assertStatus(502)->assertJsonPath('error.code', 'source.head_unavailable')->assertJsonPath('error.details', []);
        expect(threadBranchCounts())->toBe($before);
    })->with([
        'unreadable' => [['release'], 1, false, 0.0], 'truncated' => [['release'], 0, true, 0.0],
        'malformed' => [['bad..name'], 0, false, 0.0], 'slow' => [['release'], 0, false, 10.0],
    ]);

    it('records and resumes the same frozen selection without rereading the remote', function (array $branch, string $target, ?string $base): void {
        $default = threadDefault($this->beast, 'tool');
        threadBranchRemote();
        $body = ['name' => 'fix-login', 'branch' => $branch];
        $path = "projects/{$default->project_id}/instances";
        $response = threadCall($this->phone, 'POST', $path, $body, ['Idempotency-Key' => 'branch-create'])->assertAccepted();
        $operation = Operation::query()->findOrFail($response->json('data.id'));
        $selection = ['mode' => $branch['mode'], 'target' => $target, 'base' => $base, 'source_sha' => str_repeat('a', 40)];
        expect($operation->input['branch_selection'])->toBe($selection)
            ->and($operation->input['direct_provisioning']['container']['branch_selection'])->toBe($selection)
            ->and(Instance::query()->findOrFail($operation->target_id)->branch_override)->toBe($target)
            ->and($operation->input['direct_provisioning']['container']['steps'])->toBe([]);
        threadCall($this->phone, 'POST', $path, $body, ['Idempotency-Key' => 'branch-create'])->assertAccepted()->assertJsonPath('data.id', $operation->id);
        $operation->update(['status' => OperationStatus::Failed, 'error_code' => 'instance.create_clone_failed', 'lock_key' => null]);
        $resumed = threadCall($this->phone, 'POST', $path, $body, ['Idempotency-Key' => 'branch-resume'])->assertAccepted();
        expect(Operation::query()->findOrFail($resumed->json('data.id'))->input['branch_selection'])->toBe($selection);
    })->with([
        'new' => [['mode' => 'new', 'from' => 'release'], 'fix-login', 'release'],
        'existing' => [['mode' => 'existing', 'name' => 'feature/login'], 'feature/login', null],
    ]);

    it('refuses a changed mode, base or target on same-name resume', function (array $changed): void {
        $default = threadDefault($this->beast, 'tool');
        threadBranchRemote();
        $path = "projects/{$default->project_id}/instances";
        $response = threadCall($this->phone, 'POST', $path, ['name' => 'fix-login', 'branch' => ['mode' => 'new', 'from' => 'release']])->assertAccepted();
        $operation = Operation::query()->findOrFail($response->json('data.id'));
        $operation->update(['status' => OperationStatus::Failed, 'lock_key' => null]);
        $before = threadBranchCounts();
        threadCall($this->phone, 'POST', $path, ['name' => 'fix-login', 'branch' => $changed])->assertConflict()->assertJsonPath('error.code', 'instance.placement_conflict');
        expect(threadBranchCounts())->toBe($before);
    })->with([
        'changed base' => [['mode' => 'new', 'from' => 'main']],
        'changed mode' => [['mode' => 'existing', 'name' => 'fix-login']],
        'changed target' => [['mode' => 'existing', 'name' => 'feature/login']],
    ]);

    it('does not read remote heads or acquire source credentials when branch is omitted or name checked', function (): void {
        $default = threadDefault($this->beast, 'tool');
        $runner = Mockery::mock(ProcessRunner::class);
        $runner->shouldNotReceive('run');
        app()->instance(ProcessRunner::class, $runner);
        $github = Mockery::mock(GitHubApi::class);
        $github->shouldNotReceive('repositoryInstallation');
        $github->shouldNotReceive('repositoryReadToken');
        app()->instance(GitHubApi::class, $github);
        threadCall($this->phone, 'GET', "projects/{$default->project_id}/instances/check?name=fix-login")->assertOk();
        $operation = threadCreate($this->phone, $default->project, 'fix-login');
        expect($operation->input)->not->toHaveKey('branch_selection')
            ->and($operation->input['direct_provisioning']['container'])->not->toHaveKey('branch_selection');
    });
});

it('bounds explicit branch credential acquisition and git within one ten-second admission deadline', function (string $phase): void {
    $this->freezeTime();
    $default = threadDefault($this->beast, 'tool');
    app(GitHubAppStore::class)->put(new GitHubAppCredentials(123, 'fixture', 'fixture', 'nckrtl', 'user', 'https://github.com/apps/fixture', 'fixture-key'));
    $github = Mockery::mock(GitHubApi::class);
    $github->shouldReceive('repositoryInstallation')->once()->andReturnUsing(function ($credentials, $repository, $deadline) use ($phase): int {
        expect($deadline->remaining())->toBe(10.0);
        if ($phase === 'installation') {
            $this->travel(10)->seconds();
        }

        return 7;
    });
    if ($phase === 'token') {
        $github->shouldReceive('repositoryReadToken')->once()->andReturnUsing(function ($credentials, $installation, $repository, $deadline): string {
            expect($deadline->remaining())->toBe(10.0);
            $this->travel(10)->seconds();

            return 'fixture-token';
        });
    }
    app()->instance(GitHubApi::class, $github);
    $runner = Mockery::mock(ProcessRunner::class);
    $runner->shouldNotReceive('run');
    app()->instance(ProcessRunner::class, $runner);
    $before = threadBranchCounts();
    threadCall($this->phone, 'POST', "projects/{$default->project_id}/instances", ['name' => 'fix-login', 'branch' => ['mode' => 'new', 'from' => 'release']])
        ->assertStatus(502)->assertJsonPath('error.code', 'source.head_unavailable');
    expect(threadBranchCounts())->toBe($before);
    Http::assertNothingSent();
})->with(['installation', 'token']);

it('projects explicit branch result additions and keeps failed Instances for retry', function (string $mode, ?string $base): void {
    $default = threadDefault($this->beast, 'tool');
    threadBranchRemote();
    $response = threadCall($this->phone, 'POST', "projects/{$default->project_id}/instances", ['name' => 'fix-login', 'branch' => $mode === 'new'
        ? ['mode' => 'new', 'from' => $base] : ['mode' => 'existing', 'name' => 'feature/login']])->assertAccepted();
    $operation = Operation::query()->findOrFail($response->json('data.id'));
    $operation->update(['status' => OperationStatus::Running, 'state' => ['swamp_run_id' => 'branch-run']]);
    app(DevelopmentInstanceCreateProjection::class)->apply($operation, [
        'status' => 'failed', 'run_id' => 'branch-run', 'error_code' => 'instance.create_clone_failed',
        'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(),
        'result' => ['instance_id' => $operation->target_id, 'ready' => false, 'failed_step' => 'clone', 'error_code' => 'instance.create_clone_failed'],
    ]);
    threadCall($this->phone, 'GET', "operations/{$operation->id}")->assertOk()
        ->assertJsonPath('data.error_code', 'instance.create_clone_failed')
        ->assertJsonPath('data.result.branch', $mode === 'new' ? 'fix-login' : 'feature/login')
        ->assertJsonPath('data.result.base_branch', $base)->assertJsonPath('data.result.instance_id', $operation->target_id);
    expect(Instance::query()->findOrFail($operation->target_id)->error_code)->toBe('instance.create_clone_failed');
})->with([['new', 'release'], ['existing', null]]);

it('serializes two simultaneous explicit branch admissions before reserving an Instance', function (): void {
    $default = threadDefault($this->beast, 'tool');
    $database = tempnam(sys_get_temp_dir(), 'branch-race-');
    $copy = new PDO('sqlite:'.$database);
    $copy->exec('PRAGMA journal_mode=WAL');
    $schema = DB::select("SELECT type, name, sql FROM sqlite_master WHERE sql IS NOT NULL AND name NOT LIKE 'sqlite_%' ORDER BY CASE type WHEN 'table' THEN 0 ELSE 1 END");
    foreach ($schema as $entry) {
        if ($entry->type !== 'table') {
            continue;
        }
        $copy->exec($entry->sql);
        foreach (DB::table($entry->name)->get() as $row) {
            $values = (array) $row;
            $columns = implode(',', array_map(static fn (string $column): string => '"'.$column.'"', array_keys($values)));
            $statement = $copy->prepare('INSERT INTO "'.$entry->name.'" ('.$columns.') VALUES ('.implode(',', array_fill(0, count($values), '?')).')');
            $statement->execute(array_values($values));
        }
    }
    foreach ($schema as $entry) {
        if ($entry->type !== 'table') {
            $copy->exec($entry->sql);
        }
    }
    $copy = null;
    $pairs = [stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP), stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP)];
    $children = [];
    try {
        foreach ([0, 1] as $index) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                throw new RuntimeException('Could not fork an admission.');
            }
            if ($pid === 0) {
                try {
                    foreach ($pairs as $other => $pair) {
                        fclose($pair[0]);
                        if ($other !== $index) {
                            fclose($pair[1]);
                        }
                    }
                    $socket = $pairs[$index][1];
                    stream_set_timeout($socket, 1);
                    config(['database.connections.sqlite.database' => $database]);
                    DB::purge('sqlite');
                    // Both reads see an unoccupied target before either create takes the writer lock.
                    $ready = false;
                    DB::listen(function (QueryExecuted $query) use ($socket, &$ready): void {
                        if (! $ready && DB::transactionLevel() === 0 && str_contains($query->sql, 'from "instances" where "project_id"') && str_contains($query->sql, 'order by "id"')) {
                            $ready = true;
                            fwrite($socket, 'R');
                            if (fread($socket, 1) !== 'G') {
                                throw new RuntimeException('Admission barrier timed out.');
                            }
                        }
                    });
                    threadBranchRemote();
                    $response = threadCall($this->phone, 'POST', "projects/{$default->project_id}/instances",
                        ['name' => 'race-'.$index, 'branch' => ['mode' => 'existing', 'name' => 'feature/login']]);
                    file_put_contents($database.'.'.$index, json_encode(['status' => $response->status(), 'body' => $response->json()], JSON_THROW_ON_ERROR));
                    DB::disconnect('sqlite');
                    exit(0);
                } catch (Throwable $error) {
                    file_put_contents($database.'.'.$index, json_encode(['error' => $error->getMessage()], JSON_THROW_ON_ERROR));
                    exit(1);
                }
            }
            $children[] = $pid;
        }
        foreach ($pairs as $pair) {
            fclose($pair[1]);
            stream_set_timeout($pair[0], 1);
            expect(fread($pair[0], 1))->toBe('R');
        }
        foreach ($pairs as $pair) {
            fwrite($pair[0], 'G');
        }
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            expect(pcntl_wexitstatus($status))->toBe(0);
        }
        $results = array_map(static fn (int $index): array => json_decode(file_get_contents($database.'.'.$index), true, flags: JSON_THROW_ON_ERROR), [0, 1]);
        expect(array_column($results, 'status'))->toEqualCanonicalizing([202, 409]);
        $failure = array_values(array_filter($results, static fn (array $result): bool => $result['status'] === 409))[0];
        expect($failure['body']['error']['code'])->toBe('branch.in_use');
        $copy = new PDO('sqlite:'.$database);
        expect((int) $copy->query('SELECT COUNT(*) FROM instances')->fetchColumn())->toBe(2)
            ->and((int) $copy->query('SELECT COUNT(*) FROM operations')->fetchColumn())->toBe(2)
            ->and((int) $copy->query('SELECT COUNT(*) FROM instance_runtimes')->fetchColumn())->toBe(2);
    } finally {
        foreach ($children as $pid) {
            if (pcntl_waitpid($pid, $status, WNOHANG) === 0) {
                posix_kill($pid, SIGTERM);
                pcntl_waitpid($pid, $status);
            }
        }
        foreach ([$database, $database.'-wal', $database.'-shm', $database.'.0', $database.'.1'] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
});

/** Normalize only generated prepare identities and bridge observation time, preserving key order and every other byte. */
function threadLegacySnapshot(mixed $value, ?string $key = null): mixed
{
    if ($key === 'bridge_admitted_at') {
        return '<bridge-admitted-at>';
    }
    if (is_string($value)) {
        return preg_replace('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/', '<prepare-uuid>', $value);
    }
    if (is_array($value)) {
        foreach ($value as $field => $entry) {
            $value[$field] = threadLegacySnapshot($entry, (string) $field);
        }
    }

    return $value;
}

// Frozen from main a38e037e; accepted delta: clone-mail progress folds into clone-container.
it('matches the installed-client no-branch intent steps result and resume snapshot byte for byte', function (): void {
    $this->freezeTime();
    $default = threadDefault($this->beast, 'tool');
    $response = threadCall($this->phone, 'POST', "projects/{$default->project->id}/instances", ['name' => 'fix-login'])
        ->assertAccepted()->assertJsonPath('data.status', 'queued');
    $operation = Operation::query()->findOrFail($response->json('data.id'));
    $initial = threadLegacySnapshot($operation->input);
    $operation->update(['status' => OperationStatus::Running, 'state' => ['swamp_run_id' => 'legacy-run']]);
    app(DevelopmentInstanceCreateProjection::class)->apply($operation, [
        'status' => 'failed', 'run_id' => 'legacy-run', 'error_code' => 'instance.create_clone_failed',
        'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(),
        'result' => ['instance_id' => $operation->target_id, 'ready' => false, 'failed_step' => 'clone', 'error_code' => 'instance.create_clone_failed'],
    ]);
    $failedResult = $operation->refresh()->result;
    $resume = threadCreate($this->phone, $default->project, 'fix-login');
    $workflow = Yaml::parseFile(base_path('../../swamp/workflows/workflow-operation-instance-create.yaml'));
    $steps = [];
    foreach ($workflow['jobs'] as $job) {
        if ($job['name'] === 'clone') {
            $steps = array_column($job['steps'], 'name');
        }
    }
    $snapshot = json_encode(['input' => $initial, 'clone_steps' => $steps, 'failed_result' => $failedResult,
        'resume' => threadLegacySnapshot($resume->input)], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    $path = __DIR__.'/../../../Fixtures/conn-create-no-branch-folded-mail.json';
    expect($snapshot)->toBe(file_get_contents($path));
    // The start receipt exposes the full intent too; keep the installed-client response unchanged.
    $expected = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    expect(threadLegacySnapshot($response->json('data.input')))->toBe($expected['input']);
});

it('adds explicit branch fields to a successful clone receipt without replacing existing fields', function (?array $branch, bool $mailFailure = false): void {
    $default = threadDefault($this->beast, 'tool');
    if ($branch !== null) {
        threadBranchRemote();
    }
    $body = ['name' => 'fix-login', ...($branch === null ? [] : ['branch' => $branch])];
    $response = threadCall($this->phone, 'POST', "projects/{$default->project_id}/instances", $body)->assertAccepted();
    $operation = Operation::query()->findOrFail($response->json('data.id'));
    $operation->update(['status' => OperationStatus::Running, 'state' => ['swamp_run_id' => 'success-run']]);
    $frame = [];
    $socket = Mockery::mock(WebSocketClient::class);
    $socket->shouldReceive('connect')->once();
    $socket->shouldReceive('isConnected')->andReturn(true);
    $socket->shouldReceive('send')->once()->withArgs(static function (array $message) use (&$frame): bool {
        $frame = $message;

        return $message['type'] === 'vault.read-secret';
    });
    $socket->shouldReceive('receive')->once()->andReturnUsing(static function () use (&$frame): array {
        return [['id' => $frame['id'], 'type' => 'vault.read-secret', 'payload' => ['data' => ['vaultName' => $frame['payload']['vaultName'],
            'secretKey' => $frame['payload']['secretKey'], 'vaultType' => 'local_encryption', 'value' => 'fixture-session']]]];
    });
    $socket->shouldReceive('close');
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.address' => 'swamp.test', 'swamp.ca_path' => '/tmp/ca.pem', 'swamp.run_token' => 'fixture-run-token']);
    $native = ['ready' => true, 'instance_id' => $operation->target_id, 'commit' => str_repeat('a', 40), 't3' => [
        'environment_id' => 'env-branch', 'server_version' => '0.1.7', 'expires_at' => '2026-12-10T10:00:00.000Z',
        'gateway_session' => ['vault' => 'orbit-control-inputs', 'key' => 'nckrtl-instance-t3-model-register-session-gateway-1-token'],
        'swamp_session' => ['vault' => 'orbit-control-inputs', 'key' => 'nckrtl-instance-t3-model-register-session-swamp-1-token']]];
    if ($mailFailure) {
        $native['mail'] = ['status' => 'failed', 'reason' => 'instance.mail_update_failed', 'ports' => [1025, 2525]];
        $native['warnings'] = [['code' => 'instance.mail_update_failed', 'step' => 'mail']];
    }
    app(DevelopmentInstanceCreateProjection::class)->apply($operation, [
        'status' => 'succeeded', 'run_id' => 'success-run', 'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(), 'result' => $native]);
    $additions = $branch === null ? [] : ['branch' => $branch['mode'] === 'new' ? 'fix-login' : 'feature/login',
        'base_branch' => $branch['mode'] === 'new' ? 'release' : null];
    expect($operation->refresh()->result)->toBe([...$native, ...$additions, 'mail_keys_set' => []]);
    threadCall($this->phone, 'GET', "operations/{$operation->id}")->assertOk()->assertJsonPath('data.status', 'succeeded');
})->with([
    'legacy' => [null], 'legacy mail warning' => [null, true], 'new' => [['mode' => 'new', 'from' => 'release']], 'existing' => [['mode' => 'existing', 'name' => 'feature/login']],
]);

it('preserves core creates that intentionally put two Instances on one branch', function (): void {
    $default = threadDefault($this->beast, 'tool');
    $runner = Mockery::mock(ProcessRunner::class);
    $runner->shouldNotReceive('run');
    app()->instance(ProcessRunner::class, $runner);
    foreach (['old-one', 'old-two'] as $name) {
        app(StartDevelopmentInstanceCreateAction::class)->execute(
            new CreateInstanceData($default->project_id, $this->beast->id, $name, null, null, 'feature/login'),
            'node:'.$this->phone->id, null);
    }
    expect(Instance::query()->where('project_id', $default->project_id)->where('branch_override', 'feature/login')->count())->toBe(2);
});

it('refuses a changed source identity between the remote read and reservation without admitting anything', function (): void {
    $default = threadDefault($this->beast, 'tool');
    $before = threadBranchCounts();
    $runner = Mockery::mock(ProcessRunner::class);
    $runner->shouldReceive('run')->once()->andReturnUsing(static function () use ($default) {
        $default->project->update(['repository_url' => 'https://github.com/nckrtl/another.git']);

        return new CommandResult(0, str_repeat('a', 40)."\trefs/heads/release\n", '', 1, false);
    });
    app()->instance(ProcessRunner::class, $runner);
    threadCall($this->phone, 'POST', "projects/{$default->project_id}/instances", ['name' => 'fix-login', 'branch' => ['mode' => 'new', 'from' => 'release']])
        ->assertStatus(502)->assertJsonPath('error.code', 'source.head_unavailable');
    expect(threadBranchCounts())->toBe($before);
});

it('refuses ref-like and malformed explicit branch names before any remote read or reservation', function (string $value, bool $sharedValid, string $mode): void {
    expect(GitBranchName::isValid($value))->toBe($sharedValid);
    $default = threadDefault($this->beast, 'tool');
    $runner = Mockery::mock(ProcessRunner::class);
    $runner->shouldNotReceive('run');
    app()->instance(ProcessRunner::class, $runner);
    $before = threadBranchCounts();
    $field = $mode === 'new' ? 'from' : 'name';

    threadCall($this->phone, 'POST', "projects/{$default->project_id}/instances", ['name' => 'fix-login', 'branch' => ['mode' => $mode, $field => $value]])
        ->assertUnprocessable()->assertJsonPath('error.code', 'validation.failed')->assertJsonValidationErrors('branch.'.$field, 'error.details');

    expect(threadBranchCounts())->toBe($before);
})->with([
    'full local ref' => ['refs/heads/x', true],
    'remote tracking ref' => ['refs/remotes/origin/x', true],
    'leading dash' => ['-x', false],
    'double dot' => ['x..y', false],
    'reflog syntax' => ['x@{y', false],
    'lock suffix' => ['feature.lock', false],
    'trailing slash' => ['feature/', false],
])->with(['new', 'existing']);

it('accepts ordinary nested branch names without treating embedded ref words as prefixes', function (string $value, string $mode): void {
    $default = threadDefault($this->beast, 'tool');
    threadBranchRemote([$value]);
    $field = $mode === 'new' ? 'from' : 'name';

    threadCall($this->phone, 'POST', "projects/{$default->project_id}/instances", ['name' => 'fix-login', 'branch' => ['mode' => $mode, $field => $value]])
        ->assertAccepted();
})->with(['refs-fix/x', 'heads-up/x', 'feature/refs/heads/x', 'feature/heads/x'])->with(['new', 'existing']);

it('accepts heads/x as a branch name for explicit branch selection', function (string $mode): void {
    $default = threadDefault($this->beast, 'tool');
    $runner = Mockery::mock(ProcessRunner::class);
    $runner->shouldReceive('run')->once()->with(Mockery::on(static fn ($call): bool => in_array('ls-remote', $call->arguments, true)))
        ->andReturn(new CommandResult(0, str_repeat('a', 40)."\trefs/heads/heads/x\n".str_repeat('b', 40)."\trefs/heads/x\n", '', 1, false));
    app()->instance(ProcessRunner::class, $runner);
    $field = $mode === 'new' ? 'from' : 'name';

    $response = threadCall($this->phone, 'POST', "projects/{$default->project_id}/instances", ['name' => 'fix-login', 'branch' => ['mode' => $mode, $field => 'heads/x']])
        ->assertAccepted();

    $operation = Operation::query()->findOrFail($response->json('data.id'));
    $selection = ['mode' => $mode, 'target' => $mode === 'new' ? 'fix-login' : 'heads/x', 'base' => $mode === 'new' ? 'heads/x' : null,
        'source_sha' => str_repeat('a', 40)];
    expect($operation->input['branch_selection'])->toBe($selection)
        ->and($operation->input['direct_provisioning']['container']['branch_selection'])->toBe($selection);
})->with(['new', 'existing']);

/** Execute the production CSR argv, substituting only public inputs and the key's scratch path. */
function routeSanRequest(string $workflow, string $directory, string $domain): ProcessInvocation
{
    $definition = Yaml::parseFile(base_path('../../swamp/workflows/'.$workflow.'.yaml'));
    $steps = $definition['jobs'][0]['steps'];
    $step = array_values(array_filter($steps, static fn (array $step): bool => in_array($step['name'], ['csr', 'Make a CSR on the Node'], true)))[0];
    $expression = substr($step['task']['inputs']['argv'], 4, -3);
    $expression = strtr($expression, [
        '"/etc/caddy/orbit-certificates/" + self.leaf.scope + "/" + "versions/" + self.leaf.version + "/key.pem"' => json_encode($directory.'/key.pem'),
        '"/var/lib/orbit/role-certificates/" + inputs.leaf.scope + "/" + inputs.leaf.version + "/key.pem"' => json_encode($directory.'/key.pem'),
        'inputs.intent.leaves[inputs.leaf].directory' => json_encode($directory),
        'inputs.intent.leaves[inputs.leaf].domain' => json_encode($domain),
        '"subjectAltName=DNS:" + self.leaf.domain' => json_encode('subjectAltName=DNS:'.$domain),
        '"subjectAltName=DNS:" + inputs.leaf.domain' => json_encode('subjectAltName=DNS:'.$domain),
        '"/CN=" + self.leaf.domain' => json_encode('/CN='.$domain),
        '"/CN=" + inputs.leaf.domain' => json_encode('/CN='.$domain),
    ]);
    $argv = json_decode($expression, true, flags: JSON_THROW_ON_ERROR);
    if ($workflow === 'workflow-route-create-csr') {
        // The Node's surrounding install/chown needs root:caddy; run its unchanged CSR statement here.
        expect(preg_match('/openssl req .+?(?= &&)/', $argv[5], $match))->toBe(1);
        $argv[5] = $match[0];
    }

    return new ProcessInvocation($argv, timeout: 5);
}

function expectRouteSanLeaf(string $domain, string $request): string
{
    $certificate = test()->signer->sign($domain, $request, test()->root);
    $path = test()->directory.'/leaf.pem';
    file_put_contents($path, $certificate);
    $details = openssl_x509_parse($certificate);
    expect($details['extensions']['subjectAltName'])->toBe('DNS:'.$domain)
        ->and($details['extensions']['basicConstraints'])->toBe('CA:FALSE')
        ->and($details['extensions']['extendedKeyUsage'])->toBe('TLS Web Server Authentication')
        ->and(openssl_x509_check_private_key($certificate, file_get_contents(test()->directory.'/key.pem')))->toBeTrue();
    $validation = test()->processes->run(new ProcessInvocation([
        'openssl', 'verify', '-CAfile', test()->directory.'/root.pem', '-purpose', 'sslserver', '-verify_hostname', $domain, $path,
    ], timeout: 5));
    expect($validation->exitCode)->toBe(0, $validation->stderr);

    return $certificate;
}

describe('Route certificate producer compatibility', function (): void {
    beforeEach(function (): void {
        $this->directory = TestOrbitHome::scratch('san-leaf');
        mkdir($this->directory.'/ca', 0700, true);
        config(['orbit.home' => $this->directory]);
        $key = file_get_contents(base_path('tests/Fixtures/gateway-bootstrap-root.key'));
        file_put_contents($this->directory.'/ca/root.key', $key);
        $configuration = $this->directory.'/root.cnf';
        file_put_contents($configuration, "[req]\ndistinguished_name=subject\n[subject]\n[ca]\nbasicConstraints=critical,CA:TRUE\nkeyUsage=critical,keyCertSign,cRLSign\n");
        $request = openssl_csr_new(['commonName' => 'Orbit Test Root'], $key, ['config' => $configuration]);
        $root = openssl_csr_sign($request, null, $key, 2, ['config' => $configuration, 'x509_extensions' => 'ca', 'digest_alg' => 'sha256']);
        openssl_x509_export($root, $this->root);
        file_put_contents($this->directory.'/root.pem', $this->root);
        $this->processes = new NativeProcessRunner;
        $this->signer = new GatewayLeafCertificateSigner($this->processes);
    });

    it('signs the new production CSR at the unchanged live 64 character domain limit', function (string $workflow): void {
        $domain = str_repeat('n', 54).'.tool.test';
        expect(strlen($domain))->toBe(64);

        $result = $this->processes->run(routeSanRequest($workflow, $this->directory, $domain));

        expect($result->exitCode)->toBe(0, $result->stderr)
            ->and(openssl_csr_get_subject($result->stdout)['CN'])->toBe('Orbit');
        $certificate = expectRouteSanLeaf($domain, $result->stdout);
        expect(openssl_x509_parse($certificate)['subject']['CN'])->toBe('Orbit');
    })->with(['workflow-route-create-csr', 'workflow-route-certificate-publish', 'workflow-node-role-certificate-publish']);

    it('accepts the future producer form before any producer changes, including after switch-back', function (string $domain): void {
        $result = $this->processes->run(new ProcessInvocation([
            'openssl', 'req', '-quiet', '-new', '-newkey', 'rsa:2048', '-nodes', '-keyout', $this->directory.'/key.pem',
            '-subj', '/CN=Orbit', '-addext', 'subjectAltName=DNS:'.$domain,
        ], timeout: 5));

        expect($result->exitCode)->toBe(0);
        $certificate = expectRouteSanLeaf($domain, $result->stdout);
        expect(openssl_x509_parse($certificate)['subject']['CN'])->toBe('Orbit');
    })->with(['ordinary domain' => 'future.tool.test',
        '253 character DNS name' => str_repeat('n', 63).'.tool.'.str_repeat('a', 63).'.'.str_repeat('b', 63).'.'.str_repeat('c', 56)]);

    it('still signs and validates an old Node request during release overlap', function (bool $withSan): void {
        $domain = 'existing.tool.test';
        $argv = ['openssl', 'req', '-quiet', '-new', '-newkey', 'rsa:2048', '-nodes', '-keyout', $this->directory.'/key.pem', '-subj', '/CN='.$domain];
        if ($withSan) {
            $argv = [...$argv, '-addext', 'subjectAltName=DNS:'.$domain];
        }
        $result = $this->processes->run(new ProcessInvocation($argv, timeout: 5));
        expect($result->exitCode)->toBe(0);
        $certificate = expectRouteSanLeaf($domain, $result->stdout);
        expect(openssl_x509_parse($certificate)['subject']['CN'])->toBe($domain);
    })->with(['previous workflow without SAN' => false, 'old CN with exact SAN' => true]);

    it('refuses a new request whose SAN is missing or is not the admitted domain alone', function (?string $san): void {
        $argv = ['openssl', 'req', '-quiet', '-new', '-newkey', 'rsa:2048', '-nodes', '-keyout', $this->directory.'/key.pem', '-subj', '/CN=Orbit'];
        if ($san !== null) {
            $argv = [...$argv, '-addext', 'subjectAltName='.$san];
        }
        $result = $this->processes->run(new ProcessInvocation($argv, timeout: 5));
        expect($result->exitCode)->toBe(0)
            ->and(fn () => $this->signer->sign('tool.test', $result->stdout, $this->root))
            ->toThrow(ResourceOperationException::class, 'The CSR must be an RSA 2048 request for the admitted domain only.');
    })->with(['missing SAN' => null, 'different DNS name' => 'DNS:other.test', 'extra DNS name' => 'DNS:tool.test,DNS:other.test']);

    it('keeps a real CSR failure diagnostic visible without RSA progress dots', function (string $workflow): void {
        $result = $this->processes->run(routeSanRequest($workflow, $this->directory.'/missing', 'tool.test'));

        expect($result->exitCode)->not->toBe(0)
            ->and($result->stderr)->toContain('No such file or directory')->not->toMatch('/[.+*]{20}/');
    })->with(['workflow-route-create-csr', 'workflow-route-certificate-publish', 'workflow-node-role-certificate-publish']);
});

it('refuses a clone of a nested-fleet Project before it records an Instance or an Operation', function (): void {
    $default = threadDefault($this->beast, 'tool');
    // The flag changes only while a Project has no Instance; this fixture sets it on the recorded row to reach the shared create.
    DB::table('projects')->where('id', $default->project_id)->update(['nested_fleet' => true]);
    $instances = Instance::query()->count();
    $runtimes = InstanceRuntime::query()->count();
    $operations = Operation::query()->count();

    threadCall($this->phone, 'POST', "projects/{$default->project_id}/instances", ['name' => 'fix-login-k3j9'])
        ->assertStatus(503)->assertJsonPath('error.code', 'operation.kind_unavailable')
        ->assertJsonPath('error.message', 'Instances of a nested-fleet Project are not available until nested-fleet hosting is built.');
    expect(Instance::query()->count())->toBe($instances)->and(InstanceRuntime::query()->count())->toBe($runtimes)
        ->and(Operation::query()->count())->toBe($operations);
});

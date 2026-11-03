<?php

declare(strict_types=1);

use App\Actions\Instances\RegisterInstanceAction;
use App\Data\Instances\RegisterInstanceData;
use App\Domain\Instances\Environment\InstanceEnvironmentContextResolver;
use App\Domain\Instances\InstanceRegisterResult;
use App\Domain\Operations\DirectOperation;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentView\CacheAgentStateView;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\Swamp\StartSwampRun;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Models\Instance;
use App\Models\Node;
use App\Models\NodeFact;
use App\Models\Operation;
use App\Models\Project;
use Tests\Support\FakeRoutePublicationSocket;

beforeEach(function (): void {
    $this->project = Project::query()->create(['name' => 'Register proof', 'slug' => 'register-proof', 'repository_url' => 'https://github.com/example/proof.git', 'type' => 'node-package', 'root' => '.']);
    $this->node = Node::query()->create(['name' => 'register-dev', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'amd64', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.9', 'wireguard_ip' => '10.44.0.9', 'ssh_host_fingerprint' => 'SHA256:register', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'AAAAC3NzaC1lZDI1NTE5AAAAIGaNG9xwGkyymXqkaSEKl6+u5VLBHPbi0ga0ya7sdCtG', 'settings' => ['apps' => ['path' => '/srv/apps']]]);
    orbit_test_set_app_placement_role($this->node, false);
    $this->instance = Instance::query()->create(['project_id' => $this->project->id, 'node_id' => $this->node->id, 'name' => 'proof', 'checkout_path' => '/srv/apps/register-proof/proof', 'source_layout' => 'checkout', 'status' => 'reserved']);
    $this->operation = Operation::query()->create(['status' => 'queued', 'state' => ['swamp_run_id' => 'a075eb23-7ac4-4487-80d1-57a038a92676'], 'kind' => 'instance.register', 'target_type' => 'instance', 'target_id' => $this->instance->id, 'node_id' => $this->node->id, 'input' => ['checkout' => $this->instance->checkout_path, 'repository' => $this->project->repository_url, 'repository_identity' => $this->project->repository_identity, 'project_type' => 'node-package']]);
    $this->reads = register_reads($this->instance->checkout_path);
});

/** The read steps' stdout for one plain checkout of github.com/example/proof on branch feature. */
function register_reads(string $checkout, array $change = []): array
{
    return [...['filesystem' => str_repeat('b', 64)."\nabsent\n", 'top' => $checkout."\n", 'common' => $checkout."/.git\n",
        'worktrees' => "worktree {$checkout}\nHEAD ".str_repeat('a', 40)."\nbranch refs/heads/feature\n", 'origin' => "https://github.com/example/proof.git\n",
        'branch' => "feature\n", 'commit' => str_repeat('a', 40)."\n", 'default' => "refs/remotes/origin/main\n"], ...$change];
}

/**
 * The Node agent is online and reports one plain checkout of github.com/example/proof on branch feature.
 *
 * @param  array<string,mixed>  $change
 */
function register_report(Node $node, string $checkout, array $change = []): void
{
    app(CacheAgentStateView::class)->putSubscriber(true, true, 1);
    seed_agent_view($node->id, []);
    NodeFact::query()->updateOrCreate(['node_id' => $node->id, 'kind' => 'checkout', 'subject' => $checkout], [
        'digest' => bin2hex(random_bytes(32)), 'observed_at' => now(), 'received_at' => now(),
        'data' => [...['owner' => 'orbit', 'top_level' => $checkout, 'common_dir' => $checkout.'/.git', 'linked' => false, 'worktrees' => [$checkout],
            'origin' => 'https://github.com/example/proof.git', 'branch' => 'feature', 'head' => str_repeat('a', 40), 'default_branch' => 'main',
            'application' => $checkout, 'artisan' => 'absent', 'composer' => null, 'env' => null], ...$change]]);
}

/** @param array<string,mixed> $result */
function register_terminal(string $status, array $result): array
{
    return ['run_id' => 'a075eb23-7ac4-4487-80d1-57a038a92676', 'status' => $status, 'started_at' => now()->format('Y-m-d\TH:i:s.v\Z'), 'finished_at' => now()->format('Y-m-d\TH:i:s.v\Z'), 'result' => $result];
}

it('projects native adoption and source facts into the reserved Instance', function (): void {
    $operation = app(InstanceRegisterResult::class)->apply($this->operation, register_terminal('succeeded', $this->reads));
    expect($operation->result)->toBe(['registered' => true, 'branch' => 'feature', 'commit' => str_repeat('a', 40), 'detached' => false, 'digest' => str_repeat('b', 64),
        'common' => $this->instance->checkout_path.'/.git', 'default_branch' => 'main', 'inferred_slug' => 'proof', 'php_version' => null, 'laravel' => false]);
    expect($this->instance->refresh()->source_is_laravel)->toBeFalse();
    expect($this->instance->status->value)->toBe('active')
        ->and($this->instance->branch)->toBe('feature')->and($this->instance->starting_commit)->toBe(str_repeat('a', 40))
        ->and($this->instance->registration_relocation_state)->toBe('relocated')->and($this->instance->registration_completed_at)->not->toBeNull();
});

it('keeps failed adoption reserved for a later retry', function (): void {
    $operation = app(InstanceRegisterResult::class)->apply($this->operation, register_terminal('failed', ['steps' => ['Read the Git top level' => ['status' => 'failed', 'exit_code' => 128]]]));
    expect($operation->error_code)->toBe('instance.source_invalid')
        ->and($this->instance->refresh()->status->value)->toBe('reserved')->and($this->instance->registration_completed_at)->toBeNull();
});

it('fails a checkout whose read facts do not match the admitted source', function (array $change, string $code): void {
    $operation = app(InstanceRegisterResult::class)->apply($this->operation, register_terminal('succeeded', register_reads($this->instance->checkout_path, $change)));
    expect($operation->status->value)->toBe('failed')->and($operation->error_code)->toBe($code)
        ->and($this->instance->refresh()->status->value)->toBe('reserved');
})->with([
    'another top level' => [['top' => "/srv/apps/other\n"], 'instance.registration_conflict'],
    'a linked worktree' => [['common' => "/srv/apps/main/.git\n"], 'instance.registration_conflict'],
    'a second worktree' => [['worktrees' => "worktree /srv/apps/register-proof/proof\n\nworktree /srv/apps/other\n"], 'instance.registration_conflict'],
    'another repository' => [['origin' => "https://github.com/example/other.git\n"], 'instance.source_identity_invalid'],
    'a masked credential' => [['origin' => "https://REDACTED@github.com/example/proof.git\n"], 'instance.source_identity_invalid'],
    'no commit' => [['commit' => "\n"], 'instance.source_invalid'],
]);

it('selects the PHP version from composer.json and refuses an entry point the source does not declare', function (): void {
    $composer = json_encode(['require' => ['php' => '^8.4']]);
    $read = fn (string $entryPoint): array => register_reads($this->instance->checkout_path, ['filesystem' => str_repeat('b', 64)."\n{$entryPoint}\n{$composer}\n"]);
    $operation = app(InstanceRegisterResult::class)->apply($this->operation, register_terminal('succeeded', $read('regular')));
    expect($operation->status->value)->toBe('failed')->and($operation->error_code)->toBe('app-dev.laravel_source_invalid');

    $this->operation->update(['status' => 'running', 'error_code' => null, 'result' => null, 'finished_at' => null]);
    $operation = app(InstanceRegisterResult::class)->apply($this->operation, register_terminal('succeeded', $read('absent')));
    expect($operation->result['php_version'])->toBeString()->and($operation->result['laravel'])->toBeFalse()
        ->and($this->instance->refresh()->selected_php_version)->toBe($operation->result['php_version']);
});

it('refuses unsupported registration before another row or Operation is reserved', function (array $change): void {
    $values = [...['sourcePath' => '/srv/apps/register-proof/other', 'includeWorktrees' => false, 'projectId' => $this->project->id, 'instanceName' => 'other', 'root' => null, 'domain' => null, 'runSetup' => false], ...$change];
    expect(fn () => app(RegisterInstanceAction::class)->execute($this->node, new RegisterInstanceData(...$values)))->toThrow(ResourceOperationException::class, 'Native registration currently');
    expect(Instance::query()->count())->toBe(1)->and(Operation::query()->count())->toBe(1);
})->with([
    'worktrees' => [['includeWorktrees' => true]], 'setup' => [['runSetup' => true]],
    'project inference' => [['projectId' => null]], 'domain' => [['domain' => 'proof.test']],
    'relocation' => [['sourcePath' => '/work/proof']],
]);

it('returns only recorded public intent to the native reader', function (): void {
    expect(app(InstanceRegisterResult::class)->input($this->operation)['intent'])->toMatchArray($this->operation->input)
        ->and(app(InstanceRegisterResult::class)->input($this->operation)['intent']['ssh_execution']['target']['host_keys'])->toBe([$this->node->ssh_host_key_type.' '.$this->node->ssh_host_key]);
});

$register = static fn (string $name = 'native'): Instance|Operation => app(RegisterInstanceAction::class)->execute(test()->node,
    new RegisterInstanceData('/srv/apps/register-proof/'.$name, false, test()->project->id, $name, null, null, false));

it('records a checkout with a private or absent .env at once, with no task', function (?array $env) use ($register): void {
    register_report($this->node, '/srv/apps/register-proof/native', ['env' => $env]);

    $instance = $register();

    expect($instance)->toBeInstanceOf(Instance::class)->and(Operation::query()->count())->toBe(1)
        ->and($instance->status->value)->toBe('active')->and($instance->branch)->toBe('feature')
        ->and($instance->starting_commit)->toBe(str_repeat('a', 40))->and($instance->registration_default_branch)->toBe('main')
        ->and($instance->registration_source_digest)->toBeNull()->and($instance->registration_completed_at)->not->toBeNull();
})->with([
    'absent .env' => [null],
    'owner-only .env' => [['kind' => 'regular', 'mode' => '0600', 'owner' => 'orbit']],
]);

it('starts the run that protects .env when others can read it, or when an older agent did not report it', function (Closure $change) use ($register): void {
    app()->instance(WebSocketClient::class, new FakeRoutePublicationSocket);
    config(['swamp.run_token' => 'native-register-fixture']);
    $change->call($this);

    expect($register())->toBeInstanceOf(Operation::class);
    expect(Instance::query()->where('name', 'native')->sole()->status->value)->toBe('reserved');
})->with([
    'group-readable .env' => [fn () => register_report($this->node, '/srv/apps/register-proof/native', ['env' => ['kind' => 'regular', 'mode' => '0640', 'owner' => 'orbit']])],
    'symlinked .env' => [fn () => register_report($this->node, '/srv/apps/register-proof/native', ['env' => ['kind' => 'symlink', 'mode' => '0777', 'owner' => 'orbit']])],
    'agent before 0.8.0' => [function (): void {
        register_report($this->node, '/srv/apps/register-proof/native');
        $fact = NodeFact::query()->where('subject', '/srv/apps/register-proof/native')->sole();
        $data = $fact->data;
        unset($data['env']);
        $fact->update(['data' => $data]);
    }],
]);

it("can't verify a checkout the agent has not reported, and reserves nothing", function () use ($register): void {
    app(CacheAgentStateView::class)->putSubscriber(true, true, 1);
    seed_agent_view($this->node->id, []);

    expect(fn () => $register())->toThrow(ResourceOperationException::class, "can't verify");
    expect(Instance::query()->count())->toBe(1)->and(Operation::query()->count())->toBe(1);
});

it('refuses a reported checkout that is not one plain checkout of the Project by the Node account', function (array $change, string $code) use ($register): void {
    register_report($this->node, '/srv/apps/register-proof/native', $change);

    try {
        $register();
        $this->fail('Registration should refuse.');
    } catch (ResourceOperationException $exception) {
        expect($exception->errorCode)->toBe($code);
    }
    expect(Instance::query()->count())->toBe(1);
})->with([
    'linked worktree' => [['linked' => true], 'instance.registration_conflict'],
    'sibling worktree' => [['worktrees' => ['/srv/apps/register-proof/native', '/srv/apps/register-proof/other']], 'instance.registration_conflict'],
    'another repository' => [['origin' => 'https://github.com/example/other.git'], 'instance.source_identity_invalid'],
    'masked origin' => [['origin' => 'https://REDACTED@github.com/example/proof.git'], 'instance.source_identity_invalid'],
    'another owner' => [['owner' => 'root'], 'instance.source_owner_invalid'],
    'no commit' => [['head' => null], 'instance.source_invalid'],
]);

it('admits and completes in-place registration through one real native HTTP root', function (): void {
    register_report($this->node, '/srv/apps/register-proof/native', ['env' => ['kind' => 'regular', 'mode' => '0644', 'owner' => 'orbit']]);
    $gateway = $this->markAsGateway(Node::query()->create(['name' => 'register-gateway', 'status' => 'active',
        'platform' => 'linux', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.1', 'wireguard_ip' => '10.44.0.1']));
    $this->node->accessibleNodes()->attach($gateway);
    $this->node->accessibleNodes()->attach($this->node);
    $this->withServerVariables(['REMOTE_ADDR' => $this->node->wireguard_ip]);
    $agent = Mockery::mock(AgentCommandRunner::class);
    $agent->shouldNotReceive('run');
    app()->instance(AgentCommandRunner::class, $agent);
    $socket = new FakeRoutePublicationSocket;
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.run_token' => 'native-register-fixture']);
    $body = ['project_id' => $this->project->id, 'source_path' => '/srv/apps/register-proof/native', 'instance_name' => 'native'];
    $response = $this->withHeader('Idempotency-Key', 'register.native.fixture')->postJson('/api/v1/instances/register', $body)->assertAccepted();
    $id = $response->json('data.id');
    $operation = Operation::query()->findOrFail($id);
    $instance = Instance::query()->findOrFail($operation->target_id);
    expect($instance->status->value)->toBe('reserved')->and($instance->branch)->toBeNull();
    app(StartSwampRun::class)->start($operation);
    expect($socket->sent)->toHaveCount(1);
    expect($socket->sent[0]['payload']['workflowIdOrName'])->toBe('operation-instance-register');
    expect($socket->sent[0]['payload']['inputs'])->toMatchArray(['operation_id' => $id, 'kind' => 'instance.register', 'node_id' => $this->node->id])
        ->and($socket->sent[0]['payload']['inputs']['intent']['checkout'])->toBe($operation->input['checkout']);
    $this->postJson('/api/v1/instances/register', $body)->assertAccepted()->assertJsonPath('data.id', $id);
    $this->withoutHeader('Idempotency-Key');
    $steps = ['Read the checkout files' => 'filesystem', 'Read the Git top level' => 'top', 'Read the Git common dir' => 'common', 'List the worktrees' => 'worktrees',
        'Read the origin' => 'origin', 'Read the branch' => 'branch', 'Read the commit' => 'commit', 'Read the default branch' => 'default', 'Protect the .env file' => null];
    $reads = register_reads($instance->checkout_path);
    $history = ['id' => 'a075eb23-7ac4-4487-80d1-57a038a92676', 'workflowName' => 'operation-instance-register',
        'inputs' => ['operation_id' => $operation->id, 'kind' => $operation->kind, 'node_id' => $operation->node_id],
        'status' => 'succeeded', 'startedAt' => now()->format('Y-m-d\TH:i:s.v\Z'), 'completedAt' => now()->format('Y-m-d\TH:i:s.v\Z'),
        'jobs' => [['name' => 'source', 'status' => 'succeeded', 'steps' => array_map(static fn (string $name, ?string $read): array => ['name' => $name, 'status' => 'succeeded',
            'outputs' => ['stdout' => $read === null ? '' : $reads[$read], 'exitCode' => 0]], array_keys($steps), $steps)]]];
    config(['swamp.read_token' => 'fixture.read']);
    $socket->reply = static fn (array $request): array => [['id' => $request['id'], 'type' => 'workflow.history.get', 'payload' => ['data' => $history]]];
    $selector = app(DirectOperation::class)->definition('instance.register')['terminal_result'];
    app(SwampCommandStep::class)->projectTerminal($operation, ['id' => $history['id'], 'status' => 'succeeded'], $selector);
    app(SwampCommandStep::class)->projectTerminal($operation, ['id' => $history['id'], 'status' => 'succeeded'], $selector);
    expect(array_column($socket->sent, 'type'))->toBe(['workflow.run', 'workflow.history.get']);
    expect($operation->refresh()->lock_key)->toBeNull();
    expect($instance->refresh()->source_is_laravel)->toBeFalse();
    expect(app(InstanceEnvironmentContextResolver::class)->resolve($instance, true)->laravel)->toBeFalse();
    expect($instance->status->value)->toBe('active')->and($instance->branch)->toBe('feature');
    expect($instance->registration_completed_at)->not->toBeNull();
    expect($operation->input)->not->toHaveKey('agent_sha256');
});

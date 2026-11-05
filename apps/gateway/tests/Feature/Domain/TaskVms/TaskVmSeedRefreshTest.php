<?php

declare(strict_types=1);

use App\Actions\TaskVms\StartTaskVmSeedRefreshAction;
use App\Domain\GitHub\GitHubAppCredentials;
use App\Domain\GitHub\GitHubAppStore;
use App\Domain\Nodes\WorkerKnownHostsPublisher;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmHost;
use App\Domain\TaskVms\TaskVmImageBuildState;
use App\Domain\TaskVms\TaskVmSeedRefresh;
use App\Domain\TaskVms\TaskVmSeedRefreshIntent;
use App\Domain\TaskVms\TaskVmSeedRefreshProjection;
use App\Domain\TaskVms\TaskVmSettings;
use App\Domain\TaskVms\TaskVmSlot;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketEndpoint;
use App\Infrastructure\TaskVms\TaskVmVolumes;
use App\Models\Cluster;
use App\Models\Node;
use App\Models\Operation;
use App\Models\TaskVmImageBuild;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

uses(LazilyRefreshDatabase::class);

const RefreshCommit = 'dddddddddddd0000000000000000000000000000';
const RefreshImage = 'c1c2cf4051fb0123456789abcdef0123456789abcdef0123456789abcdef0123';

beforeEach(function (): void {
    // A release layout whose current release is RefreshCommit.
    $this->base = sys_get_temp_dir().'/orbit-seed-refresh-'.bin2hex(random_bytes(4));
    mkdir($this->base.'/releases/'.substr(RefreshCommit, 0, 12).'/apps/gateway', 0700, true);
    file_put_contents($this->base.'/releases/'.substr(RefreshCommit, 0, 12).'/REVISION', RefreshCommit."\n");
    symlink('releases/'.substr(RefreshCommit, 0, 12), $this->base.'/orbit');
    config(['orbit.gateway_checkout' => $this->base.'/orbit/apps/gateway', 'swamp.proof_club_api_key' => 'proof-club-key']);
});

afterEach(function (): void {
    Process::run(['rm', '-rf', $this->base]);
});

/** A configured task VM host with a promoted image, the GitHub App that reads the release repository, and a vault that records its puts. */
function refreshWorld(): array
{
    $cluster = Cluster::query()->create(['name' => 'dev', 'tld' => 'dev']);
    $host = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'beast', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64', 'public_ssh_host' => '10.44.0.9',
        'wireguard_ip' => '10.44.0.9', 'user' => 'orbit', 'ssh_host_fingerprint' => 'SHA256:fixture', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key',
    ]));
    app()->instance(TaskVmSettings::class, new TaskVmSettings(true, $cluster->id, '10.44.0.128/25',
        [new TaskVmHost($host->id, 'orbit-tasks', 'orbittask0', '10.251.77.0/24', 5, 4, '8GiB', '40GiB')],
        'http://10.44.0.3:8317', '/pi', str_repeat('a', 64), ['gpt-6']));
    app()->instance(WorkerKnownHostsPublisher::class, new class implements WorkerKnownHostsPublisher
    {
        public function publish(): void {}
    });
    TaskVmImageBuild::query()->create(['host_node_id' => $host->id, 'recipe_identity' => 'recipe-1', 'state' => TaskVmImageBuildState::Succeeded,
        'promoted_at' => now(), 'images' => ['base' => ['project' => 'orbit-tasks', 'type' => 'virtual-machine', 'fingerprint' => RefreshImage]]]);
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $pem);
    app(GitHubAppStore::class)->put(new GitHubAppCredentials(4242, 'proof', 'proof', 'hardimpactdev', 'organization', 'https://github.com/apps/proof', $pem));
    Http::fake(fn (Request $request) => match (true) {
        str_ends_with($request->url(), '/installation') => Http::response(['id' => 123]),
        str_ends_with($request->url(), '/access_tokens') => Http::response(['token' => 'read-token', 'permissions' => ['contents' => 'read', 'metadata' => 'read'],
            'expires_at' => now()->addHour()->format('Y-m-d\TH:i:s\Z')], 201),
        default => Http::response('unexpected '.$request->url(), 500),
    });
    $socket = new class implements WebSocketClient
    {
        /** @var list<array<string,mixed>> */
        public array $frames = [];

        private bool $open = false;

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

    return [$host, $socket];
}

/** A running seed refresh Operation with the intent of the world's host. */
function runningRefresh(Node $host): Operation
{
    $input = app(TaskVmSeedRefreshIntent::class)->capture($host);

    return Operation::query()->create(['kind' => 'task_vm.seed_refresh', 'target_type' => 'task_vm_host', 'target_id' => $host->id, 'node_id' => $host->id,
        'status' => 'running', 'sequence' => 1, 'attempts' => 1, 'input' => $input, 'state' => ['swamp_run_id' => 'fixture-refresh']]);
}

/** @return array<string,mixed> */
function refreshRun(string $status, ?string $promoted = null, ?string $ready = null, string $endCheck = 'succeeded'): array
{
    return ['run_id' => 'fixture-refresh', 'status' => $status, 'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(),
        'result' => ['steps' => ['Check the seed' => ['status' => $endCheck]], 'summary' => ['failed_steps' => $endCheck === 'failed' ? ['Check the seed'] : []],
            ...($promoted === null ? [] : ['promoted' => ['stdout' => $promoted."\n"]]),
            ...($ready === null ? [] : ['ready' => ['stdout' => $ready."\n"]])]];
}

function promotedReport(array $change = []): string
{
    return json_encode(['seed' => 'orbit-topology-seed-c1c2cf4051fb',
        'referenced_bytes' => 6_300_796_128, 'written_bytes' => 778_560_288, ...$change], JSON_THROW_ON_ERROR);
}

const ReadyList = '["ready-app-dev","ready-app-prod","ready-gateway"]';

it('names the seed a claim clones from the image, and the refresh swaps what that name holds', function (): void {
    $fingerprint = RefreshImage;

    expect(TaskVmVolumes::seed($fingerprint))->toBe('orbit-topology-seed-c1c2cf4051fb');
    expect(TaskVmSlot::seedSource($fingerprint))->toBe(TaskVmVolumes::seed($fingerprint));
    expect(TaskVmSeedRefresh::building($fingerprint))->toBe('orbit-topology-seed-c1c2cf4051fb-refresh');
    expect(TaskVmVolumes::previousSeed($fingerprint))->toBe('orbit-topology-seed-c1c2cf4051fb-previous');
    // The host audit and the stray report own all three names of a kept image, and no other seed name.
    foreach (['', '-new', '-refresh', '-previous'] as $suffix) {
        expect(TaskVmVolumes::owns('orbit-topology-seed-c1c2cf4051fb'.$suffix, [RefreshImage]))->toBeTrue();
        expect(TaskVmVolumes::owns('orbit-topology-seed-c1c2cf4051fb'.$suffix, ['aaaaaaaaaaaa']))->toBeFalse();
    }
    expect(TaskVmVolumes::owns('orbit-topology-seed-c1c2cf4051fb-old', [RefreshImage]))->toBeFalse();
})->group('seed-refresh');

it('boots the builder as a large VM from the task image with the seed clone as its topology disk', function (): void {
    $config = json_decode(TaskVmSeedRefresh::config(new TaskVmHost(1, 'orbit-tasks', 'orbittask0', '10.251.77.0/24', 5, 4, '8GiB', '40GiB'), RefreshImage), true, flags: JSON_THROW_ON_ERROR);

    expect($config['config']['limits.cpu'])->toBe('6');
    expect($config['config']['limits.memory'])->toBe('12GiB');
    expect($config['config']['raw.qemu.conf'])->toContain('free-page-reporting');
    expect($config['devices']['root'])->toMatchArray(['pool' => 'orbit-tasks', 'size' => '60GiB']);
    expect($config['devices']['eth0'])->toMatchArray(['network' => 'orbittask0', 'type' => 'nic']);
    expect($config['devices']['topology-seed'])->toMatchArray(['type' => 'disk', 'pool' => 'orbit-tasks', 'source' => 'orbit-topology-seed-c1c2cf4051fb-refresh']);
    // The builder is no fleet Node: no static address, no identity volume, no cloud-init account.
    expect(array_keys($config['devices']))->toBe(['root', 'eth0', 'topology-seed']);
    expect(TaskVmSeedRefresh::BuilderName)->not->toMatch('/\Atvm-[0-9]+\z/D');
    expect(TaskVmSeedRefresh::Branch)->toBe('seed-refresh/task-1');
})->group('seed-refresh');

it('renders the full public intent of a refresh and sends only vault names for the credentials', function (): void {
    [$host, $socket] = refreshWorld();

    $input = app(TaskVmSeedRefreshIntent::class)->capture($host);

    expect($input)->toMatchArray(['host_node_id' => $host->id, 'node_id' => $host->id]);
    expect($input['image'])->toBe(['fingerprint' => RefreshImage, 'recipe_identity' => 'recipe-1']);
    expect($input['seed'])->toBe(['project' => 'orbit-tasks', 'pool' => 'orbit-tasks', 'current' => 'orbit-topology-seed-c1c2cf4051fb',
        'building' => 'orbit-topology-seed-c1c2cf4051fb-refresh', 'previous' => 'orbit-topology-seed-c1c2cf4051fb-previous', 'size' => '45GiB']);
    expect($input['builder'])->toMatchArray(['name' => 'tvm-seed-refresh', 'checkout' => '/home/orbit/apps/orbit/workspace', 'group' => '1',
        'branch' => 'seed-refresh/task-1', 'roles' => ['gateway', 'app-dev', 'app-prod']]);
    expect($input['source'])->toBe(['repository' => 'https://github.com/hardimpactdev/orbit', 'commit' => RefreshCommit]);
    expect($input['host_ssh_execution']['target'])->toMatchArray(['host' => '10.44.0.9', 'user' => 'orbit', 'port' => 22]);

    expect($input['execution_secrets'])->toBe([
        'git_authorization' => ['vault' => 'orbit-control-inputs', 'key' => 'task-vm-seed-refresh-git-'.$host->id],
        'proof_club' => ['vault' => 'orbit-control-inputs', 'key' => 'swamp-proof-club-api-key'],
    ]);
    $encoded = json_encode($input, JSON_THROW_ON_ERROR);
    expect($encoded)->not->toContain('read-token')->not->toContain('proof-club-key')->not->toContain(base64_encode('x-access-token:read-token'));
    expect(collect($socket->frames)->pluck('payload.key')->sort()->values()->all())->toBe(['swamp-proof-club-api-key', 'task-vm-seed-refresh-git-'.$host->id]);
    expect(collect($socket->frames)->firstWhere('payload.key', 'task-vm-seed-refresh-git-'.$host->id)['payload']['value'])->toBe('Authorization: Basic '.base64_encode('x-access-token:read-token'));

    $operation = Operation::query()->create(['kind' => 'task_vm.seed_refresh', 'target_type' => 'task_vm_host', 'target_id' => $host->id, 'node_id' => $host->id,
        'status' => 'queued', 'sequence' => 1, 'attempts' => 0, 'input' => $input]);
    $envelope = app(TaskVmSeedRefreshProjection::class)->input($operation);
    expect(array_keys($envelope['execution_secrets']))->toBe(['git_authorization', 'proof_club']);
    expect($envelope['intent'])->not->toHaveKey('execution_secrets')->toHaveKeys(['seed', 'builder', 'source', 'image']);
})->group('seed-refresh');

it('refuses a host that cannot refresh, before any credential is copied', function (): void {
    [$host, $socket] = refreshWorld();
    $code = static fn (Closure $call): string => rescue(fn () => $call() ?? 'none', static fn (ResourceOperationException $error): string => $error->errorCode, false);
    $other = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'plain', 'status' => 'active', 'platform' => 'linux',
        'public_ssh_host' => '10.44.0.8', 'wireguard_ip' => '10.44.0.8', 'user' => 'orbit']));

    expect($code(fn () => app(TaskVmSeedRefreshIntent::class)->capture($other)))->toBe('task_vm.host_unavailable');

    $build = TaskVmImageBuild::query()->create(['host_node_id' => $host->id, 'recipe_identity' => 'recipe-2', 'state' => TaskVmImageBuildState::Running]);
    expect($code(fn () => app(TaskVmSeedRefreshIntent::class)->capture($host)))->toBe('task_vm.image_build_running');
    $build->delete();

    TaskVmImageBuild::query()->where('host_node_id', $host->id)->delete();
    expect($code(fn () => app(TaskVmSeedRefreshIntent::class)->capture($host)))->toBe('task_vm.image_unavailable');
    expect($socket->frames)->toBe([]);
})->group('seed-refresh');

it('refuses a refresh without the proof club key or a readable release', function (): void {
    [$host] = refreshWorld();
    $code = static fn (Closure $call): string => rescue(fn () => $call() ?? 'none', static fn (ResourceOperationException $error): string => $error->errorCode, false);

    config(['swamp.proof_club_api_key' => '']);
    expect($code(fn () => app(TaskVmSeedRefreshIntent::class)->capture($host)))->toBe('task_vm.seed_refresh_unavailable');

    config(['swamp.proof_club_api_key' => 'proof-club-key']);
    unlink($this->base.'/orbit');
    expect($code(fn () => app(TaskVmSeedRefreshIntent::class)->capture($host)))->toBe('task_vm.seed_refresh_unavailable');
})->group('seed-refresh');

it('admits one refresh as one Operation on the host and returns the running one for a second', function (): void {
    [$host] = refreshWorld();

    $operation = rescue(fn (): Operation => app(StartTaskVmSeedRefreshAction::class)->execute($host), fn (Throwable $error): Operation => Operation::query()->latest('id')->firstOrFail(), false);

    expect(Operation::query()->where('kind', 'task_vm.seed_refresh')->count())->toBe(1);
    expect([$operation->kind, $operation->target_type, $operation->target_id, $operation->node_id])->toBe(['task_vm.seed_refresh', 'task_vm_host', $host->id, $host->id]);
    expect($operation->lock_key)->toBe('task_vm_host:'.$host->id);
    expect($operation->input['seed']['current'])->toBe('orbit-topology-seed-c1c2cf4051fb');

    $again = rescue(fn (): Operation => app(StartTaskVmSeedRefreshAction::class)->execute($host), fn (Throwable $error): Operation => Operation::query()->latest('id')->firstOrFail(), false);

    expect($again->id)->toBe($operation->id);
    expect(Operation::query()->where('kind', 'task_vm.seed_refresh')->count())->toBe(1);
})->group('seed-refresh');

it('records what the host holds after a refresh from the reports of the run', function (): void {
    Http::preventStrayRequests();
    [$host] = refreshWorld();
    $operation = runningRefresh($host);

    app(TaskVmSeedRefreshProjection::class)->apply($operation, refreshRun('succeeded', promotedReport(), ReadyList));

    expect($operation->refresh()->status->value)->toBe('succeeded');
    expect($operation->result)->toBe(['ready' => true, 'host_node_id' => $host->id, 'image_fingerprint' => RefreshImage, 'seed' => 'orbit-topology-seed-c1c2cf4051fb',
        'roles' => ['gateway', 'app-dev', 'app-prod'], 'referenced_bytes' => 6_300_796_128, 'written_bytes' => 778_560_288]);
})->group('seed-refresh');

it('refuses a success without the end check, the right seed, the three ready copies or a size', function (string $case): void {
    [$host] = refreshWorld();
    $operation = runningRefresh($host);
    $run = match ($case) {
        'no end check' => refreshRun('succeeded', promotedReport(), ReadyList, 'failed'),
        'no promotion report' => refreshRun('succeeded', null, ReadyList),
        'no ready report' => refreshRun('succeeded', promotedReport(), null),
        'another seed' => refreshRun('succeeded', promotedReport(['seed' => 'orbit-topology-seed-aaaaaaaaaaaa']), ReadyList),
        'a missing ready copy' => refreshRun('succeeded', promotedReport(), '["ready-app-dev","ready-gateway"]'),
        'an extra ready copy' => refreshRun('succeeded', promotedReport(), '["ready-app-dev","ready-app-prod","ready-gateway","ready-operator"]'),
        'a bad size' => refreshRun('succeeded', promotedReport(['referenced_bytes' => 0]), ReadyList),
        'a bad written size' => refreshRun('succeeded', promotedReport(['written_bytes' => 'many']), ReadyList),
        'text that is no report' => refreshRun('succeeded', 'promoted', ReadyList),
    };

    expect(fn () => app(TaskVmSeedRefreshProjection::class)->apply($operation, $run))
        ->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('operation.result_invalid'));
    expect($operation->refresh()->status->value)->toBe('running');
})->with(['no end check', 'no promotion report', 'no ready report', 'another seed', 'a missing ready copy', 'an extra ready copy', 'a bad size', 'a bad written size',
    'text that is no report'])->group('seed-refresh');

it('records a failed, cancelled or interrupted run as failed and keeps no result', function (string $status): void {
    [$host] = refreshWorld();
    $operation = runningRefresh($host);

    app(TaskVmSeedRefreshProjection::class)->apply($operation, [...refreshRun($status, null, null, 'failed'), 'error_code' => 'operation.native_failed']);

    expect($operation->refresh()->status->value)->toBe($status === 'interrupted' ? 'failed' : $status);
    expect($operation->result ?? [])->not->toHaveKey('ready')->not->toHaveKey('seed');
})->with(['failed', 'cancelled', 'interrupted'])->group('seed-refresh');

it('accepts only a refresh of its own host', function (): void {
    [$host] = refreshWorld();
    $operation = runningRefresh($host);
    $operation->forceFill(['target_id' => $host->id + 1])->save();

    expect(fn () => app(TaskVmSeedRefreshProjection::class)->input($operation))
        ->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('operation.execution_forbidden'));
})->group('seed-refresh');

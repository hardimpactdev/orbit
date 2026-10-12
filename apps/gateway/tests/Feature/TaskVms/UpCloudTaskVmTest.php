<?php

declare(strict_types=1);

use App\Actions\TaskVms\AllocateTaskVmAction;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\TaskCapacityException;
use App\Domain\Tasks\TaskCompute;
use App\Domain\Tasks\TaskStatus;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmHost;
use App\Domain\TaskVms\TaskVmProvider;
use App\Domain\TaskVms\TaskVmSettings;
use App\Domain\TaskVms\TaskVmSize;
use App\Domain\TaskVms\TaskVmState;
use App\Domain\TaskVms\TaskVmUpCloud;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\KnownHostsRepository;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\TaskVms\IncusTaskVmProvider;
use App\Infrastructure\TaskVms\TaskVmImageRecipe;
use App\Infrastructure\TaskVms\TaskVmProviders;
use App\Infrastructure\TaskVms\TaskVmWorkspace;
use App\Infrastructure\TaskVms\UpCloudServers;
use App\Infrastructure\TaskVms\UpCloudTaskVmImageBuilder;
use App\Infrastructure\TaskVms\UpCloudTaskVmProvider;
use App\Jobs\TaskVms\ProvisionTaskVm;
use App\Jobs\TaskVms\RefreshUpCloudTaskVmTemplate;
use App\Jobs\TaskVms\SweepTaskVmHosts;
use App\Models\Node;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskVm;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;

use function Pest\Laravel\mock;

const TVM_UP_GATEWAY_KEY = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGatewayKey gateway';

/** A fake UpCloud API: servers and template storages, with the state changes the provider waits for. */
final class FakeUpCloud
{
    /** @var array<string, array<string, mixed>> */
    public array $servers = [];

    /** @var array<string, array<string, mixed>> */
    public array $storages = [];

    /** @var list<string> */
    public array $requests = [];

    /** @var list<array<string, mixed>> */
    public array $created = [];

    /** List servers without their labels, as the list may. */
    public bool $listWithoutLabels = false;

    private int $addresses = 10;

    public function install(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://api.upcloud.com/1.3/*' => fn (Request $request): PromiseInterface => $this->handle($request)]);
    }

    /** @param  list<array{key: string, value: string}>  $labels */
    public function server(string $title, array $labels, string $state = 'started'): string
    {
        $uuid = (string) Str::uuid();
        $disk = (string) Str::uuid();
        $this->servers[$uuid] = [
            'uuid' => $uuid, 'title' => $title, 'hostname' => $title, 'state' => $state, 'next' => 'started',
            'labels' => ['label' => $labels],
            'ip_addresses' => ['ip_address' => [['access' => 'public', 'family' => 'IPv4', 'address' => '94.237.1.'.$this->addresses++]]],
            'storage_devices' => ['storage_device' => [['storage' => $disk, 'type' => 'disk']]],
        ];

        return $uuid;
    }

    public function template(string $title): string
    {
        $uuid = (string) Str::uuid();
        $this->storages[$uuid] = ['uuid' => $uuid, 'title' => $title, 'access' => 'private', 'type' => 'template', 'state' => 'online'];

        return $uuid;
    }

    public function count(string $prefix): int
    {
        return count(array_filter($this->requests, static fn (string $request): bool => str_starts_with($request, $prefix)));
    }

    private function handle(Request $request): PromiseInterface
    {
        $url = parse_url($request->url());
        $path = substr((string) ($url['path'] ?? ''), strlen('/1.3/'));
        $query = (string) ($url['query'] ?? '');
        $method = $request->method();
        $this->requests[] = $method.' '.$path.($query === '' ? '' : '?'.$query);
        $body = $request->data();

        if ($method === 'GET' && $path === 'server') {
            parse_str($query, $params);
            $key = $params['label'] ?? null;
            $servers = array_values(array_filter($this->servers, static fn (array $server): bool => $key === null
                || array_any($server['labels']['label'], static fn (array $label): bool => $label['key'] === $key)));

            if ($this->listWithoutLabels) {
                $servers = array_map(static fn (array $server): array => array_diff_key($server, ['labels' => true]), $servers);
            }

            return Http::response(['servers' => ['server' => $servers]]);
        }
        if ($method === 'POST' && $path === 'server') {
            $this->created[] = $body['server'];
            $uuid = $this->server($body['server']['title'], $body['server']['labels']['label'], 'maintenance');

            return Http::response(['server' => $this->servers[$uuid]], 202);
        }
        if (preg_match('#\Aserver/([0-9a-f-]{36})(?:/(start|stop))?\z#', $path, $match) === 1) {
            $server = &$this->servers[$match[1]];
            if ($server === null) {
                unset($this->servers[$match[1]]);

                return Http::response(['error' => ['error_code' => 'SERVER_NOT_FOUND']], 404);
            }
            if ($method === 'GET') {
                $answer = $server;
                // Each reading moves a server that is changing to its next state.
                if ($server['state'] === 'maintenance') {
                    $server['state'] = $server['next'];
                }

                return Http::response(['server' => $answer]);
            }
            if ($method === 'POST' && ($match[2] ?? null) === 'stop') {
                [$server['state'], $server['next']] = ['maintenance', 'stopped'];
                $server['stop_type'] = $body['stop_server']['stop_type'];

                return Http::response(['server' => $server], 202);
            }
            if ($method === 'POST' && ($match[2] ?? null) === 'start') {
                [$server['state'], $server['next']] = ['maintenance', 'started'];

                return Http::response(['server' => $server], 202);
            }
            if ($method === 'DELETE') {
                if ($server['state'] !== 'stopped') {
                    return Http::response(['error' => ['error_code' => 'SERVER_STATE_ILLEGAL']], 400);
                }
                unset($this->servers[$match[1]]);

                return Http::response('', 204);
            }
        }
        if ($method === 'GET' && $path === 'storage/template') {
            return Http::response(['storages' => ['storage' => array_values($this->storages)]]);
        }
        if (preg_match('#\Astorage/([0-9a-f-]{36})(/templatize)?\z#', $path, $match) === 1) {
            if (($match[2] ?? '') === '/templatize') {
                $uuid = (string) Str::uuid();
                $this->storages[$uuid] = ['uuid' => $uuid, 'title' => $body['storage']['title'], 'access' => 'private', 'type' => 'template', 'state' => 'maintenance'];

                return Http::response(['storage' => $this->storages[$uuid]], 201);
            }
            $storage = &$this->storages[$match[1]];
            if ($storage === null) {
                unset($this->storages[$match[1]]);

                return Http::response(['error' => ['error_code' => 'STORAGE_NOT_FOUND']], 404);
            }
            if ($method === 'GET') {
                $answer = $storage;
                $storage['state'] = 'online';

                return Http::response(['storage' => $answer]);
            }
            if ($method === 'PUT') {
                $storage['title'] = $body['storage']['title'];

                return Http::response(['storage' => $storage], 202);
            }
            if ($method === 'DELETE') {
                unset($this->storages[$match[1]]);

                return Http::response('', 204);
            }
        }

        return Http::response(['error' => ['error_code' => 'UNEXPECTED', 'error_message' => $method.' '.$path]], 500);
    }
}

/** Answers the SSH commands that reach an UpCloud server before it is a Node. */
class FakeUpCloudSsh implements SshExecutor
{
    /** @var list<array{string, list<string>, ?string}> */
    public array $calls = [];

    public string $cloudInit = '{"status":"done","errors":[]}';

    public ?string $manifest = null;

    public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
    {
        $this->calls[] = [$connection->host, $command->arguments, $command->input];
        $line = implode(' ', $command->arguments);

        return match (true) {
            str_contains($line, 'cloud-init status') => new CommandResult($this->cloudInit === '' ? 255 : 0, $this->cloudInit, '', 1, false),
            $command->arguments === ['uname', '-m'] => new CommandResult(0, "x86_64\n", '', 1, false),
            str_contains($line, 'bash -s') => new CommandResult(0, "{\"ok\":true}\n", '', 1, false),
            str_contains($line, 'printf absent') => new CommandResult(0, $this->manifest ?? 'absent', '', 1, false),
            $command->arguments === ['sudo', '-n', 'ufw', 'status'] => new CommandResult(0, "Status: active\n", '', 1, false),
            default => new CommandResult(0, '', '', 1, false),
        };
    }
}

/** @param  array<string, mixed>  $upcloud */
function tvm_up_settings(?array $upcloud = [], int $maxVms = 1, ?int $hostId = null): void
{
    app()->instance(TaskVmSettings::class, new TaskVmSettings(
        enabled: true, devClusterId: 4, wireguardRange: '10.44.0.128/25',
        hosts: [new TaskVmHost($hostId ?? test()->host->id, 'orbit-tasks', 'orbittask0', '10.251.77.0/24', $maxVms, 2, '4GiB', '20GiB')],
        modelProxyOrigin: 'http://10.44.0.3:8317', piArtifactPath: null, piArtifactSha256: null, piModels: [],
        upcloud: $upcloud === null ? null : new TaskVmUpCloud(...[
            'enabled' => true, 'projectIds' => [test()->project->id], 'tokenFile' => test()->tokenFile, 'maxVms' => 2,
            'zone' => 'nl-ams1', 'plan' => '2xCPU-4GB', 'diskGb' => 25, 'sourceTemplate' => '01000000-0000-4000-8000-000030260200',
            ...$upcloud,
        ]),
    ));
}

function tvm_up_group(?Project $project = null): Task
{
    return Task::topLevel()->create([
        'project_id' => ($project ?? test()->project)->id, 'title' => 'Export', 'brief' => 'Work', 'status' => 'reserved',
        'task_compute' => TaskCompute::Vm, 'implementer_agent_driver' => 'pi', 'reviewer_agent_driver' => 'pi',
    ]);
}

function tvm_up_vm(TaskVmState $state = TaskVmState::Provisioning, ?string $hostKey = null): TaskVm
{
    $vm = TaskVm::query()->create([
        'group_id' => tvm_up_group()->id, 'host_node_id' => null, 'provider' => TaskVm::UpCloud, 'name' => 'tvm-pending-'.Str::uuid(),
        'state' => $state, 'wireguard_ip' => '10.44.0.'.(129 + TaskVm::query()->count()), 'pi_token' => str_repeat('p', 64),
        'ssh_host_key' => $hostKey,
    ]);
    $vm->update(['name' => 'tvm-'.$vm->id]);

    return $vm;
}

beforeEach(function (): void {
    $this->project = Project::query()->create(['name' => 'DLF', 'slug' => 'dlf', 'repository_url' => 'https://github.com/acme/dlf.git', 'default_branch' => 'main']);
    $this->host = Node::query()->create([
        'name' => 'beast', 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'architecture' => 'x86_64',
        'public_ssh_host' => '10.44.0.7', 'wireguard_ip' => '10.44.0.7', 'user' => 'nckrtl',
    ]);
    $this->tokenFile = tempnam(sys_get_temp_dir(), 'upcloud-token-');
    file_put_contents($this->tokenFile, "token: ucat_test_token\n");
    chmod($this->tokenFile, 0600);
    $this->upcloud = new FakeUpCloud;
    $this->upcloud->install();
    $this->ssh = new FakeUpCloudSsh;
    app()->instance(SshExecutor::class, $this->ssh);
    app()->instance(KnownHostsStore::class, new KnownHostsRepository(sys_get_temp_dir().'/upcloud-known-hosts-'.Str::random(8).'/known_hosts'));
    mock(SshKeyProvider::class)->shouldReceive('publicKey')->andReturn(TVM_UP_GATEWAY_KEY)
        ->shouldReceive('privateKeyPath')->andReturn('/tmp/gateway-key');
    Sleep::fake();
    tvm_up_settings();
});

afterEach(function (): void {
    @unlink($this->tokenFile);
});

describe('settings', function (): void {
    /** @param  array<string, mixed>  $upcloud */
    function tvm_up_config(array $upcloud, bool $taskVms = true): void
    {
        config(['task_vms' => [
            'enabled' => $taskVms, 'dev_cluster_id' => $taskVms ? 4 : null, 'wireguard_range' => '10.44.0.128/25',
            'model_proxy_origin' => $taskVms ? 'http://10.44.0.3:8317' : null, 'park_after_minutes' => null,
            'pi' => ['artifact_path' => null, 'artifact_sha256' => null, 'models' => []],
            'incus' => ['hosts' => $taskVms ? [['node_id' => 7, 'cidr' => '10.251.77.0/24', 'max_vms' => 4]] : []],
            'upcloud' => [...['enabled' => false, 'projects' => [], 'token_file' => null, 'max_vms' => null, 'zone' => null, 'plan' => null, 'disk_gb' => null, 'source_template' => null], ...$upcloud],
        ]]);
    }

    it('leaves UpCloud unconfigured while every value is unset', function (): void {
        $config = config_without_env('task_vms.php', [
            'ORBIT_TASK_VMS_UPCLOUD_ENABLED', 'ORBIT_TASK_VMS_UPCLOUD_PROJECTS', 'ORBIT_TASK_VMS_UPCLOUD_TOKEN_FILE', 'ORBIT_TASK_VMS_UPCLOUD_MAX_VMS',
            'ORBIT_TASK_VMS_UPCLOUD_ZONE', 'ORBIT_TASK_VMS_UPCLOUD_PLAN', 'ORBIT_TASK_VMS_UPCLOUD_DISK_GB', 'ORBIT_TASK_VMS_UPCLOUD_SOURCE_TEMPLATE',
        ]);
        tvm_up_config($config['upcloud']);

        expect($config['upcloud']['enabled'])->toBeFalse()
            ->and($config['upcloud']['projects'])->toBe([])
            ->and(TaskVmSettings::fromConfig()->upcloud)->toBeNull();
    });

    it('reads the overflow with its defaults', function (): void {
        tvm_up_config(['enabled' => true, 'projects' => [12, 12, 4], 'token_file' => '/home/orbit/upcloud.yaml', 'max_vms' => '3']);

        expect(TaskVmSettings::fromConfig()->upcloud)->toEqual(new TaskVmUpCloud(true, [12, 4], '/home/orbit/upcloud.yaml', 3, 'nl-ams1', '2xCPU-4GB', 25, '01000000-0000-4000-8000-000030260200'))
            ->and(TaskVmSettings::fromConfig()->upcloud?->allows(4))->toBeTrue()
            ->and(TaskVmSettings::fromConfig()->upcloud?->allows(5))->toBeFalse();
    });

    it('keeps a configured but switched-off overflow, which allows no Project', function (): void {
        tvm_up_config(['token_file' => '/home/orbit/upcloud.yaml', 'projects' => [12], 'max_vms' => 1]);

        expect(TaskVmSettings::fromConfig()->upcloud?->allows(12))->toBeFalse();
    });

    it('refuses an invalid overflow', function (array $upcloud, bool $taskVms, string $reason): void {
        tvm_up_config($upcloud, $taskVms);

        expect(fn () => TaskVmSettings::fromConfig())->toThrow(TaskVmException::class, $reason);
    })->with([
        'enabled without token' => [['enabled' => true, 'max_vms' => 1], true, 'upcloud.token_file is required'],
        'enabled without task VMs' => [['enabled' => true, 'token_file' => '/t', 'max_vms' => 1], false, 'needs task VMs enabled'],
        'no max_vms' => [['token_file' => '/t'], true, 'upcloud.max_vms'],
        'max_vms too high' => [['token_file' => '/t', 'max_vms' => 17], true, 'upcloud.max_vms'],
        'relative token file' => [['token_file' => 'token.yaml', 'max_vms' => 1], true, 'absolute path'],
        'project names' => [['token_file' => '/t', 'max_vms' => 1, 'projects' => ['dlf']], true, 'Project ids'],
        'zone' => [['token_file' => '/t', 'max_vms' => 1, 'zone' => 'Amsterdam'], true, 'upcloud.zone'],
        'disk' => [['token_file' => '/t', 'max_vms' => 1, 'disk_gb' => 5], true, 'upcloud.disk_gb'],
    ]);
});

describe('placement', function (): void {
    beforeEach(function (): void {
        Queue::fake();
        // beast's one slot is taken.
        TaskVm::query()->create([
            'group_id' => tvm_up_group()->id, 'host_node_id' => $this->host->id, 'provider' => TaskVm::Incus, 'name' => 'tvm-full',
            'state' => TaskVmState::Ready, 'wireguard_ip' => '10.44.0.200', 'pi_token' => str_repeat('p', 64),
        ]);
    });

    it('places an opted-in group at UpCloud only when no host has a free slot', function (): void {
        $vm = app(AllocateTaskVmAction::class)->execute(tvm_up_group());

        expect($vm->provider)->toBe(TaskVm::UpCloud)
            ->and($vm->host_node_id)->toBeNull()
            ->and($vm->name)->toBe('tvm-'.$vm->id)
            ->and($vm->state)->toBe(TaskVmState::Provisioning);
        Queue::assertPushed(ProvisionTaskVm::class, fn (ProvisionTaskVm $job): bool => $job->taskVmId === $vm->id);
    });

    it('never places a large VM at UpCloud', function (): void {
        tvm_up_settings(maxVms: 2);
        $group = tvm_up_group();
        Task::query()->create(['parent_id' => $group->id, 'position' => 1, 'title' => 'Dev', 'brief' => 'Dev', 'status' => TaskStatus::Todo, 'topology' => ['app-dev']]);

        expect(fn () => app(AllocateTaskVmAction::class)->execute($group))->toThrow(TaskCapacityException::class, AllocateTaskVmAction::NoLargeCapacity)
            ->and(TaskVm::query()->where('provider', TaskVm::UpCloud)->count())->toBe(0)
            ->and(TaskVmSize::forGroup($group))->toBe(TaskVmSize::Large);
    });

    it('prefers a free Incus slot', function (): void {
        tvm_up_settings(maxVms: 2);

        expect(app(AllocateTaskVmAction::class)->execute(tvm_up_group())->provider)->toBe(TaskVm::Incus);
    });

    it('keeps the group waiting without the switch, the opt-in, or the configuration', function (?array $upcloud): void {
        tvm_up_settings($upcloud);

        expect(fn () => app(AllocateTaskVmAction::class)->execute(tvm_up_group()))->toThrow(TaskCapacityException::class, AllocateTaskVmAction::NoCapacity)
            ->and(TaskVm::query()->where('provider', TaskVm::UpCloud)->count())->toBe(0);
    })->with([
        'switch off' => [['enabled' => false]],
        'Project not opted in' => [['projectIds' => [999]]],
        'not configured' => [null],
    ]);

    it('never places more live UpCloud VMs than max_vms, failed and parked ones too', function (): void {
        tvm_up_settings(['maxVms' => 2]);
        tvm_up_vm(TaskVmState::Failed);
        tvm_up_vm(TaskVmState::Parked);

        expect(fn () => app(AllocateTaskVmAction::class)->execute(tvm_up_group()))->toThrow(TaskCapacityException::class, AllocateTaskVmAction::NoCapacity);
    });

    it('gives an UpCloud VM no workspace template', function (): void {
        $vm = tvm_up_vm();

        expect(app(TaskVmWorkspace::class)->template($vm, $vm->group, '/home/orbit/apps'))->toBeNull();
    });
});

describe('provider', function (): void {
    it('is the provider of an upcloud row, and Incus stays the provider of an incus row', function (): void {
        $providers = new ReflectionClass(TaskVmProviders::class);
        $for = $providers->getMethod('for');
        $router = app(TaskVmProviders::class);
        $incus = TaskVm::query()->make(['provider' => TaskVm::Incus, 'name' => 'tvm-1']);

        expect($for->invoke($router, tvm_up_vm()))->toBeInstanceOf(UpCloudTaskVmProvider::class)
            ->and($for->invoke($router, $incus))->toBeInstanceOf(IncusTaskVmProvider::class)
            ->and(app(TaskVmProvider::class))->toBeInstanceOf(TaskVmProviders::class);
    });

    it('creates one server from the newest verified template with the pinned host key and one public IPv4 interface', function (): void {
        $this->upcloud->template('orbit-task-base-20261010000000');
        $current = $this->upcloud->template('orbit-task-base-20261011000000');
        $this->upcloud->template('orbit-task-base-20261012000000-unverified');
        $vm = tvm_up_vm();

        app(UpCloudTaskVmProvider::class)->create($vm, "#cloud-config\n{\"users\":[]}\n");
        $vm->refresh();

        $request = $this->upcloud->created[0];
        $userData = json_decode(substr($request['user_data'], 14), true);
        expect($this->upcloud->created)->toHaveCount(1)
            ->and($request['title'])->toBe($vm->name)
            ->and($request['hostname'])->toBe($vm->name)
            ->and($request['labels'])->toBe(['label' => [['key' => 'orbit-task-vm', 'value' => (string) $vm->id]]])
            ->and($request['networking'])->toBe(['interfaces' => ['interface' => [['type' => 'public', 'ip_addresses' => ['ip_address' => [['family' => 'IPv4']]]]]]])
            ->and($request['storage_devices']['storage_device'][0])->toMatchArray(['action' => 'clone', 'storage' => $current, 'size' => 25])
            ->and($request)->toMatchArray(['zone' => 'nl-ams1', 'plan' => '2xCPU-4GB', 'metadata' => 'yes', 'remote_access_enabled' => 'no'])
            ->and($request['login_user'])->toBe(['username' => 'root', 'ssh_keys' => ['ssh_key' => [TVM_UP_GATEWAY_KEY]]])
            ->and($userData['users'])->toBe([])
            ->and($userData['disable_root'])->toBeTrue()
            ->and($userData['ssh_keys']['ed25519_public'])->toBe($vm->ssh_host_key)
            ->and($userData['ssh_keys']['ed25519_private'])->toContain('BEGIN OPENSSH PRIVATE KEY')
            ->and($vm->provider_id)->toBe(array_key_first($this->upcloud->servers))
            ->and($this->upcloud->servers[$vm->provider_id]['state'])->toBe('started');

        // The pinned fingerprint is the one ssh-keygen reads from the key.
        $file = tempnam(sys_get_temp_dir(), 'pub');
        file_put_contents($file, $vm->ssh_host_key);
        $listed = Process::run(['ssh-keygen', '-l', '-E', 'sha256', '-f', $file])->output();
        unlink($file);
        expect($listed)->toContain(app(UpCloudTaskVmProvider::class)->sshHostFingerprint($vm).' ');
    });

    it('finds a server whose create answer was lost and never sends a second create', function (): void {
        $this->upcloud->template('orbit-task-base-20261011000000');
        $vm = tvm_up_vm(hostKey: 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIHostKey');
        $uuid = $this->upcloud->server($vm->name, [['key' => 'orbit-task-vm', 'value' => (string) $vm->id]], 'stopped');

        app(UpCloudTaskVmProvider::class)->create($vm, "#cloud-config\n{}\n");

        expect($this->upcloud->created)->toBe([])
            ->and($vm->refresh()->provider_id)->toBe($uuid)
            ->and($vm->ssh_host_key)->toBe('ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIHostKey')
            ->and($this->upcloud->count("POST server/{$uuid}/start"))->toBe(1);
    });

    it('refuses a create while the account runs max_vms task VM servers, also lost ones', function (): void {
        $this->upcloud->template('orbit-task-base-20261011000000');
        $this->upcloud->server('tvm-901', [['key' => 'orbit-task-vm', 'value' => '901']]);
        $this->upcloud->server('tvm-902', [['key' => 'orbit-task-vm', 'value' => '902']]);
        $this->upcloud->server('web', [['key' => 'other', 'value' => 'x']]);

        expect(fn () => app(UpCloudTaskVmProvider::class)->create(tvm_up_vm(), "#cloud-config\n{}\n"))
            ->toThrow(TaskVmException::class, 'UpCloud already runs 2 task VM servers, the limit of 2.')
            ->and($this->upcloud->created)->toBe([]);
    });

    it('refuses a create without a verified template', function (): void {
        $this->upcloud->template('orbit-task-base-20261012000000-unverified');

        expect(fn () => app(UpCloudTaskVmProvider::class)->create(tvm_up_vm(), "#cloud-config\n{}\n"))
            ->toThrow(TaskVmException::class, 'task-vms:build-upcloud-image')
            ->and($this->upcloud->created)->toBe([]);
    });

    it('reads the state and address, cloud-init and the manifest over SSH with the pinned key', function (): void {
        $vm = tvm_up_vm(hostKey: 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIHostKey');
        $this->upcloud->server($vm->name, [['key' => 'orbit-task-vm', 'value' => (string) $vm->id]]);
        $provider = app(UpCloudTaskVmProvider::class);
        $this->ssh->manifest = '{"schema":1}';

        $observation = $provider->observe($vm);

        expect($observation?->running)->toBeTrue()
            ->and($observation?->address)->toBe('94.237.1.10')
            ->and($provider->bootstrapReady($vm))->toBeTrue()
            ->and($provider->imageManifest($vm))->toBe('{"schema":1}')
            ->and(array_unique(array_column($this->ssh->calls, 0)))->toBe(['94.237.1.10'])
            ->and(file_get_contents(app(KnownHostsStore::class)->path()))->toContain('94.237.1.10 ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIHostKey');

        $this->ssh->cloudInit = '';
        expect($provider->bootstrapReady($vm))->toBeFalse();
        $this->ssh->cloudInit = '{"status":"error","errors":["boom"]}';
        expect(fn () => $provider->bootstrapReady($vm))->toThrow(TaskVmException::class, 'Cloud-init failed');
    });

    it('stops a server cleanly for a park and deletes it with its disk on destroy', function (): void {
        $vm = tvm_up_vm(TaskVmState::Ready);
        $uuid = $this->upcloud->server($vm->name, [['key' => 'orbit-task-vm', 'value' => (string) $vm->id]]);
        $provider = app(UpCloudTaskVmProvider::class);

        $provider->stop($vm);
        expect($this->upcloud->servers[$uuid]['stop_type'])->toBe('soft');

        $provider->start($vm);
        expect($this->upcloud->servers[$uuid]['state'])->toBe('started');

        $provider->destroy($vm->refresh());
        $provider->destroy($vm);

        expect($this->upcloud->servers)->toBe([])
            ->and($this->upcloud->count("DELETE server/{$uuid}?storages=1"))->toBe(1)
            ->and($provider->observe($vm))->toBeNull();
    });

    it('reads the label of a server that the list shows without labels', function (): void {
        $this->upcloud->listWithoutLabels = true;
        $uuid = $this->upcloud->server('tvm-901', [['key' => 'orbit-task-vm', 'value' => '901']]);

        expect(app(UpCloudServers::class)->labelled(TaskVmUpCloud::Label))->toBe([['uuid' => $uuid, 'title' => 'tvm-901', 'value' => '901']]);
    });

    it('refuses a server that does not carry its label', function (): void {
        $vm = tvm_up_vm();
        $uuid = $this->upcloud->server($vm->name, [['key' => 'orbit-task-vm', 'value' => '999']]);
        $vm->update(['provider_id' => $uuid]);

        expect(fn () => app(UpCloudTaskVmProvider::class)->observe($vm))->toThrow(TaskVmException::class, 'does not match');
    });

    it('never sends the token anywhere but the UpCloud API, and refuses a token file others can read', function (): void {
        tvm_up_vm();
        app(UpCloudServers::class)->labelled(TaskVmUpCloud::Label);
        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer ucat_test_token') && str_starts_with($request->url(), 'https://api.upcloud.com/1.3/'));

        chmod($this->tokenFile, 0644);
        expect(fn () => app(UpCloudServers::class)->labelled(TaskVmUpCloud::Label))->toThrow(TaskVmException::class, 'mode 0600');
    });
});

describe('sweep', function (): void {
    beforeEach(function (): void {
        // The Incus host is not active, so the sweep reads only UpCloud.
        $this->host->update(['status' => LifecycleStatus::Provisioning]);
    });

    it('deletes labelled servers without a live row and fails a row whose server stays gone', function (): void {
        $ready = tvm_up_vm(TaskVmState::Ready);
        $kept = tvm_up_vm(TaskVmState::Ready);
        $this->upcloud->server($kept->name, [['key' => 'orbit-task-vm', 'value' => (string) $kept->id]]);
        $orphan = $this->upcloud->server('tvm-777', [['key' => 'orbit-task-vm', 'value' => '777']]);
        $foreign = $this->upcloud->server('web-1', [['key' => 'orbit-task-vm', 'value' => '778']]);

        app()->call([new SweepTaskVmHosts, 'handle']);

        expect($this->upcloud->servers)->not->toHaveKey($orphan)
            ->and($this->upcloud->servers)->toHaveKey($foreign)
            ->and($ready->refresh()->state)->toBe(TaskVmState::Ready);

        app()->call([new SweepTaskVmHosts, 'handle']);

        expect($ready->refresh()->state)->toBe(TaskVmState::Failed)
            ->and($ready->error_code)->toBe('task_vm.vm_missing')
            ->and($kept->refresh()->state)->toBe(TaskVmState::Ready);
    });

    it('also sweeps with the switch off, and touches nothing without the configuration', function (): void {
        tvm_up_settings(['enabled' => false]);
        $orphan = $this->upcloud->server('tvm-777', [['key' => 'orbit-task-vm', 'value' => '777']]);

        app()->call([new SweepTaskVmHosts, 'handle']);
        expect($this->upcloud->servers)->not->toHaveKey($orphan);

        tvm_up_settings(null);
        $this->upcloud->requests = [];
        app()->call([new SweepTaskVmHosts, 'handle']);
        expect($this->upcloud->requests)->toBe([]);
    });

    it('sweeps UpCloud while task VMs are off and only the token file is set', function (): void {
        $settings = app(TaskVmSettings::class);
        app()->instance(TaskVmSettings::class, new TaskVmSettings(false, null, '10.44.0.128/25', $settings->hosts, null, null, null, [], upcloud: new TaskVmUpCloud(...[...get_object_vars($settings->upcloud), 'enabled' => false])));
        $this->host->update(['status' => LifecycleStatus::Active]);
        $orphan = $this->upcloud->server('tvm-777', [['key' => 'orbit-task-vm', 'value' => '777']]);

        app()->call([new SweepTaskVmHosts, 'handle']);

        expect($this->upcloud->servers)->not->toHaveKey($orphan)
            ->and($this->ssh->calls)->toBe([]);
    });

    it('never deletes the servers or the template of a build that is marked as running', function (): void {
        $this->travelTo(now()->addHours(3));
        $running = now()->subHours(3)->format('YmdHis');
        $stale = now()->subHours(3)->subMinute()->format('YmdHis');
        Cache::put('task-vms:upcloud-image-build:running:'.$running, true, 60);
        $builder = $this->upcloud->server('tvm-image-'.$running, [['key' => 'orbit-task-image', 'value' => $running]]);
        $template = $this->upcloud->template('orbit-task-base-'.$running.'-unverified');
        $leftover = $this->upcloud->server('tvm-image-'.$stale, [['key' => 'orbit-task-image', 'value' => $stale]]);
        $leftoverTemplate = $this->upcloud->template('orbit-task-base-'.$stale.'-unverified');

        app()->call([new SweepTaskVmHosts, 'handle']);
        app(UpCloudTaskVmImageBuilder::class)->sweep();

        expect($this->upcloud->servers)->toHaveKey($builder)->not->toHaveKey($leftover)
            ->and($this->upcloud->storages)->toHaveKey($template)->not->toHaveKey($leftoverTemplate);
    });
});

describe('image build', function (): void {
    it('builds the template with the shared recipe, smoke-boots it, promotes it, and keeps two', function (): void {
        $old = $this->upcloud->template('orbit-task-base-20261001000000');
        $previous = $this->upcloud->template('orbit-task-base-20261002000000');
        $stages = [];

        $template = app(UpCloudTaskVmImageBuilder::class)->build(function (string $stage) use (&$stages): void {
            $stages[] = $stage;
        });

        $commands = array_map(static fn (array $call): string => implode(' ', $call[1]), $this->ssh->calls);
        $programs = array_keys(app(TaskVmImageRecipe::class)->programs());
        expect($stages)->toBe(['render', 'sweep', 'launch', 'cloud-init', 'orbit-user', ...$programs, 'agent-binary', 'masks', 'firewall', 'manifest', 'clean', 'templatize', 'delete-builder', 'smoke', 'promote', 'prune'])
            ->and($programs)->toContain('bootstrap', 'app-dev-prerequisites')
            ->and($this->upcloud->storages[$template]['title'])->toStartWith('orbit-task-base-')->not->toEndWith('-unverified')
            ->and($this->upcloud->storages)->not->toHaveKey($old)
            ->and($this->upcloud->storages)->toHaveKey($previous)
            ->and($this->upcloud->servers)->toBe([])
            ->and($this->upcloud->created[0]['storage_devices']['storage_device'][0]['storage'])->toBe('01000000-0000-4000-8000-000030260200')
            ->and($this->upcloud->created[1]['storage_devices']['storage_device'][0]['storage'])->toBe($template)
            ->and($this->upcloud->created[0]['labels']['label'][0]['key'])->toBe('orbit-task-image')
            ->and($commands)->toContain('uname -m', 'sudo -n sh -c '.TaskVmImageRecipe::Masks, 'sudo -n bash -s')
            // ufw is on before the identity goes, with only the public SSH rule that enrollment opens and closes.
            ->and(array_slice($commands, (int) array_search('sudo -n ufw default deny incoming', $commands, true), 4))->toBe([
                'sudo -n ufw default deny incoming',
                'sudo ufw allow in proto tcp to any port 22 comment orbit:public-ssh-recovery',
                'sudo -n ufw --force enable',
                'sudo -n ufw status',
            ])
            ->and(array_search('sudo -n ufw --force enable', $commands, true))->toBeLessThan(array_search('sudo -n bash -s', $commands, true))
            ->and(end($commands))->toContain('ufw status | grep -qx "Status: active"')
            ->and(Cache::get('task-vms:upcloud-image-build:running:'.substr((string) $this->upcloud->storages[$template]['title'], -14)))->toBeNull()
            ->and(array_filter($commands, static fn (string $command): bool => str_starts_with($command, 'sudo -n bash -ceu '.TaskVmImageRecipe::InstallAgent)))->toHaveCount(1)
            ->and(json_decode(substr($this->upcloud->created[0]['user_data'], 14), true)['packages'])->toContain('openssh-server');
    });

    it('deletes its servers and its unverified template when the smoke boot fails', function (): void {
        $this->ssh = new class extends FakeUpCloudSsh
        {
            #[Override]
            public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
            {
                if (str_contains(implode(' ', $command->arguments), 'authorized_keys')) {
                    return new CommandResult(1, '', 'no manifest', 1, false);
                }

                return parent::execute($connection, $command);
            }
        };
        app()->instance(SshExecutor::class, $this->ssh);
        $kept = $this->upcloud->template('orbit-task-base-20261002000000');

        expect(fn () => app(UpCloudTaskVmImageBuilder::class)->build())->toThrow(TaskVmException::class, 'stage [smoke]')
            ->and($this->upcloud->servers)->toBe([])
            ->and(array_keys($this->upcloud->storages))->toBe([$kept])
            ->and(Cache::lock('task-vms:upcloud-image-build', 10)->get())->toBeTrue();
    });

    it('fails the build when ufw does not turn on', function (): void {
        $this->ssh = new class extends FakeUpCloudSsh
        {
            #[Override]
            public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
            {
                return $command->arguments === ['sudo', '-n', 'ufw', 'status'] ? new CommandResult(0, "Status: inactive\n", '', 1, false) : parent::execute($connection, $command);
            }
        };
        app()->instance(SshExecutor::class, $this->ssh);

        expect(fn () => app(UpCloudTaskVmImageBuilder::class)->build())->toThrow(TaskVmException::class, 'stage [firewall]')
            ->and($this->upcloud->servers)->toBe([])
            ->and($this->upcloud->storages)->toBe([]);
    });
});

describe('nightly template refresh', function (): void {
    it('builds a new template on the template queue and keeps the two newest', function (): void {
        $old = $this->upcloud->template('orbit-task-base-20261001000000');
        $previous = $this->upcloud->template('orbit-task-base-20261002000000');

        app()->call([new RefreshUpCloudTaskVmTemplate, 'handle']);

        $verified = array_filter($this->upcloud->storages, static fn (array $storage): bool => ! str_ends_with($storage['title'], '-unverified'));
        expect($verified)->toHaveCount(2)->toHaveKey($previous)->not->toHaveKey($old)
            ->and($this->upcloud->servers)->toBe([])
            ->and([new RefreshUpCloudTaskVmTemplate()->connection, new RefreshUpCloudTaskVmTemplate()->queue])->toBe(['task-vms', 'task-vm-templates']);
    });

    it('builds nothing before the first template or without the token file', function (?array $upcloud): void {
        tvm_up_settings($upcloud);

        app()->call([new RefreshUpCloudTaskVmTemplate, 'handle']);

        expect($this->upcloud->created)->toBe([]);
    })->with([
        'no verified template yet' => [[]],
        'not configured' => [null],
    ]);

    it('is queued every night at 02:00 while the UpCloud token file is set', function (): void {
        Artisan::call('schedule:list');
        $refresh = collect(app(Schedule::class)->events())->first(static fn ($event): bool => $event instanceof CallbackEvent && $event->description === RefreshUpCloudTaskVmTemplate::class);

        config(['task_vms.enabled' => true, 'task_vms.upcloud.token_file' => null]);
        expect($refresh)->not->toBeNull()
            ->and($refresh->expression)->toBe('0 2 * * *')
            ->and($refresh->filtersPass(app()))->toBeFalse();

        config(['task_vms.enabled' => false, 'task_vms.upcloud.token_file' => '/home/orbit/upcloud.yaml']);
        expect($refresh->filtersPass(app()))->toBeTrue();
    });
});

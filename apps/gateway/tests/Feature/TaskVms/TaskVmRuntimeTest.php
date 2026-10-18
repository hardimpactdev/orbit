<?php

declare(strict_types=1);

use App\Actions\TaskVms\PrepareTaskVmRuntimeAction;
use App\Domain\Extensions\ExtensionStore;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\Processes\ProcessRuntimeManager;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\TaskVms\ModelKeyIssuer;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmState;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\TaskVms\TaskVmRuntime;
use App\Models\Node;
use App\Models\Process;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskVm;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Orbit\Extensions\AiRouter\Domain\AiRouterState;
use Tests\Support\AppDevFakeSshExecutor;
use Tests\Support\ProcessesApiFakeRuntimeManager;
use Tests\Support\TaskVmSettingsBuilder;

use function Pest\Laravel\mock;

const TVM_RUNTIME_ORIGIN = 'http://10.44.0.3:8317';
const TVM_RUNTIME_PI_TOKEN = 'task-vm-pi-token-with-more-than-32-characters';

function tvm_runtime_settings(?string $artifact, ?string $digest, array $models = ['gpt-5.6-luna']): void
{
    TaskVmSettingsBuilder::bind(['modelProxyOrigin' => TVM_RUNTIME_ORIGIN, 'piArtifactPath' => $artifact, 'piArtifactSha256' => $digest, 'piModels' => $models]);
}

function tvm_runtime_vm(bool $enrolled = true): TaskVm
{
    $project = Project::query()->create(['name' => 'Shop', 'slug' => 'shop', 'repository_url' => 'git@github.com:acme/shop.git', 'default_branch' => 'main']);
    $node = static fn (string $name, string $address, string $user): Node => Node::query()->create([
        'name' => $name, 'status' => LifecycleStatus::Active, 'platform' => 'linux',
        'public_ssh_host' => $address, 'wireguard_ip' => $address, 'user' => $user,
    ]);
    $host = $node('beast', '10.44.0.7', 'nckrtl');
    $group = Task::topLevel()->create(['project_id' => $project->id, 'title' => 'Export', 'brief' => 'Work', 'status' => 'todo']);

    return TaskVm::query()->create([
        'group_id' => $group->id, 'host_node_id' => $host->id, 'node_id' => $enrolled ? $node('tvm-1', '10.44.0.130', 'orbit')->id : null,
        'provider' => 'incus', 'name' => 'tvm-1', 'state' => TaskVmState::Provisioning, 'wireguard_ip' => '10.44.0.130', 'pi_token' => TVM_RUNTIME_PI_TOKEN,
    ]);
}

/**
 * A CLIProxyAPI that keeps its key list in memory, as its management API does.
 *
 * @return ArrayObject<int, string>
 */
function tvm_runtime_cliproxy(): ArrayObject
{
    $keys = new ArrayObject;
    Http::fake([TVM_RUNTIME_ORIGIN.'/*' => function (Request $request) use ($keys) {
        $path = parse_url($request->url(), PHP_URL_PATH);
        if ($path === '/v0/management/api-keys' && $request->method() === 'GET') {
            return Http::response(['api-keys' => array_values($keys->getArrayCopy())]);
        }
        if ($path === '/v0/management/api-keys' && $request->method() === 'PATCH') {
            $index = array_search($request['old'], $keys->getArrayCopy(), true);
            if ($index !== false) {
                unset($keys[$index]);
            }
            if ($request['new'] !== '') {
                $keys->append($request['new']);
            }
            $keys->exchangeArray(array_values($keys->getArrayCopy()));

            return Http::response(['status' => 'ok']);
        }
        $token = substr($request->header('Authorization')[0] ?? '', 7);

        return Http::response([], in_array($token, $keys->getArrayCopy(), true) ? 200 : 401);
    }]);

    return $keys;
}

beforeEach(function (): void {
    mock(SshKeyProvider::class)->shouldReceive('privateKeyPath')->andReturn('/keys/private');
    mock(KnownHostsStore::class)->shouldReceive('path')->andReturn('/keys/known_hosts');
    $this->transport = new AppDevFakeSshExecutor;
    app()->instance(SshExecutor::class, $this->transport);
    $this->processes = new ProcessesApiFakeRuntimeManager;
    app()->instance(ProcessRuntimeManager::class, $this->processes);
    app(AiRouterState::class)->enable(1, 'cache', TVM_RUNTIME_ORIGIN, 'management-key', 'read-token', 'control-token');
    $this->artifact = tempnam(sys_get_temp_dir(), 'pi-artifact');
    file_put_contents($this->artifact, 'pi-server executable');
    tvm_runtime_settings($this->artifact, hash_file('sha256', $this->artifact));
});

afterEach(function (): void {
    @unlink($this->artifact);
});

describe('prepare', function (): void {
    it('registers the group key, installs Pi as the managed user and starts the pi-server Process', function (): void {
        $keys = tvm_runtime_cliproxy();
        $vm = tvm_runtime_vm();

        app(TaskVmRuntime::class)->prepare($vm);

        $key = $vm->fresh()?->model_key;
        expect($key)->toMatch('/\A[a-f0-9]{64}\z/')
            ->and($keys->getArrayCopy())->toHaveCount(2)->toContain($key);

        [$check, $install, $models, $token] = $this->transport->commands;
        expect(array_map(static fn ($connection): array => [$connection->host, $connection->user], $this->transport->connections))
            ->toBe(array_fill(0, 4, ['10.44.0.130', 'orbit']))
            ->and([$check->arguments[0], $check->arguments[1], ...array_slice($check->arguments, 3)])
            ->toBe(['bash', '-ceu', '--', hash_file('sha256', $this->artifact)])
            ->and($check->arguments[2])->toContain('"$HOME/.local/bin/pi-server" | sha256sum --check --status')
            ->and($check->protectedInput)->toBeNull()
            ->and([$install->arguments[0], $install->arguments[1], ...array_slice($install->arguments, 3)])
            ->toBe(['bash', '-ceu', '--', hash_file('sha256', $this->artifact)])
            ->and($install->arguments[2])->toContain('sha256sum --check --status', 'mv -f -- "$staged" "$bin/pi-server"')
            ->and(stream_get_contents($install->protectedInput?->stream()))->toBe('pi-server executable')
            ->and(array_slice($models->arguments, 3))->toBe(['--', 'models.json'])
            ->and($models->arguments[2])->toContain('install -d -m 0700 -- "$HOME/.pi" "$dir"')
            ->and(json_decode((string) stream_get_contents($models->protectedInput?->stream()), true))->toBe(['providers' => ['orbit-task-vm' => [
                'baseUrl' => TVM_RUNTIME_ORIGIN.'/v1', 'api' => 'openai-responses', 'apiKey' => $key,
                'models' => [['id' => 'gpt-5.6-luna', 'reasoning' => true]],
            ]]])
            ->and(array_slice($token->arguments, 3))->toBe(['--', 'orbit-token'])
            ->and(stream_get_contents($token->protectedInput?->stream()))->toBe(TVM_RUNTIME_PI_TOKEN)
            ->and(implode(' ', array_map(static fn ($command): string => implode(' ', $command->arguments), $this->transport->commands)))
            ->not->toContain($key, TVM_RUNTIME_PI_TOKEN);

        $process = Process::query()->sole();
        expect($process->owner_id)->toBe($vm->node_id)
            ->and($process->name)->toBe('pi-server')
            ->and($process->runtime)->toBe(ProcessRuntime::Systemd)
            ->and($process->runtime_config['command'])->toBe([
                '/home/orbit/.local/bin/pi-server', 'serve', '--host=10.44.0.130', '--port=3774',
                '--token-file=/home/orbit/.pi/agent/orbit-token', '--allow-provider=orbit-task-vm',
            ])
            ->and($process->runtime_config)->not->toHaveKey('user')
            ->and($process->restart_policy)->toBe('always')
            ->and($process->keep_alive)->toBeTrue()
            ->and($process->status)->toBe(LifecycleStatus::Active);
    });

    it('keeps the key and the Process when it runs again', function (): void {
        $keys = tvm_runtime_cliproxy();
        $vm = tvm_runtime_vm();

        app(TaskVmRuntime::class)->prepare($vm);
        $key = $vm->fresh()?->model_key;
        app(TaskVmRuntime::class)->prepare($vm->fresh());

        expect($vm->fresh()?->model_key)->toBe($key)
            ->and($keys->getArrayCopy())->toHaveCount(2)
            ->and(Process::query()->count())->toBe(1)
            ->and($this->transport->commands)->toHaveCount(8);
    });

    it('skips the upload when the VM already has the pinned Pi executable from its base image', function (): void {
        tvm_runtime_cliproxy();
        $this->transport = new AppDevFakeSshExecutor([new CommandResult(0, 'current', '', 1, false)]);
        app()->instance(SshExecutor::class, $this->transport);

        app(TaskVmRuntime::class)->prepare(tvm_runtime_vm());

        expect(array_map(static fn ($command): ?string => $command->arguments[4] ?? null, $this->transport->commands))
            ->toBe([hash_file('sha256', $this->artifact), 'models.json', 'orbit-token'])
            ->and(array_filter($this->transport->commands, static fn ($command): bool => $command->protectedInput !== null
                && stream_get_contents($command->protectedInput->stream()) === 'pi-server executable'))->toBe([])
            ->and(Process::query()->sole()->name)->toBe('pi-server');
    });

    it('refuses before any change without a Node or the Pi settings', function (string $missing, string $code): void {
        Http::fake();
        $vm = tvm_runtime_vm($missing !== 'node');
        if ($missing === 'artifact') {
            tvm_runtime_settings(null, null);
        } elseif ($missing === 'models') {
            tvm_runtime_settings($this->artifact, hash_file('sha256', $this->artifact), []);
        }

        expect(fn () => app(TaskVmRuntime::class)->prepare($vm))
            ->toThrow(fn (TaskVmException $exception) => expect($exception->errorCode)->toBe($code));
        expect($this->transport->commands)->toBe([])->and($vm->fresh()?->model_key)->toBeNull();
        Http::assertNothingSent();
    })->with([
        ['node', 'task_vm.not_enrolled'],
        ['artifact', 'task_vm.invalid_config'],
        ['models', 'task_vm.invalid_config'],
    ]);

    it('names the task VM when a step fails on it', function (): void {
        tvm_runtime_cliproxy();
        $vm = tvm_runtime_vm();
        $this->transport = new AppDevFakeSshExecutor([new CommandResult(1, '', 'sha256sum: WARNING: 1 computed checksum did NOT match', 1, false)]);
        app()->instance(SshExecutor::class, $this->transport);

        expect(fn () => app(TaskVmRuntime::class)->prepare($vm))
            ->toThrow(TaskVmException::class, 'Pi could not be prepared on task VM [tvm-1].');
        expect($this->transport->commands)->toHaveCount(1)->and(Process::query()->count())->toBe(0);
    });

    it('fails with a model key error when CLIProxyAPI refuses the key', function (): void {
        Http::fake([TVM_RUNTIME_ORIGIN.'/*' => Http::response([], 403)]);
        $vm = tvm_runtime_vm();

        expect(fn () => app(TaskVmRuntime::class)->prepare($vm))
            ->toThrow(fn (TaskVmException $exception) => expect($exception->errorCode)->toBe('task_vm.model_key_failed'));
        expect($this->transport->commands)->toBe([])
            ->and($vm->fresh()?->model_key)->toMatch('/\A[a-f0-9]{64}\z/');
    });
});

describe('model router readiness', function (): void {
    it('stores no model key while the router holds no management key, under the lock its teardown takes', function (): void {
        Http::fake();
        app(ExtensionStore::class)->set('tasks', true);
        app(AiRouterState::class)->disable();
        $vm = tvm_runtime_vm();

        expect(fn () => app(TaskVmRuntime::class)->prepare($vm))
            ->toThrow(fn (TaskVmException $exception) => expect([$exception->errorCode, $exception->status])->toBe(['task_vm.model_key_failed', 409]));
        expect($vm->fresh()?->model_key)->toBeNull()
            ->and($vm->fresh()?->ai_router_origin)->toBeNull()
            ->and($this->transport->commands)->toBe([])
            ->and(Cache::lock(ModelKeyIssuer::Lock, 10)->get())->toBeTrue();
        Http::assertNothingSent();
    });
});

describe('model proxy origin', function (): void {
    it('refuses an origin that is not the ai-router CLIProxyAPI', function (): void {
        Http::fake();
        app(AiRouterState::class)->enable(1, 'cache', 'http://10.44.0.9:8317', 'management-key', 'read-token', 'control-token');
        $vm = tvm_runtime_vm();

        expect(fn () => app(TaskVmRuntime::class)->prepare($vm))
            ->toThrow(fn (TaskVmException $exception) => expect($exception->errorCode)->toBe('task_vm.invalid_config'));
        expect($vm->fresh()?->model_key)->toBeNull()->and($this->transport->commands)->toBe([]);
        Http::assertNothingSent();
    });

    it('refuses to prepare again after the origin changed under a stored key', function (): void {
        Http::fake();
        $vm = tvm_runtime_vm();
        $vm->update(['model_key' => str_repeat('k', 64), 'ai_router_origin' => 'http://10.44.0.9:8317']);

        expect(fn () => app(TaskVmRuntime::class)->prepare($vm->fresh()))
            ->toThrow(TaskVmException::class, 'model_proxy_origin changed while task VM [tvm-1] holds a key at [http://10.44.0.9:8317].');
        Http::assertNothingSent();
    });
});

describe(PrepareTaskVmRuntimeAction::class, function (): void {
    it('leaves a row that a destroy took while Pi was prepared', function (): void {
        tvm_runtime_cliproxy();
        $vm = tvm_runtime_vm();
        Event::listen(RequestSending::class, static function () use ($vm): void {
            TaskVm::query()->whereKey($vm->id)->update(['state' => TaskVmState::Destroying]);
        });

        app(PrepareTaskVmRuntimeAction::class)->execute($vm->id);

        expect($vm->fresh()?->state)->toBe(TaskVmState::Destroying)->and($vm->fresh()?->ready_at)->toBeNull();
    });
});

describe('release', function (): void {
    it('revokes the group key and keeps the anchor', function (): void {
        $keys = tvm_runtime_cliproxy();
        $vm = tvm_runtime_vm();
        app(TaskVmRuntime::class)->prepare($vm);
        $key = $vm->fresh()?->model_key;

        app(TaskVmRuntime::class)->release($vm->fresh());

        expect($vm->fresh()?->model_key)->toBeNull()
            ->and($keys->getArrayCopy())->toHaveCount(1)->not->toContain($key);
    });

    it('revokes the key at the origin stored with it, after the setting changed', function (): void {
        $keys = tvm_runtime_cliproxy();
        $vm = tvm_runtime_vm();
        app(TaskVmRuntime::class)->prepare($vm);
        expect($vm->fresh()?->ai_router_origin)->toBe(TVM_RUNTIME_ORIGIN);
        TaskVmSettingsBuilder::bind(['modelProxyOrigin' => 'http://10.44.0.9:8317']);

        app(TaskVmRuntime::class)->release($vm->fresh());

        expect($vm->fresh()?->model_key)->toBeNull()->and($keys->getArrayCopy())->toHaveCount(1);
    });

    it('revokes a key that a stale copy of the row does not show', function (): void {
        $keys = tvm_runtime_cliproxy();
        $vm = tvm_runtime_vm();
        $stale = $vm->fresh();
        app(TaskVmRuntime::class)->prepare($vm);

        app(TaskVmRuntime::class)->release($stale);

        expect($vm->fresh()?->model_key)->toBeNull()->and($keys->getArrayCopy())->toHaveCount(1);
    });

    it('does nothing without a key', function (): void {
        Http::fake();

        app(TaskVmRuntime::class)->release(tvm_runtime_vm());

        Http::assertNothingSent();
    });
});

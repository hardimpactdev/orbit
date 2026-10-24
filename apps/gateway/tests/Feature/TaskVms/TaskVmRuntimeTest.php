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
use App\Infrastructure\AgentCommands\AgentCommandRunner;
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
use Tests\Support\FakeAgentCommandRunner;
use Tests\Support\ProcessesApiFakeRuntimeManager;
use Tests\Support\TaskVmSettingsBuilder;

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
    $installed = false;
    $this->agents = new FakeAgentCommandRunner;
    $this->agents->answer('pi.inspect-artifact', static function () use (&$installed): array {
        return ['current' => $installed];
    });
    $this->agents->answer('pi.receive-artifact', static fn (array $input): array => ['received_bytes' => $input['total'], 'verified' => true]);
    $this->agents->answer('pi.install-artifact', static function () use (&$installed): array {
        $installed = true;

        return ['changed' => true];
    });
    $this->agents->answer('pi.configure-runtime', ['changed' => true, 'binary' => '/home/orbit/.local/bin/pi-server', 'token_file' => '/home/orbit/.pi/agent/orbit-token']);
    app()->instance(AgentCommandRunner::class, $this->agents);
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

        expect($this->agents->commands())->toBe(['pi.inspect-artifact', 'pi.receive-artifact', 'pi.install-artifact', 'pi.configure-runtime']);
        expect(array_column($this->agents->calls, 'node'))->toBe(array_fill(0, 4, 'tvm-1'));
        expect($this->agents->calls[0]['input'])->toBe(['user' => 'orbit', 'sha256' => hash_file('sha256', $this->artifact)]);
        expect(base64_decode($this->agents->calls[1]['input']['bytes'], true))->toBe('pi-server executable');
        expect($this->agents->calls[2]['input'])->toBe($this->agents->calls[0]['input']);
        expect($this->agents->calls[3]['input'])->toBe(['user' => 'orbit', 'origin' => TVM_RUNTIME_ORIGIN,
            'model_key' => $key, 'token' => TVM_RUNTIME_PI_TOKEN, 'models' => ['gpt-5.6-luna']]);

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
            ->and($this->agents->calls)->toHaveCount(6);
    });

    it('skips the upload when the VM already has the pinned Pi executable from its base image', function (): void {
        tvm_runtime_cliproxy();
        $this->agents->answer('pi.inspect-artifact', ['current' => true]);

        app(TaskVmRuntime::class)->prepare(tvm_runtime_vm());

        expect($this->agents->commands())->toBe(['pi.inspect-artifact', 'pi.configure-runtime'])
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
        expect($this->agents->calls)->toBe([])->and($vm->fresh()?->model_key)->toBeNull();
        Http::assertNothingSent();
    })->with([
        ['node', 'task_vm.not_enrolled'],
        ['artifact', 'task_vm.invalid_config'],
        ['models', 'task_vm.invalid_config'],
    ]);

    it('names the task VM when a step fails on it', function (): void {
        tvm_runtime_cliproxy();
        $vm = tvm_runtime_vm();
        $this->agents->refuse('pi.inspect-artifact', 'pi.artifact_failed', ['reason' => 'Unreadable Pi executable.']);

        expect(fn () => app(TaskVmRuntime::class)->prepare($vm))
            ->toThrow(TaskVmException::class, 'Pi could not be prepared on task VM [tvm-1].');
        expect($this->agents->calls)->toHaveCount(1)->and(Process::query()->count())->toBe(0);
    });

    it('creates no Process when Pi inspection or configuration returns an incomplete result', function (string $command): void {
        tvm_runtime_cliproxy();
        $vm = tvm_runtime_vm();
        $this->agents->answer($command, []);

        expect(fn () => app(TaskVmRuntime::class)->prepare($vm))->toThrow(TaskVmException::class, 'Pi could not be prepared');
        expect(Process::query()->count())->toBe(0);
    })->with(['pi.inspect-artifact', 'pi.configure-runtime']);

    it('keeps model-key intent and creates no Process when native configuration refuses', function (): void {
        tvm_runtime_cliproxy();
        $vm = tvm_runtime_vm();
        $this->agents->refuse('pi.configure-runtime', 'pi.runtime_failed', ['reason' => 'Pi path is not an owned regular file.']);

        expect(fn () => app(TaskVmRuntime::class)->prepare($vm))->toThrow(TaskVmException::class, 'Pi could not be prepared');
        expect($vm->fresh()->model_key)->not->toBeNull();
        expect(Process::query()->count())->toBe(0);
    });

    it('fails with a model key error when CLIProxyAPI refuses the key', function (): void {
        Http::fake([TVM_RUNTIME_ORIGIN.'/*' => Http::response([], 403)]);
        $vm = tvm_runtime_vm();

        expect(fn () => app(TaskVmRuntime::class)->prepare($vm))
            ->toThrow(fn (TaskVmException $exception) => expect($exception->errorCode)->toBe('task_vm.model_key_failed'));
        expect($this->agents->calls)->toBe([])
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
            ->and($this->agents->calls)->toBe([])
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
        expect($vm->fresh()?->model_key)->toBeNull()->and($this->agents->calls)->toBe([]);
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

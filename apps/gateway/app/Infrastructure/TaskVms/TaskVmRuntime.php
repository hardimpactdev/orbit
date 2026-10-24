<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Actions\Processes\CreateProcessAction;
use App\Data\Processes\AddProcessData;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\Processes\ProcessTargetType;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\ModelKeyIssuer;
use App\Domain\TaskVms\TaskVmConfig;
use App\Domain\TaskVms\TaskVmError;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmSettings;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\AgentRunnerFailure;
use App\Infrastructure\AgentCommands\PiArtifactDelivery;
use App\Infrastructure\Tasks\Pi\PiConnection;
use App\Models\Node;
use App\Models\TaskVm;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Prepares Pi on an enrolled task VM over the Node's normal SSH connection, as its managed user:
 * the group's model key, the pinned Pi executable, `models.json`, the token, and the normal
 * `pi-server` Process. Every step is idempotent, so a retried job repeats it. `release` revokes the key.
 */
final readonly class TaskVmRuntime
{
    /** The only `models.json` provider on a task VM. The model router is the VM's only model route. */
    public const string PiProvider = 'orbit-task-vm';

    public function __construct(
        private TaskVmSettings $settings,
        private AgentCommandRunner $agents,
        private PiArtifactDelivery $delivery,
        private ModelKeyIssuer $keys,
        private CreateProcessAction $processes,
        private PiConnection $pi,
        private TaskVmStepClock $clock,
    ) {}

    public function prepare(TaskVm $vm): void
    {
        $node = $vm->node ?? throw new TaskVmException(TaskVmError::NotEnrolled, "Task VM [{$vm->name}] has no Node yet.");
        $token = $vm->pi_token ?? throw new TaskVmException(TaskVmError::RuntimeFailed, "Task VM [{$vm->name}] has no Pi token: no task group claimed it.", 409);
        $origin = $this->keys->origin();
        if ($vm->ai_router_origin !== null && $vm->ai_router_origin !== $origin) {
            throw TaskVmConfig::invalid("model_proxy_origin changed while task VM [{$vm->name}] holds a key at [{$vm->ai_router_origin}].");
        }
        $artifact = $this->settings->piArtifactPath;
        $digest = $this->settings->piArtifactSha256;
        if ($artifact === null || $digest === null || $this->settings->piModels === []) {
            throw TaskVmConfig::invalid('pi.artifact_path, pi.artifact_sha256 and pi.models are required to prepare a task VM.');
        }
        $key = $this->ensureModelKey($vm, $origin, 60);
        $this->clock->step('model-key');

        try {
            $input = ['user' => $node->user, 'sha256' => $digest];
            $installed = $this->agents->run($node, 'pi.inspect-artifact', $input);
            if (($installed['current'] ?? null) === false) {
                $this->delivery->deliver($node, $artifact, $digest);
                $this->agents->run($node, 'pi.install-artifact', $input);
                $this->clock->step('pi-upload');
            } elseif (($installed['current'] ?? null) === true) {
                $this->clock->step('pi-current');
            } else {
                throw new AgentRunnerFailure('pi.inspect-artifact', AgentRunnerFailure::MalformedResult, 'Pi inspection did not report its installed state.');
            }
            $configured = $this->agents->run($node, 'pi.configure-runtime', [
                'user' => $node->user, 'origin' => $origin, 'model_key' => $key, 'token' => $token, 'models' => $this->settings->piModels,
            ]);
            if (! is_string($configured['binary'] ?? null) || ! is_string($configured['token_file'] ?? null)) {
                throw new AgentRunnerFailure('pi.configure-runtime', AgentRunnerFailure::MalformedResult, 'Pi preparation did not return its runtime paths.');
            }
            $this->clock->step('pi-files');
            $this->processes->execute(new AddProcessData(
                targetType: ProcessTargetType::Node,
                targetId: $node->id,
                name: 'pi-server',
                runtime: ProcessRuntime::Systemd,
                command: [
                    $configured['binary'], 'serve', '--host='.$node->wireguard_ip, '--port='.$this->pi->port(),
                    '--token-file='.$configured['token_file'], '--allow-provider='.self::PiProvider,
                ],
                image: null,
                workingDirectory: null,
                environment: [],
                ports: [],
                volumes: [],
                restartPolicy: 'always',
                start: true,
                keepAlive: true,
            ));
            $this->clock->step('pi-server');
        } catch (Throwable $exception) {
            throw new TaskVmException(TaskVmError::RuntimeFailed, "Pi could not be prepared on task VM [{$vm->name}].", 502, $exception);
        }
    }

    /**
     * Whether a started VM can take work again: its Node answers SSH over WireGuard, its agent unit is active,
     * and Pi answers `GET /capabilities` with the VM's token. Any failure reads as not yet.
     */
    public function responds(TaskVm $vm): bool
    {
        $node = $vm->node;
        if (! $node instanceof Node) {
            return false;
        }

        try {
            if (($this->agents->run($node, 'agent.status')['observer'] ?? null) !== 'active') {
                return false;
            }

            return Http::connectTimeout(3)->timeout(5)->withToken($this->pi->token($node))
                ->get($this->pi->baseUrl($node).'/capabilities')->successful();
        } catch (Throwable) {
            return false;
        }
    }

    /** Revokes the group's model key at the origin that holds it. A task VM without a key has nothing to revoke. */
    public function release(TaskVm $vm): void
    {
        $vm->refresh();
        if ($vm->model_key === null) {
            return;
        }
        $origin = $vm->ai_router_origin ?? $this->keys->origin();
        $this->withModelKeyLock(60, function () use ($vm, $origin): void {
            $vm->refresh();
            if ($vm->model_key !== null) {
                $this->keys->revoke($origin, $vm->model_key);
                $vm->update(['model_key' => null]);
            }
        });
    }

    /**
     * The key and its origin are stored before the model router learns the key, so a retry registers the same key there.
     * The router must be ready first, under the same lock as its teardown, so no row stores a key it cannot revoke.
     */
    private function ensureModelKey(TaskVm $vm, string $origin, int $wait = 60): string
    {
        return $this->withModelKeyLock($wait, function () use ($vm, $origin): string {
            $vm->refresh();
            try {
                $this->keys->assertReady();
            } catch (ResourceOperationException $exception) {
                throw new TaskVmException(TaskVmError::ModelKeyFailed, $exception->getMessage(), 409, $exception);
            }
            if ($vm->model_key === null) {
                $vm->update(['model_key' => bin2hex(random_bytes(32)), 'ai_router_origin' => $origin]);
            }
            $key = (string) $vm->model_key;
            $this->keys->issue($origin, $key);

            return $key;
        });
    }

    /**
     * @template T
     *
     * @param  callable(): T  $operation
     * @return T
     */
    private function withModelKeyLock(int $wait, callable $operation): mixed
    {
        try {
            return Cache::lock(ModelKeyIssuer::Lock, 600)->block($wait, $operation);
        } catch (TaskVmException $exception) {
            throw $exception;
        } catch (LockTimeoutException $exception) {
            throw new TaskVmException(TaskVmError::ModelKeyFailed, 'Another model key operation is running.', 409, $exception);
        } catch (Throwable $exception) {
            throw new TaskVmException(TaskVmError::ModelKeyFailed, 'The group model key could not be confirmed with the model router.', 502, $exception);
        }
    }
}

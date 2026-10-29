<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Domain\Operations\OperationTransport;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\ModelKeyIssuer;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmSettings;
use App\Domain\TaskVms\TaskVmState;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\PiArtifactDelivery;
use App\Infrastructure\Tasks\Pi\PiConnection;
use App\Models\Node;
use App\Models\Operation;
use App\Models\TaskVm;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/** Named Gateway primitives only. Swamp orders them; the Process family owns Process effects. */
final readonly class TaskVmRuntimeCommand implements OperationTransport
{
    use TaskVmTransportSchema;

    public function __construct(private ModelKeyIssuer $keys, private PiArtifactDelivery $artifacts, private AgentCommandRunner $agents, private PiConnection $pi, private TaskVmSettings $settings) {}

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function run(Operation $operation, array $run, #[\SensitiveParameter] array $input): array
    {
        return match ($input['action'] ?? null) {
            'issue', 'revoke' => $this->key($operation, $input),
            'upload' => $this->upload($operation, $input),
            'confirm' => $this->confirm($operation, $input),
            default => throw new ResourceOperationException('operation.execution_forbidden', 'The runtime primitive is not declared.', 422),
        };
    }

    /** @param array<string, mixed> $input
     * @return array{ready: bool}
     */
    private function key(Operation $operation, #[\SensitiveParameter] array $input): array
    {
        $vm = TaskVm::query()->findOrFail(ValidatedData::integer($input['task_vm_id']));
        $origin = ValidatedData::string($input['origin']);
        $key = ValidatedData::string($input['key']);
        if ($vm->id !== $operation->target_id || $vm->model_key === null || ! hash_equals($vm->model_key, $key) || $vm->ai_router_origin !== $origin) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The model key does not belong to this VM intent.', 422);
        }
        try {
            Cache::lock(ModelKeyIssuer::Lock, 600)->block(60, function () use ($input, $origin, $key): void {
                if ($input['action'] === 'issue') {
                    $this->keys->assertReady();
                    $this->keys->issue($origin, $key);
                } else {
                    $this->keys->revoke($origin, $key);
                }
            });
        } catch (Throwable) {
            throw new TaskVmException('task_vm.model_key_failed', 'The group model key could not be confirmed with the model router.', 502);
        }

        return ['ready' => true];
    }

    /** @param array<string, mixed> $input
     * @return array{ready: bool}
     */
    private function upload(Operation $operation, #[\SensitiveParameter] array $input): array
    {
        $vm = TaskVm::query()->findOrFail($operation->target_id);
        if ($vm->node_id !== $operation->node_id || $vm->state !== TaskVmState::Provisioning || $input['artifact'] !== $this->settings->piArtifactPath || $input['sha256'] !== $this->settings->piArtifactSha256) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The artifact does not match this VM runtime intent.', 422);
        }
        $node = Node::query()->findOrFail($operation->node_id);
        $this->artifacts->deliver($node, ValidatedData::string($input['artifact']), ValidatedData::string($input['sha256']));

        return ['ready' => true];
    }

    /** @param array<string, mixed> $input
     * @return array{ready: bool}
     */
    private function confirm(Operation $operation, #[\SensitiveParameter] array $input): array
    {
        $vm = TaskVm::query()->findOrFail(ValidatedData::integer($input['task_vm_id']));
        $node = $vm->node;
        if ($node === null || $node->id !== $operation->node_id) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The runtime Node does not match this Operation.', 422);
        }
        $pinned = ($this->agents->run($node, 'pi.inspect-artifact', ['user' => $node->user, 'sha256' => ValidatedData::string($input['sha256'])])['current'] ?? null) === true;
        $active = ($this->agents->run($node, 'agent.status')['observer'] ?? null) === 'active';
        $responds = Http::connectTimeout(3)->timeout(5)->withToken($this->pi->token($node))->get($this->pi->baseUrl($node).'/capabilities')->successful();

        return ['ready' => $pinned && $active && $responds];
    }
}

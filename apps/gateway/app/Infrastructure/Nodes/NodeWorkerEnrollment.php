<?php

declare(strict_types=1);

namespace App\Infrastructure\Nodes;

use App\Domain\Nodes\NodeEnrollmentPrivateOwner;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationSecretOutputStore;
use App\Domain\Operations\OperationSecretSource;
use App\Domain\Operations\OperationSecretValue;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use SensitiveParameter;
use Throwable;

/** Protected intent material only. Swamp decides when to mint, deliver and retry. */
final readonly class NodeWorkerEnrollment implements OperationSecretOutputStore, OperationSecretSource
{
    /** @param array{enrollment_token:string,server_token:string} $credentials */
    public function stage(Node $node, #[SensitiveParameter] array $credentials): string
    {
        if (! app(NodeWorkerRollout::class)->allows($node)) {
            throw new ResourceOperationException('swamp.node_worker_not_ready', 'The Node is outside the worker rollout.', 422);
        }
        foreach (['enrollment_token' => 'orbit-node-'.$node->id.'.', 'server_token' => 'orbit-node-'.$node->id.'-access.'] as $key => $prefix) {
            if (! str_starts_with($credentials[$key], $prefix) || preg_match('/\A[a-z0-9-]+\.[A-Za-z0-9_-]{16,4096}\z/D', $credentials[$key]) !== 1) {
                throw new ResourceOperationException('swamp.node_worker_not_ready', 'Worker credentials must belong to the selected Node.', 422);
            }
        }
        $reference = bin2hex(random_bytes(32));
        Cache::put('nodes:worker-enrollment:'.$reference, Crypt::encryptString(json_encode(['node_id' => $node->id, ...$credentials], JSON_THROW_ON_ERROR)), now()->addMinutes(30));

        return $reference;
    }

    public function name(): string
    {
        return 'node.worker_credentials';
    }

    public function targetTypes(): array
    {
        return ['node', 'task_vm', 'task_group'];
    }

    public function ownerId(Operation $operation): ?int
    {
        if (in_array($operation->kind, ['task_vm.provision', 'tasks.provision', 'task_vm.enroll', 'task_vm.prepare_runtime'], true)) {
            return app(NodeEnrollmentPrivateOwner::class)->taskVm($operation, 'worker_credentials');
        }

        return $operation->kind === 'node.converge' && $operation->target_type === 'node' && $operation->target_id === $operation->node_id ? $operation->node_id : null;
    }

    public function resolve(Operation $operation, int $id, string $key, string $parameter): OperationSecretValue
    {
        $run = $operation->state['swamp_run_id'] ?? null;
        $allowed = DirectOperation::owns($operation) && $key === '' && is_string($run) && $run !== '';
        if ($id !== $this->ownerId($operation) || $parameter !== 'worker_credentials' || $operation->status->terminal() || ! $allowed) {
            throw new ResourceOperationException('operation.execution_forbidden', 'Worker credentials do not belong to this Node intent.', 422);
        }
        if ($operation->kind !== 'node.converge') {
            $ciphertext = Cache::get('nodes:worker-enrollment:operation:'.$operation->id);
            if (! is_string($ciphertext)) {
                return new OperationSecretValue(null);
            }
            try {
                $credentials = json_decode(Crypt::decryptString($ciphertext), true, flags: JSON_THROW_ON_ERROR);
                if (is_array($credentials)) {
                    $this->validateCredentials($id, ValidatedData::object($credentials));

                    return new OperationSecretValue($credentials);
                }
            } catch (Throwable) {
                // Protected material never enters an exception.
            }
            throw new ResourceOperationException('swamp.node_worker_not_ready', 'The enrollment material is unavailable.', 409);
        }
        $reference = $operation->input['_worker_enrollment'] ?? null;
        if ($reference === null) {
            return new OperationSecretValue(null);
        }
        try {
            $ciphertext = is_string($reference) ? Cache::get('nodes:worker-enrollment:'.$reference) : null;
            $value = is_string($ciphertext) ? json_decode(Crypt::decryptString($ciphertext), true, flags: JSON_THROW_ON_ERROR) : null;
            if (is_array($value) && ($value['node_id'] ?? null) === $id && is_string($value['enrollment_token'] ?? null) && is_string($value['server_token'] ?? null)) {
                return new OperationSecretValue(['enrollment_token' => $value['enrollment_token'], 'server_token' => $value['server_token']]);
            }
        } catch (Throwable) {
            // The exception can carry protected intent material.
        }
        throw new ResourceOperationException('swamp.node_worker_not_ready', 'The staged worker credentials are missing or expired.', 409);
    }

    public function store(Operation $operation, int $ownerId, #[SensitiveParameter] mixed $value): void
    {
        if ($operation->status->terminal() || $ownerId !== $this->ownerId($operation) || ! is_array($value)
            || app(NodeEnrollmentPrivateOwner::class)->taskVm($operation, 'worker_credentials') === null) {
            throw new ResourceOperationException('operation.execution_forbidden', 'Worker enrollment output has no owning admitted Node.', 422);
        }
        $this->validateCredentials($ownerId, ValidatedData::object($value));
        Cache::put('nodes:worker-enrollment:operation:'.$operation->id,
            Crypt::encryptString(json_encode($value, JSON_THROW_ON_ERROR)), now()->addDay());
    }

    /** @param array<string,mixed> $credentials */
    private function validateCredentials(int $nodeId, #[SensitiveParameter] array $credentials): void
    {
        if (array_diff(array_keys($credentials), ['enrollment_token', 'server_token']) !== []) {
            throw new ResourceOperationException('swamp.node_worker_not_ready', 'Worker enrollment output is invalid.', 422);
        }
        foreach (['enrollment_token' => 'orbit-node-'.$nodeId.'.', 'server_token' => 'orbit-node-'.$nodeId.'-access.'] as $key => $prefix) {
            $value = $credentials[$key] ?? null;
            if (! is_string($value) || ! str_starts_with($value, $prefix)
                || preg_match('/\A[a-z0-9-]+\.[A-Za-z0-9_-]{16,4096}\z/D', $value) !== 1) {
                throw new ResourceOperationException('swamp.node_worker_not_ready', 'Worker enrollment output belongs to another Node.', 422);
            }
        }
    }
}

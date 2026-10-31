<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Models\TaskVm;
use App\Support\ValidatedData;
use Illuminate\Support\Arr;

/** Full public command-step intent. Execution-copy descriptors are supplied separately. */
final readonly class TaskVmEnrollmentWorkflowInput
{
    public function __construct(private TaskVmEnrollmentIntent $enrollment, private TaskVmModelKeyInputs $models, private SwampCommandStep $shared) {}

    /** Store the model owner's exact descriptors once; the root owns this captured input.
     * @param  array<string,mixed>  $public
     * @return array<string,mixed>
     */
    public function withExecutionCopies(TaskVm $vm, array $public): array
    {
        if (($public['task_vm_id'] ?? null) !== $vm->id || ! is_int($public['gateway_node_id'] ?? null)
            || ! is_string($vm->pi_token) || preg_match('/\A[a-f0-9]{64}\z/D', $vm->pi_token) !== 1) {
            throw new ResourceOperationException('task_vm.enrollment_invalid', 'Execution copies require the admitted VM.', 409);
        }
        $model = $this->models->capture($vm, $public['gateway_node_id'], $this->shared->executionCopy(...));
        foreach ($model['intent'] as $field => $value) {
            if (($public[$field] ?? null) !== $value) {
                throw new ResourceOperationException('task_vm.enrollment_invalid', 'Model and enrollment placements must agree.', 409);
            }
        }

        return [...$public, 'model_execution_secrets' => $model['execution_secrets'],
            'execution_secrets' => ['pi_token' => $this->shared->executionCopy('orbit-control-inputs', 'task-vm-pi-token-'.$vm->id, $vm->pi_token)]];
    }

    /** The projection supplies this outer envelope to the canonical shared input hook.
     * @param  array<string,mixed>  $captured
     * @return array{intent:array<string,mixed>,execution_secrets:array<string,mixed>}
     */
    public function envelope(array $captured): array
    {
        $model = ValidatedData::object($captured['model_execution_secrets'] ?? []);
        $execution = ValidatedData::object($captured['execution_secrets'] ?? []);

        return ['intent' => Arr::except($captured, ['model_execution_secrets', 'execution_secrets']),
            'execution_secrets' => [...$model, ...$execution]];
    }

    /** Capture through the same admission policy used by the provision parent.
     * @return array<string,mixed>
     */
    public function capture(TaskVm $vm): array
    {
        $intent = $this->enrollment->capture($vm);
        $placement = $this->enrollment->privateInput($vm, ValidatedData::integer($intent['execution_node_id']));

        return $this->fromCaptured($intent, $placement, [
            'address' => $vm->address,
            'public_key' => $vm->ssh_host_key,
        ]);
    }

    /** Shared by roots whose provider step produces the public connection later.
     * @param  array<string,mixed>  $intent
     * @param  array<string,mixed>  $placement
     * @param  array{address:string|null,public_key:string|null}  $connection
     * @return array<string,mixed>
     */
    public function fromCaptured(array $intent, array $placement, array $connection): array
    {
        $public = Arr::only($intent, ['task_vm_id', 'guest_node_id', 'execution_node_id', 'provider',
            'name', 'user', 'pi_url', 'agent_sha256', 'agent', 'agent_artifact_url', 'bootstrap', 'vm', 'node', 'runtime']);
        $public += Arr::only($placement, ['gateway_node_id', 'issuer_node_id', 'model_router_node_id',
            'model_origin', 'participant_node_ids', '_wireguard', 'network']);
        foreach (['node' => ['node_id', 'name', 'user', 'platform', 'architecture', 'wireguard_ip', 'cluster_id', 'ssh_jump_node_id'],
            'bootstrap' => ['user', 'public_key', 'releases', 'packages', 'dns_policy'],
            'agent' => ['configuration', 'certificate', 'unit', 'checkouts'],
            'vm' => ['project', 'name'],
            'network' => ['server_public_key', 'endpoint', 'address', 'subnet', 'dns_server', 'domains'],
            '_wireguard' => ['schema_version', 'executor_node_id', 'hub_id', 'context_node_id', 'excluded_node_id', 'agent_sha256'],
        ] as $field => $keys) {
            if (is_array($public[$field] ?? null)) {
                $public[$field] = Arr::only($public[$field], $keys);
            }
        }
        $runtime = is_array($public['runtime'] ?? null) ? $public['runtime'] : [];
        $runtime = Arr::only($runtime, ['guest_node_id', 'user', 'agent_sha256', 'pi_sha256', 'origin', 'models', 'process']);
        if (is_array($runtime['process'] ?? null)) {
            $runtime['process'] = Arr::only($runtime['process'], ['process_id', 'name', 'runtime', 'content_hash', 'desired', 'on_demand', 'activation_pending', 'agent_sha256', 'retired']);
        }
        $public['runtime'] = $runtime;
        $node = is_array($public['node'] ?? null) ? $public['node'] : [];
        $hub = is_array($public['_wireguard'] ?? null) ? $public['_wireguard'] : [];
        $id = $public['task_vm_id'] ?? null;
        $guest = $public['guest_node_id'] ?? null;
        $executor = $public['execution_node_id'] ?? null;
        $participants = $public['participant_node_ids'] ?? null;
        $owners = [$guest, $executor, $public['gateway_node_id'] ?? null, $public['issuer_node_id'] ?? null,
            $public['model_router_node_id'] ?? null, $hub['hub_id'] ?? null];
        if (! is_int($id) || $id < 1 || ! is_int($guest) || $guest < 1
            || ! is_int($executor) || $executor < 1 || $executor === $guest
            || ($public['name'] ?? null) !== 'tvm-'.$id || ($node['node_id'] ?? null) !== $guest
            || ($node['name'] ?? null) !== $public['name'] || ($runtime['guest_node_id'] ?? null) !== $guest
            || ($runtime['origin'] ?? null) !== ($public['model_origin'] ?? null)
            || ($hub['context_node_id'] ?? null) !== $guest || ! array_key_exists('excluded_node_id', $hub) || $hub['excluded_node_id'] !== null
            || ($hub['executor_node_id'] ?? null) !== ($public['gateway_node_id'] ?? null)
            || ! is_array($participants) || array_any($owners, static fn (mixed $owner): bool => ! is_int($owner) || $owner < 1 || ! in_array($owner, $participants, true))
            || ! in_array($public['provider'] ?? null, ['incus', 'upcloud'], true)
            || ($public['provider'] === 'upcloud' && $executor !== $public['gateway_node_id'])) {
            throw new ResourceOperationException('task_vm.enrollment_invalid', 'Command-step enrollment requires one admitted VM and its exact placements.', 409);
        }

        return ['schema_version' => 1, ...$public,
            'connection' => ['address' => $connection['address'], 'public_key' => $connection['public_key']]];
    }
}

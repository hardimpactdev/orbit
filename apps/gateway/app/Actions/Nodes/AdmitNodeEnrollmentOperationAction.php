<?php

declare(strict_types=1);

namespace App\Actions\Nodes;

use App\Actions\Operations\StartOperationAction;
use App\Data\Nodes\ProvisionNodeData;
use App\Domain\Nodes\NodeEnrollmentPlan;
use App\Domain\Nodes\NodeEnrollmentPolicy;
use App\Domain\Nodes\NodeEnrollmentRoutes;
use App\Domain\Nodes\NodeProvisioningLock;
use App\Domain\Nodes\NodeProvisioningLockException;
use App\Domain\Nodes\NodeTaskVmLifecycleOwnership;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationStore;
use App\Domain\Processes\ProcessSwampSafety;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\Nodes\NodeBootstrapCommandFactory;
use App\Infrastructure\Nodes\NodeRoleFirewallInput;
use App\Models\Node;
use App\Models\Operation;
use App\Models\TaskVm;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** Records public enrollment intent and the target hold; all effects are Swamp children. */
final readonly class AdmitNodeEnrollmentOperationAction
{
    public const array IdentityFields = ['status', 'cluster_id', 'platform', 'architecture', 'tld', 'user', 'public_ssh_host', 'public_ssh_port',
        'wireguard_ip', 'lan_ip', 'wireguard_endpoint_override', 'dns_server_override', 'failed_step', 'error_code',
        'ssh_host_key_type', 'ssh_host_key', 'ssh_host_fingerprint', 'wireguard_public_key', 'ssh_jump_node_id'];

    public function __construct(private StartOperationAction $operations, private OperationKinds $kinds, private OperationIdempotency $idempotency,
        private OperationStore $store, private NodeProvisioningLock $lock, private NodeEnrollmentPolicy $policy,
        private NodeEnrollmentRoutes $routes, private NodeEnrollmentPlan $plan, private AgentDelivery $delivery,
        private NodeBootstrapCommandFactory $bootstrap, private NodeRoleFirewallInput $firewall) {}

    public function execute(ProvisionNodeData $data, ?string $requestId = null): Operation
    {
        if (($existing = $this->idempotency->replay('node.add')) !== null) {
            return $existing;
        }
        if (! is_array($this->kinds->definition('node.add'))) {
            throw new ResourceOperationException('operation.kind_unavailable', 'Node enrollment is waiting for its complete execution contract.', 503);
        }
        try {
            return $this->lock->run($data->name, fn (): Operation => DB::transaction(fn (): Operation => $this->admit($data, $requestId)));
        } catch (NodeProvisioningLockException $exception) {
            throw $exception->toBusyException();
        }
    }

    private function admit(ProvisionNodeData $data, ?string $requestId): Operation
    {
        $node = Node::query()->where('name', $data->name)->lockForUpdate()->first() ?? new Node(['name' => $data->name]);
        if ($node->exists && app(ProcessSwampSafety::class)->requiresSynchronous($node)) {
            throw new ResourceOperationException('node.executor_dependency', 'Executor Nodes require synchronous enrollment recovery.', 409);
        }
        if ($data->taskVmId !== null || $this->idempotency->parent !== null) {
            app(NodeTaskVmLifecycleOwnership::class)->enrollment($node, $data);
        }
        $spec = $this->policy->prepare($node, $data);
        $existed = $node->exists;
        $prior = $existed ? $node->only(self::IdentityFields) : null;
        if ($prior !== null) {
            $prior['status'] = $node->status->value;
        }
        $candidate = $spec['candidate'];
        $changes = $this->routes->capture($node, ValidatedData::nullableString($candidate['tld']),
            $candidate['cluster_id'] === null ? null : ValidatedData::integer($candidate['cluster_id']));
        if (! $existed) {
            $node->fill([...$candidate, 'status' => LifecycleStatus::Provisioning]);
            $node->save();
        }
        $render = clone $node;
        $render->fill($candidate);
        $input = ['name' => $node->name, 'mode' => $spec['mode'], 'requested_architecture' => $spec['requested_architecture'],
            'managed_user' => $spec['managed_user'], 'enrollment_address' => $candidate['wireguard_ip'],
            'bootstrap_host' => $candidate['public_ssh_host'], 'bootstrap_port' => $candidate['public_ssh_port'],
            'bootstrap_user' => $spec['bootstrap_user'], 'bootstrap_fingerprint' => $spec['fingerprint'],
            'bootstrap_identity' => ['$secret' => 'node.bootstrap_ssh_identity', 'id' => $node->id],
            'agent_pins' => array_map(static fn (array $artifact): string => $artifact['sha256'], $this->delivery->artifacts()),
            'roleless_operator' => $spec['roleless_operator'], 'skip_bootstrap' => $data->taskVmId !== null,
            'bootstrap_command' => $spec['mode'] === 'linux' ? $this->bootstrap->input($render, $spec['managed_user'], $spec['roleless_operator']) : [],
            'bootstrap_firewall' => $spec['mode'] === 'linux' && ! $node->roles()->exists() ? $this->firewall->publicSsh($render, true) : null];
        $intent = ['id' => $node->id, 'existed' => $existed, 'prior_state' => $prior, 'candidate' => $candidate,
            'route_changes' => $changes, 'roles' => array_map(static fn ($role): string => $role->value, $data->roles),
            'task_vm_id' => $data->taskVmId, 'settings_provided' => $data->settingsProvided, 'settings' => $data->settings?->toArray()];
        $steps = $this->plan->build($node, $input, $intent);
        $intents = [];
        foreach ($steps as $step) {
            if ($step['type'] === 'child') {
                $intents[] = array_intersect_key($step, array_flip(['key', 'kind', 'node_id', 'input']));
            }
        }
        $intents[] = ['key' => 'add-restore', 'kind' => 'node.enrollment_restore', 'node_id' => $node->id, 'input' => []];
        $operation = $this->operations->execute('node.add', 'node', $node->id, $node->id,
            [...$input, '_node_plan' => $steps, '_node_result' => ['id' => $node->id, 'name' => $node->name, 'enrolled' => true],
                '_node_failure_result' => ['id' => $node->id, 'name' => $node->name, 'enrolled' => false]], 'operator', $requestId);
        if ($operation->wasRecentlyCreated) {
            $this->store->change($operation, ['state' => [...($operation->state ?? []), 'enrollment_intent' => $intent, 'child_intents' => $intents]]);
            if ($data->taskVmId !== null) {
                $taskVm = TaskVm::query()->lockForUpdate()->findOrFail($data->taskVmId);
                if ($taskVm->node_id !== null && $taskVm->node_id !== $node->id) {
                    throw new ResourceOperationException('node.task_vm_conflict', 'The task VM already owns another Node.', 409);
                }
                $taskVm->update(['node_id' => $node->id]);
            }
        }

        return $operation;
    }
}

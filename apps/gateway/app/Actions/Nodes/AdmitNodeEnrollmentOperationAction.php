<?php

declare(strict_types=1);

namespace App\Actions\Nodes;

use App\Actions\Operations\StartOperationAction;
use App\Data\Nodes\ProvisionNodeData;
use App\Domain\Nodes\NativeNodeEnrollmentInput;
use App\Domain\Nodes\NodeEnrollmentPolicy;
use App\Domain\Nodes\NodeProvisioningLock;
use App\Domain\Nodes\NodeProvisioningLockException;
use App\Domain\Nodes\Storage\NodeSettingsNormalizer;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationStore;
use App\Domain\Processes\ProcessSwampSafety;
use App\Domain\Routes\RouteExecutionProof;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** Records public enrollment intent and the target hold; all effects are Swamp children. */
final readonly class AdmitNodeEnrollmentOperationAction
{
    public const array IdentityFields = ['status', 'cluster_id', 'platform', 'architecture', 'tld', 'user', 'public_ssh_host', 'public_ssh_port',
        'wireguard_ip', 'lan_ip', 'dns_server_override', 'failed_step', 'error_code',
        'ssh_host_key_type', 'ssh_host_key', 'ssh_host_fingerprint', 'wireguard_public_key', 'ssh_jump_node_id'];

    public function __construct(private StartOperationAction $operations, private OperationKinds $kinds, private OperationIdempotency $idempotency,
        private OperationStore $store, private NodeProvisioningLock $lock, private NodeEnrollmentPolicy $policy,
        private NativeNodeEnrollmentInput $native) {}

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
        $spec = $this->policy->prepare($node, $data);
        $existed = $node->exists;
        $prior = $existed ? $node->only(self::IdentityFields) : null;
        if ($prior !== null) {
            $prior['status'] = $node->status->value;
        }
        $candidate = $spec['candidate'];
        if ($node->exists && $node->instances()->exists()) {
            throw new ResourceOperationException('node.has_instances', 'A Node with Instances cannot be enrolled again.', 409);
        }
        if (! $existed) {
            $node->fill([...$candidate, 'status' => LifecycleStatus::Provisioning]);
            $node->save();
        }
        $operation = $this->operations->execute('node.add', 'node', $node->id, $node->id,
            ['name' => $node->name], 'operator', $requestId);
        if ($operation->wasRecentlyCreated) {
            $native = $this->native->capture($node, $operation, $spec, $data);
            $leaves = [];
            foreach ($native['role_snapshots'] as $snapshot) {
                $certificates = ValidatedData::object($snapshot['certificates']);
                foreach (RouteExecutionProof::objects($certificates['leaves']) as $leaf) {
                    $leaves[] = $leaf;
                }
            }
            $this->store->change($operation, ['input' => [...$operation->input,
                'intent' => $native['intent'], 'execution_secrets' => $native['execution_secrets'],
                'participant_node_ids' => $native['participant_node_ids'],
                '_node_enrollment' => ['existed' => $existed, 'prior_state' => $prior, 'candidate' => $candidate,
                    'role_snapshots' => $native['role_snapshots'], 'certificates' => $leaves,
                    'agent_secret_hash' => $native['agent_secret_hash'],
                    'settings_provided' => $data->settingsProvided, 'settings' => app(NodeSettingsNormalizer::class)->storedOver($node->settings, $data->settings)]]]);
        }

        return $operation;
    }
}

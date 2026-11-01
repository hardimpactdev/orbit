<?php

declare(strict_types=1);

namespace App\Actions\Nodes;

use App\Actions\Operations\StartOperationAction;
use App\Domain\AppDev\PrivateDnsAnswerExpiry;
use App\Domain\Nodes\NativeNodeRoleConvergeInput;
use App\Domain\Nodes\NativeNodeRoleRemovalInput;
use App\Domain\Nodes\NodeRoleCertificateInput;
use App\Domain\Nodes\NodeRoleParentInput;
use App\Domain\Nodes\NodeRoleIntent;
use App\Domain\Nodes\NodeRoleRelocateInput;
use App\Domain\Nodes\NodeRoleRuntimeInput;
use App\Domain\Nodes\NodeRoleSettings;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationStore;
use App\Domain\Routes\RouteExecutionProof;
use App\Domain\Routes\RouteRenderContext;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** Legacy order: capture source, transfer real assignment, prove target, then withdraw source. */
final readonly class AdmitNodeRoleRelocateAction
{
    public function __construct(private StartOperationAction $operations, private OperationStore $store,
        private OperationIdempotency $idempotency, private NodeRoleParentInput $input) {}

    public function execute(Node $target, RoleName $role, bool $force = false, ?Node $from = null, ?string $requestId = null): Operation
    {
        if (($existing = $this->idempotency->replay('node.role_relocate')) !== null) {
            return $existing;
        }

        return DB::transaction(function () use ($target, $role, $force, $from, $requestId): Operation {
            $target = Node::query()->lockForUpdate()->findOrFail($target->id);
            $prepared = app(NodeRoleRelocateInput::class)->prepare($target, $role, $force, $from);
            $source = Node::query()->lockForUpdate()->findOrFail($prepared['source']->id);
            $assignment = NodeRole::query()->lockForUpdate()->findOrFail($prepared['assignment']->id);
            if ($source->id === 209 || ! NativeNodeRoleConvergeInput::supportsContribution($target, $role)) {
                throw new ResourceOperationException('operation.kind_unavailable', 'This transfer requires its implemented native target and source cleanup.', 422);
            }
            if (! is_string($target->agent_secret_hash) || preg_match('/\A[a-f0-9]{64}\z/D', $target->agent_secret_hash) !== 1) {
                throw new ResourceOperationException('node.agent_rekey_required', 'The target requires its recorded Node agent identity.', 409);
            }
            $operation = $this->operations->execute('node.role_relocate', 'node', $target->id, $target->id,
                ['node_id' => $target->id, 'role' => $role->value, 'source_node_id' => $source->id], 'operator', $requestId,
                lockKey: OperationStore::lockKey('node', $target->id));
            if (! $operation->wasRecentlyCreated) {
                return $operation;
            }
            $removal = app(NativeNodeRoleRemovalInput::class);
            if ($assignment->node_id === $target->id) {
                $previous = Operation::query()->where('kind', 'node.role_relocate')->where('node_id', $target->id)
                    ->where('input->_relocate->assignment_id', $assignment->id)->where('input->_relocate->source_node_id', $source->id)
                    ->whereKeyNot($operation->id)->latest('id')->first();
                $priorTransfer = $previous === null ? [] : ValidatedData::object($previous->input['_relocate'] ?? []);
                $priorIntent = $previous === null ? [] : ValidatedData::object($previous->input['intent'] ?? []);
                if ($previous === null || ! isset($priorTransfer['source_before'], $priorTransfer['source'], $priorIntent['source'])) {
                    throw new ResourceOperationException('operation.kind_unavailable', 'This transfer has no captured native source state; its original outcome must be settled first.', 422);
                }
                $roleIntent = ValidatedData::object($previous->state['role_intent']);
                $transfer = $priorTransfer;
                $before = RouteExecutionProof::objects($transfer['source_before']);
                $sourceSnapshot = ValidatedData::object($transfer['source']);
                $sourceIntent = ValidatedData::object($priorIntent['source']);
            } else {
                $roleIntent = NodeRoleIntent::capture($assignment, false, $target);
                $before = $removal->publications($source, $role);
                $sourceSnapshot = ['node_id' => $source->id, 'role' => $role->value, 'assignment_id' => $assignment->id,
                    'runtime' => app(NodeRoleRuntimeInput::class)->removal($source, $role)];
                $assignment->claimRemoval();
                $sourceIntent = $removal->capture($source, $assignment, $before);
                $assignment->update(['node_id' => $target->id]);
            }
            $assignment->claimConvergence();
            app(NodeRoleSettings::class)->copyForTransfer($source->id, $target->id, $role);
            $captured = $this->input->target($target, $assignment, false);
            $certificates = app(NodeRoleCertificateInput::class)->capture($target, $role, $source);
            $nativeCertificates = ValidatedData::object($captured['intent']['certificates'] ?? $captured['snapshot']['certificates']);
            $certificates['leaves'] = array_map(static fn (array $leaf): array => [
                'node_id' => ValidatedData::integer($leaf['node_id']), 'scope' => ValidatedData::string($leaf['scope']),
                'domain' => ValidatedData::string($leaf['domain']), 'version' => ValidatedData::string($leaf['version']),
                'root_hash' => ValidatedData::string($leaf['root_hash']),
            ], RouteExecutionProof::objects($nativeCertificates['leaves']));
            $after = app(RouteRenderContext::class)->renderRoleCertificates($certificates,
                fn (): array => $removal->publications($source, $role));
            $sourceIntent['publications'] = [];
            foreach ($after as $member) {
                $prior = array_find($before, static fn (array $row): bool => $row['node_id'] === $member['node_id']);
                if ($prior === null) {
                    throw new ResourceOperationException('operation.kind_unavailable', 'Transfer needs the complete pre-move source publication.', 422);
                }
                $sourceIntent['publications'][] = ['node_id' => $member['node_id'], 'control_node_id' => 209,
                    'host_keys' => $member['host_keys'], 'connection' => $member['connection'],
                    'before' => array_intersect_key($prior, array_flip(['caddy', 'php', 'dns'])),
                    'after' => array_intersect_key($member, array_flip(['caddy', 'php', 'dns']))];
            }
            $copies = $this->input->executionCopies($operation, $target, $captured['snapshot']);
            $this->store->change($operation, ['input' => [...$operation->input,
                '_role' => $captured['snapshot'], 'native_role' => $captured['intent'],
                '_relocate' => ['schema_version' => 1, 'assignment_id' => $assignment->id, 'role' => $role->value,
                    'source_node_id' => $source->id, 'target_node_id' => $target->id,
                    'target' => $captured['snapshot'], 'source' => $sourceSnapshot, 'source_before' => $before, 'certificates' => $certificates],
                'intent' => ['target' => $captured['intent'], 'source' => $sourceIntent,
                    'withdrawal_grace_seconds' => $role === RoleName::WebSocket ? PrivateDnsAnswerExpiry::WithdrawalGraceSeconds : 0],
                'execution_secrets' => $copies,
                'participant_node_ids' => array_values(array_unique([...$captured['participant_node_ids'], $source->id, ...array_map(static fn (array $member): int => ValidatedData::integer($member['node_id']), $after)]))],
                'state' => [...$operation->state, 'role_intent' => $roleIntent]]);

            return $operation;
        });
    }
}

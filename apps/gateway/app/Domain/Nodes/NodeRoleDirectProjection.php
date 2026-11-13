<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Data\Nodes\NodeData;
use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\NativeOperationProjection;
use App\Domain\Routes\RouteExecutionProof;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Swamp\NativeRunResult;
use App\Models\NodeRole;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** Role assignment and footprint state only. The original native root owns every effect. */
final readonly class NodeRoleDirectProjection implements DirectOperationProjection, NativeOperationProjection
{
    /**
     * The run's steps that only read the Node. A run that fails at one of them has changed nothing on the Node,
     * because every later step depends on them. Keep this list in step with `operation-node-role-add`.
     *
     * @var list<string>
     */
    private const array StepsBeforeNodeChange = ['Read the OS release', 'Require supported Ubuntu'];

    public function native(Operation $operation): bool
    {
        return true;
    }

    public function input(Operation $operation): array
    {
        return ['intent' => ValidatedData::object($operation->input['native_role']),
            'execution_secrets' => $operation->input['execution_secrets'] ?? []];
    }

    /** @param array<string,mixed> $data */
    public function apply(Operation $operation, array $data): Operation
    {
        if (! in_array($data['status'] ?? null, ['succeeded', 'failed', 'cancelled', 'interrupted'], true)) {
            throw new ResourceOperationException('operation.result_invalid', 'Native role addition projects terminal results only.', 422);
        }

        return DB::transaction(function () use ($operation, $data): Operation {
            $operation->setRawAttributes(Operation::query()->whereKey($operation->id)->lockForUpdate()->firstOrFail()->getAttributes(), true);
            $terminal = $operation->status->terminal();
            $snapshot = ValidatedData::object($operation->input['_role']);
            $raw = ValidatedData::object($data['result'] ?? []);
            $proven = $data['status'] === 'succeeded' && NativeRunResult::stepStatus($raw, 'Check the role') === 'succeeded';
            $summary = ValidatedData::object($raw['summary'] ?? []);
            $failed = $raw['failed_step'] ?? (is_array($summary['failed_steps'] ?? null) ? ($summary['failed_steps'][0] ?? 'operation') : 'operation');
            $error = $failed === 'Require supported Ubuntu' ? 'role.os_unsupported' : ($raw['error_code'] ?? $data['error_code'] ?? 'node_role.convergence_failed');
            $result = $proven
                ? ['assignment_id' => $snapshot['assignment_id'], 'role' => $snapshot['role'], 'created' => $snapshot['created'], 'role_proven' => true]
                : ['failed_step' => $failed, 'error_code' => $error];
            if (! $proven && $data['status'] === 'failed') {
                $data['error_code'] = $error;
            }
            $projected = app(DirectOperation::class)->project($operation, $data, $result);
            if ($terminal) {
                return $projected;
            }
            $intent = ValidatedData::object($operation->input['native_role']);
            if (NativeRunResult::stepStatus($raw, 'Apply the role on the Node') === 'succeeded') {
                app(GatewayPrivateDnsState::class)->record(ValidatedData::integer($operation->node_id), ValidatedData::object($intent['runtime'] ?? []));
            }
            $certificates = ValidatedData::object($snapshot['certificates']);
            foreach (RouteExecutionProof::objects($certificates['leaves']) as $index => $leaf) {
                if (NativeRunResult::stepStatus($raw, 'Sign role certificates-'.$index) === 'succeeded') {
                    app(NodeRolePublicationState::class)->record($certificates, 'certificates_published', ValidatedData::integer($leaf['node_id']));
                }
            }
            $caddy = array_values(array_filter(RouteExecutionProof::objects($intent['publications']),
                static fn (array $publication): bool => ValidatedData::object($publication['after'])['caddy'] !== null));
            foreach ($caddy as $publication) {
                if (NativeRunResult::stepStatus($raw, 'Publish role sites') === 'succeeded') {
                    app(NodeRolePublicationState::class)->record($certificates, 'caddy_published', ValidatedData::integer($publication['node_id']));
                }
            }
            if (NativeRunResult::stepStatus($raw, 'Apply Analytics runtime-0') === 'succeeded') {
                app(NodeRoleRuntimeProjection::class)->record($snapshot);
            }
            $assignment = NodeRole::query()->whereKey($snapshot['assignment_id'])->where('node_id', $operation->node_id)->where('role', $snapshot['role'])->firstOrFail();
            if ($proven) {
                $assignment->markConvergenceActive();
                $digests = ValidatedData::stringMap($intent['artifact_digests']);
                if ($digests !== []) {
                    $entries = [];
                    foreach ($digests as $name => $digest) {
                        $entries[] = ['name' => $name, 'digest' => $digest];
                    }
                    $applied = array_fill_keys(array_keys($digests), ['status' => 'applied']);
                    app(NodeFootprintProjection::class)->record($operation,
                        ['node_id' => $operation->node_id, 'artifacts' => $entries], ['footprint_proven' => false], $applied);
                }
            } else {
                $failedStep = ValidatedData::string($result['failed_step'] ?? 'operation');
                $assignment->markConvergenceFailed($failedStep, ValidatedData::string($result['error_code'] ?? 'node_role.convergence_failed'),
                    nodeUntouched: $data['status'] === 'failed' && in_array($failedStep, self::StepsBeforeNodeChange, true));
            }
            app(RecordEventBroadcaster::class)->broadcast(RecordEventType::NodeUpdated, $assignment->node_id,
                NodeData::fromModel($assignment->node->refresh())->toArray());

            return $projected;
        });
    }
}

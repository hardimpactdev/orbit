<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Data\Nodes\NodeData;
use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Fleet\NodeCliState;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\NodeRole;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** Role assignment and footprint state only. The original native root owns every effect. */
final readonly class NodeRoleDirectProjection implements DirectOperationProjection
{
    public function input(Operation $operation): array
    {
        return ValidatedData::object($operation->input['_role'] ?? []);
    }

    public function apply(Operation $operation, array $data): Operation
    {
        $status = OperationStatus::from(ValidatedData::string($data['status']));
        $result = ValidatedData::object($data['result'] ?? []);
        $snapshot = $this->input($operation);
        $artifacts = [];
        if (in_array($status, [OperationStatus::Succeeded, OperationStatus::Failed], true)) {
            if (($result['assignment_id'] ?? null) !== ($snapshot['assignment_id'] ?? null)
                || ($result['created'] ?? null) !== ($snapshot['created'] ?? null)
                || array_diff(array_keys($result), ['role_proven', 'assignment_id', 'created', 'baseline', 'footprint', 'cli', 'follow_up', 'error_code', 'failed_step']) !== []) {
                throw $this->invalid();
            }
            $baseline = $result['baseline'] ?? null;
            if (! is_array($baseline) || ! array_is_list($baseline) || count($baseline) > 3) {
                throw $this->invalid();
            }
            $stages = [];
            foreach ($baseline as $entry) {
                if (! is_array($entry) || array_diff(array_keys($entry), ['stage', 'ok', 'error_code']) !== []
                    || ! in_array($entry['stage'] ?? null, ['prerequisites', 'ci-host', 'firewall'], true)
                    || isset($stages[$entry['stage']]) || ! is_bool($entry['ok'] ?? null)
                    || ($entry['ok'] ? isset($entry['error_code']) : (! is_string($entry['error_code'] ?? null)
                        || preg_match('/\A[a-z][a-z0-9_-]*\.[a-z0-9_.-]{1,90}\z/D', $entry['error_code']) !== 1))) {
                    throw $this->invalid();
                }
                $stages[$entry['stage']] = $entry['ok'];
            }
            if ($status === OperationStatus::Succeeded) {
                if (($result['role_proven'] ?? null) !== true || count($stages) !== 3 || in_array(false, $stages, true)
                    || ($data['error_code'] ?? null) !== null || isset($result['error_code']) || isset($result['failed_step'])) {
                    throw $this->invalid();
                }
                $footprint = ValidatedData::object($result['footprint'] ?? []);
                $proved = ($footprint['footprint_proven'] ?? null) === true;
                $artifacts = app(NodeFootprintProjection::class)->validate(ValidatedData::object($snapshot['footprint']), $footprint,
                    $proved ? OperationStatus::Succeeded : OperationStatus::Failed, ValidatedData::nullableString($footprint['error_code'] ?? null));
                $followUps = $proved ? [] : ['role-footprint:'.ValidatedData::string($footprint['error_code'])];
                $cli = ValidatedData::object($result['cli'] ?? []);
                $cliPlan = ValidatedData::object($snapshot['cli']);
                if (array_key_exists('error_code', $cli)) {
                    $cliCode = $cli['error_code'];
                    if (! is_string($cliCode) || preg_match('/\A(?:cli|fleet|node|operation|agent)\.[a-z0-9_.-]{1,90}\z/D', $cliCode) !== 1
                        || array_diff(array_keys($cli), ['error_code']) !== []) {
                        throw $this->invalid();
                    }
                    $followUps[] = 'role-cli:'.$cliCode;
                } else {
                    if (array_diff(array_keys($cli), ['completed', 'outcome', 'reason', 'version', 'configured']) !== []
                        || ($cli['completed'] ?? null) !== true || ! is_bool($cli['configured'] ?? null)
                        || ! array_key_exists('version', $cli) || ! array_key_exists('reason', $cli)
                        || ! in_array($cli['outcome'] ?? null, ['cli_installed', 'cli_present', 'skipped'], true)) {
                        throw $this->invalid();
                    }
                    if ($cli['outcome'] === 'skipped') {
                        if (! is_string($cli['reason']) || $cli['reason'] !== ($cliPlan['skip_reason'] ?? null)
                            || $cli['version'] !== null || $cli['configured'] !== false) {
                            throw $this->invalid();
                        }
                    } elseif (($cliPlan['skip_reason'] ?? null) !== null || ($cliPlan['plan_error'] ?? null) !== null || $cli['reason'] !== null
                        || ($cli['outcome'] === 'cli_present' ? $cli['version'] !== null
                            : (! is_string($cli['version']) || $cli['version'] !== (ValidatedData::object($cliPlan['release'])['version'] ?? null)))) {
                        throw $this->invalid();
                    }
                }
                if (($result['follow_up'] ?? null) !== ($followUps === [] ? null : implode(', ', $followUps))) {
                    throw $this->invalid();
                }
            } elseif (($result['role_proven'] ?? null) !== false || ($result['error_code'] ?? null) !== ($data['error_code'] ?? null)
                || ! is_string($result['error_code'] ?? null) || preg_match('/\A[a-z][a-z0-9_-]*\.[a-z0-9_.-]{1,90}\z/D', $result['error_code']) !== 1
                || ! in_array($result['failed_step'] ?? null, ['admission', 'agent-install', 'prerequisites', 'ci-host', 'firewall'], true)
                || array_intersect(['footprint', 'cli', 'follow_up'], array_keys($result)) !== []) {
                throw $this->invalid();
            }
        } elseif ($result !== [] || ($data['error_code'] ?? null) !== null) {
            throw $this->invalid();
        }

        return DB::transaction(function () use ($operation, $data, $status, $result, $snapshot, $artifacts): Operation {
            $operation->setRawAttributes(Operation::query()->whereKey($operation->id)->lockForUpdate()->firstOrFail()->getAttributes(), true);
            $terminal = $operation->status->terminal();
            if (! $terminal) {
                NodeRoleDirectInput::bound($operation);
            }
            $projected = app(DirectOperation::class)->project($operation, $data, $result, 'node-role');
            if ($terminal || ! $status->terminal()) {
                return $projected;
            }
            $assignment = NodeRole::query()->whereKey($snapshot['assignment_id'])->where('node_id', $operation->node_id)
                ->where('role', $snapshot['role'])->firstOrFail();
            if ($status === OperationStatus::Succeeded) {
                $assignment->markConvergenceActive();
                app(NodeFootprintProjection::class)->record($operation, ValidatedData::object($snapshot['footprint']),
                    ValidatedData::object($result['footprint']), $artifacts);
                $cli = ValidatedData::object($result['cli']);
                if (($cli['error_code'] ?? null) === 'cli.foreign_binary') {
                    app(NodeCliState::class)->markForeign($assignment->node);
                } elseif (($cli['outcome'] ?? null) !== 'skipped' && ! isset($cli['error_code'])) {
                    app(NodeCliState::class)->clear($assignment->node);
                }
            } else {
                $assignment->markConvergenceFailed(ValidatedData::string($result['failed_step'] ?? 'operation'),
                    ValidatedData::string($result['error_code'] ?? 'operation.cancelled'));
            }
            app(RecordEventBroadcaster::class)->broadcast(RecordEventType::NodeUpdated, $assignment->node_id,
                NodeData::fromModel($assignment->node->refresh())->toArray());

            return $projected;
        });
    }

    private function invalid(): ResourceOperationException
    {
        return new ResourceOperationException('operation.result_invalid', 'The role result differs from its admitted native stages.', 422);
    }
}

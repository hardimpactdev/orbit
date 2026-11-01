<?php

declare(strict_types=1);

namespace App\Domain\Instances\Deployment;

use App\Domain\Instances\VpEnvironment\InstanceVpEnvironmentMaterial;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\DirectOperationWorkflowInput;
use App\Domain\Tools\VpEnvironment;
use App\Models\Instance;
use App\Models\InstanceDeployment;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** Records bounded native facts. All sequencing, runtime checks and effects belong to Swamp. */
final readonly class InstanceDeployProjection implements DirectOperationProjection, DirectOperationWorkflowInput
{
    public function __construct(
        private DirectOperation $direct,
        private InstanceDeploymentDirectInput $intent,
        private InstanceDeploymentRecorder $history,
        private InstanceRuntimeDefinitions $members,
        private InstanceVpEnvironmentMaterial $vp,
    ) {}

    public function workflowInput(Operation $operation): array
    {
        $input = $this->intent->admitted($operation);
        $processes = ValidatedData::object($input['deployment'])['variant'] === 'production'
            ? $this->members->processWorkflowInput($operation) : ['members' => [], 'execution_secrets' => []];

        return ['intent' => [...$input, 'process_members' => $processes['members']], 'execution_secrets' => $processes['execution_secrets']];
    }

    public function input(Operation $operation): array
    {
        return $this->intent->admitted($operation);
    }

    public function apply(Operation $operation, array $data): Operation
    {
        return DB::transaction(function () use ($operation, $data): Operation {
            $locked = Operation::query()->lockForUpdate()->findOrFail($operation->id);
            $instance = Instance::query()->with(['node', 'project'])->findOrFail($locked->target_id);
            $terminal = $locked->status->terminal();
            $status = ValidatedData::string($data['status']);
            $payload = ValidatedData::object($data['result'] ?? []);
            $historyId = $locked->state['deployment_id'] ?? null;
            if (! is_int($historyId)) {
                $row = $this->history->start($instance, $locked->triggered_by ?? 'operation',
                    kind: $locked->kind === 'instance.rollback' ? 'rollback' : 'deploy', callerNodeId: $locked->callerNodeId());
                $historyId = $row->id;
                $locked->update(['state' => [...($locked->state ?? []), 'deployment_id' => $historyId]]);
            }
            $text = static fn (mixed $value, int $limit): ?string => $value === null ? null : mb_strcut(ValidatedData::string($value), 0, $limit, 'UTF-8');
            $facts = ['deployment_id' => $historyId, 'release' => $text(ValidatedData::object($payload['release'] ?? [])['name'] ?? null, 128),
                'selected_release' => $text(ValidatedData::object($payload['selected_release'] ?? [])['name'] ?? null, 128),
                'commit' => $text($payload['commit'] ?? null, 64), 'failed_step' => $text($payload['failed_step'] ?? null, 255)];
            if (is_string($payload['output_tail'] ?? null) && $payload['output_tail'] !== '') {
                $facts['output_tail'] = str_replace("\0", '', $text($payload['output_tail'], 2048) ?? '');
            }
            if (($payload['step_outcome'] ?? null) === 'unknown') {
                $facts['step_outcome'] = 'unknown';
            }
            $projected = $this->direct->project($locked, $data, $status === 'running' ? [] : $facts);
            if ($terminal) {
                return $projected;
            }
            $admitted = ValidatedData::object($locked->input['deployment']);
            if (is_array($payload['vp_environment'] ?? null)) {
                $runtime = $payload['vp_environment'];
                $path = ValidatedData::string($admitted['home']);
                if ($admitted['variant'] !== 'development' && $facts['release'] !== null) {
                    $path .= '/releases/'.$facts['release'];
                }
                $this->vp->recordReference($instance, $path, VpEnvironment::fromArray($runtime), ValidatedData::string($runtime['status']));
            }
            if ($status === 'running') {
                return $projected;
            }
            if ($admitted['variant'] === 'development' && $status === 'succeeded') {
                $instance->update(['seed_path' => $admitted['home'], 'seed_repository' => $admitted['home'], 'seed_commit' => $facts['commit']]);
            } elseif ($admitted['variant'] !== 'development' && $facts['selected_release'] !== null && ($payload['selection_confirmed'] ?? false) === true) {
                $instance->update(['checkout_path' => ValidatedData::string($admitted['home']).'/releases/'.$facts['selected_release']]);
            }
            if (is_array($payload['environment'] ?? null)) {
                $keys = ValidatedData::stringList($payload['environment']['keys'] ?? []);
                sort($keys, SORT_STRING);
                $instance->forceFill(['environment_owned_keys' => array_values(array_unique($keys)),
                    'environment_runtime_pending_at' => null])->saveOrFail();
            }
            if (($data['error_code'] ?? null) === 'operation.native_failed' && isset($locked->input['runtime_definitions'])) {
                $captured = ValidatedData::object($locked->input['runtime_definitions']);
                $members = $captured['processes'];
                if (! is_array($members) || ! array_is_list($members)) {
                    throw new \InvalidArgumentException('Captured deployment Processes must be a list.');
                }
                $definitions = ValidatedData::object($payload['runtime_definitions'] ?? []);
                $definitions['processes'] = array_map(static function (mixed $member): array {
                    $member = ValidatedData::object($member);

                    return ['process_id' => ValidatedData::integer($member['process_id']), 'ready' => false, 'error_code' => 'operation.native_failed'];
                }, $members);
                $payload['runtime_definitions'] = $definitions;
            }
            if (is_array($payload['runtime_definitions'] ?? null)) {
                $this->members->project($locked, ValidatedData::object($payload['runtime_definitions']));
            }
            $row = InstanceDeployment::query()->where('instance_id', $instance->id)->findOrFail($historyId);
            $this->history->recordNative($row, $status, ValidatedData::nullableString($data['error_code'] ?? null), $facts);

            return $projected;
        });
    }
}

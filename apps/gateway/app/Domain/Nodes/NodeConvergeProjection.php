<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tools\ToolOperation;
use App\Domain\Tools\ToolStatus;
use App\Models\Operation;
use App\Models\Tool;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** Bounded results only. Converge never projects assignments or Instances. */
final readonly class NodeConvergeProjection implements DirectOperationProjection
{
    public function input(Operation $operation): array
    {
        $snapshot = ValidatedData::object($operation->input['_converge'] ?? []);

        return ['intent' => $snapshot['intent'] ?? [], 'execution_secrets' => $snapshot['execution_secrets'] ?? []];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        $snapshot = ValidatedData::object($operation->input['_converge'] ?? []);
        $intent = ValidatedData::object($snapshot['intent'] ?? []);
        $admitted = collect(array_map(ValidatedData::object(...), is_array($intent['tools'] ?? null) ? $intent['tools'] : []))->keyBy('id');
        try {
            $result = NodeConvergeResult::parse($data['result'] ?? null);
            $tools = array_map(ValidatedData::object(...), is_array($result['tools'] ?? null) ? $result['tools'] : []);
            $phases = array_map(ValidatedData::object(...), is_array($result['phases'] ?? null) ? $result['phases'] : []);
            if (count($tools) > $admitted->count() || ($phases[4]['status'] === 'succeeded' && count($tools) !== $admitted->count())) {
                throw new ResourceOperationException('operation.result_invalid', 'The converge Tool observations are incomplete or outside scope.', 422);
            }
            foreach ($tools as $observation) {
                if (! $admitted->has(ValidatedData::integer($observation['id']))) {
                    throw new ResourceOperationException('operation.result_invalid', 'The converge Tool observation is not admitted.', 422);
                }
            }
            if (($data['status'] ?? null) !== 'succeeded' && $result['outcome'] === 'succeeded') {
                throw new ResourceOperationException('operation.result_invalid', 'An unsuccessful native run cannot report success.', 422);
            }
        } catch (ResourceOperationException $error) {
            return app(DirectOperation::class)->project($operation, [...$data, 'status' => 'failed', 'error_code' => $error->errorCode], ['error_code' => $error->errorCode]);
        }
        $data = [...$data, 'status' => $result['outcome'], 'error_code' => $result['error_code'] ?? null];

        return DB::transaction(function () use ($operation, $data, $result, $tools, $admitted): Operation {
            $locked = Operation::query()->whereKey($operation->id)->lockForUpdate()->firstOrFail();
            if ($locked->status->terminal()) {
                return $locked;
            }
            foreach ($tools as $observation) {
                $id = ValidatedData::integer($observation['id']);
                $plan = ValidatedData::object($admitted->get($id));
                $tool = Tool::query()->whereKey($id)->where('node_id', $operation->node_id)->lockForUpdate()->first();
                if ($tool === null || $tool->package !== $plan['package'] || $tool->tool_manager_id !== $plan['manager_id']
                    || $tool->version_constraint !== $plan['version_constraint']) {
                    continue;
                }
                $tool->status = $observation['status'] === 'installed' ? ToolStatus::Installed : ToolStatus::Failed;
                $tool->error_code = $tool->status === ToolStatus::Failed ? 'tool.install_failed' : null;
                $tool->failed_operation = $tool->status === ToolStatus::Failed ? ToolOperation::Install : null;
                if ($tool->status === ToolStatus::Installed) {
                    $tool->installed_version = ValidatedData::string($observation['installed_version']);
                }
                $tool->save();
            }

            return app(DirectOperation::class)->project($locked, $data, $result);
        });
    }
}

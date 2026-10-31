<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Process;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

final readonly class AnalyticsUpdateProjection implements DirectOperationProjection
{
    public function __construct(private DirectOperation $direct, private AnalyticsRoleSettingsRepository $settings) {}

    public function input(Operation $operation): array
    {
        return $operation->input;
    }

    public function apply(Operation $operation, array $data): Operation
    {
        $status = OperationStatus::from(ValidatedData::string($data['status']));
        $result = [];
        if ($status === OperationStatus::Succeeded) {
            $result = ['ready' => true, 'version' => $operation->input['version'], 'previous_version' => $operation->input['previous_version']];
        } elseif ($status === OperationStatus::Failed) {
            $result = ['error_code' => ValidatedData::nullableString($data['error_code'] ?? null)];
            $observation = ValidatedData::object($data['result'] ?? []);
            if (is_bool($observation['rolled_back'] ?? null)) {
                $result['rolled_back'] = $observation['rolled_back'];
            }
        }

        return DB::transaction(function () use ($operation, $data, $status, $result): Operation {
            $operation->refresh();
            $terminal = $operation->status->terminal();
            $projected = $this->direct->project($operation, $data, $result);
            if ($terminal) {
                return $projected;
            }
            if ($status === OperationStatus::Succeeded) {
                $process = Process::query()->findOrFail(ValidatedData::integer($projected->input['process_id']));
                $version = ValidatedData::string($projected->input['version']);
                $process->update(['runtime_config' => [...$process->runtime_config, 'image' => PlausibleProcess::IMAGE.':v'.$version,
                    'command' => $projected->input['after']['command'],
                    'volumes' => $projected->input['after']['mounts']],
                    'status' => LifecycleStatus::Active, 'failed_step' => null, 'error_code' => null]);
                $this->settings->storeVersion(Node::query()->findOrFail($projected->node_id), $version);
            }
            if ($status === OperationStatus::Failed && ($result['rolled_back'] ?? null) === false) {
                Process::query()->findOrFail(ValidatedData::integer($projected->input['process_id']))
                    ->update(['status' => LifecycleStatus::Failed, 'failed_step' => null, 'error_code' => $result['error_code']]);
            }

            return $projected;
        });
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\OperationStatus;
use App\Models\Operation;
use App\Support\ValidatedData;

/** Records the manager and the package of a finished install. The run holds no result of its own. */
final readonly class TaskPackageProjection implements DirectOperationProjection
{
    public function input(Operation $operation): array
    {
        return ['intent' => $operation->input];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        $status = OperationStatus::from(ValidatedData::string($data['status']));
        if (in_array($status, [OperationStatus::Failed, OperationStatus::Interrupted], true)) {
            $data['error_code'] = 'tasks.package_failed';
            $result = ['error_code' => 'tasks.package_failed'];
        } else {
            $result = $status === OperationStatus::Succeeded ? ['manager' => $operation->input['manager'], 'package' => $operation->input['package']] : [];
        }
        $data['result'] = $result;

        return app(DirectOperation::class)->project($operation, $data, $result);
    }
}

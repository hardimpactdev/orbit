<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\OperationStatus;
use App\Models\Operation;
use App\Support\ValidatedData;

/** Records the manager, the package and what became of the setup token for a finished install. */
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
            $result = $status === OperationStatus::Succeeded
                ? ['manager' => $operation->input['manager'], 'package' => $operation->input['package'], 'dependency_token' => self::token($data)]
                : [];
        }
        $data['result'] = $result;

        return app(DirectOperation::class)->project($operation, $data, $result);
    }

    /**
     * What the remove step reported about the token. A run that reports nothing counts as unrevoked, so a missing report is never read as a success.
     *
     * @param  array<string,mixed>  $data
     * @return array{state:string,reason?:string}
     */
    private static function token(array $data): array
    {
        $reported = ValidatedData::object($data['result'] ?? [])['dependency_token'] ?? null;
        if (is_array($reported) && in_array($reported['state'] ?? null, ['revoked', 'none', 'unrevoked'], true)) {
            return array_filter(['state' => $reported['state'], 'reason' => is_string($reported['reason'] ?? null) ? mb_substr($reported['reason'], 0, 200) : null]);
        }

        return ['state' => 'unrevoked', 'reason' => 'The revoke result was not recorded.'];
    }
}

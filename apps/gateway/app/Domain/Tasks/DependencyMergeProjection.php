<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\NativeOperationProjection;
use App\Models\Operation;
use App\Support\ValidatedData;

/** Public intent and bounded result recording. Swamp owns merge policy. */
final readonly class DependencyMergeProjection implements DirectOperationProjection, NativeOperationProjection
{
    public function native(Operation $operation): bool
    {
        return true;
    }

    public function input(Operation $operation): array
    {
        return ['intent' => array_diff_key($operation->input, ['execution_secrets' => true]), 'execution_secrets' => $operation->input['execution_secrets']];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        $native = ValidatedData::object($data['result'] ?? []);
        if (($data['status'] ?? null) === 'succeeded') {
            $result = ValidatedData::object(json_decode(ValidatedData::string($native['merge_receipt']), true, flags: JSON_THROW_ON_ERROR));
        } else {
            $failure = ValidatedData::object($data['failure'] ?? []);
            $detail = $failure['detail'] ?? null;
            $codes = ['disabled', 'pr_changed', 'merge_refused', 'default_branch_changed', 'pr_not_ready', 'files_unreadable', 'files_forbidden', 'lock_removed', 'contents_unreadable', 'contents_forbidden', 'major_bump', 'smoke_missing', 'checks_unreadable', 'checks_not_passed', 'smoke_not_passed', 'packagist_unavailable'];
            $code = in_array($detail, array_map(static fn (string $code): string => 'dependency_merge.'.$code, $codes), true) ? $detail : 'dependency_merge.merge_refused';
            $data['error_code'] = $code;
            $result = ['error_code' => $code];
        }
        $data['result'] = $result;

        return app(DirectOperation::class)->project($operation, $data, $result);
    }
}

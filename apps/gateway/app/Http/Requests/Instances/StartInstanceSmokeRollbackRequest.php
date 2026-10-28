<?php

declare(strict_types=1);

namespace App\Http\Requests\Instances;

use App\Data\Instances\InstanceSmokeRollbackData;

final class StartInstanceSmokeRollbackRequest extends InstanceSmokeProofRequest
{
    protected const string Error = 'instance.smoke_rollback_invalid';

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'failure_key' => ['required', 'string', 'regex:/\A[A-Za-z0-9._:-]{1,128}\z/D'],
            'expected' => ['required', 'array:deployment_id,commit,release'],
            'expected.deployment_id' => ['required', 'integer', 'min:1'],
            'expected.commit' => ['required', 'string', 'regex:/\A[0-9a-f]{40}\z/D'],
            'expected.release' => ['required', 'string', 'regex:/\A[A-Za-z0-9][A-Za-z0-9._-]{0,127}\z/D'],
            'target' => ['required', 'array:deployment_id,commit,release'],
            'target.deployment_id' => ['required', 'integer', 'min:1'],
            'target.commit' => ['required', 'string', 'regex:/\A[0-9a-f]{40}\z/D'],
            'target.release' => ['required', 'string', 'regex:/\A[A-Za-z0-9][A-Za-z0-9._-]{0,127}\z/D'],
            'target_smoke_request_key' => ['required', 'string', 'regex:/\A[A-Za-z0-9._:-]{1,128}\z/D'],
        ];
    }

    public function payload(): InstanceSmokeRollbackData
    {
        return InstanceSmokeRollbackData::from($this->validated());
    }
}

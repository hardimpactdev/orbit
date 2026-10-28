<?php

declare(strict_types=1);

namespace App\Http\Requests\Instances;

use App\Data\Instances\InstanceSmokeControlData;

final class ControlInstanceSmokeRequest extends InstanceSmokeProofRequest
{
    protected const string Error = 'instance.smoke_control_invalid';

    public function rules(): array
    {
        return [
            'action' => ['required', 'in:hold,alert,release,redeploy'],
            'hold_key' => ['required', 'string', 'regex:/\A[A-Za-z0-9._:-]{1,100}\z/D'],
            'expected' => ['required', 'array:deployment_id,commit,release'],
            'expected.deployment_id' => ['required', 'integer', 'min:1'],
            'expected.commit' => ['required', 'string', 'regex:/\A[0-9a-f]{40}\z/D'],
            'expected.release' => ['required', 'string', 'regex:/\A[A-Za-z0-9][A-Za-z0-9._-]{0,127}\z/D'],
            'smoke_request_key' => ['present', 'nullable', 'string', 'regex:/\A[A-Za-z0-9._:-]{1,128}\z/D'],
            'rollback_request_key' => ['present', 'nullable', 'string', 'regex:/\A[A-Za-z0-9._:-]{1,128}\z/D'],
        ];
    }

    public function payload(): InstanceSmokeControlData
    {
        return InstanceSmokeControlData::from($this->validated());
    }
}

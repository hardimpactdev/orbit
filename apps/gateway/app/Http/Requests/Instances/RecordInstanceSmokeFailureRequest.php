<?php

declare(strict_types=1);

namespace App\Http\Requests\Instances;

use App\Data\Instances\InstanceSmokeFailureData;

final class RecordInstanceSmokeFailureRequest extends InstanceSmokeProofRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'deployment_id' => ['required', 'integer', 'min:1'],
            'commit' => ['required', 'string', 'regex:/\A[0-9a-f]{40}\z/D'],
            'release' => ['required', 'string', 'regex:/\A[A-Za-z0-9][A-Za-z0-9._-]{0,127}\z/D'],
            'smoke_request_key' => ['required', 'string', 'regex:/\A[A-Za-z0-9._:-]{1,128}\z/D'],
        ];
    }

    public function payload(): InstanceSmokeFailureData
    {
        return InstanceSmokeFailureData::from($this->validated());
    }
}

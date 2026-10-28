<?php

declare(strict_types=1);

namespace App\Http\Requests\Instances;

final class CreateInstanceSmokeRepairRequest extends InstanceSmokeProofRequest
{
    protected const string Error = 'instance.smoke_repair_invalid';

    public function rules(): array
    {
        return ['failure_key' => ['required', 'string', 'regex:/\Asmoke-failure\.[1-9][0-9]*\.[1-9][0-9]*\z/D']];
    }

    public function failureKey(): string
    {
        return $this->string('failure_key')->toString();
    }
}

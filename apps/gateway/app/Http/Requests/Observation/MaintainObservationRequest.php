<?php

declare(strict_types=1);

namespace App\Http\Requests\Observation;

final class MaintainObservationRequest extends ObservationRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['run_id' => ['required', 'uuid']];
    }
}

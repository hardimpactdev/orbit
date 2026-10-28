<?php

declare(strict_types=1);

namespace App\Http\Requests\Fleet;

use App\Http\Requests\Operations\ExecutorRequest;

final class AdmitRolloutNodeRequest extends ExecutorRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['rollout_id' => ['required', 'integer', 'min:1']];
    }
}

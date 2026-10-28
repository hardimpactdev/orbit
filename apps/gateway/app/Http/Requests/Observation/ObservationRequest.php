<?php

declare(strict_types=1);

namespace App\Http\Requests\Observation;

use App\Domain\Shared\ResourceOperationException;
use App\Http\Requests\Operations\ExecutorRequest;

/** Reuse the kit's Services identity check and Gateway access boundary. */
class ObservationRequest extends ExecutorRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }

    protected function passedValidation(): void
    {
        if (array_diff(array_keys($this->all()), array_keys($this->rules())) !== []) {
            throw new ResourceOperationException('observation.input_invalid', 'Observation accepts no Node, probe or command input.', 422);
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Requests\Observation;

use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use Illuminate\Foundation\Http\FormRequest;

/** Fleet read/state access belongs only to the configured control-plane peer. */
class ObservationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $id = config('dependency_detection.services_node_id');
        $caller = $this->getUserResolver()();
        if ((! is_int($id) && ! is_string($id)) || preg_match('/\A[1-9][0-9]*\z/D', (string) $id) !== 1
            || ! $caller instanceof Node || (string) $caller->id !== (string) $id) {
            throw new ResourceOperationException('operation.executor_forbidden', 'The configured observation control-plane peer is required.', 403);
        }

        return true;
    }

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

<?php

declare(strict_types=1);

namespace App\Http\Requests\Instances;

use App\Data\Instances\AutomaticDeployEventData;
use App\Domain\Instances\Deployment\AutomaticDeployResult;
use App\Http\Requests\TopLevelJsonObjectInspector;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use UnexpectedValueException;

final class StoreAutomaticDeployEventRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'event' => ['required', 'string', 'in:activity,failed,paused,stalled'],
            'deployment_id' => ['required_if:event,failed,paused', 'nullable', 'integer', 'min:1'],
            'commit' => ['required_if:event,stalled', 'nullable', 'string', 'regex:/\A[0-9a-f]{40}(?:[0-9a-f]{24})?\z/D'],
            'since' => ['required_if:event,stalled', 'nullable', 'date'],
            'result' => ['required_if:event,activity,stalled', 'nullable', 'string', Rule::enum(AutomaticDeployResult::class)],
            'operation_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'error_code' => ['sometimes', 'nullable', 'string', 'max:255'],
            'message' => ['sometimes', 'nullable', 'string', 'max:4096'],
        ];
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        try {
            return app(TopLevelJsonObjectInspector::class)->inspect($this->getContent(), ['event', 'deployment_id', 'commit', 'since', 'result', 'operation_id', 'error_code', 'message']);
        } catch (UnexpectedValueException) {
            throw ValidationException::withMessages(['body' => ['The request body must be a JSON object with supported keys.']]);
        }
    }

    public function payload(): AutomaticDeployEventData
    {
        return AutomaticDeployEventData::from($this->validated());
    }
}

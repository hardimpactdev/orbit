<?php

declare(strict_types=1);

namespace App\Http\Requests\Instances;

use App\Data\Instances\AutomaticDeployPublicationData;
use App\Domain\Instances\Deployment\AutomaticDeployResult;
use App\Http\Requests\TopLevelJsonObjectInspector;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use UnexpectedValueException;

final class PublishAutomaticDeployPolicyRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'resumed_after' => ['required', 'integer', 'min:0'],
            'last_deployment_id' => ['required', 'integer', 'min:0'],
            'result' => ['required', 'string', Rule::enum(AutomaticDeployResult::class)],
            'commit' => ['present', 'nullable', 'string', 'regex:/\A[0-9a-f]{40}(?:[0-9a-f]{24})?\z/D'],
            'head' => ['present', 'nullable', 'string', 'regex:/\A[0-9a-f]{40}(?:[0-9a-f]{24})?\z/D'],
            'since' => ['required', 'date'],
            'error_code' => ['present', 'nullable', 'string', 'max:255'],
            'message' => ['present', 'nullable', 'string', 'max:4096'],
            'pause' => ['present', 'nullable', 'array:reason,deployment_id,commit,paused_at', 'required_array_keys:reason,deployment_id,commit,paused_at'],
            'pause.reason' => ['required_with:pause', 'string', 'in:manual_deploy,rollback'],
            'pause.deployment_id' => ['required_with:pause', 'integer', 'min:1'],
            'pause.commit' => ['nullable', 'string', 'regex:/\A[0-9a-f]{40}(?:[0-9a-f]{24})?\z/D'],
            'pause.paused_at' => ['required_with:pause', 'date'],
        ];
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        try {
            return app(TopLevelJsonObjectInspector::class)->inspect($this->getContent(), ['resumed_after', 'last_deployment_id', 'result', 'commit', 'head', 'since', 'error_code', 'message', 'pause']);
        } catch (UnexpectedValueException) {
            throw ValidationException::withMessages(['body' => ['The request body must be a JSON object with supported keys.']]);
        }
    }

    public function payload(): AutomaticDeployPublicationData
    {
        return AutomaticDeployPublicationData::from($this->validated());
    }
}

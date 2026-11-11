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
            'development_retries' => ['sometimes', 'array:manual_after,observed_through,unsettled,commits', 'required_array_keys:manual_after,observed_through,unsettled,commits'],
            'development_retries.manual_after' => ['required_with:development_retries', 'integer', 'min:0'],
            'development_retries.observed_through' => ['required_with:development_retries', 'integer', 'min:0'],
            'development_retries.unsettled' => ['present_with:development_retries', 'array', 'max:50'],
            'development_retries.unsettled.*' => ['integer', 'min:1', 'distinct'],
            'development_retries.commits' => ['present_with:development_retries', 'array', 'max:50'],
            'development_retries.commits.*' => ['array:commit,attempts,last_failure_at,stopped,evidence,reset_operation_id,reset_after,reset_at', 'required_array_keys:commit,attempts,last_failure_at,stopped,evidence,reset_after,reset_at'],
            'development_retries.commits.*.commit' => ['required', 'string', 'distinct', 'regex:/\A[0-9a-f]{40}(?:[0-9a-f]{24})?\z/D'],
            'development_retries.commits.*.attempts' => ['required', 'integer', 'min:0', 'max:3'],
            'development_retries.commits.*.last_failure_at' => ['nullable', 'date'],
            'development_retries.commits.*.stopped' => ['required', 'boolean'],
            'development_retries.commits.*.reset_operation_id' => ['sometimes', 'integer', 'min:0'],
            'development_retries.commits.*.reset_after' => ['required', 'integer', 'min:0'],
            'development_retries.commits.*.reset_at' => ['nullable', 'date'],
            'development_retries.commits.*.evidence' => ['present', 'array', 'max:3'],
            'development_retries.commits.*.evidence.*' => ['array:key,deployment_id', 'required_array_keys:key,deployment_id'],
            'development_retries.commits.*.evidence.*.key' => ['nullable', 'string', 'max:255'],
            'development_retries.commits.*.evidence.*.deployment_id' => ['nullable', 'integer', 'min:1'],
            'route_operation_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'resumed_after' => ['required', 'integer', 'min:0'],
            'last_deployment_id' => ['required', 'integer', 'min:0'],
            'history_version' => ['required', 'string', 'regex:/\A[0-9a-f]{64}\z/D'],
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
            return app(TopLevelJsonObjectInspector::class)->inspect($this->getContent(), ['development_retries', 'route_operation_id', 'resumed_after', 'last_deployment_id', 'history_version', 'result', 'commit', 'head', 'since', 'error_code', 'message', 'pause']);
        } catch (UnexpectedValueException) {
            throw ValidationException::withMessages(['body' => ['The request body must be a JSON object with supported keys.']]);
        }
    }

    public function payload(): AutomaticDeployPublicationData
    {
        return AutomaticDeployPublicationData::from($this->validated());
    }
}

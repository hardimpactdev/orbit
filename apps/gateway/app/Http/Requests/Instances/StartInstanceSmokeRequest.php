<?php

declare(strict_types=1);

namespace App\Http\Requests\Instances;

use App\Data\Instances\InstanceSmokeRequestData;
use App\Domain\Shared\ResourceOperationException;
use App\Http\Requests\TopLevelJsonObjectInspector;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use UnexpectedValueException;

final class StartInstanceSmokeRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'deployment_id' => ['required', 'integer', 'min:1'],
            'commit' => ['required', 'string', 'regex:/\A[0-9a-f]{40}\z/D'],
            'release' => ['required', 'string', 'regex:/\A[A-Za-z0-9][A-Za-z0-9._-]{0,127}\z/D'],
            'base_url' => ['required', 'string', 'max:255'],
            'workflow' => ['required', 'string', 'regex:~\A\.github/workflows/[A-Za-z0-9_-][A-Za-z0-9_.-]{0,127}\.ya?ml\z~D'],
            'ref' => ['required', 'string', 'max:255', 'not_regex:/[\x00-\x20\x7f]/'],
        ];
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        try {
            return app(TopLevelJsonObjectInspector::class)->inspect($this->getContent(), array_keys($this->rules()));
        } catch (UnexpectedValueException) {
            throw new ResourceOperationException('instance.smoke_invalid', 'Smoke requires exactly the documented JSON object.');
        }
    }

    protected function failedValidation(Validator $validator): never
    {
        throw new ResourceOperationException('instance.smoke_invalid', 'Smoke requires a selected deployment, HTTPS Route origin, workflow path and ref.');
    }

    public function payload(): InstanceSmokeRequestData
    {
        return InstanceSmokeRequestData::from($this->validated());
    }
}

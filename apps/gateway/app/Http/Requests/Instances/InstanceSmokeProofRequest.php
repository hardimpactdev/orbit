<?php

declare(strict_types=1);

namespace App\Http\Requests\Instances;

use App\Domain\Shared\ResourceOperationException;
use App\Support\StrictJson;
use App\Support\ValidatedData;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use InvalidArgumentException;

abstract class InstanceSmokeProofRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    abstract public function rules(): array;

    protected const string Error = 'instance.smoke_failure_invalid';

    public function key(): string
    {
        $keys = $this->headers->all('Idempotency-Key');
        $key = $keys[0] ?? '';
        if (count($keys) !== 1 || preg_match('/\A[A-Za-z0-9._:-]{1,128}\z/D', $key) !== 1) {
            throw new ResourceOperationException(static::Error, 'A valid Idempotency-Key is required.');
        }

        return $key;
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        try {
            $keys = array_values(array_filter(array_keys($this->rules()), static fn (string $key): bool => ! str_contains($key, '.')));
            StrictJson::fields(StrictJson::decode($this->getContent(), 4096), $keys);

            return ValidatedData::object(json_decode($this->getContent(), true, flags: JSON_THROW_ON_ERROR));
        } catch (InvalidArgumentException) {
            throw new ResourceOperationException(static::Error, 'Recovery requires exactly the documented JSON object.');
        }
    }

    protected function failedValidation(Validator $validator): never
    {
        throw new ResourceOperationException(static::Error, 'Recovery requires complete deployment and smoke identities.');
    }
}

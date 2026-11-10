<?php

declare(strict_types=1);

namespace App\Http\Requests\Instances;

use App\Domain\Instances\InstanceSecretBindings;
use App\Http\Requests\TopLevelJsonObjectInspector;
use App\Support\ValidatedData;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use UnexpectedValueException;

/** The body of `instance:secret:bind`: the hosts, the optional headers and the optional placeholder prefix. No value. */
final class BindInstanceSecretRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'hosts' => ['required', 'array', 'list', 'min:1', 'max:'.InstanceSecretBindings::MaximumHosts],
            'hosts.*' => ['required', 'string', 'max:253'],
            'headers' => ['sometimes', 'array', 'list', 'max:'.InstanceSecretBindings::MaximumHeaders],
            'headers.*' => ['required', 'string', 'max:64'],
            'prefix' => ['sometimes', 'nullable', 'string', 'max:16'],
        ];
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        try {
            return app(TopLevelJsonObjectInspector::class)->inspect($this->getContent(), ['hosts', 'headers', 'prefix']);
        } catch (UnexpectedValueException $exception) {
            throw ValidationException::withMessages(['body' => [$exception->getMessage()]]);
        }
    }

    /** @return list<string> */
    public function hosts(): array
    {
        return ValidatedData::stringList($this->validated('hosts'));
    }

    /** @return list<string> */
    public function headers(): array
    {
        return ValidatedData::stringList($this->validated('headers', []));
    }

    public function prefix(): ?string
    {
        return ValidatedData::nullableString($this->validated('prefix'));
    }
}

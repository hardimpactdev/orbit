<?php

declare(strict_types=1);

namespace App\Http\Requests\Instances;

use App\Http\Requests\TopLevelJsonObjectInspector;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use UnexpectedValueException;

final class AttachInstanceNodeServiceTargetRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'process_id' => ['required', 'integer', 'min:1'],
            'protocol' => ['sometimes', 'string'],
            'port' => ['sometimes', 'integer', 'min:1', 'max:65535'],
            'host_env_key' => ['required', 'string', 'regex:/\A[A-Za-z_][A-Za-z0-9_]{0,254}\z/D'],
            'port_env_key' => ['required', 'string', 'different:host_env_key', 'regex:/\A[A-Za-z_][A-Za-z0-9_]{0,254}\z/D'],
        ];
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        try {
            return app(TopLevelJsonObjectInspector::class)->inspect($this->getContent(), ['process_id', 'protocol', 'port', 'host_env_key', 'port_env_key']);
        } catch (UnexpectedValueException $exception) {
            throw ValidationException::withMessages(['body' => [$exception->getMessage()]]);
        }
    }
}

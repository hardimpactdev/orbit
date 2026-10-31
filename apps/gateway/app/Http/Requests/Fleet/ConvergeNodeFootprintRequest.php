<?php

declare(strict_types=1);

namespace App\Http\Requests\Fleet;

use App\Support\ValidatedData;
use Illuminate\Foundation\Http\FormRequest;

final class ConvergeNodeFootprintRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'force' => ['sometimes', 'boolean'],
            'worker_credentials' => ['sometimes', 'array:enrollment_token,server_token'],
            'worker_credentials.enrollment_token' => ['required_with:worker_credentials', 'string', 'max:4096'],
            'worker_credentials.server_token' => ['required_with:worker_credentials', 'string', 'max:4096'],
        ];
    }

    /** @return array{enrollment_token:string,server_token:string}|null */
    public function workerCredentials(): ?array
    {
        $value = $this->validated('worker_credentials');

        return is_array($value) ? [
            'enrollment_token' => ValidatedData::string($value['enrollment_token'] ?? null),
            'server_token' => ValidatedData::string($value['server_token'] ?? null),
        ] : null;
    }

    /** Whether to re-apply every artifact, not only the ones whose digest changed. */
    public function force(): bool
    {
        return $this->boolean('force');
    }
}

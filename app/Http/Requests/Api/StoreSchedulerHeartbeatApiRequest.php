<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Contracts\Validation\Validator as ValidationContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class StoreSchedulerHeartbeatApiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'heartbeat_at' => ['required', 'date'],
            'registry_synced_at' => ['nullable', 'date'],
        ];
    }

    /**
     * @return array{heartbeat_at: string, registry_synced_at: string|null}
     */
    public function payload(): array
    {
        /** @var array{heartbeat_at: string, registry_synced_at?: string|null} $validated */
        $validated = $this->validated();

        return [
            'heartbeat_at' => $validated['heartbeat_at'],
            'registry_synced_at' => $validated['registry_synced_at'] ?? null,
        ];
    }

    #[\Override]
    protected function failedValidation(ValidationContract $validator): void
    {
        $field = array_key_first($validator->errors()->messages()) ?? 'payload';

        throw new HttpResponseException(response()->json([
            'error' => [
                'code' => 'validation_failed',
                'message' => 'Scheduler heartbeat payload is invalid.',
                'meta' => ['field' => $field],
            ],
        ], 422));
    }
}

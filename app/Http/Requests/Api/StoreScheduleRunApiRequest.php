<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Contracts\Validation\Validator as ValidationContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class StoreScheduleRunApiRequest extends FormRequest
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
            'schedule_key' => ['required', 'string', 'max:255'],
            'status' => ['required', 'string', 'in:completed,failed,skipped,missed'],
            'exit_code' => ['nullable', 'integer'],
            'stdout' => ['nullable', 'string'],
            'stderr' => ['nullable', 'string'],
            'started_at' => ['required', 'date'],
            'finished_at' => ['nullable', 'date'],
        ];
    }

    /**
     * @return array{
     *     schedule_key: string,
     *     status: string,
     *     exit_code: int|null,
     *     stdout: string|null,
     *     stderr: string|null,
     *     started_at: string,
     *     finished_at: string|null
     * }
     */
    public function payload(): array
    {
        /** @var array{
         *     schedule_key: string,
         *     status: string,
         *     exit_code?: int|null,
         *     stdout?: string|null,
         *     stderr?: string|null,
         *     started_at: string,
         *     finished_at?: string|null
         * } $validated
         */
        $validated = $this->validated();

        return [
            'schedule_key' => $validated['schedule_key'],
            'status' => $validated['status'],
            'exit_code' => $validated['exit_code'] ?? null,
            'stdout' => $validated['stdout'] ?? null,
            'stderr' => $validated['stderr'] ?? null,
            'started_at' => $validated['started_at'],
            'finished_at' => $validated['finished_at'] ?? null,
        ];
    }

    #[\Override]
    protected function failedValidation(ValidationContract $validator): void
    {
        $field = array_key_first($validator->errors()->messages()) ?? 'payload';

        throw new HttpResponseException(response()->json([
            'error' => [
                'code' => 'validation_failed',
                'message' => 'Schedule run history payload is invalid.',
                'meta' => ['field' => $field],
            ],
        ], 422));
    }
}

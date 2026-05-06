<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Contracts\Validation\Validator as ValidationContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class SendAgentIdeMessageApiRequest extends FormRequest
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
            'message' => ['required', 'string', 'filled'],
            'app' => ['required_without:workspace', 'string', 'filled'],
            'workspace' => ['prohibited'],
        ];
    }

    public function messageBody(): string
    {
        /** @var string $message */
        $message = $this->validated('message');

        return trim($message);
    }

    public function appSelector(): string
    {
        /** @var string $app */
        $app = $this->validated('app');

        return trim($app);
    }

    #[\Override]
    protected function failedValidation(ValidationContract $validator): void
    {
        $field = $validator->errors()->has('message') ? 'message' : 'target';
        $message = $field === 'message'
            ? 'Message is required.'
            : 'App target is required.';

        throw new HttpResponseException(response()->json([
            'error' => [
                'code' => 'validation_failed',
                'message' => $message,
                'meta' => ['field' => $field],
            ],
        ], 422));
    }
}

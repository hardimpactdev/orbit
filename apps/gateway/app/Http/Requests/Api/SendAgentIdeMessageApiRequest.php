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
            'instance' => ['required_without_all:workspace,path', 'string', 'filled', 'prohibits:workspace,path'],
            'workspace' => ['required_without_all:instance,path', 'string', 'filled', 'prohibits:path'],
            'path' => ['required_without_all:instance,workspace', 'string', 'filled'],
        ];
    }

    public function messageBody(): string
    {
        /** @var string $message */
        $message = $this->validated('message');

        return trim($message);
    }

    public function instanceSelector(): string
    {
        /** @var string $instance */
        $instance = $this->validated('instance');

        return trim($instance);
    }

    public function workspaceSelector(): ?string
    {
        $workspace = $this->validated('workspace');

        return is_string($workspace) ? trim($workspace) : null;
    }

    public function pathSelector(): ?string
    {
        $path = $this->validated('path');

        return is_string($path) ? trim($path) : null;
    }

    #[\Override]
    protected function failedValidation(ValidationContract $validator): void
    {
        $field = $validator->errors()->has('message') ? 'message' : 'target';
        $message = $field === 'message'
            ? 'Message is required.'
            : 'Instance or workspace target is required.';

        throw new HttpResponseException(response()->json([
            'error' => [
                'code' => 'validation_failed',
                'message' => $message,
                'meta' => ['field' => $field],
            ],
        ], 422));
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Requests\Analytics;

use App\Http\Requests\TopLevelJsonObjectInspector;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use UnexpectedValueException;

final class SetAnalyticsCredentialsRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'api_key' => ['required', 'string', 'min:8', 'max:512'],
            'management_email' => ['sometimes', 'required', 'string', 'email:rfc', 'max:254'],
            'management_password' => ['sometimes', 'required', 'string', 'max:4096', 'not_regex:/[\x00-\x1F\x7F]/'],
        ];
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        try {
            return app(TopLevelJsonObjectInspector::class)->inspect($this->getContent(), ['api_key', 'management_email', 'management_password']);
        } catch (UnexpectedValueException $exception) {
            throw ValidationException::withMessages(['body' => [$exception->getMessage()]]);
        }
    }

    public function apiKey(): string
    {
        $validated = $this->validated();

        return is_string($validated['api_key'] ?? null) ? $validated['api_key'] : '';
    }

    public function managementEmail(): ?string
    {
        $email = $this->validated('management_email');

        return is_string($email) ? $email : null;
    }

    public function managementPassword(): ?string
    {
        $password = $this->validated('management_password');

        return is_string($password) ? $password : null;
    }
}

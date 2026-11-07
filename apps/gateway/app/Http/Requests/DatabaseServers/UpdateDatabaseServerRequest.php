<?php

declare(strict_types=1);

namespace App\Http\Requests\DatabaseServers;

use App\Http\Requests\TopLevelJsonObjectInspector;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use UnexpectedValueException;

final class UpdateDatabaseServerRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return ['require_password' => ['required', $this->strictTrue(...)]];
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        try {
            return app(TopLevelJsonObjectInspector::class)->inspect($this->getContent(), ['require_password']);
        } catch (UnexpectedValueException $exception) {
            throw ValidationException::withMessages(['body' => [$exception->getMessage()]]);
        }
    }

    private function strictTrue(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value !== true) {
            $fail("The {$attribute} field must be true.");
        }
    }
}

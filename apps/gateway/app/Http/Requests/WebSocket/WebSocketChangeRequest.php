<?php

declare(strict_types=1);

namespace App\Http\Requests\WebSocket;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class WebSocketChangeRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [];
    }

    public function operationKey(): string
    {
        $key = $this->header('Idempotency-Key');
        if ($key === null) {
            return (string) Str::uuid();
        }
        if (! preg_match('/\A[A-Za-z0-9._:-]{1,128}\z/D', $key)) {
            throw ValidationException::withMessages(['Idempotency-Key' => ['Use 1 to 128 letters, digits, periods, underscores, colons, or hyphens.']]);
        }

        return $key;
    }
}

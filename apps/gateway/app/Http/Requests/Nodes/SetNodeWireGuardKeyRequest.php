<?php

declare(strict_types=1);

namespace App\Http\Requests\Nodes;

use App\Http\Requests\TopLevelJsonObjectInspector;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use UnexpectedValueException;

final class SetNodeWireGuardKeyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'wireguard_public_key' => ['required', 'string', function (string $attribute, mixed $value, Closure $fail): void {
                $decoded = is_string($value) ? base64_decode($value, strict: true) : false;

                if (! is_string($decoded) || strlen($decoded) !== 32 || base64_encode($decoded) !== $value) {
                    $fail('The WireGuard public key must be canonical base64 for exactly 32 bytes.');
                }
            }],
        ];
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        try {
            return app(TopLevelJsonObjectInspector::class)->inspect($this->getContent(), ['wireguard_public_key']);
        } catch (UnexpectedValueException $exception) {
            throw ValidationException::withMessages(['body' => [$exception->getMessage()]]);
        }
    }

    public function publicKey(): string
    {
        $validated = $this->validated();

        return is_string($validated['wireguard_public_key'] ?? null) ? $validated['wireguard_public_key'] : '';
    }
}

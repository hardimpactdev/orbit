<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use InvalidArgumentException;
use Orbit\Extensions\Conn\Domain\Push\ConnPushCredentials;

final class SetConnPushCredentialsRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'key_id' => ['required', 'string', 'regex:/\A[A-Za-z0-9]{1,32}\z/'],
            'team_id' => ['required', 'string', 'regex:/\A[A-Za-z0-9]{1,32}\z/'],
            'private_key' => [
                'required',
                'string',
                'max:'.ConnPushCredentials::MaxPrivateKeyBytes,
                static function (string $attribute, mixed $value, Closure $fail): void {
                    if (! is_string($value)) {
                        $fail('The private key must be a valid EC private key PEM.');

                        return;
                    }

                    try {
                        ConnPushCredentials::assertValidPrivateKey($value);
                    } catch (InvalidArgumentException $exception) {
                        $fail($exception->getMessage());
                    }
                },
            ],
        ];
    }

    public function keyId(): string
    {
        $value = $this->validated('key_id');

        return is_string($value) ? $value : '';
    }

    public function teamId(): string
    {
        $value = $this->validated('team_id');

        return is_string($value) ? $value : '';
    }

    public function privateKey(): string
    {
        $value = $this->validated('private_key');

        return is_string($value) ? $value : '';
    }
}

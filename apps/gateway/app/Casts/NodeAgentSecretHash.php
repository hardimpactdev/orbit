<?php

declare(strict_types=1);

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/** Reads rollback-era SHA-256 values; every new write remains encrypted.
 * @implements CastsAttributes<string|null, string|null>
 */
final readonly class NodeAgentSecretHash implements CastsAttributes
{
    /** @param array<string,mixed> $attributes */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_string($value) && preg_match('/\A[0-9a-f]{64}\z/D', $value) === 1) {
            return $value;
        }
        if (! is_string($value)) {
            throw new DecryptException('The Node agent hash is invalid.');
        }

        return Crypt::decryptString($value);
    }

    /** @param array<string,mixed> $attributes */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null ? null : Crypt::encryptString($value);
    }
}

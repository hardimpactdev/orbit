<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use JsonException;
use SensitiveParameter;

/** Encrypted startup material, kept outside durable operation and job records. */
final readonly class TaskWorkerEnrollment
{
    public function stage(#[SensitiveParameter] string $token, #[SensitiveParameter] ?string $serverToken = null): string
    {
        $reference = bin2hex(random_bytes(32));
        Cache::put('tasks:worker-enrollment:'.$reference, Crypt::encryptString(json_encode(['enrollment_token' => $token, 'server_token' => $serverToken], JSON_THROW_ON_ERROR)), now()->addMinutes(30));

        return $reference;
    }

    /** @return array{enrollment_token: string, server_token: ?string}|null */
    public function read(string $reference): ?array
    {
        $ciphertext = Cache::get('tasks:worker-enrollment:'.$reference);

        if (! is_string($ciphertext)) {
            return null;
        }
        try {
            $credentials = json_decode(Crypt::decryptString($ciphertext), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            // Older staged values contain plaintext tokens, not a credential pair.
            return null;
        }
        if (! is_array($credentials) || ! is_string($credentials['enrollment_token'] ?? null)) {
            return null;
        }
        $server = $credentials['server_token'] ?? null;
        if ($server !== null && ! is_string($server)) {
            return null;
        }

        return ['enrollment_token' => $credentials['enrollment_token'], 'server_token' => $server];
    }

    public function forget(string $reference): void
    {
        Cache::forget('tasks:worker-enrollment:'.$reference);
    }
}

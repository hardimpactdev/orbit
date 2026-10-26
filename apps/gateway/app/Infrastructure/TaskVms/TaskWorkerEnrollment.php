<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use SensitiveParameter;

/** Encrypted startup material, kept outside durable operation and job records. */
final readonly class TaskWorkerEnrollment
{
    public function stage(#[SensitiveParameter] string $token): string
    {
        $reference = bin2hex(random_bytes(32));
        Cache::put('tasks:worker-enrollment:'.$reference, Crypt::encryptString($token), now()->addMinutes(30));

        return $reference;
    }

    public function read(string $reference): ?string
    {
        $ciphertext = Cache::get('tasks:worker-enrollment:'.$reference);

        return is_string($ciphertext) ? Crypt::decryptString($ciphertext) : null;
    }

    public function forget(string $reference): void
    {
        Cache::forget('tasks:worker-enrollment:'.$reference);
    }
}

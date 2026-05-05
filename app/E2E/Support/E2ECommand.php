<?php

declare(strict_types=1);

namespace App\E2E\Support;

use Illuminate\Contracts\Process\ProcessResult;
use RuntimeException;

final readonly class E2ECommand
{
    public static function exec(E2EInstance $instance, string $command, string $message, ?int $timeoutSeconds = null): ProcessResult
    {
        $result = $instance->exec($command, $timeoutSeconds);

        if (! $result->successful()) {
            throw new RuntimeException(trim($message."\n".$result->output().$result->errorOutput()));
        }

        return $result;
    }

    public static function orbit(E2EInstance $instance, string $command, string $message, ?int $timeoutSeconds = null): ProcessResult
    {
        return self::exec(
            $instance,
            'sudo -iu orbit bash -lc '.escapeshellarg($command),
            $message,
            $timeoutSeconds,
        );
    }

    public static function ssh(E2EInstance $instance, string $user, SshKeyPair $key, string $command, ?int $timeoutSeconds = null): ProcessResult
    {
        $result = $instance->ssh($user, $key, $command, $timeoutSeconds);

        if (! $result->successful()) {
            throw new RuntimeException(trim("SSH command failed: {$command}\n".$result->output().$result->errorOutput()));
        }

        return $result;
    }
}

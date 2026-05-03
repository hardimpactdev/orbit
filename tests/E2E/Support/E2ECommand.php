<?php

declare(strict_types=1);

namespace Tests\E2E\Support;

use Illuminate\Contracts\Process\ProcessResult;

final readonly class E2ECommand
{
    public static function exec(E2EInstance $instance, string $command, string $message, ?int $timeoutSeconds = null): ProcessResult
    {
        $result = $instance->exec($command, $timeoutSeconds);

        expect($result->successful())->toBeTrue($message."\n".$result->output().$result->errorOutput());

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

        @file_put_contents('/tmp/e2e-ssh-trace.log',
            sprintf("[%s] %s@%s : %s\n  rc=%s\n  out=%s\n  err=%s\n",
                date('H:i:s'), $user, $instance->name(), substr($command, 0, 120),
                $result->exitCode(), substr($result->output(), 0, 400), substr($result->errorOutput(), 0, 400)),
            FILE_APPEND);

        expect($result->successful())->toBeTrue($result->output().$result->errorOutput());

        return $result;
    }
}

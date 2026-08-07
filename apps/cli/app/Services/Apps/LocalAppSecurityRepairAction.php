<?php

declare(strict_types=1);

namespace App\Services\Apps;

use Symfony\Component\Process\Process;

final readonly class LocalAppSecurityRepairAction
{
    /**
     * @return array<string, mixed>
     */
    public function repair(mixed $user, mixed $home, mixed $path): array
    {
        $user = $this->user($user);
        $home = $this->absolutePath($home, 'home');
        $path = $this->absolutePath($path, 'path');
        $commands = [];

        if (! $this->run(['id', '-u', $user])->isSuccessful()) {
            $commands[] = $this->mustRun([
                'sudo',
                'useradd',
                '--system',
                '--create-home',
                '--home-dir',
                $home,
                '--shell',
                '/usr/sbin/nologin',
                $user,
            ]);
        }

        $commands[] = $this->mustRun(['sudo', 'install', '-d', '-m', '0750', '-o', $user, '-g', $user, $home]);

        if (is_dir($path)) {
            $commands[] = $this->mustRun(['sudo', 'chown', '-R', "{$user}:{$user}", $path]);
            $commands[] = $this->mustRun(['sudo', 'chmod', '-R', 'go-w', $path]);
        }

        return [
            'user' => $user,
            'home' => $home,
            'path' => $path,
            'commands' => $commands,
        ];
    }

    /**
     * @param  list<string>  $command
     */
    private function run(array $command): Process
    {
        $process = new Process($command);
        $process->setTimeout(60);
        $process->run();

        return $process;
    }

    /**
     * @param  list<string>  $command
     * @return array{command: list<string>, exit_code: int|null}
     */
    private function mustRun(array $command): array
    {
        $process = $this->run($command);

        if (! $process->isSuccessful()) {
            $error = trim($process->getErrorOutput());
            $error = $error !== '' ? $error : trim($process->getOutput());
            $error = $error !== '' ? $error : 'app security repair command failed';

            throw new LocalAppSecurityRepairFailure(
                errorCode: 'app_security_repair_failed',
                message: $error,
                meta: [
                    'command' => $command[1] ?? $command[0],
                    'exit_code' => $process->getExitCode(),
                ],
            );
        }

        return [
            'command' => $command,
            'exit_code' => $process->getExitCode(),
        ];
    }

    private function user(mixed $value): string
    {
        if (is_string($value) && preg_match('/\A[a-z_][a-z0-9_-]*[$]?\z/', $value) === 1) {
            return $value;
        }

        throw new LocalAppSecurityRepairFailure(
            errorCode: 'validation_failed',
            message: 'Runtime user is invalid.',
            meta: ['field' => 'user'],
        );
    }

    private function absolutePath(mixed $value, string $field): string
    {
        if (is_string($value) && $value !== '' && str_starts_with($value, '/') && ! str_contains($value, "\0")) {
            return rtrim(string: $value, characters: '/');
        }

        throw new LocalAppSecurityRepairFailure(
            errorCode: 'validation_failed',
            message: "{$field} must be an absolute path.",
            meta: ['field' => $field],
        );
    }
}

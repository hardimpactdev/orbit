<?php

declare(strict_types=1);

namespace App\Services\EnvFiles;

use Illuminate\Support\Facades\Process;

final readonly class RuntimeUserEnvFileWriter
{
    /**
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    public function write(string $path, string $contents, mixed $runtimeUser): array
    {
        $runtimeUser = $this->runtimeUser($runtimeUser, $path);
        $result = Process::input($contents)
            ->timeout(30)
            ->run([
                'sudo',
                '-n',
                '-u',
                $runtimeUser,
                'tee',
                '--',
                $path,
            ]);

        if (! $result->successful()) {
            throw new LocalEnvFileFailure(
                errorCode: 'env_file.write_failed',
                message: 'Env file could not be written as its runtime user.',
                meta: [
                    'path' => $path,
                    'runtime_user' => $runtimeUser,
                    'exit_code' => $result->exitCode(),
                ],
            );
        }

        return [
            'data' => [
                'path' => $path,
                'bytes' => strlen($contents),
            ],
            'meta' => [],
        ];
    }

    private function runtimeUser(mixed $value, string $path): string
    {
        if (! is_string($value) || preg_match('/\A[a-z_][a-z0-9_-]*[$]?\z/', $value) !== 1) {
            throw $this->invalidRuntimeUser();
        }

        $pathOwner = $this->pathOwner($path);

        if ($pathOwner === null || $pathOwner !== $value) {
            throw $this->invalidRuntimeUser();
        }

        return $value;
    }

    private function pathOwner(string $path): ?string
    {
        if (preg_match('#\A/home/([^/]+)/#', $path, $matches) === 1) {
            return $matches[1];
        }

        if (preg_match('#\A/Users/([^/]+)/#', $path, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    private function invalidRuntimeUser(): LocalEnvFileFailure
    {
        return new LocalEnvFileFailure(
            errorCode: 'validation_failed',
            message: 'Env file runtime user must own the managed app path.',
            meta: ['field' => 'runtime_user'],
        );
    }
}

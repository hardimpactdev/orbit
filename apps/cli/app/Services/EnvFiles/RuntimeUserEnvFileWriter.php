<?php

declare(strict_types=1);

namespace App\Services\EnvFiles;

use Illuminate\Support\Facades\Process;

final readonly class RuntimeUserEnvFileWriter
{
    /**
     * Build the POSIX sh script that stages, chmods, revalidates, and renames
     * an env file as the runtime user. Temp cleanup runs inside this shell via
     * trap so the gateway process never needs unlink rights on the temp file.
     */
    public static function publishScript(string $temporary, string $path, int $mode): string
    {
        // Reject symlinks and non-regular existing targets (e.g. directories)
        // before mv. POSIX `mv` into an existing directory exits 0 and moves
        // the temp inside it, which must never count as publishing .env.
        return sprintf(
            'set -eu; tmp=%1$s; target=%2$s; trap \'rm -f -- "$tmp"\' EXIT HUP INT TERM; cat > "$tmp"; chmod %3$o "$tmp"; if [ -L "$target" ]; then exit 1; fi; if [ -e "$target" ] && [ ! -f "$target" ]; then exit 1; fi; mv -f -- "$tmp" "$target"; trap - EXIT HUP INT TERM',
            escapeshellarg($temporary),
            escapeshellarg($path),
            $mode,
        );
    }

    /**
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    public function write(string $path, string $contents, mixed $runtimeUser): array
    {
        $runtimeUser = $this->runtimeUser($runtimeUser, $path);
        $directory = dirname($path);
        $mode = is_file($path) ? fileperms($path) & 0o777 : 0o600;
        $temporary = $directory.'/.env.tmp.'.bin2hex(random_bytes(8));
        $script = self::publishScript($temporary, $path, $mode);

        $result = Process::input($contents)
            ->timeout(30)
            ->run([
                'sudo',
                '-n',
                '-u',
                $runtimeUser,
                'sh',
                '-c',
                $script,
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
        $matches = [];

        if (preg_match('#\A/home/([^/]+)/#', $path, $matches) === 1) {
            return $matches[1];
        }

        $matches = [];

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

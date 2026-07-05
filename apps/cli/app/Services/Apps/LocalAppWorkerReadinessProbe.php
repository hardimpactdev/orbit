<?php

declare(strict_types=1);

namespace App\Services\Apps;

final readonly class LocalAppWorkerReadinessProbe
{
    public function __construct(
        private LocalAppWorkerReadinessInputValidator $validator,
    ) {}

    /**
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    public function probe(mixed $path, mixed $workerFile): array
    {
        $path = $this->validator->path($path);
        $workerFile = $this->validator->workerFile($workerFile);
        $tokens = $this->tokens($path, $workerFile);

        return [
            'data' => [
                'path' => $path,
                'worker_file' => $workerFile,
                'tokens' => $tokens,
                'stdout' => $tokens === [] ? '' : implode("\n", $tokens)."\n",
            ],
            'meta' => [],
        ];
    }

    /**
     * @return list<string>
     */
    private function tokens(string $path, string $workerFile): array
    {
        $tokens = [];

        if (is_dir("{$path}/vendor/laravel/octane")) {
            $tokens[] = $this->installedMarker();
        }

        if (is_file("{$path}/{$workerFile}")) {
            $tokens[] = $this->workerFileMarker();
        }

        if ($this->configuresFrankenPhp("{$path}/config/octane.php")) {
            $tokens[] = $this->configuredMarker();
        }

        return $tokens;
    }

    private function configuresFrankenPhp(string $path): bool
    {
        if (! is_file($path)) {
            return false;
        }

        $contents = file_get_contents($path);

        if (! is_string($contents)) {
            return false;
        }

        return array_any(
            $this->phpStringLiterals($contents),
            fn ($value) => trim(string: (string) $value, characters: '\'"') === 'frankenphp',
        );
    }

    /**
     * @return list<string>
     */
    private function phpStringLiterals(string $contents): array
    {
        /** @var list<array{0: int, 1: string, 2: int}|string> $tokens */
        $tokens = token_get_all($contents);
        $literals = [];

        foreach ($tokens as $token) {
            if (! is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }

            $literals[] = $token[1];
        }

        return $literals;
    }

    private function installedMarker(): string
    {
        return 'octane:installed';
    }

    private function workerFileMarker(): string
    {
        return 'frankenphp-worker-file:present';
    }

    private function configuredMarker(): string
    {
        return 'frankenphp:configured';
    }
}

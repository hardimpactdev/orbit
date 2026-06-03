<?php

declare(strict_types=1);

namespace App\E2E\Support;

final readonly class E2EGitHubAuth
{
    /**
     * @return list<string>
     */
    public static function dockerEnvOptions(): array
    {
        if (self::environment() === []) {
            return [];
        }

        return [
            '--env '.escapeshellarg('GH_TOKEN'),
            '--env '.escapeshellarg('GITHUB_TOKEN'),
        ];
    }

    public static function shellInputScript(string $command): ?string
    {
        $environment = self::environment();

        if ($environment === []) {
            return null;
        }

        $exports = array_map(
            fn (string $key, string $value): string => 'export '.$key.'='.escapeshellarg($value),
            array_keys($environment),
            array_values($environment),
        );

        return implode(PHP_EOL, [
            'set -euo pipefail',
            ...$exports,
            'bash -lc '.escapeshellarg($command),
            '',
        ]);
    }

    public static function composerAuthConfigCommand(string $composerHome): string
    {
        $authPath = rtrim($composerHome, '/').'/auth.json';

        return sprintf(
            'if [ -n "${GH_TOKEN:-${GITHUB_TOKEN:-}}" ]; then composer config --global github-oauth.github.com "${GH_TOKEN:-${GITHUB_TOKEN:-}}" >/dev/null && chmod 600 %s 2>/dev/null || true; fi',
            escapeshellarg($authPath),
        );
    }

    /**
     * @return array{GH_TOKEN: string, GITHUB_TOKEN: string}|array{}
     */
    private static function environment(): array
    {
        $token = self::token();

        if ($token === null) {
            return [];
        }

        return [
            'GH_TOKEN' => $token,
            'GITHUB_TOKEN' => $token,
        ];
    }

    private static function token(): ?string
    {
        foreach (['GH_TOKEN', 'GITHUB_TOKEN'] as $key) {
            $value = getenv($key);

            if (! is_string($value)) {
                continue;
            }

            $token = trim($value);

            if ($token !== '') {
                return $token;
            }
        }

        return null;
    }
}

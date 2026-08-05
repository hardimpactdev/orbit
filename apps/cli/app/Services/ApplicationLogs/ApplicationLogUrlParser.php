<?php

declare(strict_types=1);

namespace App\Services\ApplicationLogs;

final readonly class ApplicationLogUrlParser
{
    /**
     * @return array{ok: true, host: string}|array{ok: false, field: string, message: string}
     */
    public function parse(string $value): array
    {
        $value = trim($value);

        if ($value === '') {
            return $this->fail('A target URL or hostname is required.');
        }

        if (str_contains($value, '://')) {
            return $this->parseUrl($value);
        }

        return $this->parseHostname($value);
    }

    /**
     * @return array{ok: true, host: string}|array{ok: false, field: string, message: string}
     */
    private function parseUrl(string $value): array
    {
        $parts = parse_url($value);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return $this->fail('The target URL is invalid.');
        }

        $scheme = strtolower((string) $parts['scheme']);

        if (! in_array($scheme, ['http', 'https'], true)) {
            return $this->fail('The target URL scheme must be http or https.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return $this->fail('The target URL must not include credentials.');
        }

        if (isset($parts['query']) || isset($parts['fragment'])) {
            return $this->fail('The target URL must not include a query or fragment.');
        }

        $path = $parts['path'] ?? '/';

        if ($path !== '' && $path !== '/') {
            return $this->fail('The target URL must not include a path.');
        }

        if (isset($parts['port'])) {
            $port = (int) $parts['port'];
            $default = $scheme === 'https' ? 443 : 80;

            if ($port !== $default) {
                return $this->fail('The target URL must not include a non-default port.');
            }
        }

        return ['ok' => true, 'host' => mb_strtolower((string) $parts['host'])];
    }

    /**
     * @return array{ok: true, host: string}|array{ok: false, field: string, message: string}
     */
    private function parseHostname(string $value): array
    {
        if (
            str_contains($value, '/')
            || str_contains($value, '?')
            || str_contains($value, '#')
            || str_contains($value, '@')
            || str_contains($value, ' ')
            || str_contains($value, '\\')
            || preg_match('/:\d+$/', $value) === 1
        ) {
            return $this->fail('The target hostname is invalid.');
        }

        return ['ok' => true, 'host' => mb_strtolower($value)];
    }

    /**
     * @return array{ok: false, field: string, message: string}
     */
    private function fail(string $message): array
    {
        return [
            'ok' => false,
            'field' => 'target',
            'message' => $message,
        ];
    }
}

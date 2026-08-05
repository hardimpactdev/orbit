<?php

declare(strict_types=1);

namespace App\Services\ApplicationLogs;

final readonly class ApplicationLogUrlParser
{
    public function __construct(
        private ApplicationLogUrlSchemeValidator $schemeValidator = new ApplicationLogUrlSchemeValidator,
    ) {}

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

        if (! is_array($parts)) {
            return $this->fail('The target URL is invalid.');
        }

        $validated = $this->schemeValidator->validate($parts);

        if ($validated['ok'] === false) {
            return $this->fail($validated['message']);
        }

        return ['ok' => true, 'host' => $validated['host']];
    }

    /**
     * @return array{ok: true, host: string}|array{ok: false, field: string, message: string}
     */
    private function parseHostname(string $value): array
    {
        if ($this->hostnameIsInvalid($value)) {
            return $this->fail('The target hostname is invalid.');
        }

        return ['ok' => true, 'host' => mb_strtolower($value)];
    }

    private function hostnameIsInvalid(string $value): bool
    {
        return (
            str_contains($value, '/')
            || str_contains($value, '?')
            || str_contains($value, '#')
            || str_contains($value, '@')
            || str_contains($value, ' ')
            || str_contains($value, '\\')
            || preg_match('/:\d+$/', $value) === 1
        );
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

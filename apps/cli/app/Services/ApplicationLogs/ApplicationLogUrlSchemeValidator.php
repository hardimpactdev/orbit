<?php

declare(strict_types=1);

namespace App\Services\ApplicationLogs;

/**
 * Validates scheme/path/port constraints for application-log target URLs.
 */
final readonly class ApplicationLogUrlSchemeValidator
{
    /**
     * @param  array<string, mixed>  $parts  parse_url result
     * @return array{ok: true, host: string}|array{ok: false, message: string}
     */
    public function validate(array $parts): array
    {
        if (! isset($parts['scheme'], $parts['host'])) {
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
     * @return array{ok: false, message: string}
     */
    private function fail(string $message): array
    {
        return [
            'ok' => false,
            'message' => $message,
        ];
    }
}

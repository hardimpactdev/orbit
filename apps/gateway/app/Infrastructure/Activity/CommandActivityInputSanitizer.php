<?php

declare(strict_types=1);

namespace App\Infrastructure\Activity;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

final readonly class CommandActivityInputSanitizer
{
    private const string REDACTED = '[REDACTED]';

    private const string REDACTION_FAILURE = 'Output withheld: redaction failed.';

    private const string INVALID_ENVIRONMENT_NAME = '[INVALID_ENVIRONMENT_NAME]';

    private const string INVALID_PROPERTY_NAME = '[INVALID_PROPERTY_NAME]';

    /**
     * A field is secret when one of its name segments is one of these words.
     *
     * @var list<string>
     */
    private const array SECRET_WORDS = [
        'key',
        'keys',
        'token',
        'tokens',
        'secret',
        'secrets',
        'password',
        'passwords',
        'passwd',
        'passphrase',
        'credential',
        'credentials',
        'bearer',
        'pem',
        'apikey',
        'appkey',
        'authtoken',
        'accesstoken',
        'privatekey',
        'authorization',
        'cookie',
        'cookies',
    ];

    /**
     * Audited fields whose names contain a secret word but whose values are public.
     *
     * @var list<string>
     */
    private const array NON_SECRET_KEYS = [
        'public_key',
        'wireguard_public_key',
        'ssh_public_key',
        'public_pem',
        'host_key_fingerprint',
        'runtime_key',
        'env_key',
        'idempotency_key',
        'token_expires_at',
    ];

    /** Secret-named fields whose numeric values are usage counts, such as `tokens` or `token_count`. */
    private const array NUMERIC_METRIC_SUFFIXES = ['tokens', 'count'];

    private const string SECRET_KEY_PREFIX = '(?:[A-Za-z][A-Za-z0-9]*+[_-])';

    private const string SECRET_KEY_SUFFIX = '(?:[_-](?:HASH|BASE))?';

    private const string SECRET_TEXT_WORDS = '(?:TOKENS?|SECRETS?|PASSWORDS?|PASSWD|PASSPHRASE|CREDENTIALS?|BEARER|APIKEY|APPKEY|AUTHTOKEN|ACCESSTOKEN|PRIVATEKEY)';

    /** Leave the segment before a final HASH or BASE suffix for the secret-word match. */
    private const string NON_TERMINAL_SECRET_PREFIX = '(?![A-Za-z0-9]++[_-](?:HASH|BASE)(?![A-Za-z0-9_-]))'.self::SECRET_KEY_PREFIX;

    /** A bare `key` is prose, so it needs at least one prefix. Possessive repeats use no per-segment backtracking stack. */
    private const string SECRET_KEY_IDENTIFIER = '(?:(?:'.self::NON_TERMINAL_SECRET_PREFIX.')++(?:'.self::SECRET_TEXT_WORDS.'|KEYS?)'
        .'|'.self::SECRET_TEXT_WORDS.')'.self::SECRET_KEY_SUFFIX;

    /** A failed key must skip its prefixes, otherwise each word after a hyphen scans the same tail again. */
    private const string SKIP_KEY_PREFIX = '|\b'.self::SECRET_KEY_PREFIX.'++(*SKIP)(*F)';

    private const string PEM_BLOCK_PATTERN = '/-----BEGIN [A-Z0-9 ]++-----[\s\S]*?(?:-----END [A-Z0-9 ]++-----|\z)/';

    public function sanitize(mixed $value, ?string $key = null): mixed
    {
        return $this->sanitizeAt($value, $key, $key ?? '');
    }

    private function sanitizeAt(mixed $value, ?string $key, string $path): mixed
    {
        $publicChildKey = $path === 'child_phases.*.key'
            && is_string($value) && preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_.:-]*\z/D', $value) === 1;
        if (! $publicChildKey && is_string($key) && $this->isSensitiveKey($key) && ! $this->isNumericMetric($key, $value)) {
            return self::REDACTED;
        }

        if (! is_array($value)) {
            return is_string($value) ? $this->redactText($value) : $value;
        }

        if (is_string($key) && Str::snake($key) === 'environment') {
            return $this->sanitizeEnvironment($value);
        }

        return $this->propertiesAt($value, $path);
    }

    /**
     * @param  array<array-key, mixed>  $properties
     * @return array<array-key, mixed>
     */
    public function sanitizeProperties(array $properties): array
    {
        return $this->propertiesAt($properties, '');
    }

    /** @param array<array-key,mixed> $properties
     * @return array<array-key,mixed>
     */
    private function propertiesAt(array $properties, string $path): array
    {
        $sanitized = [];

        foreach ($properties as $nestedKey => $nestedValue) {
            if (is_string($nestedKey) && ! $this->isValidPropertyName($nestedKey)) {
                $sanitized[self::INVALID_PROPERTY_NAME] = self::REDACTED;

                continue;
            }

            $segment = is_string($nestedKey) ? $nestedKey : '*';
            $sanitized[$nestedKey] = $this->sanitizeAt(
                $nestedValue,
                is_string($nestedKey) ? $nestedKey : null,
                $path === '' ? $segment : $path.'.'.$segment,
            );
        }

        return $sanitized;
    }

    private function isValidPropertyName(string $name): bool
    {
        return preg_match('//u', $name) === 1 && preg_match('/[\p{C}\p{Zl}\p{Zp}]/u', $name) === 0;
    }

    public function redactText(string $value): string
    {
        // Allow 2 steps per byte and up to 2.5x growth from earlier replacements, with headroom.
        $limit = ini_set('pcre.backtrack_limit', (string) max(1_000_000, 10 * strlen($value)));

        try {
            $redacted =
                preg_replace(
                    pattern: self::PEM_BLOCK_PATTERN,
                    replacement: self::REDACTED,
                    subject: $value,
                ) ?? $this->redactionFailed();
            $redacted =
                preg_replace(
                    pattern: '/\b([a-z][a-z0-9+.-]*+:\/\/)[^\/@\s]++@|\b[a-z][a-z0-9+.-]*+(*SKIP)(*F)/i',
                    replacement: '$1'.self::REDACTED.'@',
                    subject: $redacted,
                ) ?? $this->redactionFailed();
            $redacted =
                preg_replace(
                    pattern: '/\b((?:Proxy-)?Authorization)\s*+:\s*+(?!\[REDACTED\])[^\s\'\"]++(?:\s++[^\s\'\"]++)?+/i',
                    replacement: '$1: '.self::REDACTED,
                    subject: $redacted,
                ) ?? $this->redactionFailed();
            $redacted =
                preg_replace(
                    pattern: '/(*NO_START_OPT)\bBearer\s++(?:"[^"]*+"|\'[^\']*+\'|[A-Za-z0-9][A-Za-z0-9._\-+\/=]{7,}+)/i',
                    replacement: 'Bearer '.self::REDACTED,
                    subject: $redacted,
                ) ?? $this->redactionFailed();
            $redacted = preg_replace(
                '/(?<![A-Za-z0-9])(?:gh[pousr]_[A-Za-z0-9_]{16,}|github_pat_[A-Za-z0-9_]{20,})(?![A-Za-z0-9])/',
                self::REDACTED,
                $redacted,
            ) ?? $this->redactionFailed();
            $keys = self::SECRET_KEY_IDENTIFIER;
            $redacted =
                preg_replace(
                    pattern: '/\b('.$keys.')\s*+=\s*+(?:"[^"]*+"|\'[^\']*+\'|[^\s&#]++)'.self::SKIP_KEY_PREFIX.'/i',
                    replacement: '$1='.self::REDACTED,
                    subject: $redacted,
                ) ?? $this->redactionFailed();
            $redacted =
                preg_replace(
                    pattern: '/("(?:'.$keys.')"\s*+:\s*+)(?:"(?:\\\\.|[^"\\\\])*+"|\'(?:\\\\.|[^\'\\\\])*+\'|[^,}\s]++)/i',
                    replacement: '$1"'.self::REDACTED.'"',
                    subject: $redacted,
                ) ?? $this->redactionFailed();

            return
                preg_replace(
                    pattern: '/\b('.$keys.')\s*+:\s*+(?:"[^"]*+"|\'[^\']*+\'|\S++)'.self::SKIP_KEY_PREFIX.'/i',
                    replacement: '$1: '.self::REDACTED,
                    subject: $redacted,
                ) ?? $this->redactionFailed();
        } finally {
            ini_set('pcre.backtrack_limit', $limit);
        }
    }

    private function redactionFailed(): string
    {
        $error = preg_last_error();
        Log::error(self::REDACTION_FAILURE, [
            'pcre_error' => $error,
            'exception' => new RuntimeException(self::REDACTION_FAILURE, $error),
        ]);

        return self::REDACTION_FAILURE;
    }

    /**
     * Booleans and null carry no secret. Numbers stay only under usage-count names.
     */
    private function isNumericMetric(string $key, mixed $value): bool
    {
        if (is_bool($value) || $value === null) {
            return true;
        }

        if (! is_int($value) && ! is_float($value)) {
            return false;
        }

        $segments = explode('_', self::normalizeKey($key));

        return in_array(end($segments), self::NUMERIC_METRIC_SUFFIXES, strict: true);
    }

    private static function normalizeKey(string $key): string
    {
        $separated = preg_replace('/(?<=[a-z0-9])(?=[A-Z])|(?<=[A-Z])(?=[A-Z][a-z])/', '_', $key) ?? $key;

        return trim(strtolower(preg_replace('/[^A-Za-z0-9]+/', '_', $separated) ?? $separated), '_');
    }

    private function isSensitiveKey(string $key): bool
    {
        $normalized = self::normalizeKey($key);

        if (in_array($normalized, ['content_text', 'content_base64'], true)) {
            return true;
        }

        if (in_array($normalized, self::NON_SECRET_KEYS, strict: true)) {
            return false;
        }

        return self::hasSecretWord($key);
    }

    /** Shared name detection without activity-specific public-key exceptions. */
    public static function hasSecretWord(string $name): bool
    {
        return array_intersect(explode('_', self::normalizeKey($name)), self::SECRET_WORDS) !== [];
    }

    /**
     * @param  array<array-key, mixed>  $environment
     * @return array<string, string>
     */
    private function sanitizeEnvironment(array $environment): array
    {
        $sanitized = [];

        foreach (array_keys($environment) as $name) {
            $safeName = is_string($name) && preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/D', $name) === 1
                ? $name
                : self::INVALID_ENVIRONMENT_NAME;
            $sanitized[$safeName] = self::REDACTED;
        }

        return $sanitized;
    }
}

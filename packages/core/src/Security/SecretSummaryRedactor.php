<?php

declare(strict_types=1);

namespace Orbit\Core\Security;

/**
 * Redacts secret-shaped values from activity and operation summary surfaces.
 *
 * Used at persistence boundaries for command lines, stdout/stderr summaries,
 * and nested structured payloads so raw APP_KEY / password / token / api-key
 * material never lands in activity_log or operation_runs summary columns.
 *
 * Matching is key-shaped (env, JSON, human key/value, nested array keys) plus
 * a few complete structured forms (PEM blocks, HTTP Authorization Bearer
 * syntax). It is not a blanket entropy scan or open-ended credential catalog.
 */
final class SecretSummaryRedactor
{
    public const string REDACTED = '<redacted>';

    /**
     * Case-insensitive exact keys whose values must never be retained.
     *
     * @var list<string>
     */
    private const array FORBIDDEN_KEYS = [
        'app_key',
        'app-key',
        'appkey',
        'application_key',
        'application-key',
        'operation_token',
        'executor_secret',
        'password',
        'password_hash',
        'password-hash',
        'secret',
        'token',
        'api_key',
        'api-key',
        'api_token',
        'api-token',
        'access_token',
        'access-token',
        'refresh_token',
        'refresh-token',
        'private_key',
        'private-key',
        'pre_shared_key',
        'pre-shared-key',
        'bearer',
        'bearer_token',
        'bearer-token',
    ];

    /**
     * Identifier for env / JSON / human string forms.
     * Optional single compound prefix allows user_password / reverb_app_key while
     * word-boundaries keep token_count and ordinary prose intact.
     */
    private const string SECRET_KEY_CORE =
        '(?:APP[_-]?KEY|APPLICATION[_-]?KEY|APPKEY|API[_-]?KEY|API[_-]?TOKEN|ACCESS[_-]?TOKEN|'
            .'REFRESH[_-]?TOKEN|OPERATION[_-]?TOKEN|EXECUTOR[_-]?SECRET|PRIVATE[_-]?KEY|'
            .'PRE[_-]?SHARED[_-]?KEY|PASSWORD[_-]?HASH|PASSWORD|SECRET|TOKEN|BEARER[_-]?TOKEN|BEARER)';

    private const string SECRET_KEY_IDENTIFIER = '(?:[A-Za-z][A-Za-z0-9]*[_-])*'.self::SECRET_KEY_CORE;

    private const string PEM_BLOCK_PATTERN = '/-----BEGIN [A-Z0-9 ]+-----[\s\S]*?-----END [A-Z0-9 ]+-----/';

    public function redactString(string $value): string
    {
        $redacted = $value;
        $keys = self::SECRET_KEY_IDENTIFIER;

        // Complete PEM blocks even when no secret-shaped key names the value.
        $redacted =
            preg_replace(
                self::PEM_BLOCK_PATTERN,
                self::REDACTED,
                $redacted,
            ) ?? $redacted;

        // Authorization / Proxy-Authorization headers (any scheme + credential).
        // Stop at quotes/whitespace so quoted shell header forms keep delimiters.
        $redacted =
            preg_replace(
                '/\b((?:Proxy-)?Authorization)\s*:\s*[^\s\'"]+(?:\s+[^\s\'"]+)?/i',
                '$1: '.self::REDACTED,
                $redacted,
            ) ?? $redacted;

        // Standalone Bearer <credential> (header-less or mid-line).
        // Require quoted or token-shaped credentials so prose like "bearer of"
        // is left alone.
        $redacted =
            preg_replace(
                '/\bBearer\s+(?:"[^"]*"|\'[^\']*\'|[A-Za-z0-9][A-Za-z0-9._\-+\/=]{7,})/i',
                'Bearer '.self::REDACTED,
                $redacted,
            ) ?? $redacted;

        // Env-style: PASSWORD=..., api_key='...', user_password=..., mixed case.
        $redacted =
            preg_replace(
                '/\b('.$keys.')\s*=\s*(?:"[^"]*"|\'[^\']*\'|\S+)/i',
                '$1='.self::REDACTED,
                $redacted,
            ) ?? $redacted;

        // JSON object members: "password":"…", "api-key" : "…".
        $redacted =
            preg_replace(
                '/("(?:'.$keys.')"\s*:\s*)(?:"(?:\\\\.|[^"\\\\])*"|\'(?:\\\\.|[^\'\\\\])*\'|[^,}\s]+)/i',
                '$1"'.self::REDACTED.'"',
                $redacted,
            ) ?? $redacted;

        // Human key: value forms on their own line or after whitespace.
        return (
            preg_replace(
                '/\b('.$keys.')\s*:\s*(?:"[^"]*"|\'[^\']*\'|\S+)/i',
                '$1: '.self::REDACTED,
                $redacted,
            ) ?? $redacted
        );
    }

    /**
     * @param  array<array-key, mixed>  $payload
     * @return array<array-key, mixed>
     */
    public function redactArray(array $payload): array
    {
        $result = [];

        foreach ($payload as $key => $value) {
            if (is_string($key) && $this->isForbiddenKey($key)) {
                $result[$key] = self::REDACTED;

                continue;
            }

            $result[$key] = $this->redactMixed($value);
        }

        return $result;
    }

    public function redactMixed(mixed $value): mixed
    {
        if (is_string($value)) {
            return $this->redactString($value);
        }

        if (is_array($value)) {
            return $this->redactArray($value);
        }

        return $value;
    }

    public function isForbiddenKey(string $key): bool
    {
        // Treat hyphen and underscore forms as one key policy so command-option
        // keys like app-key match app_key without caller-side normalization.
        $normalized = strtolower(str_replace(search: '-', replace: '_', subject: $key));

        foreach (self::FORBIDDEN_KEYS as $forbidden) {
            if ($normalized === str_replace(search: '-', replace: '_', subject: strtolower($forbidden))) {
                return true;
            }
        }

        // Suffix-shaped sibling secrets (user_password, reverb_app_key) without
        // matching ordinary keys like secretary or token_count.
        return (
            preg_match(
                '/(?:^|_)(app_?key|password(?:_hash)?|secret|token|api_?key|api_?token|access_?token|refresh_?token|private_?key|pre_?shared_?key|bearer(?:_?token)?)$/',
                $normalized,
            ) === 1
        );
    }
}

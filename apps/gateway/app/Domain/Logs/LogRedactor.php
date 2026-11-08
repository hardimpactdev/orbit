<?php

declare(strict_types=1);

namespace App\Domain\Logs;

use App\Infrastructure\Activity\CommandActivityInputSanitizer;
use App\Models\Instance;
use App\Models\Process;
use App\Models\Schedule;
use SensitiveParameter;

/**
 * The Gateway's redaction of log text: each stored environment value of the record, then the
 * secret patterns. One-shot log reads and the live log relay both use it (ADR 0153).
 */
final readonly class LogRedactor
{
    /** A shorter value would blank ordinary words, such as `true` or `local`, out of the log. */
    public const int ShortestValue = 8;

    /**
     * The exact keys whose values are Laravel settings, not secrets. Replacing them would hide ordinary
     * words, such as `production` in `production.INFO`. The list names each key in full: a pattern such
     * as `LOG_*` would also skip `LOG_SLACK_WEBHOOK_URL`, which holds a secret. Hosts and URLs stay
     * redacted, because a host name or a URL can carry credentials or name a private system.
     *
     * @var list<string>
     */
    public const array SettingKeys = [
        'APP_ENV', 'APP_NAME', 'APP_DEBUG', 'APP_LOCALE', 'APP_FALLBACK_LOCALE', 'APP_FAKER_LOCALE', 'APP_TIMEZONE',
        'APP_MAINTENANCE_DRIVER', 'APP_MAINTENANCE_STORE', 'BCRYPT_ROUNDS',
        'LOG_CHANNEL', 'LOG_STACK', 'LOG_LEVEL', 'LOG_DEPRECATIONS_CHANNEL',
        'DB_CONNECTION', 'DB_PORT',
        'SESSION_DRIVER', 'SESSION_LIFETIME', 'SESSION_ENCRYPT',
        'BROADCAST_CONNECTION', 'FILESYSTEM_DISK', 'QUEUE_CONNECTION', 'CACHE_STORE', 'CACHE_DRIVER',
        'MAIL_MAILER', 'MAIL_PORT', 'MAIL_ENCRYPTION',
    ];

    public function __construct(private CommandActivityInputSanitizer $sanitizer) {}

    /**
     * The values to replace in the log of an Instance, a Process or a Schedule, longest first: each stored
     * environment value of eight characters or more, except the values of the exact `SettingKeys`.
     *
     * @return list<string>
     */
    public function valuesFor(#[SensitiveParameter] Instance|Process|Schedule $record): array
    {
        if ($record instanceof Schedule) {
            return $record->target instanceof Instance ? $this->valuesFor($record->target) : [];
        }

        $environment = [];

        if ($record instanceof Instance) {
            foreach ($record->environmentValues as $value) {
                $environment[] = [$value->env_key, $value->env_value];
            }
        } else {
            $stored = $record->runtime_config['environment'] ?? null;

            foreach (is_array($stored) ? $stored : [] as $key => $value) {
                $environment[] = [(string) $key, $value];
            }
        }

        $values = [];

        foreach ($environment as [$key, $value]) {
            if (is_string($value) && strlen($value) >= self::ShortestValue && ! in_array(strtoupper($key), self::SettingKeys, strict: true)) {
                $values[] = $value;
            }
        }

        $values = array_values(array_unique($values));
        usort($values, static fn (string $first, string $second): int => strlen($second) <=> strlen($first));

        return $values;
    }

    /** @param list<string> $values */
    public function redact(#[SensitiveParameter] string $text, #[SensitiveParameter] array $values): string
    {
        return $this->sanitizer->redactText($values === [] ? $text : self::replaceCovered($text, $values));
    }

    /**
     * Replace the union of every occurrence of every value with one marker per covered range. Replacing
     * value by value would leave the remainder of an overlapping value, such as `ijk` from `fghijk`
     * after `abcdefgh` in `abcdefghijk`, and could match a value inside an inserted marker.
     *
     * @param  list<string>  $values
     */
    private static function replaceCovered(#[SensitiveParameter] string $text, #[SensitiveParameter] array $values): string
    {
        $ends = [];

        foreach ($values as $value) {
            if ($value === '') {
                continue;
            }

            for ($start = strpos($text, $value); $start !== false; $start = strpos($text, $value, $start + 1)) {
                $ends[$start] = max($ends[$start] ?? 0, $start + strlen($value));
            }
        }

        ksort($ends);
        $redacted = '';
        $cursor = 0;
        $coveredTo = -1;

        foreach ($ends as $start => $end) {
            if ($start > $coveredTo) {
                $redacted .= substr($text, $cursor, $start - $cursor).'[REDACTED]';
            }

            $coveredTo = max($coveredTo, $end);
            $cursor = $coveredTo;
        }

        return $redacted.substr($text, $cursor);
    }
}

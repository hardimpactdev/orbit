<?php

declare(strict_types=1);

namespace App\Console\Commands\Concerns;

trait RendersDeployResponses
{
    protected function wantsJson(): bool
    {
        return $this->option('json') === true;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $meta
     */
    protected function jsonSuccess(array $data, array $meta = []): int
    {
        $this->line(json_encode([
            'success' => [
                'data' => $data,
                'meta' => empty($meta) ? (object) [] : $meta,
            ],
        ], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $data
     */
    protected function failCommand(string $code, string $message, array $meta = [], array $data = []): int
    {
        if (! $this->wantsJson()) {
            $this->error($message);

            return self::FAILURE;
        }

        $error = [
            'code' => $code,
            'message' => $message,
            'meta' => empty($meta) ? (object) [] : $meta,
        ];

        if ($data !== []) {
            $error['data'] = $data;
        }

        $this->line(json_encode(['error' => $error], JSON_THROW_ON_ERROR));

        return self::FAILURE;
    }

    protected function stringArgument(string $key): ?string
    {
        $value = $this->argument($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    protected function stringOption(string $key): ?string
    {
        $value = $this->option($key);

        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }

    protected function positiveIntOption(string $key, int $default): int|false
    {
        $value = $this->option($key);

        if ($value === null || $value === '') {
            return $default;
        }

        if (is_string($value) && ctype_digit($value) && (int) $value >= 1) {
            return (int) $value;
        }

        if (is_int($value) && $value >= 1) {
            return $value;
        }

        return false;
    }

    protected function optionalPositiveIntOption(string $key): int|false|null
    {
        $value = $this->option($key);

        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value) && ctype_digit($value) && (int) $value >= 1) {
            return (int) $value;
        }

        if (is_int($value) && $value >= 1) {
            return $value;
        }

        return false;
    }
}

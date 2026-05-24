<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\GatewayApiClient;
use LaravelZero\Framework\Commands\Command;
use Orbit\Core\Http\JsonEnvelope;

abstract class OrbitCommand extends Command
{
    /**
     * @param  array<string, mixed>  $payload
     */
    protected function outputAsJson(array $payload): int
    {
        $this->line(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }

    protected function gateway(): GatewayApiClient
    {
        return app(GatewayApiClient::class);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    protected function renderFailure(string $code, string $message, array $meta = []): int
    {
        if ($this->wantsJson()) {
            $this->outputAsJson(JsonEnvelope::failure($code, $message, $meta));

            return self::FAILURE;
        }

        $this->line("{$code}: {$message}");

        return self::FAILURE;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $meta
     */
    protected function renderSuccess(array $data = [], array $meta = []): int
    {
        if ($this->wantsJson()) {
            return $this->outputAsJson(JsonEnvelope::success($data, $meta));
        }

        if ($data === []) {
            $this->line('OK');

            return self::SUCCESS;
        }

        foreach ($data as $key => $value) {
            $this->line("{$key}: {$this->renderHumanValue($value)}");
        }

        return self::SUCCESS;
    }

    protected function wantsJson(): bool
    {
        return (bool) $this->option('json');
    }

    private function renderHumanValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_scalar($value) || $value === null) {
            return (string) $value;
        }

        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}

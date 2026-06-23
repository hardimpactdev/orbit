<?php

declare(strict_types=1);

namespace App\Commands\Concerns;

use Orbit\Core\Http\JsonEnvelope;

trait EmitsCanonicalEnvelopes
{
    /**
     * @param  array<string, mixed>  $payload
     */
    protected function outputAsJson(array $payload): int
    {
        $this->outputJsonLine($payload);

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function outputJsonLine(array $payload): void
    {
        $this->line(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        if (defined('STDOUT') && is_resource(STDOUT)) {
            @fflush(STDOUT);
        }
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $data
     */
    protected function renderFailure(string $code, string $message, array $meta = [], array $data = []): int
    {
        if ($this->wantsMachineJson()) {
            $payload = JsonEnvelope::failure($code, $message, $meta);

            if ($data !== []) {
                $payload['error']['data'] = $data;
            }

            $this->outputAsJson($payload);

            return self::FAILURE;
        }

        $this->line("{$code}: {$message}");

        return self::FAILURE;
    }

    /**
     * D12: unwrap an inbound gateway success envelope so the CLI never emits
     * `success.success`. Gateway-backed commands can pass the gateway response
     * verbatim and the helper will resolve the inner data/meta.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $meta
     */
    protected function renderSuccess(array $data = [], array $meta = []): int
    {
        if (isset($data['success']) && is_array($data['success'])) {
            $envelope = $data['success'];
            $innerData = is_array($envelope['data'] ?? null) ? $envelope['data'] : [];
            $innerMeta = is_array($envelope['meta'] ?? null) ? $envelope['meta'] : [];
            $data = $innerData;
            $meta = $meta === [] ? $innerMeta : $meta;
        }

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
        return $this->hasInputOption('json') && (bool) $this->option('json');
    }

    protected function wantsStreamingJson(): bool
    {
        return $this->hasInputOption('stream-json') && (bool) $this->option('stream-json');
    }

    protected function wantsMachineJson(): bool
    {
        return $this->wantsJson() || $this->wantsStreamingJson();
    }

    protected function allowsInteractiveInput(): bool
    {
        return ! $this->wantsMachineJson() && $this->input->isInteractive();
    }

    private function hasInputOption(string $option): bool
    {
        return $this->input->hasOption($option);
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

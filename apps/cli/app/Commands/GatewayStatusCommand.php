<?php

declare(strict_types=1);

namespace App\Commands;

use App\Exceptions\GatewayApiException;

final class GatewayStatusCommand extends OrbitCommand
{
    private const string ENDPOINT = '/api/status';

    #[\Override]
    protected $signature = 'gateway:status {--json}';

    #[\Override]
    protected $description = 'Show gateway API status';

    public function handle(): int
    {
        try {
            $response = $this->gateway()->get(self::ENDPOINT);
        } catch (GatewayApiException $exception) {
            return $this->renderFailure(
                $exception->cliFailureCode(),
                $exception->getMessage(),
                $this->failureMeta($exception),
            );
        }

        if ($this->wantsJson()) {
            return $this->renderSuccess($response, ['endpoint' => self::ENDPOINT]);
        }

        return $this->renderGatewayStatus($response);
    }

    /**
     * Render the `gateway` status fields as flat key-value lines. Top-level
     * response keys outside `gateway` are not shown. When `gateway` is absent,
     * the full response is rendered so an older gateway shape stays readable.
     *
     * @param  array<string, mixed>  $response
     */
    private function renderGatewayStatus(array $response): int
    {
        $body = $this->unwrapEnvelope($response);
        $gateway = $body['gateway'] ?? $body;

        if (! is_array($gateway)) {
            $gateway = ['status' => $gateway];
        }

        foreach ($gateway as $key => $value) {
            $this->line("{$key}: {$this->statusValue($value)}");
        }

        return self::SUCCESS;
    }

    /**
     * Unwrap a gateway success envelope so the documented status fields render
     * directly, never the raw `success` wrapper.
     *
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    private function unwrapEnvelope(array $response): array
    {
        $success = $response['success'] ?? null;

        if (is_array($success)) {
            $data = $success['data'] ?? null;

            return is_array($data) ? $data : [];
        }

        return $response;
    }

    private function statusValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_scalar($value) || $value === null) {
            return (string) $value;
        }

        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @return array<string, mixed>
     */
    private function failureMeta(GatewayApiException $exception): array
    {
        $meta = ['endpoint' => self::ENDPOINT];

        if ($exception->statusCode() !== null) {
            $meta['status_code'] = $exception->statusCode();
        }

        if ($exception->bodyExcerpt() !== null) {
            $meta['body_excerpt'] = $exception->bodyExcerpt();
        }

        return $meta;
    }
}
